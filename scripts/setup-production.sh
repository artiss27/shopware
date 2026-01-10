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
# First check if Caddy is running (port 8000 is accessible)
log_info "Waiting for web container to become ready..."
log_info "Step 1: Checking if Caddy is running..."
ELAPSED=0
CADDY_READY=false
while [ $ELAPSED -lt 60 ]; do
    if docker compose -f docker-compose.prod.yml exec -T web curl -f http://localhost:8000 &> /dev/null 2>&1; then
        CADDY_READY=true
        log_info "Caddy is running"
        break
    fi
    sleep 5
    ELAPSED=$((ELAPSED + 5))
    echo -n "."
done
echo ""

if [ "$CADDY_READY" = false ]; then
    log_error "Caddy failed to start within 60s"
    log_error "Container status:"
    docker compose -f docker-compose.prod.yml ps web
    log_error "Container logs (last 100 lines):"
    docker compose -f docker-compose.prod.yml logs web --tail=100
    log_error "Checking if Caddy process is running:"
    docker compose -f docker-compose.prod.yml exec -T web ps aux 2>/dev/null | grep -i caddy || echo "Caddy process not found"
    log_error "Checking if entrypoint script executed:"
    docker compose -f docker-compose.prod.yml exec -T web ls -la /tmp/Caddyfile.proc 2>/dev/null || echo "Processed Caddyfile not found"
    log_error "Checking processed Caddyfile (first 50 lines):"
    docker compose -f docker-compose.prod.yml exec -T web head -50 /tmp/Caddyfile.proc 2>/dev/null || echo "Cannot read processed Caddyfile"
    log_error "Checking Caddy validation:"
    docker compose -f docker-compose.prod.yml exec -T web caddy validate --config /tmp/Caddyfile.proc 2>&1 || echo "Caddy validation failed or caddy not found"
    log_error "Checking environment variables:"
    docker compose -f docker-compose.prod.yml exec -T web env 2>/dev/null | grep -E "CADDY_|APP_" | head -20 || echo "Cannot access container"
    log_error ""
    log_error "TROUBLESHOOTING:"
    log_error "1. If CADDY_HOST is set to a domain (e.g., stage.artiss.ua), Caddy will try to get SSL certificate"
    log_error "2. If domain DNS is not configured yet, Caddy will fail"
    log_error "3. For first setup, you can use: CADDY_HOST=localhost:8000"
    log_error "4. After DNS is configured, change CADDY_HOST to your domain"
    exit 1
fi

# Now check if health check endpoint is available (Shopware may not be installed yet)
# On first run, Shopware is not installed, so health check may return 404
# We'll just verify that Caddy is responding (even with 404/500 is ok)
log_info "Step 2: Verifying Caddy is responding..."
sleep 5
if docker compose -f docker-compose.prod.yml exec -T web curl -f http://localhost:8000 &> /dev/null 2>&1 || \
   docker compose -f docker-compose.prod.yml exec -T web curl -s -o /dev/null -w "%{http_code}" http://localhost:8000 | grep -qE "[2345].."; then
    log_info "Caddy is responding (application may not be installed yet, that's ok)"
else
    log_error "Caddy is not responding properly"
    log_error "Container status:"
    docker compose -f docker-compose.prod.yml ps web
    log_error "Container logs (last 100 lines):"
    docker compose -f docker-compose.prod.yml logs web --tail=100
    log_error "Checking if Caddy is running:"
    docker compose -f docker-compose.prod.yml exec -T web ps aux 2>/dev/null | grep -i caddy || echo "Caddy process not found or container not accessible"
    log_error "Checking if PHP-FPM is running:"
    docker compose -f docker-compose.prod.yml exec -T web ps aux 2>/dev/null | grep -i php-fpm || echo "PHP-FPM process not found or container not accessible"
    log_error "Checking processed Caddyfile:"
    docker compose -f docker-compose.prod.yml exec -T web cat /tmp/Caddyfile.proc 2>/dev/null || echo "Processed Caddyfile not found or container not accessible"
    log_error "Checking environment variables:"
    docker compose -f docker-compose.prod.yml exec -T web env 2>/dev/null | grep -E "CADDY_|APP_" | head -20 || echo "Cannot access container"
    exit 1
fi

log_info "Web container is ready (Caddy is running)"

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
