# MicroHelium Dockerfile
# PHP 8.3 with required extensions for Laravel 12

FROM php:8.3-fpm-alpine

LABEL maintainer="MicroHelium Team"
LABEL description="MicroHelium - Hackathon and Programming Contest Management Platform"

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    postgresql-dev \
    linux-headers \
    $PHPIZE_DEPS \
    # Supervisor for queue workers
    supervisor

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        mysqli \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        xml \
        sockets

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install PCOV for code coverage
RUN pecl install pcov && docker-php-ext-enable pcov

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user for running Composer and Artisan
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

# Copy application files
COPY --chown=www:www . /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Note: Assets should be pre-built before building the Docker image
# Run 'npm install && npm run build' locally before 'docker compose up'

# Set permissions
RUN chown -R www:www /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Create directories for autojudge and logs
RUN mkdir -p /var/www/html/storage/app/judge \
    && mkdir -p /var/www/html/storage/app/problems \
    && mkdir -p /var/www/html/storage/app/runs \
    && mkdir -p /var/log/php \
    && chown -R www:www /var/www/html/storage \
    && chown -R www:www /var/log/php

# Note: PHP-FPM needs to start as root to read config and bind port,
# then it switches to the 'www' user defined in www.conf

# Expose port 9000 for PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
