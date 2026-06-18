#!/bin/bash

# Build script for GovPortal
# This script builds the React application and prepares it for Nextcloud

set -e

echo "Building GovPortal..."

# Navigate to the project directory
cd "$(dirname "$0")"

# Install dependencies
echo "Installing dependencies..."
npm install

# Build the React application
echo "Building React application..."
npm run build

# Create the Nextcloud app structure
echo "Preparing Nextcloud app..."

APP_DIR="nextcloud-app"
JS_DIR="$APP_DIR/js"

# Ensure JS directory exists
mkdir -p "$JS_DIR"

# Copy built files to the Nextcloud app
echo "Copying built files..."

# Copy the main JavaScript bundle
if [ -f "dist/assets/index-*.js" ]; then
    cp dist/assets/index-*.js "$JS_DIR/govportal-main.js"
fi

# If there are vendor chunks, concatenate them
if [ -f "dist/assets/vendor-*.js" ]; then
    cat dist/assets/vendor-*.js dist/assets/index-*.js > "$JS_DIR/govportal-main.js"
fi

# Copy CSS if separate
if [ -f "dist/assets/index-*.css" ]; then
    cat "$APP_DIR/css/style.css" dist/assets/index-*.css > "$APP_DIR/css/style.css.tmp"
    mv "$APP_DIR/css/style.css.tmp" "$APP_DIR/css/style.css"
fi

# Create the deployable archive
echo "Creating deployment archive..."
ARCHIVE_NAME="govportal-v1.0.0.tar.gz"
tar -czf "$ARCHIVE_NAME" -C "$APP_DIR" .

echo ""
echo "Build complete!"
echo ""
echo "Deployment options:"
echo "1. Copy '$APP_DIR' to your Nextcloud's 'custom_apps' directory"
echo "2. Or extract '$ARCHIVE_NAME' to 'custom_apps/govportal'"
echo ""
echo "Then enable the app in Nextcloud Admin > Apps"
