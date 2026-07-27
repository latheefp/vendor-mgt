# syntax=docker/dockerfile:1
####################################################################
# CakePHP 5 API on FrankenPHP.
#
# FrankenPHP speaks HTTP directly, so there is no nginx and no
# FastCGI translation layer. The request path is simply:
#
#   browser -> this container -> Redis -> MySQL
#
#   target dev  : source bind-mounted, file watching, deps incl. dev
#   target prod : source + vendor + built SPA baked in, opcache tuned
####################################################################

########################  base  ####################################
FROM dunglas/frankenphp:1-php8.4-alpine AS base

# install-php-extensions ships with the image and handles the build
# dependencies for us.
RUN install-php-extensions \
        intl \
        pdo_mysql \
        mysqli \
        zip \
        gd \
        opcache \
        bcmath \
        redis

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Money maths must never be at the mercy of a locale or a float.
RUN { \
      echo 'date.timezone=Asia/Kolkata'; \
      echo 'memory_limit=512M'; \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'max_execution_time=120'; \
      echo 'serialize_precision=-1'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

########################  dev  #####################################
FROM base AS dev

RUN install-php-extensions xdebug

RUN { \
      echo 'xdebug.mode=off'; \
      echo 'xdebug.client_host=host.docker.internal'; \
      echo 'xdebug.start_with_request=trigger'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/zz-dev.ini

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

########################  prod  ####################################
FROM base AS prod

RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.jit=tracing'; \
      echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/zz-prod.ini

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

COPY . .
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p tmp/cache/models tmp/cache/persistent tmp/cache/views tmp/sessions logs \
    && chown -R www-data:www-data tmp logs \
    && chmod -R 0775 tmp logs

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
