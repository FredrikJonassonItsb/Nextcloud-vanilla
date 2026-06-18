# Build script for GovPortal (Windows PowerShell)
# This script builds the React application and prepares it for Nextcloud

$ErrorActionPreference = "Stop"

Write-Host "Building GovPortal..." -ForegroundColor Cyan

# Navigate to the project directory
$ProjectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ProjectDir

# Install dependencies
Write-Host "Installing dependencies..." -ForegroundColor Yellow
npm install

# Build the React application
Write-Host "Building React application..." -ForegroundColor Yellow
npm run build

# Create the Nextcloud app structure
Write-Host "Preparing Nextcloud app..." -ForegroundColor Yellow

$AppDir = "nextcloud-app"
$JsDir = "$AppDir\js"

# Ensure JS directory exists
New-Item -ItemType Directory -Force -Path $JsDir | Out-Null

# Copy built files to the Nextcloud app
Write-Host "Copying built files..." -ForegroundColor Yellow

# Copy the main JavaScript bundle (IIFE format)
$JsFile = "dist\govportal.js"
if (Test-Path $JsFile) {
    Copy-Item $JsFile -Destination "$JsDir\govportal-main.js" -Force
    Write-Host "Copied govportal.js" -ForegroundColor Green
} else {
    Write-Host "Warning: govportal.js not found!" -ForegroundColor Red
}

# Copy CSS
$CssFile = "dist\govportal.css"
if (Test-Path $CssFile) {
    # Read base styles and append built CSS
    $BaseCssPath = "$AppDir\css\base.css"
    $BuiltCss = Get-Content $CssFile -Raw

    if (Test-Path $BaseCssPath) {
        $BaseCss = Get-Content $BaseCssPath -Raw
        Set-Content -Path "$AppDir\css\style.css" -Value ($BaseCss + "`n" + $BuiltCss)
    } else {
        Set-Content -Path "$AppDir\css\style.css" -Value $BuiltCss
    }
    Write-Host "Copied govportal.css" -ForegroundColor Green
}

# Create the deployable archive
Write-Host "Creating deployment archive..." -ForegroundColor Yellow
$ArchiveName = "govportal-v1.0.0.zip"

if (Test-Path $ArchiveName) {
    Remove-Item $ArchiveName
}

Compress-Archive -Path "$AppDir\*" -DestinationPath $ArchiveName

Write-Host ""
Write-Host "Build complete!" -ForegroundColor Green
Write-Host ""
Write-Host "Deployment options:" -ForegroundColor Cyan
Write-Host "1. Copy '$AppDir' to your Nextcloud's 'custom_apps' directory"
Write-Host "2. Or extract '$ArchiveName' to 'custom_apps\govportal'"
Write-Host ""
Write-Host "Then enable the app in Nextcloud Admin > Apps"
