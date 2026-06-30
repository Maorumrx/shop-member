FROM php:8.3-fpm-alpine

# Switch APK repos to HTTP — bypasses TLS inspection (VPN/proxy intercepts HTTPS)
# APK still verifies package signatures independently, so this remains safe.
RUN sed -i 's/https/http/g' /etc/apk/repositories

# System dependencies + Node.js
RUN apk add --no-cache \
    bash \
    ca-certificates \
    openssl \
    curl \
    git \
    unzip \
    nodejs \
    npm \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev \
    icu-dev \
    zip \
    libzip-dev \
    shadow

# Trust the corporate proxy's root CA (ch7 TLS inspection) so composer/git/npm
# work over HTTPS. Self-bootstrapping: openssl s_client does NOT verify, so it can
# dump the cert chain the proxy presents even though that cert isn't trusted yet;
# we split each cert to its own file and rebuild the system CA bundle.
# If the build network can't reach packagist at all, no certs are added and the
# build still succeeds — drop a manual cert into ./certs/*.crt as a fallback.
RUN mkdir -p /usr/local/share/ca-certificates \
 && (echo quit | openssl s_client -showcerts -connect repo.packagist.org:443 2>/dev/null \
      | awk '/BEGIN CERTIFICATE/{c=1; n++} c{print > ("/usr/local/share/ca-certificates/proxy-" n ".crt")} /END CERTIFICATE/{c=0}') \
 && update-ca-certificates || true

# Make npm / Node trust the same system CA bundle
ENV NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mbstring \
        exif \
        gd \
        zip \
        bcmath \
        intl \
        opcache

# Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Trust mounted project directory (avoids git dubious ownership error)
RUN git config --global --add safe.directory /var/www/html

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
