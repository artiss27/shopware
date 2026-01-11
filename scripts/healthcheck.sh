#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/.."

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

ok()   { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
fail() { echo -e "${RED}[FAIL]${NC} $1"; }

source .env.prod || true

EXIT=0

echo "=== Core Services ==="

# DB
if docker compose -f docker-compose.prod.yml exec -T database mariadb-admin ping &>/dev/null; then
  ok "Database reachable"
else
  fail "Database down"
  EXIT=1
fi

# Redis
if docker compose -f docker-compose.prod.yml exec -T redis redis-cli ping | grep -q PONG; then
  ok "Redis reachable"
else
  fail "Redis down"
  EXIT=1
fi

# OpenSearch
if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]]; then
  STATUS=$(docker compose -f docker-compose.prod.yml exec -T opensearch curl -s http://localhost:9200/_cluster/health | jq -r .status 2>/dev/null || echo red)
  if [[ "$STATUS" == "green" || "$STATUS" == "yellow" ]]; then
    ok "OpenSearch $STATUS"
  else
    fail "OpenSearch unhealthy ($STATUS)"
    EXIT=1
  fi
fi

# Web (Shopware)
if docker compose -f docker-compose.prod.yml exec -T web curl -sf http://localhost/api/_info/health-check >/dev/null; then
  ok "Shopware responding"
else
  fail "Shopware not responding"
  EXIT=1
fi

echo ""
echo "=== Background (non-blocking) ==="

# Worker
if docker compose -f docker-compose.prod.yml ps worker | grep -q Up; then
  ok "Worker running"
else
  warn "Worker not running"
fi

# Cron
if docker compose -f docker-compose.prod.yml ps cron | grep -q Up; then
  ok "Cron running"
else
  warn "Cron not running"
fi

echo ""
if [[ $EXIT -eq 0 ]]; then
  ok "System is READY"
else
  fail "System is NOT READY"
fi

exit $EXIT