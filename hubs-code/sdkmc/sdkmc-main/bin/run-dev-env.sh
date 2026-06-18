#!/usr/bin/env bash
# Easy one-stop shop bringing up your SDKMC stack.
# Run bin/prune-docker.sh in each repo to clean up its containers/volumes.

set -euo pipefail

# Define the log file
LOGFILE="$HOME/sdkmc-run-dev.log"

# Get the operating system name
OS_NAME="$(uname)"

# Logging function to output messages with timestamps to both console and log file
log() {
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp]" "$@" | tee -a "$LOGFILE"
}

# Add a separator line in the log file to indicate a new script execution
log "------------------------------------------------------------"
log "Starting SDKMC stack script execution"

# Check for Docker installation
if ! command -v docker &>/dev/null; then
    log "Docker command not found. Please install Docker."
    exit 1
else
    log "Docker is installed."
fi

# Check for Docker Compose installation
if ! docker compose version &>/dev/null; then
    log "Docker Compose is not available. Please install Docker Compose."
    exit 1
else
    log "Docker Compose is installed."
fi

# Set the container name (default to 'nextcloud' if not set)
SDKMC_CONTAINER_NAME="${SDKMC_CONTAINER_NAME:-nextcloud}"
log "Using container name: $SDKMC_CONTAINER_NAME"

# Set the SDKMC path (default to parent directory of the script)
SDKMC_PATH="${SDKMC_PATH:-$(cd "$(dirname "$0")"/../ && pwd)}"
log "Using SDKMC path: $SDKMC_PATH"

# Function to check if Nextcloud is installed inside the container
check_nextcloud_installed() {
    sudo docker compose exec "$SDKMC_CONTAINER_NAME" php occ status 2>/dev/null | grep -q 'installed: true'
}

# Check if the OS is Darwin (macOS)
if [[ "$OS_NAME" == "Darwin" ]]; then
    log "This system is a Mac (macOS)."

    # Find our gitlab version of the mail repo on the developers machine containing appinfo/info.xml
    log "Finding mail app directory..."
    MAIL_APP_PATH=$(sudo find /Users -type d -name "mail" -exec test -e "{}/appinfo/info.xml" \; -print -quit)
    if [ -z "$MAIL_APP_PATH" ]; then
        log "Error: Could not find the mail app directory containing appinfo/info.xml"
        log "Proceeding without mounting mail app..."
        mkdir -p "$HOME"/.no_mail
    else
        log "Found mail app directory: $MAIL_APP_PATH"
        # Export so Docker Compose can read it
        echo "MAIL_APP_PATH=$MAIL_APP_PATH" >> "$SDKMC_PATH"/.env
    fi

    # --- SPREED APP ---
    log "Finding spreed app directory..."
    SPREED_APP_PATH=$(sudo find /Users -type d -name "spreed" -exec test -e "{}/appinfo/info.xml" \; -print -quit)
    if [ -z "$SPREED_APP_PATH" ]; then
        log "Error: Could not find the spreed app directory containing appinfo/info.xml"
        log "Proceeding without mounting spreed app..."
        mkdir -p "$HOME"/.no_spreed
    else
        log "Found spreed app directory: $SPREED_APP_PATH"
        echo "SPREED_APP_PATH=$SPREED_APP_PATH" >> "$SDKMC_PATH"/.env
    fi

else
    log "This system is not a Mac."

    # Find our gitlab version of the mail repo on the developers machine containing appinfo/info.xml
    log "Finding mail app directory..."
    MAIL_APP_PATH=$(find ~/ -type d -name "mail" -exec test -e "{}/appinfo/info.xml" \; -print -quit)
    if [ -z "$MAIL_APP_PATH" ]; then
        log "Error: Could not find the mail app directory containing appinfo/info.xml"
        log "Proceeding without mounting mail app..."
        mkdir -p "$HOME"/.no_mail
    else
        log "Found mail app directory: $MAIL_APP_PATH"
        # Export so Docker Compose can read it
        echo "MAIL_APP_PATH=$MAIL_APP_PATH" >> "$SDKMC_PATH"/.env
    fi

    # --- SPREED APP ---
    log "Finding spreed app directory..."
    SPREED_APP_PATH=$(find "$HOME" -type d -name "spreed" -exec test -e "{}/appinfo/info.xml" \; -print -quit)
    if [ -z "$SPREED_APP_PATH" ]; then
        log "Error: Could not find the spreed app directory containing appinfo/info.xml"
        log "Proceeding without mounting spreed app..."
        mkdir -p "$HOME"/.no_spreed
    else
        log "Found spreed app directory: $SPREED_APP_PATH"
        echo "SPREED_APP_PATH=$SPREED_APP_PATH" >> "$SDKMC_PATH"/.env
    fi
