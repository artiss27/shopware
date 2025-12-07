FROM ghcr.io/shopware/docker-dev:php8.3-node22-caddy

# Switch to root for package installation
USER root

# Install system dependencies for Imagick and HEIC support
RUN apk add --no-cache \
        libheif-dev \
        libde265-dev \
        x265-dev \
        imagemagick-dev \
        imagemagick \
        pkgconfig \
        autoconf \
        g++ \
        make \
        && \
    # Install PHP Imagick extension
    pecl install imagick && \
    docker-php-ext-enable imagick && \
    # Verify Imagick installation
    php -m | grep -i imagick && \
    # Clean up build dependencies
    apk del autoconf g++ make

# Return to default user (www-data:1000)
USER www-data

