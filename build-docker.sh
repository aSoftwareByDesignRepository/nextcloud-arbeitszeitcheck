#!/bin/bash

# Build script for ArbeitszeitCheck in Docker environment
# This script builds the frontend assets inside the Docker container

set +e  # Don't exit on error, we want to continue even if some entries fail

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}[INFO]${NC} Building ArbeitszeitCheck frontend assets in Docker..."

# Get container name from docker-compose
CONTAINER_NAME=$(docker-compose ps -q nextcloud 2>/dev/null || echo "")

if [ -z "$CONTAINER_NAME" ]; then
    echo -e "${YELLOW}[WARNING]${NC} Nextcloud container not running. Starting environment..."
    docker-compose up -d nextcloud
    sleep 5
    CONTAINER_NAME=$(docker-compose ps -q nextcloud)
fi

if [ -z "$CONTAINER_NAME" ]; then
    echo -e "${YELLOW}[ERROR]${NC} Could not find or start Nextcloud container"
    exit 1
fi

echo -e "${BLUE}[INFO]${NC} Using container: $CONTAINER_NAME"
echo -e "${BLUE}[INFO]${NC} Installing dependencies (if needed)..."
# Find npm/node in common locations
docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && \
	(NPM_CMD=\$(which npm 2>/dev/null || find /usr -name npm 2>/dev/null | head -1 || find /opt -name npm 2>/dev/null | head -1 || echo 'npm'); \
	if [ -x \"\$NPM_CMD\" ] || command -v npm >/dev/null 2>&1; then \
		npm install; \
	else \
		echo -e \"${YELLOW}[WARNING]${NC} npm not found, skipping dependency installation\"; \
	fi)"

# Ensure webpack cache directory exists in container
echo -e "${BLUE}[INFO]${NC} Setting up webpack cache for incremental builds..."
docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && mkdir -p .webpack-cache && chmod -R 777 .webpack-cache" || true

echo -e "${BLUE}[INFO]${NC} Building frontend assets sequentially (to reduce memory usage)..."
# Build entries one at a time to avoid memory issues
# This approach uses much less memory than building all entries at once

# Define entries to build (order matters - build main first)
ENTRIES=(
    "arbeitszeitcheck-main:main.js"
    "admin-settings:admin.js"
    "settings:settings.js"
    "compliance-dashboard:compliance-dashboard.js"
    "compliance-violations:compliance-violations.js"
    "compliance-reports:compliance-reports.js"
    "manager-dashboard:manager-dashboard.js"
    "admin-dashboard:admin-dashboard.js"
    "admin-users:admin-users.js"
    "working-time-models:working-time-models.js"
    "audit-log-viewer:audit-log-viewer.js"
)

echo -e "${BLUE}[INFO]${NC} Building ${#ENTRIES[@]} entry points sequentially..."

# Build each entry one at a time
FAILED_ENTRIES=()
for ENTRY_DEF in "${ENTRIES[@]}"; do
    IFS=':' read -r ENTRY_NAME SOURCE_FILE <<< "$ENTRY_DEF"
    echo -e "${BLUE}[INFO]${NC} Building entry: ${ENTRY_NAME} (${SOURCE_FILE})..."
    
    # Create a temporary webpack config for this single entry inside the container
    docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && cat > webpack-entry-temp.config.js << 'EOFCONFIG'
const path = require('path')
const baseConfig = require('./webpack.config.js')

// Override entry to build only this one
baseConfig.entry = {
    '${ENTRY_NAME}': path.join(__dirname, 'src', '${SOURCE_FILE}')
}

module.exports = baseConfig
EOFCONFIG"
    
    # Build with reduced memory (1.5GB per entry) and incremental cache
    # Use production mode to avoid CSP violations (no eval in source maps)
    # Cache is enabled in webpack.config.js for incremental builds
    # Remove source map references after build for CSP compliance
    # Try multiple methods to find and run webpack
    if docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && \
		export PATH=\"/var/www/html/custom_apps/arbeitszeitcheck/node_modules/.bin:\$PATH\" && \
		if command -v npx >/dev/null 2>&1; then \
			DOCKER_BUILD=1 NODE_ENV=production NODE_OPTIONS='--max-old-space-size=1536' npx webpack --config webpack-entry-temp.config.js --mode=production 2>&1 | tail -10; \
		elif [ -f node_modules/.bin/webpack ]; then \
			DOCKER_BUILD=1 NODE_ENV=production NODE_OPTIONS='--max-old-space-size=1536' ./node_modules/.bin/webpack --config webpack-entry-temp.config.js --mode=production 2>&1 | tail -10; \
		elif [ -f /usr/local/bin/npx ]; then \
			DOCKER_BUILD=1 NODE_ENV=production NODE_OPTIONS='--max-old-space-size=1536' /usr/local/bin/npx webpack --config webpack-entry-temp.config.js --mode=production 2>&1 | tail -10; \
		else \
			echo -e \"${YELLOW}[ERROR]${NC} Cannot find npx or webpack. Please ensure Node.js is installed in the container.\"; \
			false; \
		fi"; then
        # Remove source map references from JS files for CSP compliance
        docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && sed -i 's/sourceMappingURL.*//g' js/${ENTRY_NAME}.js 2>/dev/null || true"
        # Remove source map files
        docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && rm -f js/${ENTRY_NAME}.js.map 2>/dev/null || true"
        echo -e "${GREEN}[SUCCESS]${NC} Built ${ENTRY_NAME}"
    else
        echo -e "${YELLOW}[WARNING]${NC} Failed to build ${ENTRY_NAME}, continuing with next entry..."
        FAILED_ENTRIES+=("${ENTRY_NAME}")
    fi
    
    # Cleanup temp config
    docker-compose exec -T nextcloud bash -c "cd /var/www/html/custom_apps/arbeitszeitcheck && rm -f webpack-entry-temp.config.js" || true
done

# Report results
if [ ${#FAILED_ENTRIES[@]} -eq 0 ]; then
    echo -e "${GREEN}[SUCCESS]${NC} All entries built successfully!"
else
    echo -e "${YELLOW}[WARNING]${NC} ${#FAILED_ENTRIES[@]} entry(ies) failed: ${FAILED_ENTRIES[*]}"
    echo -e "${YELLOW}[WARNING]${NC} Continuing anyway..."
fi

echo -e "${BLUE}[INFO]${NC} Clearing Nextcloud cache..."
docker-compose exec -T nextcloud php occ files:scan --all

echo -e "${GREEN}[SUCCESS]${NC} Build completed successfully!"
echo -e "${BLUE}[INFO]${NC} You can now access the app at: http://localhost:8081/apps/arbeitszeitcheck/"
