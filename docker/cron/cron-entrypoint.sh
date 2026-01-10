#!/bin/sh
# Entrypoint script for cron container

# Setup crontab
echo "*/5 * * * * cd /var/www/html && php bin/console scheduled-task:run >> /var/www/html/var/log/cron.log 2>&1" | crontab -

# Start cron in foreground
exec crond -f -l 2
