FROM php:8.3-fpm
 
# Dependências do sistema:
# - libpq-dev: necessária para compilar as extensões do PostgreSQL
# - libzip-dev / libicu-dev: necessárias para as extensões zip e intl
# - git / unzip / zip: usados pelo Composer
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl zip \
    && rm -rf /var/lib/apt/lists/*
 
# Composer copiado direto da imagem oficial dele
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
 
WORKDIR /var/www/html
