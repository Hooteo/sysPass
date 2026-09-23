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

# Apply any pending DB schema upgrade automatically, so a plain "docker
# compose pull && docker compose up -d" is enough after a new image - no
# more clicking through the browser's upgrade confirmation screen by hand.
# Only makes sense once config.xml already exists (a brand new install
# goes through the installer instead, nothing to upgrade there).
auto_migrate() {
    echo "Auto-migrate: checking whether a DB schema upgrade is pending..."

    apache2ctl start

    tries=0
    until curl -sk -o /dev/null "http://127.0.0.1/index.php?r=login/index"; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Auto-migrate: Apache did not come up in time, skipping."
            apache2ctl stop
            return 1
        fi
        sleep 1
    done

    # Visiting any page while an upgrade is pending is what makes sysPass
    # generate <upgradeKey> in config.xml (SP\Modules\Web\Init::checkUpgrade())
    curl -sk -o /dev/null "http://127.0.0.1/index.php?r=upgrade/index"

    upgradeKey=$(sed -n 's:.*<upgradeKey>\(.*\)</upgradeKey>.*:\1:p' "${APP_ROOT}/app/config/config.xml")

    if [ -n "$upgradeKey" ]; then
        echo "Auto-migrate: upgrade needed, applying..."
        result=$(curl -sk -X POST \
            --data-urlencode "chkConfirm=1" \
            --data-urlencode "key=${upgradeKey}" \
            --data-urlencode "isAjax=1" \
            "http://127.0.0.1/index.php?r=upgrade/upgrade")
        echo "Auto-migrate: ${result}"
    else
        echo "Auto-migrate: no upgrade needed."
    fi

    apache2ctl stop

    tries=0
    while pidof apache2 >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 15 ]; then
            echo "Auto-migrate: Apache took too long to stop, continuing anyway."
            break
        fi
        sleep 1
    done
}

if [ "${SYSPASS_AUTO_MIGRATE:-yes}" = "yes" ] && [ -f "${APP_ROOT}/app/config/config.xml" ]; then
    auto_migrate || echo "Auto-migrate: failed, continuing normal boot (the app will fall back to the manual upgrade screen)"
fi

exec "$@"
