#!/bin/bash
# Backup verification script for Shopware 6.7 production
# Verifies backup integrity and tests restore

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
if [[ -f .env.prod ]]; then
    set -a
    source .env.prod
    set +a
fi

BACKUP_FILE="${1:-}"
VERIFY_RESTORE="${2:-false}"

if [[ -z "$BACKUP_FILE" ]]; then
    log_error "Usage: $0 <backup-file.sql.gz> [verify-restore]"
    exit 1
fi

if [[ ! -f "$BACKUP_FILE" ]]; then
    log_error "Backup file not found: $BACKUP_FILE"
    exit 1
fi

log_info "Verifying backup: $BACKUP_FILE"

# Check if file is compressed
if [[ "$BACKUP_FILE" == *.gz ]]; then
    log_info "Decompressing backup for verification..."
    gunzip -c "$BACKUP_FILE" > "${BACKUP_FILE%.gz}.tmp" || {
        log_error "Failed to decompress backup"
        exit 1
    }
    SQL_FILE="${BACKUP_FILE%.gz}.tmp"
else
    SQL_FILE="$BACKUP_FILE"
fi

# Verify SQL file integrity
log_info "Checking SQL file integrity..."

# Check for SQL syntax errors (basic check)
if ! head -n 100 "$SQL_FILE" | grep -q "CREATE TABLE\|INSERT INTO\|SET\|USE"; then
    log_error "Backup file does not appear to be a valid SQL dump"
    rm -f "$SQL_FILE"
    exit 1
fi

# Check file size (should not be empty)
if [[ ! -s "$SQL_FILE" ]]; then
    log_error "Backup file is empty"
    rm -f "$SQL_FILE"
    exit 1
fi

# Count tables in backup
TABLE_COUNT=$(grep -c "CREATE TABLE" "$SQL_FILE" || echo "0")
log_info "Found $TABLE_COUNT tables in backup"

if [[ $TABLE_COUNT -lt 10 ]]; then
    log_warn "Backup seems incomplete (less than 10 tables)"
fi

# Verify backup completeness
log_info "Checking backup completeness..."

REQUIRED_TABLES=("product" "category" "order" "customer" "media")
MISSING_TABLES=()

for table in "${REQUIRED_TABLES[@]}"; do
    if ! grep -q "CREATE TABLE.*${table}" "$SQL_FILE"; then
        MISSING_TABLES+=("$table")
    fi
done

if [[ ${#MISSING_TABLES[@]} -gt 0 ]]; then
    log_error "Missing required tables: ${MISSING_TABLES[*]}"
    rm -f "$SQL_FILE"
    exit 1
fi

log_info "All required tables found"

# Cleanup temp file
rm -f "$SQL_FILE"

log_info "Backup verification completed successfully!"

# Optional: Test restore in temporary database
if [[ "$VERIFY_RESTORE" == "true" ]]; then
    log_info "Testing restore in temporary database..."
    
    TEST_DB="shopware_backup_test_$(date +%s)"
    
    docker compose -f docker-compose.prod.yml exec -T database mariadb -u root -p"${DB_ROOT_PASSWORD}" <<EOF
CREATE DATABASE IF NOT EXISTS ${TEST_DB};
EOF
    
    gunzip -c "$BACKUP_FILE" | docker compose -f docker-compose.prod.yml exec -T database mariadb -u root -p"${DB_ROOT_PASSWORD}" "$TEST_DB" || {
        log_error "Restore test failed!"
        docker compose -f docker-compose.prod.yml exec -T database mariadb -u root -p"${DB_ROOT_PASSWORD}" -e "DROP DATABASE IF EXISTS ${TEST_DB};"
        exit 1
    }
    
    # Check if data was restored
    TABLE_COUNT_RESTORED=$(docker compose -f docker-compose.prod.yml exec -T database mariadb -u root -p"${DB_ROOT_PASSWORD}" "$TEST_DB" -e "SHOW TABLES;" 2>/dev/null | wc -l || echo "0")
    TABLE_COUNT_RESTORED=$((TABLE_COUNT_RESTORED - 1)) # Subtract header
    
    if [[ $TABLE_COUNT_RESTORED -lt 10 ]]; then
        log_error "Restore test failed - insufficient tables restored ($TABLE_COUNT_RESTORED)"
        docker compose -f docker-compose.prod.yml exec -T database mariadb -u root -p"${DB_ROOT_PASSWORD}" -e "DROP DATABASE IF EXISTS ${TEST_DB};"
        exit 1
    fi
    
    # Cleanup test database
    docker compose -f docker-compose.prod.yml exec -T database mariadb -u root -p"${DB_ROOT_PASSWORD}" -e "DROP DATABASE IF EXISTS ${TEST_DB};"
    
    log_info "Restore test completed successfully! ($TABLE_COUNT_RESTORED tables restored)"
fi
