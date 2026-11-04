# FrankenPHP serves public/ directly on port 3000. Composer runs in this same
# stage so the dependencies are checked against the real PHP 8.4 platform.
FROM dunglas/frankenphp:php8.4

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql pgsql intl zip opcache

# Same limits as the development php.ini.
RUN { \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 12M'; \
      echo 'expose_php = Off'; \
      echo 'memory_limit = 512M'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# The OpenAPI document is served as-is from outside the app folder.
COPY docs /docs

RUN chmod +x /app/docker-entrypoint.sh \
  && mkdir -p /app/uploads /app/storage/logs /app/storage/framework/cache/data /app/bootstrap/cache

EXPOSE 3000

ENTRYPOINT ["/app/docker-entrypoint.sh"]
