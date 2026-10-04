# syntax=docker/dockerfile:1
#
# 5Star Commerce — single container for Railway (or any Docker host).
#
#   /            React storefront + admin console + till (5star-frontend/dist)
#   /api/v1/...  PHP REST API (spice-api/backend/public/index.php)
#   /uploads/... Product images, QR codes, logos (persistent volume)
#
# Same origin for the SPA and the API, so no CORS configuration is needed and
# the frontend's default API base ('/api/v1') works unchanged.

# ---------------------------------------------------------------------------
# 1. Frontend build
# ---------------------------------------------------------------------------
FROM node:22-slim AS frontend

WORKDIR /build
COPY 5star-frontend/package.json 5star-frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY 5star-frontend/ ./
# VITE_API_BASE stays empty: the SPA calls '/api/v1' on its own origin.
# Railway passes service variables with the same name as build args.
ARG VITE_BRAND_LOGO_URL=""
ENV VITE_BRAND_LOGO_URL=${VITE_BRAND_LOGO_URL}
RUN npm run build

# ---------------------------------------------------------------------------
# 2. PHP dependencies (phpoffice/phpspreadsheet for Excel import)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /build
COPY spice-api/backend/composer.json spice-api/backend/composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-reqs \
        --no-scripts

# ---------------------------------------------------------------------------
# 3. Runtime: PHP 8.3 + Apache
# ---------------------------------------------------------------------------
FROM php:8.3-apache

# gd (jpeg/png/webp) for image uploads, pdo_mysql, zip for .xlsx import.
# install-php-extensions picks the right runtime libraries for whichever
# Debian release the php image is built on, and removes the -dev packages.
#
# Debian's mod_alias ships "Alias /icons/ /usr/share/apache2/icons/", which
# shadows the app's own /icons/ (PWA and home-screen icons) — commented out.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN set -eux; \
    install-php-extensions gd pdo_mysql zip opcache; \
    a2enmod rewrite headers expires deflate; \
    a2dismod -f mpm_event mpm_worker || true; \
    a2enmod mpm_prefork; \
    a2dissite 000-default; \
    sed -i 's|^\(\s*Alias /icons/\)|# \1|' /etc/apache2/mods-available/alias.conf; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-5star.ini"
COPY docker/apache.conf /etc/apache2/sites-available/5star.conf
RUN a2ensite 5star \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Application code. Only what production needs: backend + database scripts.
COPY spice-api/backend /var/www/app/backend
COPY spice-api/database/migrations /var/www/app/database/migrations
COPY spice-api/database/rollback /var/www/app/database/rollback
COPY spice-api/database/seeds /var/www/app/database/seeds
COPY --from=vendor /build/vendor /var/www/app/backend/vendor
COPY --from=frontend /build/dist /var/www/web

# Never ship a local .env, Composer binary or stray archives.
RUN rm -f /var/www/app/backend/.env /var/www/app/backend/composer.phar \
          /var/www/app/backend/bootstrap/*.zip \
    && rm -rf /var/www/app/backend/storage /var/www/app/backend/public/uploads

COPY docker/entrypoint.sh /usr/local/bin/5star-entrypoint
RUN chmod +x /usr/local/bin/5star-entrypoint

# Attach a Railway volume at /data (Railway does not allow a VOLUME line in
# the Dockerfile). Without a volume the container still runs, but uploads,
# logs and backups are lost on every redeploy.
ENV DATA_DIR=/data \
    PORT=8080

EXPOSE 8080
WORKDIR /var/www/app/backend

ENTRYPOINT ["5star-entrypoint"]
CMD ["apache2-foreground"]
