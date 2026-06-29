# Single-stage dev image used by BOTH the `workspace` shell and the `app` (php-fpm) service.
# Includes php-fpm + the Laravel PHP extensions, Composer, and Node 20 LTS for Vite/Livewire builds.
FROM php:8.3-fpm

# System libraries needed to build the PHP extensions + common dev tooling.
# `gnupg` is required by the NodeSource setup script below to import its signing key.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        ca-certificates \
        gnupg \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure + install the PHP extensions a Laravel app typically needs.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        gd \
        zip \
        intl \
        exif \
        pcntl \
        opcache

# Composer 2 (copied from the official image rather than re-downloading).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js 20.x LTS + npm via the NodeSource setup script (for Vite / asset build).
# Explicit apt-get update before install removes any ordering doubt after the lists cleanup above.
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# `app` service overrides with the same command; `workspace` is kept alive via compose tty/stdin.
CMD ["php-fpm"]
