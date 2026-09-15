#!/bin/sh
set -e

APP_ROOT=/var/www/html/sysPass

# Volume-mounted dirs (app/config, app/backup) come in owned by root on first run
for dir in config backup cache temp; do
    mkdir -p "${APP_ROOT}/app/${dir}"
done
chown -R www-data:www-data "${APP_ROOT}/app/config" "${APP_ROOT}/app/backup" "${APP_ROOT}/app/cache" "${APP_ROOT}/app/temp"

# sysPass::ConfigUtil::checkConfigDir() requires this directory to be
# exactly mode 750, or it refuses to boot with a 503
chmod 750 "${APP_ROOT}/app/config"

if [ "${USE_SSL}" = "yes" ]; then
    if [ ! -f /etc/ssl/certs/syspass-selfsigned.crt ]; then
        openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
            -keyout /etc/ssl/private/syspass-selfsigned.key \
            -out /etc/ssl/certs/syspass-selfsigned.crt \
            -subj "/CN=syspass" \
            -addext "basicConstraints=critical,CA:FALSE" \
            -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
            -addext "extendedKeyUsage=serverAuth"
    fi

    a2ensite default-ssl >/dev/null
else
    a2dissite default-ssl >/dev/null 2>&1 || true
fi

exec "$@"
