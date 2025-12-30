#!/bin/bash

# Build script that builds webpack entries sequentially to reduce memory usage
# This script builds each entry point one at a time

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}[INFO]${NC} Building ArbeitszeitCheck frontend assets sequentially..."

# Get container name from docker-compose
CONTAINER_NAME=$(docker-compose ps -q nextcloud 2>/dev/null || echo "")

if [ -z "$CONTAINER_NAME" ]; then
    echo -e "${YELLOW}[WARNING]${NC} Nextcloud container not running. Starting environment..."
    docker-compose up -d nextcloud
    sleep 5
    CONTAINER_NAME=$(docker-compose ps -q nextcloud)
fi

if [ -z "$CONTAINER_NAME" ]; then
    echo -e "${RED}[ERROR]${NC} Could not find or start Nextcloud container"
    exit 1
fi

echo -e "${BLUE}[INFO]${NC} Using container: $CONTAINER_NAME"
echo -e "${BLUE}[INFO]${NC} Installing dependencies (if needed)..."
docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && npm install"

# Define entries to build
declare -A ENTRIES=(
    ["arbeitszeitcheck-main"]="main.js"
    ["admin-settings"]="admin.js"
    ["settings"]="settings.js"
    ["compliance-dashboard"]="compliance-dashboard.js"
    ["compliance-violations"]="compliance-violations.js"
    ["compliance-reports"]="compliance-reports.js"
    ["manager-dashboard"]="manager-dashboard.js"
    ["admin-dashboard"]="admin-dashboard.js"
    ["admin-users"]="admin-users.js"
    ["working-time-models"]="working-time-models.js"
    ["audit-log-viewer"]="audit-log-viewer.js"
)

echo -e "${BLUE}[INFO]${NC} Building ${#ENTRIES[@]} entry points sequentially..."

# Build each entry one at a time
FAILED_ENTRIES=()
for ENTRY_NAME in "${!ENTRIES[@]}"; do
    SOURCE_FILE="${ENTRIES[$ENTRY_NAME]}"
    echo -e "${BLUE}[INFO]${NC} Building entry: ${ENTRY_NAME} (${SOURCE_FILE})..."
    
    # Create a temporary webpack config for this single entry
    docker-compose exec -T nextcloud bash -c "cat > /var/www/html/custom_apps/arbeitszeitcheck/webpack-entry-temp.config.js << 'EOF'
const path = require('path')
const baseConfig = require('./webpack.config.js')

// Override entry to build only this one
baseConfig.entry = {
    '${ENTRY_NAME}': path.join(__dirname, 'src', '${SOURCE_FILE}')
}

module.exports = baseConfig
EOF"
    
    # Build with reduced memory
    if docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && NODE_ENV=production NODE_OPTIONS='--max-old-space-size=1536' npx webpack --config webpack-entry-temp.config.js --mode=production --no-stats"; then
        echo -e "${GREEN}[SUCCESS]${NC} Built ${ENTRY_NAME}"
    else
        echo -e "${RED}[ERROR]${NC} Failed to build ${ENTRY_NAME}"
        FAILED_ENTRIES+=("${ENTRY_NAME}")
    fi
    
    # Cleanup temp config
    docker-compose exec -T nextcloud bash -c "rm -f /var/www/html/custom_apps/arbeitszeitcheck/webpack-entry-temp.config.js" || true
done

# Report results
if [ ${#FAILED_ENTRIES[@]} -eq 0 ]; then
    echo -e "${GREEN}[SUCCESS]${NC} All entries built successfully!"
    echo -e "${BLUE}[INFO]${NC} Clearing Nextcloud cache..."
    docker-compose exec -T nextcloud php occ files:scan --all
    echo -e "${GREEN}[SUCCESS]${NC} Build completed successfully!"
    echo -e "${BLUE}[INFO]${NC} You can now access the app at: http://localhost:8081/apps/arbeitszeitcheck/"
    exit 0
else
    echo -e "${RED}[ERROR]${NC} Failed to build ${#FAILED_ENTRIES[@]} entry(ies): ${FAILED_ENTRIES[*]}"
    exit 1
fi
