#!/bin/bash
# Quick commands for database backup/restore

case "$1" in
    backup)
        echo "📦 Creating FULL backup (all tables + all data)..."
        TIMESTAMP=$(date +%Y%m%d-%H%M%S)
        docker compose exec database mariadb-dump -u shopware -pshopware shopware \
            --single-transaction --routines --triggers --events \
            > backups/shopware-full-$TIMESTAMP.sql
        SIZE=$(ls -lh backups/shopware-full-$TIMESTAMP.sql | awk '{print $5}')
        echo "✅ Full backup: backups/shopware-full-$TIMESTAMP.sql ($SIZE)"
        ;;

    backup-smart)
        echo "📦 Creating SMART backup (all structures, data without cache/logs)..."
        TIMESTAMP=$(date +%Y%m%d-%H%M%S)
        BACKUP_FILE="backups/shopware-smart-$TIMESTAMP.sql"

        # Step 1: Dump all data EXCEPT cache/log tables
        echo "  → Exporting data (without cache/logs)..."
        docker compose exec database mariadb-dump -u shopware -pshopware shopware \
            --single-transaction --routines --triggers --events \
            --ignore-table=shopware.cache \
            --ignore-table=shopware.cache_item \
            --ignore-table=shopware.enqueue \
            --ignore-table=shopware.increment \
            --ignore-table=shopware.log_entry \
            --ignore-table=shopware.dead_message \
            --ignore-table=shopware.message_queue_stats \
            --ignore-table=shopware.notification \
            --ignore-table=shopware.webhook_event_log \
            > "$BACKUP_FILE"

        # Step 2: Add only structure for cache/log tables (no data)
        echo "  → Adding cache/log table structures (no data)..."
        docker compose exec database mariadb-dump -u shopware -pshopware shopware \
            --no-data \
            cache cache_item enqueue increment log_entry \
            dead_message message_queue_stats notification webhook_event_log \
            >> "$BACKUP_FILE" 2>/dev/null

        SIZE=$(ls -lh "$BACKUP_FILE" | awk '{print $5}')
        echo "✅ Smart backup: $BACKUP_FILE ($SIZE)"
        ;;

    backup-structure)
        echo "📦 Creating database STRUCTURE backup (no data)..."
        TIMESTAMP=$(date +%Y%m%d-%H%M%S)
        docker compose exec database mariadb-dump -u shopware -pshopware shopware \
            --single-transaction --no-data --routines --triggers --events \
            > backups/shopware-structure-$TIMESTAMP.sql
        SIZE=$(ls -lh backups/shopware-structure-$TIMESTAMP.sql | awk '{print $5}')
        echo "✅ Structure backup: backups/shopware-structure-$TIMESTAMP.sql ($SIZE)"
        ;;

    backup-content)
        echo "📦 CONTENT backup (products/categories/properties)..."
        TIMESTAMP=$(date +%Y%m%d-%H%M%S)
        docker compose exec database mariadb-dump -u shopware -pshopware shopware \
            --single-transaction \
            category category_translation \
            product product_translation product_price \
            product_media product_property \
            property_group property_group_option property_group_translation property_group_option_translation \
            custom_field custom_field_set custom_field_set_relation \
            media media_translation media_folder \
            > backups/shopware-content-$TIMESTAMP.sql
        SIZE=$(ls -lh backups/shopware-content-$TIMESTAMP.sql | awk '{print $5}')
        echo "✅ Content backup: backups/shopware-content-$TIMESTAMP.sql ($SIZE)"
        ;;

    list)
        echo "📋 Backups:"
        ls -lh backups/*.sql 2>/dev/null || echo "No backups"
        ;;

    restore)
        if [ -z "$2" ]; then
            echo "❌ Usage: $0 restore backups/shopware-backup-xxx.sql"
            exit 1
        fi
        echo "🔄 Restoring from $2..."
        docker compose exec -T database mariadb -u shopware -pshopware shopware < "$2"
        echo "✅ Restored"
        ;;

    *)
        echo "Usage:"
        echo "  $0 backup              - Full backup (ALL tables + data) ~15MB"
        echo "  $0 backup-smart        - Smart backup (all structures, data without cache/logs) ~10MB - RECOMMENDED"
        echo "  $0 backup-structure    - Database structure only (no data) ~500KB"
        echo "  $0 backup-content      - Content only (products/categories + data) ~5MB"
        echo "  $0 list                - List backups"
        echo "  $0 restore FILE        - Restore from backup"
        echo ""
        echo "💡 For daily backups use: backup-smart"
        echo "💡 backup-smart = all structures + data (except cache/logs are empty)"
        exit 1
        ;;
esac

