FROM composer:2 AS composer

FROM php:7.4-apache

# bullseye-security has been retired from the live deb.debian.org CDN (PHP
# 7.4 / Debian bullseye are long past their support window) - pin all three
# suites to a snapshot.debian.org timestamp instead, using the fallback
# lines the base image itself already ships commented-out in sources.list.
RUN sed -i \
        -e "s|^deb http://deb.debian.org/debian bullseye main|deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20221114T000000Z bullseye main|" \
        -e "s|^deb http://deb.debian.org/debian-security bullseye-security main|deb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20221114T000000Z bullseye-security main|" \
        -e "s|^deb http://deb.debian.org/debian bullseye-updates main|deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20221114T000000Z bullseye-updates main|" \
        /etc/apt/sources.list \
    && apt-get update; \
    ok=0; \
    for i in 1 2 3; do \
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
        || { echo "apt-get install failed (attempt $i/3), retrying in 10s..."; sleep 10; apt-get update; }; \
    done; \
    [ "$ok" = "1" ] || { echo "apt-get install still failing after 3 attempts, giving up"; exit 1; }; \
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
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV APP_ROOT=/var/www/html/sysPass

WORKDIR ${APP_ROOT}

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && mkdir -p app/config app/backup app/cache app/temp \
    && chown -R www-data:www-data ${APP_ROOT} \
    && chmod 750 app/config

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/default-ssl.conf /etc/apache2/sites-available/default-ssl.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 443

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
