# 📦 Database Backup - Quick Start

## Commands

```bash
# Smart backup (RECOMMENDED for daily use)
make backup           # or ./backup.sh backup-smart
# → All structures + data (except cache/logs - structure only)

# Full backup (everything with data)
make backup-full      # or ./backup.sh backup
# → Absolutely everything including cache

# List backups
make list-backups     # or ./backup.sh list

# Restore
make restore FILE=backups/shopware-smart-xxx.sql
# or ./backup.sh restore backups/shopware-smart-xxx.sql
```

## What is stored

### `make backup` or `make backup-smart` (RECOMMENDED)
- ✅ All tables with structure (including cache/logs)
- ✅ Data for all tables EXCEPT:
  - `cache`, `cache_item` (structure only)
  - `enqueue`, `increment` (structure only)
  - `log_entry`, `dead_message` (structure only)
  - `message_queue_stats`, `notification` (structure only)
  - `webhook_event_log` (structure only)
- 📊 Size: ~10MB
- ⏱️ Time: 8-12s
- 💡 On restore: all tables will be created, cache/logs will be empty

### `make backup-full`
- ✅ All tables with structure
- ✅ All data including cache/logs
- 📊 Size: ~15MB
- ⏱️ Time: 10-15s

## When to use what

**Daily backup:** `make backup`  
**Before major update:** `make backup-full`  
**Before product import:** `make backup`

## Restore

```bash
# 1. List backups
make list-backups

# 2. Restore the needed one
make restore FILE=backups/shopware-smart-20251113-223045.sql

# 3. Clear cache
docker compose exec web bin/console cache:clear
```

## Automation

Add to crontab for daily backup:

```bash
# Daily at 3:00 AM
0 3 * * * cd /path/to/shopware && make backup

# Cleanup old backups (>7 days)
0 4 * * * find /path/to/shopware/backups -name "*.sql" -mtime +7 -delete
```

---

**Detailed documentation:** `bitrix-export/docs/DATABASE-BACKUP.md`


