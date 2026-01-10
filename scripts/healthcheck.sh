#!/bin/bash
# Healthcheck script for Shopware 6.7 production
# Checks all services and reports health status

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
    echo -e "${GREEN}[OK]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[FAIL]${NC} $1"
}

# Load environment variables
if [[ -f .env.prod ]]; then
    set -a
    source .env.prod
    set +a
fi

EXIT_CODE=0

# Check docker compose
if ! command -v docker compose &> /dev/null; then
    log_error "docker compose not found"
    exit 1
fi

echo "Running health checks..."
echo ""

# 1. Check container status
echo "=== Container Status ==="
if docker compose -f docker-compose.prod.yml ps | grep -q "Up"; then
    docker compose -f docker-compose.prod.yml ps
    log_info "All containers are running"
else
    log_error "Some containers are not running"
    EXIT_CODE=1
fi
echo ""

# 2. Check database connectivity
echo "=== Database Health ==="
if docker compose -f docker-compose.prod.yml exec -T database mariadb-admin ping -h localhost -p"${DB_ROOT_PASSWORD:-}" &> /dev/null; then
    DB_CONNECTIONS=$(docker compose -f docker-compose.prod.yml exec -T database mariadb -u root -p"${DB_ROOT_PASSWORD:-}" -e "SHOW STATUS LIKE 'Threads_connected';" 2>/dev/null | tail -1 | awk '{print $2}' || echo "0")
    log_info "Database is healthy (connections: ${DB_CONNECTIONS})"
else
    log_error "Database is not responding"
    EXIT_CODE=1
fi
echo ""

# 3. Check OpenSearch health
echo "=== OpenSearch Health ==="
if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]]; then
    OPENSEARCH_HEALTH=$(docker compose -f docker-compose.prod.yml exec -T opensearch curl -s http://localhost:9200/_cluster/health 2>/dev/null | grep -o '"status":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
    if [[ "$OPENSEARCH_HEALTH" == "green" ]] || [[ "$OPENSEARCH_HEALTH" == "yellow" ]]; then
        log_info "OpenSearch is healthy (status: ${OPENSEARCH_HEALTH})"
    else
        log_error "OpenSearch is unhealthy (status: ${OPENSEARCH_HEALTH})"
        EXIT_CODE=1
    fi
else
    log_warn "OpenSearch is disabled"
fi
echo ""

# 4. Check Redis connectivity
echo "=== Redis Health ==="
if docker compose -f docker-compose.prod.yml exec -T redis redis-cli ping &> /dev/null; then
    REDIS_MEMORY=$(docker compose -f docker-compose.prod.yml exec -T redis redis-cli INFO memory 2>/dev/null | grep used_memory_human | cut -d: -f2 | tr -d '\r' || echo "unknown")
    log_info "Redis is healthy (memory: ${REDIS_MEMORY})"
else
    log_error "Redis is not responding"
    EXIT_CODE=1
fi
echo ""

# 5. Check web container health
echo "=== Web Container Health ==="
if docker compose -f docker-compose.prod.yml exec -T web curl -f http://localhost:8000/api/_info/health-check &> /dev/null; then
    log_info "Web container basic health check: OK"
    
    # Detailed health check
    DETAILED_HEALTH=$(docker compose -f docker-compose.prod.yml exec -T web curl -s http://localhost:8000/api/_info/health-check/detailed 2>/dev/null || echo "")
    if [[ -n "$DETAILED_HEALTH" ]]; then
        STATUS=$(echo "$DETAILED_HEALTH" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "unknown")
        if [[ "$STATUS" == "ok" ]]; then
            log_info "Web container detailed health check: OK"
        else
            log_warn "Web container detailed health check: $STATUS"
            echo "$DETAILED_HEALTH" | jq '.' 2>/dev/null || echo "$DETAILED_HEALTH"
        fi
    fi
else
    log_error "Web container health check failed"
    EXIT_CODE=1
fi
echo ""

# 6. Check disk usage
echo "=== Disk Usage ==="
DISK_USAGE=$(df -h / | tail -1 | awk '{print $5}' | sed 's/%//')
if [[ $DISK_USAGE -lt 80 ]]; then
    log_info "Disk usage is healthy (${DISK_USAGE}%)"
elif [[ $DISK_USAGE -lt 90 ]]; then
    log_warn "Disk usage is high (${DISK_USAGE}%)"
else
    log_error "Disk usage is critical (${DISK_USAGE}%)"
    EXIT_CODE=1
fi
echo ""

# 7. Check RAM usage
echo "=== RAM Usage ==="
RAM_TOTAL=$(free -m | grep Mem | awk '{print $2}')
RAM_USED=$(free -m | grep Mem | awk '{print $3}')
RAM_PERCENT=$((RAM_USED * 100 / RAM_TOTAL))
if [[ $RAM_PERCENT -lt 85 ]]; then
    log_info "RAM usage is healthy (${RAM_PERCENT}%)"
elif [[ $RAM_PERCENT -lt 95 ]]; then
    log_warn "RAM usage is high (${RAM_PERCENT}%)"
else
    log_error "RAM usage is critical (${RAM_PERCENT}%)"
    EXIT_CODE=1
fi
echo ""

# 8. Check message queue size (if Redis is available)
echo "=== Message Queue ==="
if docker compose -f docker-compose.prod.yml exec -T redis redis-cli ping &> /dev/null; then
    QUEUE_SIZE=$(docker compose -f docker-compose.prod.yml exec -T redis redis-cli LLEN messenger_messages 2>/dev/null || echo "0")
    if [[ $QUEUE_SIZE -lt 1000 ]]; then
        log_info "Queue size is healthy (${QUEUE_SIZE} messages)"
    elif [[ $QUEUE_SIZE -lt 5000 ]]; then
        log_warn "Queue size is high (${QUEUE_SIZE} messages)"
    else
        log_error "Queue size is critical (${QUEUE_SIZE} messages)"
        EXIT_CODE=1
    fi
else
    log_warn "Cannot check queue size (Redis unavailable)"
fi
echo ""

# 9. Check worker container
echo "=== Worker Container ==="
if docker compose -f docker-compose.prod.yml ps worker | grep -q "Up"; then
    log_info "Worker container is running"
else
    log_error "Worker container is not running"
    EXIT_CODE=1
fi
echo ""

# 10. Check cron container
echo "=== Cron Container ==="
if docker compose -f docker-compose.prod.yml ps cron | grep -q "Up"; then
    log_info "Cron container is running"
else
    log_error "Cron container is not running"
    EXIT_CODE=1
fi
echo ""

# Summary
echo "=== Summary ==="
if [[ $EXIT_CODE -eq 0 ]]; then
    log_info "All health checks passed"
    exit 0
else
    log_error "Some health checks failed"
    exit 1
fi
