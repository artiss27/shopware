#!/bin/bash
# Analyze slow query log from MariaDB
# Usage: ./analyze-slow-queries.sh [days]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

DAYS="${1:-1}"
OUTPUT_DIR="/tmp/slow-query-analysis"

log_info() {
    echo -e "\033[0;32m[INFO]\033[0m $1"
}

log_warn() {
    echo -e "\033[1;33m[WARN]\033[0m $1"
}

log_error() {
    echo -e "\033[0;31m[ERROR]\033[0m $1"
}

# Load environment variables
if [[ -f .env.prod ]]; then
    set -a
    source .env.prod
    set +a
fi

# Check if docker compose is available
if ! command -v docker compose &> /dev/null; then
    log_error "docker compose not found!"
    exit 1
fi

log_info "Analyzing slow queries from last ${DAYS} day(s)..."

# Create output directory
mkdir -p "$OUTPUT_DIR"

# Copy slow query log from container
CONTAINER_NAME="shopware-database-prod"
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    log_error "Database container not found: ${CONTAINER_NAME}"
    exit 1
fi

log_info "Extracting slow query log from container..."
docker cp "${CONTAINER_NAME}:/var/log/mysql/slow-query.log" "${OUTPUT_DIR}/slow-query.log" 2>/dev/null || {
    log_warn "Slow query log not found in container. Checking volume..."
    
    # Try to access from volume
    VOLUME_NAME="shopware_mysql-slow-log"
    if docker volume inspect "$VOLUME_NAME" &> /dev/null; then
        docker run --rm \
            -v "$VOLUME_NAME:/data:ro" \
            -v "$OUTPUT_DIR:/output" \
            alpine sh -c "cp /data/slow-query.log /output/ 2>/dev/null || echo 'Log file not found'" || true
    else
        log_error "Slow query log volume not found"
        exit 1
    fi
}

if [[ ! -f "${OUTPUT_DIR}/slow-query.log" ]] || [[ ! -s "${OUTPUT_DIR}/slow-query.log" ]]; then
    log_warn "Slow query log is empty or not found"
    log_info "Slow query log might not have any entries yet, or log file path is different"
    exit 0
fi

# Analyze using mysqldumpslow if available, otherwise use basic analysis
if command -v mysqldumpslow &> /dev/null; then
    log_info "Analyzing with mysqldumpslow..."
    mysqldumpslow "${OUTPUT_DIR}/slow-query.log" > "${OUTPUT_DIR}/analysis.txt" 2>/dev/null || {
        log_warn "mysqldumpslow not working, using basic analysis"
        # Basic analysis
        echo "=== Slow Query Summary ===" > "${OUTPUT_DIR}/analysis.txt"
        echo "Total slow queries: $(grep -c "^# Time:" "${OUTPUT_DIR}/slow-query.log" || echo "0")" >> "${OUTPUT_DIR}/analysis.txt"
    }
else
    log_info "mysqldumpslow not found, using basic analysis..."
    echo "=== Slow Query Summary ===" > "${OUTPUT_DIR}/analysis.txt"
    echo "Total slow queries: $(grep -c "^# Time:" "${OUTPUT_DIR}/slow-query.log" || echo "0")" >> "${OUTPUT_DIR}/analysis.txt"
    echo "" >> "${OUTPUT_DIR}/analysis.txt"
    echo "Top 10 slowest queries:" >> "${OUTPUT_DIR}/analysis.txt"
    grep -A 10 "^# Time:" "${OUTPUT_DIR}/slow-query.log" | head -100 >> "${OUTPUT_DIR}/analysis.txt" || true
fi

# Count queries by table
log_info "Analyzing queries by table..."
echo "" >> "${OUTPUT_DIR}/analysis.txt"
echo "=== Queries by Table ===" >> "${OUTPUT_DIR}/analysis.txt"
grep -i "FROM\|UPDATE\|INSERT INTO\|DELETE FROM" "${OUTPUT_DIR}/slow-query.log" | \
    sed -E 's/.*(FROM|UPDATE|INSERT INTO|DELETE FROM)[[:space:]]+`?([a-z_]+)`?.*/\2/i' | \
    sort | uniq -c | sort -rn | head -20 >> "${OUTPUT_DIR}/analysis.txt" || true

# Show results
log_info "Analysis complete. Results:"
echo ""
cat "${OUTPUT_DIR}/analysis.txt"
echo ""
log_info "Full log file: ${OUTPUT_DIR}/slow-query.log"
log_info "Analysis report: ${OUTPUT_DIR}/analysis.txt"
