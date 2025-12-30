#!/bin/bash

# Build script for ArbeitszeitCheck app
# This script works in Docker environments

set -e

echo "Building ArbeitszeitCheck app..."

# Check if we're in the right directory
if [ ! -f "package.json" ]; then
    echo "Error: package.json not found. Please run this script from the app directory."
    exit 1
fi

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo "Installing npm dependencies..."
    npm install
fi

# Create output directories
mkdir -p js css

# Build the frontend
echo "Building frontend assets..."
# Use build:dev for faster builds in Docker (less memory intensive)
npm run build:dev || npm run build

# Nextcloud webpack-vue-config should output CSS to css/ directory automatically
# Verify CSS files were created
if [ -d "css" ] && [ "$(ls -A css/*.css 2>/dev/null)" ]; then
    echo "CSS files generated successfully in css/ directory"
else
    echo "Warning: No CSS files found in css/ directory"
    echo "This might be normal if CSS is embedded in JS (development mode)"
fi

# Verify JS files were created
if [ -d "js" ] && [ "$(ls -A js/*.js 2>/dev/null)" ]; then
    echo "JavaScript files generated successfully in js/ directory"
else
    echo "Error: No JavaScript files found in js/ directory"
    exit 1
fi

echo "Build completed successfully!"
echo "Assets are ready in js/ and css/ directories."