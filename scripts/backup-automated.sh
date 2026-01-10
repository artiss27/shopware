#!/bin/bash
# Automated backup script for Shopware 6.7 production
# Creates backups of database, media, and OpenSearch data
# Uploads to Hetzner Storage Box (S3-compatible)

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
if [[ ! -f .env.prod ]]; then
    log_error ".env.prod file not found!"
    exit 1
fi

set -a
source .env.prod
set +a

# Configuration
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="/tmp/shopware-backups-${TIMESTAMP}"
RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-14}

# Create backup directory
mkdir -p "$BACKUP_DIR"

cleanup() {
    log_info "Cleaning up temporary files..."
    rm -rf "$BACKUP_DIR"
}
trap cleanup EXIT

# Check if S3 backup is configured
S3_ENABLED=false
if [[ -n "${HETZNER_S3_ACCESS_KEY:-}" ]] && \
   [[ -n "${HETZNER_S3_SECRET_KEY:-}" ]] && \
   [[ -n "${HETZNER_S3_ENDPOINT:-}" ]]; then
    S3_ENABLED=true
    # Configure S3 endpoint for Hetzner Storage Box
    export AWS_ACCESS_KEY_ID="${HETZNER_S3_ACCESS_KEY}"
    export AWS_SECRET_ACCESS_KEY="${HETZNER_S3_SECRET_KEY}"
    export AWS_DEFAULT_REGION="${HETZNER_S3_REGION:-fsn1}"
    S3_ENDPOINT="${HETZNER_S3_ENDPOINT}"
    S3_BUCKET="${HETZNER_S3_BUCKET:-shopware-backups}"
    
    # Check if aws-cli is installed (only if S3 is enabled)
    if ! command -v aws &> /dev/null; then
        log_error "aws-cli not found! Install it first: apt-get install awscli"
        log_error "Or disable S3 backup by removing HETZNER_S3_* variables from .env.prod"
        exit 1
    fi
    
    log_info "S3 backup enabled (Hetzner Storage Box)"
else
    log_warn "S3 backup variables not configured - backups will be saved locally only"
    log_warn "Set HETZNER_S3_ACCESS_KEY, HETZNER_S3_SECRET_KEY, and HETZNER_S3_ENDPOINT to enable S3 uploads"
fi

log_info "Starting automated backup process..."

# 1. Database backup (smart backup)
log_info "Creating database backup..."
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T database mariadb-dump \
    -u "${DB_USER:-shopware}" \
    -p"${DB_PASSWORD:-shopware}" \
    "${DB_NAME:-shopware}" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --ignore-table="${DB_NAME:-shopware}.cache" \
    --ignore-table="${DB_NAME:-shopware}.cache_item" \
    --ignore-table="${DB_NAME:-shopware}.enqueue" \
    --ignore-table="${DB_NAME:-shopware}.increment" \
    --ignore-table="${DB_NAME:-shopware}.log_entry" \
    --ignore-table="${DB_NAME:-shopware}.dead_message" \
    --ignore-table="${DB_NAME:-shopware}.message_queue_stats" \
    --ignore-table="${DB_NAME:-shopware}.notification" \
    --ignore-table="${DB_NAME:-shopware}.webhook_event_log" \
    > "$BACKUP_DIR/shopware-smart-${TIMESTAMP}.sql"

# Add cache/log table structures only
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T database mariadb-dump \
    -u "${DB_USER:-shopware}" \
    -p"${DB_PASSWORD:-shopware}" \
    "${DB_NAME:-shopware}" \
    --no-data \
    cache cache_item enqueue increment log_entry \
    dead_message message_queue_stats notification webhook_event_log \
    >> "$BACKUP_DIR/shopware-smart-${TIMESTAMP}.sql" 2>/dev/null || true

# Compress database backup
gzip "$BACKUP_DIR/shopware-smart-${TIMESTAMP}.sql"
DB_SIZE=$(du -h "$BACKUP_DIR/shopware-smart-${TIMESTAMP}.sql.gz" | cut -f1)
log_info "Database backup created: ${DB_SIZE}"

