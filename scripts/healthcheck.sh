#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

ok()   { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
fail() { echo -e "${RED}[FAIL]${NC} $1"; }

set -a
source .env.prod 2>/dev/null || true
set +a

EXIT_CODE=0

echo "=== Web Container ==="
if docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T web \
   curl -k -f https://localhost >/dev/null 2>&1; then
  ok "Web is responding"
else
  fail "Web is not responding"
  EXIT_CODE=1
fi
echo ""

echo "=== Database ==="
if docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T database \
   mariadb-admin ping -h localhost -p"${DB_ROOT_PASSWORD:-}" >/dev/null 2>&1; then
  ok "Database is alive"
else
  fail "Database is not responding"
  EXIT_CODE=1
fi
echo ""

echo "=== Redis ==="
if docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T redis redis-cli ping >/dev/null 2>&1; then
  ok "Redis is alive"
else
  fail "Redis is not responding"
  EXIT_CODE=1
fi
echo ""

echo "=== OpenSearch ==="
if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]]; then
  STATUS=$(docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T opensearch \
           curl -s http://localhost:9200/_cluster/health | grep -o '"status":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
  if [[ "$STATUS" == "green" || "$STATUS" == "yellow" ]]; then
    ok "OpenSearch healthy ($STATUS)"
  else
    fail "OpenSearch unhealthy ($STATUS)"
    EXIT_CODE=1
  fi
else
  warn "OpenSearch disabled"
fi
echo ""

echo "=== Worker ==="
if docker compose --env-file .env.prod -f docker-compose.prod.yml ps worker | grep -q "Up"; then
  ok "Worker running"
else
  warn "Worker not running yet"
fi
echo ""

echo "=== Cron ==="
if docker compose --env-file .env.prod -f docker-compose.prod.yml ps cron | grep -q "Up"; then
  ok "Cron running"
else
  warn "Cron not running"
fi
echo ""

echo "=== Summary ==="
if [[ $EXIT_CODE -eq 0 ]]; then
  ok "System healthy"
  exit 0
else
  fail "System unhealthy"
  exit 1
fi