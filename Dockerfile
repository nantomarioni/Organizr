# Stage 1: Build dependencies
FROM composer:2 AS vendor

COPY api/composer.json api/composer.lock /app/api/
WORKDIR /app/api
# specific platform check ignore because we might build on mac/arm but target linux
# and some packages might have platform constraints.
# For standard PHP packages it's usually fine.
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# Stage 2: Runtime
FROM php:8.2-fpm-alpine

# Install runtime dependencies
# shadow for usermod/groupmod
# nginx for webserver
# supervisor to run both nginx and php-fpm
# git, curl, zip, unzip for general utility and some php ext requirements
RUN apk add --no-cache \
    nginx \
    supervisor \
    shadow \
    curl \
    git \
    zip \
    unzip \
    icu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    sqlite-dev \
    libxml2-dev

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        gd \
        intl \
        mbstring \
        pdo_sqlite \
        opcache \
        xml \
        zip

# Configure Nginx
# Remove default server definition
RUN rm /etc/nginx/http.d/default.conf
COPY root/etc/nginx/http.d/default.conf /etc/nginx/http.d/default.conf

# Configure PHP-FPM
# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Configure Supervisor
COPY root/etc/supervisord.conf /etc/supervisord.conf

# Copy Application Code
WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=vendor /app/api/vendor /var/www/html/api/vendor

# Copy Entrypoint
COPY root/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Environment variables
ENV PUID=1000
ENV PGID=1000
ENV TZ=UTC

# Build arguments for versioning (optional, can be passed from CI)
ARG ORGANIZR_VERSION=dev
ENV ORGANIZR_VERSION=$ORGANIZR_VERSION

# Expose port
EXPOSE 80

# Volume for persistent data
VOLUME ["/config"]

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
