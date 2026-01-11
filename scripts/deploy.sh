#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()   { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

if [[ ! -f .env.prod ]]; then
  error ".env.prod not found"
  exit 1
fi

set -a
source .env.prod
set +a

log "Starting deployment"

log "Pulling images"
docker compose --env-file .env.prod -f docker-compose.prod.yml pull web worker cron

log "Starting infrastructure"
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d database redis opensearch
sleep 5

log "Starting web"
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d web

log "Waiting for web (Caddy + PHP only)"
MAX_WAIT=180
ELAPSED=0
while ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
        curl -k -f https://localhost >/dev/null 2>&1; do
  if [ $ELAPSED -ge $MAX_WAIT ]; then
    error "Web not reachable"
    docker compose --env-file .env.prod -f docker-compose.prod.yml logs web --tail=100
    exit 1
  fi
  sleep 5
  ELAPSED=$((ELAPSED + 5))
  echo -n "."
done
echo ""

log "Fixing filesystem permissions"
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
  chown -R 1000:1000 /var/www/html/var /var/www/html/public || true

log "Checking if Shopware is installed"
if ! docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T database \
     mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     -e "SHOW TABLES LIKE 'system_config'" | grep -q system_config; then

  log "Shopware not installed, running initial install"
  docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
    php bin/console system:install --create-database --basic-setup --force
else
  log "Shopware already installed"
fi

log "Running database migrations"
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
  php bin/console database:migrate --all

log "Clearing cache"
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
  php bin/console cache:clear

log "Warming cache"
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
  php bin/console cache:warmup || warn "Cache warmup had issues"

log "Compiling themes"
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
  php bin/console theme:compile --active-only

log "Starting workers and cron"
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d worker cron
sleep 10

if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]]; then
  log "Refreshing OpenSearch"
  docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
    php bin/console dal:refresh:index || warn "Indexing failed"
fi

log "Running health check"
"$SCRIPT_DIR/healthcheck.sh"

log "Deployment finished"
docker compose --env-file .env.prod -f docker-compose.prod.yml ps