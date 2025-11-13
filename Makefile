ifndef APP_ENV
    include .env
endif

.DEFAULT_GOAL := help
.PHONY: help
help:
    @awk 'BEGIN {FS = ":.*?## "}; /^[a-zA-Z-]+:.*?## .*$$/ {printf "[32m%-15s[0m %s\n", $$1, $$2}' Makefile | sort

###> shopware/docker-dev ###
up:
	@touch .env.local
	docker compose --env-file .env.local up -d
stop:
	docker compose stop
down:
	docker compose down
shell:
	docker compose exec web bash
watch-storefront:
	docker compose exec -e PROXY_URL=http://localhost:9998 web ./bin/watch-storefront.sh
watch-admin:
	docker compose exec web ./bin/watch-administration.sh
build-storefront:
	docker compose exec web ./bin/build-storefront.sh
build-administration:
	docker compose exec web ./bin/build-administration.sh
setup:
	docker compose exec web bin/console system:install --basic-setup --create-database --drop-database --force

###> database backup ###
backup: backup-smart ## Create smart backup (without cache/logs data) - RECOMMENDED

backup-full: ## Full database backup (everything)
	@mkdir -p backups
	@echo "📦 Creating FULL database backup..."
	@TIMESTAMP=$$(date +%Y%m%d-%H%M%S); \
	docker compose exec database mariadb-dump -u shopware -pshopware shopware \
		--single-transaction --routines --triggers --events \
		> backups/shopware-full-$$TIMESTAMP.sql && \
	SIZE=$$(ls -lh backups/shopware-full-$$TIMESTAMP.sql | awk '{print $$5}'); \
	echo "✅ Full backup: backups/shopware-full-$$TIMESTAMP.sql ($$SIZE)"

backup-smart: ## Smart backup (all structures, data without cache/logs) - RECOMMENDED
	@mkdir -p backups
	@echo "📦 Creating SMART backup..."
	@TIMESTAMP=$$(date +%Y%m%d-%H%M%S); \
	BACKUP_FILE="backups/shopware-smart-$$TIMESTAMP.sql"; \
	echo "  → Exporting data (without cache/logs)..."; \
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
		> "$$BACKUP_FILE"; \
	echo "  → Adding cache/log table structures..."; \
	docker compose exec database mariadb-dump -u shopware -pshopware shopware \
		--no-data \
		cache cache_item enqueue increment log_entry \
		dead_message message_queue_stats notification webhook_event_log \
		>> "$$BACKUP_FILE" 2>/dev/null; \
	SIZE=$$(ls -lh "$$BACKUP_FILE" | awk '{print $$5}'); \
	echo "✅ Smart backup: $$BACKUP_FILE ($$SIZE)"

restore: ## Restore backup (usage: make restore FILE=backups/shopware-smart-xxx.sql)
ifndef FILE
	@echo "❌ Specify file: make restore FILE=backups/shopware-smart-xxx.sql"
	@exit 1
endif
	@echo "🔄 Restoring from $(FILE)..."
	@docker compose exec -T database mariadb -u shopware -pshopware shopware < $(FILE)
	@echo "✅ Restored from $(FILE)"

list-backups: ## List all backups
	@echo "📋 Available backups:"
	@ls -lh backups/ 2>/dev/null || echo "No backups"
###< database backup ###

###> shopware/docker-dev ###
