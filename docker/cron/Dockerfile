# Cron container for scheduled tasks
# This uses the same production image but runs cron for scheduled tasks
FROM ghcr.io/shopware/docker-dev:php8.3-node22-caddy

USER root

# Install cron
RUN apk add --no-cache dcron

# Install runtime dependencies (same as production)
RUN apk add --no-cache \
    libheif \
    libde265 \
    x265 \
    imagemagick \
    mariadb-client \
    && pecl install imagick \
    && docker-php-ext-enable imagick

USER www-data

WORKDIR /var/www/html

# Set production environment
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Copy cron script
COPY docker/cron/cron-entrypoint.sh /usr/local/bin/cron-entrypoint.sh
RUN chmod +x /usr/local/bin/cron-entrypoint.sh

# Switch back to root for cron (cron needs root)
USER root

# Cron entrypoint will be overridden in docker-compose.prod.yml
CMD ["/usr/local/bin/cron-entrypoint.sh"]
