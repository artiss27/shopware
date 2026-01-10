#!/bin/bash
# Production deployment script for Shopware 6.7
# This script is executed on the production server via SSH from GitHub Actions

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

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

log_info "Starting deployment..."

# Check if docker compose is available
if ! command -v docker compose &> /dev/null; then
    log_error "docker compose not found!"
    exit 1
fi

# Tag current running image as previous (for rollback)
if docker compose --env-file .env.prod -f docker-compose.prod.yml ps | grep -q "shopware-web-prod"; then
    CURRENT_IMAGE=$(docker compose --env-file .env.prod -f docker-compose.prod.yml config | grep -A 5 "web:" | grep "image:" | awk '{print $2}' | tr -d '"' || echo "")
    if [[ -n "$CURRENT_IMAGE" ]]; then
        PREVIOUS_TAG="previous-$(date +%Y%m%d-%H%M%S)"
        log_info "Tagging current image as previous: $PREVIOUS_TAG"
        docker tag "$CURRENT_IMAGE" "${CURRENT_IMAGE%:*}:$PREVIOUS_TAG" || log_warn "Failed to tag current image"
        echo "$PREVIOUS_TAG" > .deployment-previous-tag || true
    fi
fi

# Pull latest image
log_info "Pulling latest Docker image..."
docker compose --env-file .env.prod -f docker-compose.prod.yml pull web || {
    log_error "Failed to pull image"
    exit 1
}

# Create backup before deployment (if not first deployment)
if docker compose --env-file .env.prod -f docker-compose.prod.yml ps | grep -q "shopware-web-prod"; then
    log_info "Creating backup before deployment..."
    "$SCRIPT_DIR/backup-automated.sh" pre-deploy || log_warn "Backup failed, but continuing deployment"
fi

# Stop old containers gracefully
log_info "Stopping old containers..."
docker compose --env-file .env.prod -f docker-compose.prod.yml stop web worker cron || true

# Start new containers
log_info "Starting new containers..."
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d database opensearch redis
sleep 5
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d web
sleep 10

# Wait for web container to be healthy
log_info "Waiting for web container to be healthy..."
MAX_WAIT=120
ELAPSED=0
# Check health on port 80 (Caddy) or 8000 (fallback)
while ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web curl -f http://localhost:80/api/_info/health-check &> /dev/null && \
      ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web curl -f http://localhost/api/_info/health-check &> /dev/null && \
      ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web curl -f http://localhost:8000/api/_info/health-check &> /dev/null; do
    if [ $ELAPSED -ge $MAX_WAIT ]; then
        log_error "Web container failed to become healthy within ${MAX_WAIT}s"
        docker compose --env-file .env.prod -f docker-compose.prod.yml logs web --tail=50
        exit 1
    fi
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo -n "."
done
echo ""

# Run database migrations
log_info "Running database migrations..."
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web php bin/console database:migrate --all -vv || {
    log_error "Migrations failed!"
    exit 1
}

# Clear cache
log_info "Clearing cache..."
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web php bin/console cache:clear --env=prod || {
    log_error "Cache clear failed!"
    exit 1
}

# Warmup cache
log_info "Warming up cache..."
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web php bin/console cache:warmup --env=prod || {
    log_warn "Cache warmup had issues, but continuing..."
}

# Compile themes
log_info "Compiling themes..."
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web php bin/console theme:compile --active-only || {
    log_error "Theme compilation failed!"
    exit 1
}

# Start worker and cron containers
log_info "Starting worker and cron containers..."
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d worker cron

# Run OpenSearch indexing if enabled
if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]]; then
    log_info "Running OpenSearch indexing..."
    docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web php bin/console dal:refresh:index || {
        log_warn "OpenSearch indexing had issues, but continuing..."
    }
fi

# Save deployment history
CURRENT_TAG="${IMAGE_TAG:-$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')}"
echo "$CURRENT_TAG" >> .deployment-history
tail -n 5 .deployment-history > .deployment-history.tmp && mv .deployment-history.tmp .deployment-history

# Final health check
log_info "Running final health check..."
if ! "$SCRIPT_DIR/healthcheck.sh"; then
    log_error "Health check failed! Initiating rollback..."
    if [[ -f "$SCRIPT_DIR/rollback.sh" ]]; then
        "$SCRIPT_DIR/rollback.sh" || log_error "Rollback failed! Manual intervention required."
    else
        log_error "Rollback script not found. Manual rollback required."
    fi
    exit 1
fi

log_info "Deployment completed successfully!"
docker compose --env-file .env.prod -f docker-compose.prod.yml ps
