#!/usr/bin/env bash
set -e

# -----------------------------------------------------------------------------
# Variable Definitions for Initialization
# -----------------------------------------------------------------------------
INIT_FILE="/opt/frontend/.initialized"
SERVICE_NAME="securemail-frontend"

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
    
    # Restore NGINX configuration files if missing
    echo "----- Restoring NGINX Main Configuration Files -----"
    if [[ -d "${NGINX_DEFAULTS}" ]]; then
      echo "Found defaults directory: ${NGINX_DEFAULTS}"
      for file in "${NGINX_DEFAULTS}"/*; do
        filename=$(basename "${file}")
        target_file="${NGINX_CONF}/${filename}"
        if [[ ! -e "${target_file}" ]]; then
          echo "Restoring missing file: ${target_file} from ${file}"
          cp -a "${file}" "${target_file}"
        else
          echo "File ${target_file} already exists; skipping restoration."
        fi
      done
    else
      echo "Defaults directory ${NGINX_DEFAULTS} not found. Skipping restoration of configuration files."
    fi

    # Self-signed Certificate Generation
    CERT_DIR='/etc/ssl/securemail'
    CRT_PATH="${CERT_DIR}/securemail.crt"
    KEY_PATH="${CERT_DIR}/securemail.key"

    echo "----- Checking SSL Certificates in ${CERT_DIR} -----"
    if [[ ! -f "${CRT_PATH}" ]] || [[ ! -f "${KEY_PATH}" ]]; then
      echo "No SSL certificate found. Generating new self-signed certificate..."
      mkdir -p "${CERT_DIR}"

      openssl req -x509 -nodes -days 365 \
        -subj "/C=US/ST=YourState/L=YourCity/O=YourCompany/CN=securemail.example.com" \
        -newkey rsa:2048 \
        -keyout "${KEY_PATH}" \
        -out "${CRT_PATH}"

      echo "Self-signed certificate generated:"
      echo "  CRT: ${CRT_PATH}"
      echo "  KEY: ${KEY_PATH}"
    else
      echo "Certificate found, skipping generation."
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

# NPM install dependencies
echo "----- Installing dependencies -----"
pnpm install

# Process files
echo "----- Processing files -----"
if /usr/local/bin/template_processor.sh; then
  echo "----- Template processing completed successfully. -----"
else
  echo "----- Template processing failed. Exiting. -----"
  exit 1
fi

# Validate nginx configuration
echo "----- Validating nginx configuration -----"
if nginx -t; then
  echo "----- Nginx configuration validation passed. -----"
else
  echo "----- Nginx configuration validation failed. Exiting. -----"
  exit 1
fi

# Build frontend application
# NOTE: We build at runtime (not Docker build time) because:
# - Environment variables come from vault at runtime, not during CI/CD
# - Template processor modifies source files with runtime configuration
# - Vue/Vite requires rebuild after source modifications to apply changes
# 
# FUTURE IMPROVEMENT: Consider implementing conditional building:
# - Only rebuild if template processing actually modified source files
# - Use file checksums/timestamps to detect changes
# - Cache builds based on environment variable hash
# This would maintain runtime flexibility while improving startup performance
echo "----- Building frontend application -----"
pnpm run build

# -----------------------------------------------------------------------------
# Copy built files to nginx
# -----------------------------------------------------------------------------
echo "----- Copying built files to nginx -----"
cp -a dist/. "${SECUREMAIL_FRONTEND_HTML_DIR}"/

# -----------------------------------------------------------------------------
# Symlink
# -----------------------------------------------------------------------------
echo "----- Creating symlinks for frontend and html directories -----"

# Create a symlink so that ${SECUREMAIL_FRONTEND_SRC_DIR}/frontend points to ${SECUREMAIL_FRONTEND_DEFAULTS_DIR}
if [ ! -L "${SECUREMAIL_FRONTEND_SRC_DIR}/frontend" ]; then
  ln -sfn "${SECUREMAIL_FRONTEND_DEFAULTS_DIR}/app" "${SECUREMAIL_FRONTEND_SRC_DIR}/frontend"
else
  echo "Symlink ${SECUREMAIL_FRONTEND_SRC_DIR}/frontend already exists"
fi

# Create a symlink so that ${SECUREMAIL_FRONTEND_SRC_DIR}/nginx points to ${SECUREMAIL_FRONTEND_HTML_DIR}
if [ ! -L "${SECUREMAIL_FRONTEND_SRC_DIR}/nginx" ]; then
  ln -sfn "${SECUREMAIL_FRONTEND_HTML_DIR}" "${SECUREMAIL_FRONTEND_SRC_DIR}/nginx"
else
  echo "Symlink ${SECUREMAIL_FRONTEND_SRC_DIR}/nginx already exists"
fi

# -----------------------------------------------------------------------------
# Permissions
# -----------------------------------------------------------------------------
echo "----- Setting permissions for ${SECUREMAIL_FRONTEND_HTML_DIR} -----"
chown -R nginx:nginx "${SECUREMAIL_FRONTEND_HTML_DIR}"

# -----------------------------------------------------------------------------
# Launch Frontend
# -----------------------------------------------------------------------------
echo "----- Launching ${SERVICE_NAME} command:" "$@" "-----"
exec "$@"