# 2. Media and thumbnails backup
log_info "Creating media backup..."
if docker volume inspect shopware_media-data-prod &> /dev/null; then
    docker run --rm \
        -v shopware_media-data-prod:/data:ro \
        -v "$BACKUP_DIR":/backup \
        alpine tar czf /backup/shopware-media-${TIMESTAMP}.tar.gz -C /data .
    MEDIA_SIZE=$(du -h "$BACKUP_DIR/shopware-media-${TIMESTAMP}.tar.gz" | cut -f1)
    log_info "Media backup created: ${MEDIA_SIZE}"
else
    log_warn "Media volume not found, skipping media backup"
fi

if docker volume inspect shopware_thumbnail-data-prod &> /dev/null; then
    docker run --rm \
        -v shopware_thumbnail-data-prod:/data:ro \
        -v "$BACKUP_DIR":/backup \
        alpine tar czf /backup/shopware-thumbnails-${TIMESTAMP}.tar.gz -C /data .
    THUMBNAIL_SIZE=$(du -h "$BACKUP_DIR/shopware-thumbnails-${TIMESTAMP}.tar.gz" | cut -f1)
    log_info "Thumbnails backup created: ${THUMBNAIL_SIZE}"
fi

# 3. OpenSearch backup (if enabled)
if [[ "${OPENSEARCH_ENABLED:-true}" == "true" ]] && docker volume inspect shopware_opensearch-data-prod &> /dev/null; then
    log_info "Creating OpenSearch backup..."
    docker run --rm \
        -v shopware_opensearch-data-prod:/data:ro \
        -v "$BACKUP_DIR":/backup \
        alpine tar czf /backup/opensearch-${TIMESTAMP}.tar.gz -C /data .
    OPENSEARCH_SIZE=$(du -h "$BACKUP_DIR/opensearch-${TIMESTAMP}.tar.gz" | cut -f1)
    log_info "OpenSearch backup created: ${OPENSEARCH_SIZE}"
fi

# Verify backup before uploading
log_info "Verifying backup integrity..."
if [[ -f "$SCRIPT_DIR/backup-verify.sh" ]]; then
    if ! "$SCRIPT_DIR/backup-verify.sh" "$BACKUP_DIR/shopware-smart-${TIMESTAMP}.sql.gz"; then
        log_error "Backup verification failed! Not uploading corrupted backup."
        exit 1
    fi
    log_info "Backup verification passed"
else
    log_warn "backup-verify.sh not found, skipping verification"
fi

