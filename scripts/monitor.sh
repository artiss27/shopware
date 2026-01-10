#!/bin/bash
# Continuous monitoring script for Shopware 6.7 production
# Checks health and sends alerts on issues

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

# Load environment variables
if [[ -f .env.prod ]]; then
    set -a
    source .env.prod
    set +a
fi

LOG_FILE="${LOG_FILE:-/var/log/shopware-monitor.log}"
ALERT_EMAIL="${MONITORING_EMAIL:-}"

# Thresholds
DISK_THRESHOLD=80
RAM_THRESHOLD=85
QUEUE_THRESHOLD=1000

# Create log file if it doesn't exist
mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

send_alert() {
    local subject="$1"
    local message="$2"
    
    log_message "ALERT: $subject - $message"
    
    if [[ -n "$ALERT_EMAIL" ]] && command -v mail &> /dev/null; then
        echo "$message" | mail -s "Shopware Alert: $subject" "$ALERT_EMAIL" 2>/dev/null || true
    fi
}

# Check if monitoring is enabled
if [[ "${MONITORING_ENABLED:-true}" != "true" ]]; then
    exit 0
fi

log_message "Starting monitoring check..."

# Check container status
if ! docker compose -f docker-compose.prod.yml ps | grep -q "Up"; then
    send_alert "Container Down" "Some containers are not running"
fi

# Check disk usage
DISK_USAGE=$(df -h / | tail -1 | awk '{print $5}' | sed 's/%//')
if [[ $DISK_USAGE -ge $DISK_THRESHOLD ]]; then
    send_alert "Disk Usage High" "Disk usage is at ${DISK_USAGE}% (threshold: ${DISK_THRESHOLD}%)"
fi

# Check RAM usage
RAM_TOTAL=$(free -m | grep Mem | awk '{print $2}')
RAM_USED=$(free -m | grep Mem | awk '{print $3}')
RAM_PERCENT=$((RAM_USED * 100 / RAM_TOTAL))
if [[ $RAM_PERCENT -ge $RAM_THRESHOLD ]]; then
    send_alert "RAM Usage High" "RAM usage is at ${RAM_PERCENT}% (threshold: ${RAM_THRESHOLD}%)"
fi

# Check message queue size
if docker compose -f docker-compose.prod.yml exec -T redis redis-cli ping &> /dev/null 2>&1; then
    QUEUE_SIZE=$(docker compose -f docker-compose.prod.yml exec -T redis redis-cli LLEN messenger_messages 2>/dev/null || echo "0")
    if [[ $QUEUE_SIZE -ge $QUEUE_THRESHOLD ]]; then
        send_alert "Queue Size High" "Message queue has ${QUEUE_SIZE} messages (threshold: ${QUEUE_THRESHOLD})"
    fi
fi

# Check web container health
if ! docker compose -f docker-compose.prod.yml exec -T web curl -f http://localhost:8000/api/_info/health-check &> /dev/null 2>&1; then
    send_alert "Web Container Unhealthy" "Web container health check failed"
fi

# Check database connectivity
if ! docker compose -f docker-compose.prod.yml exec -T database mariadb-admin ping -h localhost -p"${DB_ROOT_PASSWORD:-}" &> /dev/null 2>&1; then
    send_alert "Database Unreachable" "Database is not responding"
fi

# Check OpenSearch health (if enabled)
if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]]; then
    OPENSEARCH_STATUS=$(docker compose -f docker-compose.prod.yml exec -T opensearch curl -s http://localhost:9200/_cluster/health 2>/dev/null | grep -o '"status":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
    if [[ "$OPENSEARCH_STATUS" == "red" ]]; then
        send_alert "OpenSearch Critical" "OpenSearch cluster status is RED"
    fi
fi

# Cleanup old logs (keep last 1000 lines)
if [[ -f "$LOG_FILE" ]]; then
    tail -n 1000 "$LOG_FILE" > "${LOG_FILE}.tmp" && mv "${LOG_FILE}.tmp" "$LOG_FILE"
fi

log_message "Monitoring check completed"
