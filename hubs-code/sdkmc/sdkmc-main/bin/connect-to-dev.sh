#!/bin/bash
################################################################################
# Development Testing Script for ITSL SDK Remote Environments
################################################################################
#
# Purpose:
#   - Connect to remote ITSL SDK deployments via SSH with port forwarding
#   - Switch middleware from production to development mode for local testing
#   - Automatically restore production configuration on disconnect
#
# Updated for itsl-sdk deployment approach (migrated from legacy sdk-iac)
#
################################################################################
# PREREQUISITES ON TARGET MACHINE
################################################################################
#
# =============================================================================
# OPTION 1: Middleware Development Mode
# =============================================================================
#
# TODO: The following files must be deployed to the target machine before
#       using Option 1 (Middleware development mode).
#       These are NOT automatically deployed yet and must be manually copied.
#
# Required Files:
#   1. /opt/project_data/middleware/javamw/dev-entrypoint.sh
#      - Entrypoint script for javamw-nginx-dev container
#      - Validates MC_DOMAIN environment variable
#      - Uses envsubst to substitute ${MC_DOMAIN} in nginx template
#      - Starts nginx in foreground
#
#   2. /opt/project_data/middleware/javamw/nginx/dev.conf
#      - Nginx configuration template for javamw-dev proxy
#      - Port 1444 SSL: iipaxproxy interface (sdkmw.itsl.internal)
#      - Port 8090: javamw interface (sdkmw-outgoing.itsl.internal)
#      - Proxies traffic to local javamw via reverse SSH tunnel
#
# Source Files (to copy from):
#   - /path/to/javamw/dev-entrypoint.sh
#   - /path/to/javamw/nginx/dev.conf
#
# Docker Compose Configuration:
#   The docker-compose.yml needs these bind mounts in javamw-nginx-dev:
#
#   middleware-javamw-nginx-dev:
#     image: nginx:1.17.6
#     profiles: ["javamw-dev"]
#     volumes:
#       - "${PROJECT_DATA}/middleware/javamw/nginx/dev.conf:/etc/nginx/template.conf:ro"
#       - "${PROJECT_DATA}/middleware/javamw/dev-entrypoint.sh:/docker-entrypoint.sh:ro"
#       - "${PROJECT_DATA}/middleware/javamw/certs:/etc/nginx/certs:ro"
#     entrypoint: ["sh", "/docker-entrypoint.sh"]
#     environment:
#       - MC_DOMAIN=${MC_DOMAIN}
#
# Why These Files Are Needed:
#   - The javamw-nginx-dev container acts as a reverse proxy
#   - It forwards requests from remote iipax to your local javamw instance
#   - Without these files, the container fails to start (missing bind mounts)
#
# Deployment:
#   See: /path/to/hubs-ci-manager/docs/javamw-dev-fix-plan.md
#   for manual setup or permanent deployment via initiator ansible
#
# =============================================================================
# OPTION 2: MessageClient Port Forwarding
# =============================================================================
#
# This option does NOT require any special setup on the target machine.
# It only creates SSH port forwards to access mail services for local testing.
#
# What it does:
#   - Creates SSH tunnels to forward mail service ports from remote to local
#   - Does NOT modify any containers or services on the remote machine
#   - Safe to disconnect at any time (Ctrl+C) - no cleanup needed
#
# Local Ports Forwarded:
#   - 10143 → Remote IMAP (Dovecot on port 143)
#   - 10025 → Remote SMTP Outbound (Postfix on port 10025)
#   - 10026 → Remote SMTP Inbound (Postfix on port 25)
#   - 10124 → Additional service port
#   - 10123 → Additional service port
#
# Use Case:
#   - Testing local Hubs/MessageClient against remote mail infrastructure
#   - Accessing remote mailboxes via IMAP without exposing services publicly
#   - Testing mail sending/receiving workflows with production-like setup
#
# Local Access:
#   - Direct: Connect to localhost:10143 (IMAP), localhost:10025 (SMTP), etc.
#   - Docker: Use host.docker.internal:10143 from containers on same host
#
################################################################################