# 4. Upload to Hetzner Storage Box (if S3 is enabled) or save locally
if [[ "$S3_ENABLED" == "true" ]]; then
    log_info "Uploading backups to Hetzner Storage Box..."
    
    aws s3 cp "$BACKUP_DIR/shopware-smart-${TIMESTAMP}.sql.gz" \
        "s3://${S3_BUCKET}/db/shopware-smart-${TIMESTAMP}.sql.gz" \
        --endpoint-url "$S3_ENDPOINT" || {
        log_error "Failed to upload database backup"
        exit 1
    }
    
    if [[ -f "$BACKUP_DIR/shopware-media-${TIMESTAMP}.tar.gz" ]]; then
        aws s3 cp "$BACKUP_DIR/shopware-media-${TIMESTAMP}.tar.gz" \
            "s3://${S3_BUCKET}/media/shopware-media-${TIMESTAMP}.tar.gz" \
            --endpoint-url "$S3_ENDPOINT" || log_warn "Failed to upload media backup"
    fi
    
    if [[ -f "$BACKUP_DIR/shopware-thumbnails-${TIMESTAMP}.tar.gz" ]]; then
        aws s3 cp "$BACKUP_DIR/shopware-thumbnails-${TIMESTAMP}.tar.gz" \
            "s3://${S3_BUCKET}/media/shopware-thumbnails-${TIMESTAMP}.tar.gz" \
            --endpoint-url "$S3_ENDPOINT" || log_warn "Failed to upload thumbnails backup"
    fi
    
    if [[ -f "$BACKUP_DIR/opensearch-${TIMESTAMP}.tar.gz" ]]; then
        aws s3 cp "$BACKUP_DIR/opensearch-${TIMESTAMP}.tar.gz" \
            "s3://${S3_BUCKET}/opensearch/opensearch-${TIMESTAMP}.tar.gz" \
            --endpoint-url "$S3_ENDPOINT" || log_warn "Failed to upload OpenSearch backup"
    fi
    
    log_info "Backups uploaded successfully to S3"
    
    # 5. Cleanup old backups on S3 (older than retention period)
    log_info "Cleaning up old backups on S3 (older than ${RETENTION_DAYS} days)..."
    CUTOFF_DATE=$(date -d "${RETENTION_DAYS} days ago" +%Y%m%d)
    
    for path in "db/" "media/" "opensearch/"; do
        aws s3 ls "s3://${S3_BUCKET}/${path}" --endpoint-url "$S3_ENDPOINT" 2>/dev/null | while read -r line; do
            BACKUP_DATE=$(echo "$line" | awk '{print $4}' | grep -oE '[0-9]{8}' | head -1 || echo "")
            if [[ -n "$BACKUP_DATE" ]] && [[ "$BACKUP_DATE" -lt "$CUTOFF_DATE" ]]; then
                BACKUP_FILE=$(echo "$line" | awk '{print $4}')
                log_info "Deleting old backup: ${path}${BACKUP_FILE}"
                aws s3 rm "s3://${S3_BUCKET}/${path}${BACKUP_FILE}" --endpoint-url "$S3_ENDPOINT" || true
            fi
        done
    done
else
    # Save backups locally if S3 is not configured
    LOCAL_BACKUP_DIR="${PROJECT_ROOT}/backups"
    mkdir -p "$LOCAL_BACKUP_DIR"
    
    log_info "Saving backups locally to ${LOCAL_BACKUP_DIR}..."
    
    cp "$BACKUP_DIR/shopware-smart-${TIMESTAMP}.sql.gz" "${LOCAL_BACKUP_DIR}/" || {
        log_error "Failed to save database backup locally"
        exit 1
    }
    
    if [[ -f "$BACKUP_DIR/shopware-media-${TIMESTAMP}.tar.gz" ]]; then
        cp "$BACKUP_DIR/shopware-media-${TIMESTAMP}.tar.gz" "${LOCAL_BACKUP_DIR}/" || log_warn "Failed to save media backup locally"
    fi
    
    if [[ -f "$BACKUP_DIR/shopware-thumbnails-${TIMESTAMP}.tar.gz" ]]; then
        cp "$BACKUP_DIR/shopware-thumbnails-${TIMESTAMP}.tar.gz" "${LOCAL_BACKUP_DIR}/" || log_warn "Failed to save thumbnails backup locally"
    fi
    
    if [[ -f "$BACKUP_DIR/opensearch-${TIMESTAMP}.tar.gz" ]]; then
        cp "$BACKUP_DIR/opensearch-${TIMESTAMP}.tar.gz" "${LOCAL_BACKUP_DIR}/" || log_warn "Failed to save OpenSearch backup locally"
    fi
    
    log_info "Backups saved locally to ${LOCAL_BACKUP_DIR}"
    
    # Cleanup old local backups
    log_info "Cleaning up old local backups (older than ${RETENTION_DAYS} days)..."
    find "$LOCAL_BACKUP_DIR" -name "*.gz" -type f -mtime +${RETENTION_DAYS} -delete || true
fi

log_info "Backup process completed successfully!"

# Send notification if MONITORING_EMAIL is set
if [[ -n "${MONITORING_EMAIL:-}" ]] && command -v mail &> /dev/null; then
    echo "Backup completed successfully at $(date)" | \
        mail -s "Shopware Backup Success - ${TIMESTAMP}" "$MONITORING_EMAIL" || true
fi
