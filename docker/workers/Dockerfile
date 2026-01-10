# Worker container for async message processing
# This uses the same production image but runs messenger:consume command
FROM ghcr.io/shopware/docker-dev:php8.3-node22-caddy

USER root

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

# Worker command will be overridden in docker-compose.prod.yml
CMD ["php", "bin/console", "messenger:consume", "async", "-vv", "--time-limit=3600", "--memory-limit=512M"]
