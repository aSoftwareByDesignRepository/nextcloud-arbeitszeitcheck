#!/bin/bash
# Helper script to build a single entry point
# Usage: build-entry.sh <entry-name> <source-file>

set -e

ENTRY_NAME=$1
SOURCE_FILE=$2

if [ -z "$ENTRY_NAME" ] || [ -z "$SOURCE_FILE" ]; then
    echo "Usage: $0 <entry-name> <source-file>"
    exit 1
fi

# Create a temporary webpack config for this single entry
cat > /tmp/webpack-entry.config.js <<EOF
const path = require('path')
const baseConfig = require('./webpack.config.js')

// Override entry to build only this one
baseConfig.entry = {
    '${ENTRY_NAME}': path.join(__dirname, 'src', '${SOURCE_FILE}')
}

module.exports = baseConfig
EOF

# Build with memory limit
NODE_OPTIONS='--max-old-space-size=1536' npx webpack --config /tmp/webpack-entry.config.js --mode=development

# Cleanup
rm -f /tmp/webpack-entry.config.js
