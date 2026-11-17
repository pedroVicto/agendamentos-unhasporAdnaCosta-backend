# ---------- Etapa 1: dependências ----------
FROM php:8.3-cli AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./

# O phinx está em require-dev, mas é a ferramenta de migração — sem ele não
# há como migrar o banco no servidor. Para uma imagem sem dev:
#   docker build --build-arg COMPOSER_FLAGS=--no-dev .
ARG COMPOSER_FLAGS=""
RUN composer install $COMPOSER_FLAGS \
        --no-interaction --no-progress --prefer-dist \
        --no-scripts --optimize-autoloader

# ---------- Etapa 2: runtime ----------
FROM php:8.3-fpm AS runtime

# nginx + supervisor no mesmo container; gettext-base traz o envsubst que o
# entrypoint usa para resolver a porta.
# libpq-dev/libicu-dev/libzip-dev compilam as extensões do Postgres, intl e zip.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        gettext-base \
        libpq-dev \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl zip opcache \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/prod/php.ini        /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/prod/php-fpm.conf   /usr/local/etc/php-fpm.d/zz-prod.conf
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/prod/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/prod/entrypoint.sh  /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /etc/nginx/conf.d

WORKDIR /var/www/html

# O código entra na imagem (diferente do docker-compose de desenvolvimento,
# que monta a pasta por volume).
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

ENV PORT=80
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/app.conf"]