# --- Function Definitions ---
usage() {
    echo ""
    echo "Usage: $0 -k <ssh_key_path> -d <devenv_target>"
    echo ""
    echo "Required Arguments:"
    echo "  -k, --key <path>     Path to SSH private key file"
    echo "  -d, --devenv <host>  Target host (IP address or subdomain)"
    echo ""
    echo "Optional Arguments:"
    echo "  -h, --help           Display this help message"
    echo ""
    echo "Examples:"
    echo "  $0 -k ~/.ssh/id_rsa -d 10.43.51.34    # Connect to IP address"
    echo "  $0 -k ~/.ssh/id_rsa -d dev7           # Connect to dev7.hubs.se"
    echo ""
    exit 1
}

# Validate that -d parameter is either a valid IP or subdomain
validate_devenv() {
    local input="$1"
    # Check if it's a valid IP address (x.x.x.x format)
    if [[ "$input" =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
        return 0
    # Check if it's a valid subdomain (alphanumeric with optional hyphens)
    elif [[ "$input" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$ ]]; then
        return 0
    else
        return 1
    fi
}

# Function to determine the target host (IP or subdomain.hubs.se)
get_target_host() {
    local input="$1"
    # Check if input looks like an IP address (contains dots and numbers)
    if [[ "$input" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "$input"
    else
        echo "${input}.hubs.se"
    fi
}

# Function to ensure GatewayPorts is enabled on remote server
ensure_gateway_ports() {
    local key_path="$1"
    local target_host="$2"

    echo "[DEBUG] Checking GatewayPorts setting on remote server..."
    local current_setting=$(ssh -i "$key_path" -o StrictHostKeyChecking=no ubuntu@"$target_host" "sudo sshd -T 2>/dev/null | grep -i gatewayports | awk '{print \$2}'" 2>/dev/null)

    if [ "$current_setting" != "clientspecified" ] && [ "$current_setting" != "yes" ]; then
        echo "[DEBUG] Enabling GatewayPorts clientspecified on remote server..."
        ssh -i "$key_path" -o StrictHostKeyChecking=no ubuntu@"$target_host" "
            sudo sed -i 's/^#*GatewayPorts.*/GatewayPorts clientspecified/' /etc/ssh/sshd_config
            if ! grep -q '^GatewayPorts' /etc/ssh/sshd_config; then
                echo 'GatewayPorts clientspecified' | sudo tee -a /etc/ssh/sshd_config > /dev/null
            fi
            sudo systemctl reload ssh
        " 2>/dev/null
        echo "[DEBUG] GatewayPorts enabled and sshd reloaded"
    else
        echo "[DEBUG] GatewayPorts already configured: $current_setting"
    fi
}

# Function to create restore.sh on remote machine
copy_restore_script() {
    local key_path="$1"
    local devenv_target="$2"
    local target_host=$(get_target_host "$devenv_target")

    echo "[DEBUG] Creating restore.sh on remote machine ($target_host)..."
    ssh -i "$key_path" -o StrictHostKeyChecking=no ubuntu@"$target_host" 'cat > /tmp/restore.sh <<\RESTORE_EOF
#!/bin/bash
# Restore script for ITSL SDK development testing
# Auto-generated by test.sh

# Check if PID argument is provided
if [ -z "$1" ]; then
    echo "$(date) [Restore $$] ERROR: Usage: $0 <PID_TO_MONITOR>" >> "/tmp/restore_sh.log"
    exit 1
fi

PID_TO_MONITOR=$1
LOG_FILE="/tmp/restore_sh.log"

echo "$(date): [Restore $$] Starting restore monitor for PID $PID_TO_MONITOR..." >> "$LOG_FILE"

# Wait for the monitored process (remote shell) to exit
while kill -0 "$PID_TO_MONITOR" >/dev/null 2>&1; do
    echo "$(date): [Restore $$] PID $PID_TO_MONITOR still alive. Sleeping 5s..." >> "$LOG_FILE"
    sleep 5
done

echo "$(date): [Restore $$] PID $PID_TO_MONITOR finished. Starting restoration..." >> "$LOG_FILE"

# First, stop the dev container if it is running
echo "$(date): [Restore $$] Stopping javamw-nginx-dev container if running..." >> "$LOG_FILE"
sudo docker stop middleware-javamw-nginx-dev >> "$LOG_FILE" 2>&1 || true
sudo docker rm middleware-javamw-nginx-dev >> "$LOG_FILE" 2>&1 || true

# Use itsl-sdk commands for restoration
echo "$(date): [Restore $$] Stopping all services..." >> "$LOG_FILE"
sudo itsl-sdk stop >> "$LOG_FILE" 2>&1
if [ $? -ne 0 ]; then
    echo "$(date): [Restore $$] WARNING: itsl-sdk stop had non-zero exit (continuing...)" >> "$LOG_FILE"
fi

echo "$(date): [Restore $$] Allowing 2s for services to stop..." >> "$LOG_FILE"
sleep 2

echo "$(date): [Restore $$] Starting services with production configuration..." >> "$LOG_FILE"
sudo itsl-sdk start >> "$LOG_FILE" 2>&1
if [ $? -ne 0 ]; then
    echo "$(date): [Restore $$] ERROR: itsl-sdk start failed" >> "$LOG_FILE"
    exit 1
fi

echo "$(date): [Restore $$] Restoration complete. Services restored to configured state." >> "$LOG_FILE"
exit 0
RESTORE_EOF
chmod +x /tmp/restore.sh'

    if [ $? -ne 0 ]; then
        echo "Error: Failed to create restore.sh on remote machine" >&2
        return 1
    fi

    echo "[DEBUG] restore.sh created successfully on remote machine"
    return 0
}

# Function to connect to middleware environment (Dev Profile - FG Compose, Exit Shell on Ctrl+C, Restore via Monitor)
connect_middleware_dev() {
    local key_path="$1"
    local devenv_target="$2"
    local target_host=$(get_target_host "$devenv_target")

    echo "Connecting to Middleware Dev ($target_host) using key $key_path..."
    echo "[DEBUG] Starting SSH connection..."

    ssh -i "$key_path" -t \
        -R 172.17.0.1:7502:172.17.0.1:7502 \
        -L 0.0.0.0:20143:172.10.0.23:30143 \
        -L 0.0.0.0:20025:127.0.0.1:30025 \
        -L 0.0.0.0:28090:172.10.0.24:8090 \
        ubuntu@"$target_host" << \EOF
# Log file on remote
LOG_FILE="/tmp/restore_sh.log"

echo "$(date): [REMOTE $$] Starting connect_middleware_dev session." >> "$LOG_FILE"

# -- Remote Setup Start --
# Auto-discover PROJECT_DATA path
if [ -n "$PROJECT_DATA" ]; then
    DEPLOY_DIR="$PROJECT_DATA/initiator"
elif [ -f "/var/lib/itsl-sdk/current-project" ]; then
    DEPLOY_DIR="$(cat /var/lib/itsl-sdk/current-project)/initiator"
else
    DEPLOY_DIR="/opt/project_data/initiator"
fi

echo "[DEBUG REMOTE $$] Using deployment directory: $DEPLOY_DIR"
cd "$DEPLOY_DIR" || exit 1

echo "[DEBUG REMOTE $$] Stopping and removing production middleware-javamw container..."
sudo docker stop middleware-javamw 2>/dev/null || true
sudo docker rm middleware-javamw 2>/dev/null || true
echo "[DEBUG REMOTE $$] Removing any existing nginx-dev container..."
sudo docker stop middleware-javamw-nginx-dev 2>/dev/null || true
sudo docker rm middleware-javamw-nginx-dev 2>/dev/null || true
echo "[DEBUG REMOTE $$] Allowing 2s for cleanup..."
sleep 2

echo "[DEBUG REMOTE $$] Middleware development mode - local javamw will be used"
echo "[DEBUG REMOTE $$] Note: restore.sh will restart all services when you disconnect"
# -- Remote Setup End --

# Capture PID of this remote shell process
REMOTE_SHELL_PID=$PPID
echo "[DEBUG REMOTE $$] Remote execution shell PID: $REMOTE_SHELL_PID"

# Launch restore.sh IN BACKGROUND, monitoring this shell's PID
echo "[DEBUG REMOTE $$] Launching restore.sh in background to monitor PID $REMOTE_SHELL_PID..."
# restore.sh uses itsl-sdk commands which auto-discover paths
nohup /tmp/restore.sh $REMOTE_SHELL_PID >> "$LOG_FILE" 2>&1 &
RESTORE_PID=$!
echo "[DEBUG REMOTE $$] Restore monitor (restore.sh) PID: $RESTORE_PID, monitoring PID $REMOTE_SHELL_PID"
echo "[DEBUG REMOTE $$] Allowing 1s for restore script to launch..."
sleep 1

echo ""
echo "--- Starting Dev Profile in Foreground ---"
echo "--- Press Ctrl+C here to EXIT this shell and trigger background restoration. ---"

echo
echo
echo
echo
echo
echo
echo
echo
echo "   +----------------------------------------------------------------------------------+"
echo "   |                                                                                  |"
echo "   |   ITSL SDK Development Mode - Middleware Testing                                 |"
echo "   |                                                                                  |"
echo "   |   Your local javamw should listen on 127.0.0.1:7502 to receive data from iipax   |"
echo "   |                                                                                  |"
echo "   |   Server services available on local machine:                                    |"
echo "   |                                                                                  |"
echo "   |   Port 20143: Dovecot (IMAP)                                                     |"
echo "   |   Port 20025: Postfix (SMTP)                                                     |"
echo "   |   Port 28090: IIPAX (HTTP)                                                       |"
echo "   |                                                                                  |"
echo "   |   To disconnect and restore sdk profile (javamw) on the server, use ^C           |"
echo "   |                                                                                  |"
echo "   +----------------------------------------------------------------------------------+"
echo
echo
echo
echo
echo
echo
echo
echo

# --- Run Compose in Foreground ---
# Include both profiles so depends_on validation passes, but use --no-deps to avoid recreating initiator
echo "[DEBUG REMOTE $$] Executing: sudo docker compose --profile initiator --profile javamw-dev up --no-deps middleware-javamw-nginx-dev"
sudo docker compose --profile initiator --profile javamw-dev up --no-deps middleware-javamw-nginx-dev
COMPOSE_EXIT_CODE=$?
echo "[DEBUG REMOTE $$] 'docker compose up' finished with exit code $COMPOSE_EXIT_CODE." >> "$LOG_FILE"

# --- End of normal execution ---
# If we reach here, compose exited normally (not via Ctrl+C trap).
echo "[DEBUG REMOTE $$] Reached end of script normally. Shell (PID $REMOTE_SHELL_PID) exiting." >> "$LOG_FILE"

EOF
SSH_EXIT_CODE=$?
echo "[DEBUG] SSH command finished with exit code: $SSH_EXIT_CODE"

# Informational message restore.sh in progress and sleep for 10 seconds
echo
echo "Restore.sh in progress, please wait..."
sleep 10

# SSH connection closes after remote shell exits
echo "Middleware session ended and connection closed."
}

# Function to connect to messageclient environment
connect_messageclient() {
    local key_path="$1"
    local devenv_target="$2"
    local target_host=$(get_target_host "$devenv_target")

    echo "Connecting to MessageClient ($target_host) using key $key_path..."

    ssh -i "$key_path" -t \
        -L 0.0.0.0:10143:127.0.0.1:143 \
        -L 0.0.0.0:10025:127.0.0.1:10025 \
        -L 0.0.0.0:10026:127.0.0.1:25 \
        -L 0.0.0.0:10124:127.0.0.1:10124 \
        -L 0.0.0.0:10123:127.0.0.1:10123 \
        ubuntu@"$target_host" << \EOF
    echo
    echo
    echo
    echo
    echo
    echo
    echo
    echo
    echo "   +----------------------------------------------------------------------------------+"
    echo "   |                                                                                  |"
    echo "   |   Use the following settings in Hubs to connect to services                      |"
    echo "   |                                                                                  |"
    echo "   |   SMTP Host: host.docker.internal                                                |"
    echo "   |   SMTP Port: 10025                                                               |"
    echo "   |                                                                                  |"
    echo "   |   Inbound SMTP Host: host.docker.internal                                        |"
    echo "   |   Inbound SMTP Port: 10026                                                       |"
    echo "   |                                                                                  |"
    echo "   |   IMAP Host: host.docker.internal                                                |"
    echo "   |   IMAP Port: 10143                                                               |"
    echo "   |                                                                                  |"
    echo "   |   To disconnect, use ^C                                                          |"
    echo "   |                                                                                  |"
    echo "   +----------------------------------------------------------------------------------+"
    echo
    echo
    echo
    echo
    echo
    echo
    echo
    echo
    while true; do sleep 1; done;
EOF
}


# --- Argument Parsing ---
SSH_KEY=""
DEVENV=""

while [[ $# -gt 0 ]]; do
    key="$1"
    case $key in
        -k|--key)
        SSH_KEY="$2"
        shift # past argument
        shift # past value
        ;;
        -d|--devenv)
        DEVENV="$2"
        shift # past argument
        shift # past value
        ;;
        -h|--help)
        usage
        ;;
        *)
        echo "Unknown option: $1"
        usage
        ;;
    esac
done

# --- Validation --- Check if mandatory arguments were provided
if [ -z "$SSH_KEY" ]; then
    echo "" >&2
    echo "Error: SSH key path (-k) is required." >&2
    echo "" >&2
    usage
fi

if [ -z "$DEVENV" ]; then
    echo "" >&2
    echo "Error: Target host (-d) is required." >&2
    echo "" >&2
    usage
fi

# Expand ~ in key path AFTER validation
SSH_KEY=$(eval echo "$SSH_KEY")

# Validate SSH key file exists
if [ ! -f "$SSH_KEY" ]; then
    echo "" >&2
    echo "Error: SSH key file not found: $SSH_KEY" >&2
    echo "Please provide a valid SSH private key path." >&2
    echo "" >&2
    exit 1
fi

# Validate SSH key file permissions (should not be world-readable)
# Note: Different stat syntax for Linux vs macOS
if [ "$(uname)" = "Darwin" ]; then
    # macOS uses -f "%Lp" and returns octal permissions
    KEY_PERMS=$(stat -f "%Lp" "$SSH_KEY" 2>/dev/null | tail -c 4)
else
    # Linux uses -c "%a"
    KEY_PERMS=$(stat -c "%a" "$SSH_KEY" 2>/dev/null)
fi

if [ -n "$KEY_PERMS" ] && [ "$KEY_PERMS" -gt 600 ]; then
    echo "" >&2
    echo "Warning: SSH key has insecure permissions: $KEY_PERMS" >&2
    echo "Recommend running: chmod 600 $SSH_KEY" >&2
    echo "" >&2
fi

# Validate devenv format
if ! validate_devenv "$DEVENV"; then
    echo "" >&2
    echo "Error: Invalid target host format: $DEVENV" >&2
    echo "Must be either:" >&2
    echo "  - IP address (e.g., 10.43.51.34)" >&2
    echo "  - Subdomain (e.g., dev7)" >&2
    echo "" >&2
    exit 1
fi

echo "[CONFIG] Using SSH Key: $SSH_KEY"
echo "[CONFIG] Using Dev Env: $DEVENV"

# --- Main Menu Logic ---
echo ""
echo "Select environment to connect to:"
echo "  1) Middleware (Switch to dev profile, auto-restore on disconnect)"
echo "  2) Messageclient (Port forwarding for local testing)"
read -rp "Enter your choice (1-2): " choice

case $choice in
    1)
        # Ensure GatewayPorts is enabled for reverse tunnel binding
        ensure_gateway_ports "$SSH_KEY" "$(get_target_host "$DEVENV")"
        # Copy restore.sh to remote machine before connecting
        copy_restore_script "$SSH_KEY" "$DEVENV"
        if [ $? -ne 0 ]; then
            echo "Failed to copy restore.sh. Aborting." >&2
            exit 1
        fi
        connect_middleware_dev "$SSH_KEY" "$DEVENV"
        ;;
    2)
        connect_messageclient "$SSH_KEY" "$DEVENV"
        ;;
    *)
        echo "Invalid choice"
        exit 1
        ;;
esac

exit 0
