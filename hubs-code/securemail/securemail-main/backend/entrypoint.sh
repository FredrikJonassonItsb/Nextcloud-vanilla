#!/usr/bin/env bash
# Enable debugging and exit immediately if any command fails.
set -ex

# -----------------------------------------------------------------------------
# Variable Definitions for Initialization
# -----------------------------------------------------------------------------
INIT_FILE="/opt/backend/.initialized"
SERVICE_NAME="securemail-backend"

# -----------------------------------------------------------------------------
# Handle Initialization State
# -----------------------------------------------------------------------------
echo "----- Checking ${SERVICE_NAME} Initialization State -----"

# Handle empty .initialized file
if [ -f "${INIT_FILE}" ] && [ ! -s "${INIT_FILE}" ]; then
    echo "Found empty .initialized, setting to 0..."
    echo "0" > "${INIT_FILE}"
fi

# -----------------------------------------------------------------------------
# First-Time Initialization
# -----------------------------------------------------------------------------
if [ ! -f "${INIT_FILE}" ] || [[ "$(tr -dc '[:digit:]' < ${INIT_FILE})" -lt "1" ]]; then
    echo "----- FIRST-TIME INITIALIZATION for ${SERVICE_NAME} -----"
    
    # Copy static files (package files and routes - no runtime variables needed)
    echo "----- Copying package files -----"
    cp "/opt/templates/pnpm-lock.yaml" "/opt/backend/pnpm-lock.yaml"
    cp "/opt/templates/package.json" "/opt/backend/package.json"
    
    echo "----- Copying routes directory -----"
    mkdir -p "/opt/backend/routes"
    if [ -d "/opt/templates/routes" ]; then
      cp -r "/opt/templates/routes"/* "/opt/backend/routes/"
    else
      echo "Warning: Template routes directory not found. Skipping copy."
    fi
    
    # Dependencies already installed during Docker build
    echo "----- Skipping dependency installation (done during Docker build) -----"
    
    # Mark as initialized
    echo "1" > "${INIT_FILE}"
    echo "----- First-time initialization completed for ${SERVICE_NAME} -----"
else
    echo "----- Already initialized ${SERVICE_NAME} -----"
fi

# -----------------------------------------------------------------------------
# Every-Time Operations
# -----------------------------------------------------------------------------
echo "----- Running Every-Time Operations for ${SERVICE_NAME} -----"

# -----------------------------------------------------------------------------
# Update CA Trust Store (project-overview#102)
# Custom CAs from Hubs mounted at /usr/local/share/ca-certificates/custom/
# -----------------------------------------------------------------------------
echo "----- Updating CA Trust Store -----"
if [ -d "/usr/local/share/ca-certificates/custom" ] && \
   [ "$(ls -A /usr/local/share/ca-certificates/custom/ 2>/dev/null)" ]; then
    echo "Found custom CA certificates, updating trust store..."
else
    echo "No custom CA certificates found, using system defaults."
fi
# Always run update-ca-certificates to add new CAs or remove stale ones
# (stale certs persist in /etc/ssl/certs/ across container restarts)
update-ca-certificates 2>/dev/null || echo "WARNING: update-ca-certificates failed"

# Process server.js template with runtime variables (must run every startup)
# NOTE: We process server.js at runtime (not Docker build time) because:
# - Environment variables come from vault at runtime, not during CI/CD
# - Node.js can read the processed file directly (no build step needed)
# - Unlike frontend, backend doesn't require compilation after template processing
# 
# FUTURE IMPROVEMENT: Consider caching processed server.js:
# - Only reprocess if environment variables actually changed
# - Use environment variable hash to detect changes
# - Cache processed files based on variable combinations
echo "----- Processing server.js template -----"
src_app_template="/opt/templates/server.js"
dst_app="/opt/backend/server.js"
echo "Substituting environment variables in server.js template:"
echo "  Source: ${src_app_template}"
echo "  Destination: ${dst_app}"
sudo -E sh -c "envsubst '\${SECUREMAIL_BACKEND_IP} \${SECUREMAIL_BACKEND_PORT} \${SECUREMAIL_POSTFIX_IP} \${SECUREMAIL_POSTFIX_PORT} \${SECUREMAIL_DOVECOT_PORT} \${SECUREMAIL_DOVECOT_IP}' < '${src_app_template}' > '${dst_app}'"

# Validate server.js syntax
echo "----- Validating server.js syntax -----"
if node --check "${dst_app}"; then
  echo "----- Server.js syntax validation passed -----"
else
  echo "----- Server.js syntax validation failed. Exiting -----"
  exit 1
fi

echo "----- Copying backend modules -----"
cp "/opt/templates/otpStore.js" "/opt/backend/otpStore.js"
cp "/opt/templates/ticketStore.js" "/opt/backend/ticketStore.js"
cp "/opt/templates/sdkmcClient.js" "/opt/backend/sdkmcClient.js"

echo "----- Processing node_modules -----"
src_app_template="/opt/templates/node_modules"
dst_app="/opt/backend/node_modules"
echo "Copy node_modules"
echo "  Source: $src_app_template"
echo "  Destination: $dst_app"
cp -r "$src_app_template" "$dst_app"

# -----------------------------------------------------------------------------
# Launch Backend
# -----------------------------------------------------------------------------
echo "----- Launching  command:" "$@" "-----"
exec "$@"