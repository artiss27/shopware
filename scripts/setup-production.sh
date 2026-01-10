#!/bin/bash
# Initial production setup script for Shopware 6.7
# Run this script once after first deployment

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

# Check if .env.prod exists
if [[ ! -f .env.prod ]]; then
    log_error ".env.prod file not found!"
    log_info "Copy env.prod.template to .env.prod and fill in the values"
    exit 1
fi

# Load environment variables
set -a
source .env.prod
set +a

log_info "Starting production setup..."

# Check if docker compose is available
if ! command -v docker compose &> /dev/null; then
    log_error "docker compose not found!"
    exit 1
fi

# Create Docker volumes
log_info "Creating Docker volumes..."
docker volume create shopware_db-data-prod || true
docker volume create shopware_opensearch-data-prod || true
docker volume create shopware_redis-data-prod || true
docker volume create shopware_media-data-prod || true
docker volume create shopware_thumbnail-data-prod || true
docker volume create shopware_log-data-prod || true
docker volume create shopware_caddy-data-prod || true
docker volume create shopware_caddy-config-prod || true
log_info "Volumes created"

# Start infrastructure services first
log_info "Starting infrastructure services..."
docker compose -f docker-compose.prod.yml up -d database opensearch redis

# Wait for services to be ready
log_info "Waiting for services to be ready..."
sleep 10

# Check database
MAX_WAIT=120
ELAPSED=0
while ! docker compose -f docker-compose.prod.yml exec -T database mariadb-admin ping -h localhost -p"${DB_ROOT_PASSWORD}" &> /dev/null; do
    if [ $ELAPSED -ge $MAX_WAIT ]; then
        log_error "Database failed to become ready within ${MAX_WAIT}s"
        exit 1
    fi
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo -n "."
done
echo ""
log_info "Database is ready"

# Wait for OpenSearch
ELAPSED=0
while ! docker compose -f docker-compose.prod.yml exec -T opensearch curl -f http://localhost:9200/_cluster/health &> /dev/null; do
    if [ $ELAPSED -ge $MAX_WAIT ]; then
        log_error "OpenSearch failed to become ready within ${MAX_WAIT}s"
        exit 1
    fi
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo -n "."
done
echo ""
log_info "OpenSearch is ready"

# Start web container
log_info "Starting web container..."
docker compose -f docker-compose.prod.yml up -d web
sleep 15

# Wait for web container to be healthy
ELAPSED=0
while ! docker compose -f docker-compose.prod.yml exec -T web curl -f http://localhost:8000/api/_info/health-check &> /dev/null; do
    if [ $ELAPSED -ge $MAX_WAIT ]; then
        log_error "Web container failed to become ready within ${MAX_WAIT}s"
        docker compose -f docker-compose.prod.yml logs web --tail=50
        exit 1
    fi
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo -n "."
done
echo ""
log_info "Web container is ready"

# Run Shopware installation
log_info "Running Shopware installation..."
read -p "Have you already installed Shopware? (y/n) " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    log_info "Please run the installation command manually:"
    log_info "docker compose -f docker-compose.prod.yml exec web php bin/console system:install --basic-setup"
    log_warn "After installation, continue with plugin activation"
else
    log_info "Skipping installation (already installed)"
fi

# Activate plugins
log_info "Activating plugins..."
docker compose -f docker-compose.prod.yml exec -T web php bin/console plugin:refresh
docker compose -f docker-compose.prod.yml exec -T web php bin/console plugin:install --activate ArtissSupplier || log_warn "ArtissSupplier plugin not found"
docker compose -f docker-compose.prod.yml exec -T web php bin/console plugin:install --activate ArtissTools || log_warn "ArtissTools plugin not found"
docker compose -f docker-compose.prod.yml exec -T web php bin/console plugin:install --activate ArtissTheme || log_warn "ArtissTheme plugin not found"
docker compose -f docker-compose.prod.yml exec -T web php bin/console plugin:install --activate ArtissStorefront || log_warn "ArtissStorefront plugin not found"

# Run migrations
log_info "Running database migrations..."
docker compose -f docker-compose.prod.yml exec -T web php bin/console database:migrate --all || {
    log_error "Migrations failed!"
    exit 1
}

# Build assets
log_info "Building administration assets..."
docker compose -f docker-compose.prod.yml exec -T web php bin/console assets:install
docker compose -f docker-compose.prod.yml exec -T web php bin/console bundle:dump

log_info "Building storefront assets..."
docker compose -f docker-compose.prod.yml exec -T web php bin/console theme:compile --active-only

# Clear and warmup cache
log_info "Clearing and warming up cache..."
docker compose -f docker-compose.prod.yml exec -T web php bin/console cache:clear
docker compose -f docker-compose.prod.yml exec -T web php bin/console cache:warmup

# Index OpenSearch if enabled
if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]]; then
    log_info "Indexing OpenSearch..."
    docker compose -f docker-compose.prod.yml exec -T web php bin/console dal:refresh:index || {
        log_warn "OpenSearch indexing had issues, but continuing..."
    }
fi

# Start worker and cron containers
log_info "Starting worker and cron containers..."
docker compose -f docker-compose.prod.yml up -d worker cron

# Run health check
log_info "Running health check..."
"$SCRIPT_DIR/healthcheck.sh" || {
    log_warn "Health check had some issues, but setup completed"
}

log_info "Production setup completed!"
log_info "Next steps:"
log_info "1. Configure your domain DNS to point to this server"
log_info "2. Set up Caddy configuration for Let's Encrypt (if using HTTPS)"
log_info "3. Configure automated backups in crontab"
log_info "4. Set up monitoring (if enabled)"
