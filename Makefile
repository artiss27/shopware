# bin/console media:delete-unused --folder-entity=product --grace-period-days=0 --dry-run --limit=10

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
shell: ## Enter PHP container bash shell
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
clear-cache: ## Clear Shopware cache
	docker compose exec web bin/console cache:clear

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
	echo "✅ Smart backup: $$BACKUP_FILE ($$SIZE)"; \
	echo "🗑️  Cleaning old backups (keeping last 3)..."; \
	ls -t backups/shopware-smart-*.sql 2>/dev/null | tail -n +4 | xargs -r rm -f; \
	echo "📋 Current backups:"; \
	ls -lh backups/shopware-smart-*.sql 2>/dev/null | tail -3 || echo "No backups"

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

###> seo ###
seo-regenerate: ## Regenerate SEO URLs for all entities (clears old URLs first)
	@echo "🗑️  Clearing old SEO URLs..."
	@docker compose exec database mariadb -u shopware -pshopware shopware -e "DELETE FROM seo_url WHERE route_name = 'frontend.navigation.page';"
	@echo "🔗 Regenerating SEO URLs..."
	@docker compose exec web bin/console dal:refresh:index
	@docker compose exec web bin/console cache:clear
	@echo "✅ SEO URLs regenerated"

seo-clean: ## Clear all SEO URLs (use before regenerate)
	@echo "🗑️  Clearing all SEO URLs..."
	@docker compose exec database mariadb -u shopware -pshopware shopware -e "TRUNCATE TABLE seo_url;"
	@echo "✅ SEO URLs cleared"
###< seo ###

###> supplier ###
supplier-cleanup-pricelists: ## Cleanup orphaned supplier price lists (dry-run)
	@echo "🔍 Checking for orphaned price lists..."
	@docker compose exec web bin/console artiss:supplier:cleanup-orphaned-pricelists --dry-run

supplier-cleanup-pricelists-force: ## Delete orphaned supplier price lists older than 7 days
	@echo "🗑️  Cleaning up orphaned price lists..."
	@docker compose exec web bin/console artiss:supplier:cleanup-orphaned-pricelists --no-interaction
	@echo "✅ Cleanup completed"

supplier-rebuild: ## Rebuild supplier plugin (administration)
	@echo "🔨 Rebuilding supplier plugin..."
	@docker compose exec -e DATABASE_HOST=database web php bin/console bundle:dump
	@docker compose exec -e DATABASE_HOST=database web sh -c 'cd /var/www/html && ./bin/build-administration.sh'
	@docker compose exec web php bin/console cache:clear
	@echo "✅ Supplier plugin rebuilt"

supplier-update:
	docker compose exec web bin/console plugin:update ArtissSupplier
###< supplier ###

###> media cleanup ###
media-cleanup-check: ## Check for unused media files (dry-run, 30 days grace period)
	@echo "🔍 Checking for unused media files..."
	@docker compose exec web bin/console media:delete-unused --dry-run --grace-period-days=30

media-cleanup: ## Delete unused media files (30 days grace period)
	@echo "🗑️  Cleaning up unused media files..."
	@docker compose exec web bin/console media:delete-unused --grace-period-days=30
	@echo "✅ Media cleanup completed"

media-cleanup-products: ## Cleanup unused product media only
	@echo "🗑️  Cleaning up unused product media..."
	@docker compose exec web bin/console media:delete-unused --folder-entity=product --grace-period-days=30

media-cleanup-categories: ## Cleanup unused category media only
	@echo "🗑️  Cleaning up unused category media..."
	@docker compose exec web bin/console media:delete-unused --folder-entity=category --grace-period-days=30

media-cleanup-manufacturers: ## Cleanup unused manufacturer media only
	@echo "🗑️  Cleaning up unused manufacturer media..."
	@docker compose exec web bin/console media:delete-unused --folder-entity=product_manufacturer --grace-period-days=30

media-cleanup-report: ## Generate report of unused media
	@echo "📊 Generating unused media report..."
	@docker compose exec web bin/console media:delete-unused --dry-run --report > unused_media_report.csv
	@echo "✅ Report saved to: unused_media_report.csv"
###< media cleanup ###


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
###< shopware/docker-dev ###
