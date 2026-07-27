# syntax=docker/dockerfile:1
####################################################################
# Strategy 1: Unified Production Build (Single Container FE + BE)
#
# Stage 1 (web-builder) : Compiles the React SPA static assets into /app/dist
# Stage 2 (prod)        : FrankenPHP running CakePHP backend, with the built
#                         React SPA baked into /app/public.
#
# FrankenPHP/Caddy handles:
#   - /api/*    -> CakePHP index.php engine
#   - /*        -> React SPA index.html + static assets (microsecond performance)
####################################################################

# -------------------------------------------------------------------
# Stage 1: Build React SPA
# -------------------------------------------------------------------
FROM node:22-alpine AS web-builder

WORKDIR /app

COPY web/package.json web/package-lock.json ./
RUN npm ci

COPY web/ .
# VITE_API_BASE is relative so requests stay on the same origin (no CORS needed)
ENV VITE_API_BASE=/api
RUN npm run build

# -------------------------------------------------------------------
# Stage 2: Base PHP Environment (FrankenPHP)
# -------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-alpine AS base

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

RUN { \
      echo 'date.timezone=Asia/Kolkata'; \
      echo 'memory_limit=512M'; \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'max_execution_time=120'; \
      echo 'serialize_precision=-1'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# -------------------------------------------------------------------
# Stage 3: Production Image (Unified FE + BE)
# -------------------------------------------------------------------
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

# Copy Backend composer files and install production dependencies
COPY api/composer.json api/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# Copy CakePHP API codebase
COPY api/ .
COPY api/docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=0755 api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Copy Built React SPA from web-builder into /app/public (served by Caddy)
COPY --from=web-builder /app/dist /app/public

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p tmp/cache/models tmp/cache/persistent tmp/cache/views tmp/sessions logs \
    && chown -R www-data:www-data tmp logs \
    && chmod -R 0775 tmp logs

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
