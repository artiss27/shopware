#!/bin/bash
# Rollback script for Shopware 6.7 production
# Rolls back to previous version if deployment fails

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Load environment variables
if [[ ! -f .env.prod ]]; then
    log_error ".env.prod file not found!"
    exit 1
fi

set -a
source .env.prod
set +a

log_info "Starting rollback process..."

# Check if docker compose is available
if ! command -v docker compose &> /dev/null; then
    log_error "docker compose not found!"
    exit 1
fi

# Get current image tag
CURRENT_IMAGE="${DOCKER_IMAGE:-ghcr.io/${GITHUB_REPOSITORY:-username/shopware}:latest}"
CURRENT_TAG="${IMAGE_TAG:-latest}"

# Determine previous version
# First try .deployment-previous-tag (from last deployment)
if [[ -f .deployment-previous-tag ]]; then
    PREVIOUS_TAG=$(cat .deployment-previous-tag)
    PREVIOUS_IMAGE="${CURRENT_IMAGE%:*}:${PREVIOUS_TAG}"
    log_info "Found previous version from last deployment: ${PREVIOUS_TAG}"
    
    # Check if image exists locally
    if ! docker image inspect "$PREVIOUS_IMAGE" &> /dev/null; then
        log_warn "Previous image not found locally, trying to pull..."
        docker pull "$PREVIOUS_IMAGE" || {
            log_warn "Failed to pull previous image, trying deployment history..."
            PREVIOUS_IMAGE=""
        }
    fi
fi

# Fallback to deployment history
if [[ -z "${PREVIOUS_IMAGE:-}" ]] && [[ -f .deployment-history ]]; then
    PREVIOUS_TAG=$(tail -n 1 .deployment-history)
    PREVIOUS_IMAGE="${CURRENT_IMAGE%:*}:${PREVIOUS_TAG}"
    log_info "Using previous version from history: ${PREVIOUS_TAG}"
    
    # Try to pull if not found locally
    if ! docker image inspect "$PREVIOUS_IMAGE" &> /dev/null; then
        log_info "Pulling previous image from registry..."
        docker pull "$PREVIOUS_IMAGE" || {
            log_error "Failed to pull previous image from registry"
            exit 1
        }
    fi
fi

if [[ -z "${PREVIOUS_IMAGE:-}" ]]; then
    log_error "No previous version found. Cannot rollback."
    log_info "Available local images:"
    docker images | grep "${CURRENT_IMAGE%:*}" | head -5
    exit 1
fi

# Pull previous image
log_info "Pulling previous image: ${PREVIOUS_IMAGE}"
docker pull "${PREVIOUS_IMAGE}" || {
    log_error "Failed to pull previous image"
    exit 1
}

# Stop current containers
log_info "Stopping current containers..."
docker compose --env-file .env.prod -f docker-compose.prod.yml stop web worker cron || true

# Update .env.prod with previous image
log_info "Updating environment with previous image..."
sed -i.bak "s|DOCKER_IMAGE=.*|DOCKER_IMAGE=${PREVIOUS_IMAGE}|" .env.prod
sed -i.bak "s|IMAGE_TAG=.*|IMAGE_TAG=${PREVIOUS_TAG}|" .env.prod
rm -f .env.prod.bak

# Reload environment
set -a
source .env.prod
set +a

# Start containers with previous image
log_info "Starting containers with previous image..."
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d web
sleep 10

# Wait for health check
log_info "Waiting for health check..."
MAX_WAIT=120
ELAPSED=0
while ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web curl -f http://localhost:80/api/_info/health-check &> /dev/null && \
      ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web curl -f http://localhost/api/_info/health-check &> /dev/null && \
      ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web curl -f http://localhost:8000/api/_info/health-check &> /dev/null; do
    if [ $ELAPSED -ge $MAX_WAIT ]; then
        log_error "Rollback failed - health check timeout"
        exit 1
    fi
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo -n "."
done
echo ""

# Start worker and cron
log_info "Starting worker and cron containers..."
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d worker cron

# Run health check
log_info "Running health check..."
"$SCRIPT_DIR/healthcheck.sh" || {
    log_error "Health check failed after rollback!"
    exit 1
}

log_info "Rollback completed successfully!"
log_info "Current version: ${PREVIOUS_TAG}"
