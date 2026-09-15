FROM composer:2 AS composer

FROM php:7.4-apache

RUN apt-get update; \
    ok=0; \
    for i in 1 2 3 4 5 6 7 8 9 10; do \
        apt-get install -y --no-install-recommends \
            libpng-dev \
            libfreetype6-dev \
            libzip-dev \
            libxml2-dev \
            libonig-dev \
            libldap2-dev \
            libcurl4-openssl-dev \
            libicu-dev \
            gettext \
            unzip \
            git \
        && { ok=1; break; } \
        || { echo "apt-get install failed (attempt $i/10), retrying in 30s..."; sleep 30; apt-get update; }; \
    done; \
    [ "$ok" = "1" ] || { echo "apt-get install still failing after 10 attempts, giving up"; exit 1; }; \
    docker-php-ext-configure ldap \
    && docker-php-ext-configure gd --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        gettext \
        mbstring \
        dom \
        zip \
        ldap \
        curl \
    && a2enmod rewrite ssl \
    && echo "Listen 443" >> /etc/apache2/ports.conf \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV APP_ROOT=/var/www/html/sysPass

WORKDIR ${APP_ROOT}

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && mkdir -p app/config app/backup app/cache app/temp \
    && chown -R www-data:www-data ${APP_ROOT}

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/default-ssl.conf /etc/apache2/sites-available/default-ssl.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 443

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