fi

# Start the SDKMC stack
log "Starting SDKMC stack..."

# Navigate to the SDKMC directory
cd "$SDKMC_PATH"
log "Changed directory to $SDKMC_PATH"

# Start Docker Compose for SDKMC with build, detach, and wait options
log "Running 'docker compose up' with build, detach, and wait options..."
sudo docker compose up --build --detach --wait

# Check if Nextcloud is installed and available
if ! check_nextcloud_installed; then
    log "Waiting for Nextcloud to become available..."
    TIMEOUT=300 # Total wait time in seconds
    INTERVAL=5  # Interval between checks in seconds
    ELAPSED=0

    # Loop until Nextcloud becomes available or timeout is reached
    until check_nextcloud_installed; do
        sleep $INTERVAL
        ELAPSED=$((ELAPSED + INTERVAL))
        log "Checked at $ELAPSED seconds..."
        if [ $ELAPSED -ge $TIMEOUT ]; then
            log "Timeout reached after $ELAPSED seconds while waiting for Nextcloud to become available."
            exit 1
        fi
    done
    log "Nextcloud is now available."
else
    log "SDKMC is already installed and running."
fi

log "Installing and enabling Calendar app..."
sudo docker compose exec "$SDKMC_CONTAINER_NAME" php occ app:install calendar || true
sudo docker compose exec "$SDKMC_CONTAINER_NAME" php occ app:enable calendar || true
log "Calendar app installed and enabled (if not already)."

# locally checkout branch for mail if we're on main/master fail and log
if [ -n "$MAIL_APP_PATH" ]; then
    log "Checking out mail app branch..."
    cd "$MAIL_APP_PATH"
    current_branch=$(git rev-parse --abbrev-ref HEAD)
    if [ "$current_branch" = "main" ] || [ "$current_branch" = "master" ]; then
        log "Currently on $current_branch branch. Please switch to a feature branch, exiting now..."
        exit 1
    else
        log "Already on a feature branch: $current_branch"
    fi
    cd "$SDKMC_PATH"
else
    log "Skipping mail app branch checkout as MAIL_APP_PATH is not set."
fi

# Set up the mail app if the path is set
if [ -n "$MAIL_APP_PATH" ]; then
    log "Mail app setup..."
    sudo docker compose exec "$SDKMC_CONTAINER_NAME" bash -c 'sudo chown -R developer:developer /var/www/html/apps-extra/mail; source /home/developer/.nvm/nvm.sh && cd /var/www/html/apps-extra/mail && export GIT_DISCOVERY_ACROSS_FILESYSTEM=1 && rm node_modukes -rf && make dev-setup'
    sudo docker compose exec "$SDKMC_CONTAINER_NAME" php occ app:enable mail
    log "Mail app configured and enabled successfully."
else
    log "Skipping mail app setup as MAIL_APP_PATH is not set."
fi

# Set up the spreed app if the path is set
if [ -n "$SPREED_APP_PATH" ]; then
    log "Spreed app setup..."
    sudo docker compose exec "$SDKMC_CONTAINER_NAME" bash -c ' chown -R developer:developer /var/www/html/apps-extra/spreed php /var/www/html/occ app:enable spreed || true
    '
    sudo docker compose exec "$SDKMC_CONTAINER_NAME" php occ app:enable spreed
    log "Spreed app configured and enabled successfully."
else
    log "Skipping spreed app setup as SPREED_APP_PATH is not set."
fi

# Execute commands inside the SDKMC container
log "Executing commands inside the SDKMC container..."

sudo docker compose exec "$SDKMC_CONTAINER_NAME" bash -c '
    # Enable error handling and verbose output inside the container
    set -euo pipefail
    set -x

#    echo "Sourcing NVM (Node Version Manager)..."
#    source /home/developer/.nvm/nvm.sh

    echo "Changing directory to /var/www/html/apps-extra/sdkmc..."
    cd /var/www/html/apps-extra/sdkmc

    # Update Composer dependencies
    echo "Updating Composer dependencies..."
    composer update

    # Install NPM packages
#    echo "Installing NPM packages..."
#    npm install

    # Fix NPM audit issues
#    echo "Fixing NPM audit issues..."
#    npm audit fix || true

    # Build processes
#    echo "Running development build..."
#    npm run dev

    # Uncomment the following lines to run make and Jest tests
    # echo "Running \"make\" command..."
    # make

    # echo "Running Jest tests..."
    # ./node_modules/jest/bin/jest.js --config jest.config.js --silent false

    # Enable the SDKMC app
    echo "Enabling SDKMC app..."
    php /var/www/html/occ app:enable sdkmc
'
log "SDKMC stack has been successfully started."
