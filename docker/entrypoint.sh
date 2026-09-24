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

# A config.xml already on disk means an already-installed instance
# restarting - the classic case. But SYSPASS_DB_HOST set with NO
# config.xml yet is also a real case: the "migration" env-var path
# (Config::applyEnvironmentOverrides()) doesn't write config.xml until
# the app itself handles a first request, and that freshly-generated
# config.xml is typically already stamped with an OLDER databaseVersion
# than this image's schema (see MIGRATION.md) - there's a schema upgrade
# pending from the very first boot, even though this script can't see a
# config.xml file yet to say so. Neither of these applies to a genuinely
# brand new instance headed for the install wizard (SYSPASS_DB_HOST
# unset, no config.xml) - nothing to fix or upgrade there.
needs_boot_maintenance() {
    [ -f "${APP_ROOT}/app/config/config.xml" ] || [ -n "${SYSPASS_DB_HOST:-}" ]
}

# Starts Apache privately (not yet reachable from outside the container)
# and waits for a first successful response. That request is also what
# makes config.xml come into existence in the first place on a
# migration's first boot (see needs_boot_maintenance() above) - visiting
# any page is what makes sysPass both generate it from the SYSPASS_DB_*
# env vars, and (the same trigger auto_migrate below relies on) generate
# <upgradeKey> in it when an upgrade is pending
# (SP\Modules\Web\Init::checkUpgrade()).
start_private_apache() {
    apache2ctl start

    tries=0
    until curl -sk -o /dev/null "http://127.0.0.1/index.php?r=login/index"; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Boot maintenance: Apache did not come up in time, skipping."
            apache2ctl stop
            return 1
        fi
        sleep 1
    done
}

stop_private_apache() {
    apache2ctl stop

    tries=0
    while pidof apache2 >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 15 ]; then
            echo "Boot maintenance: Apache took too long to stop, continuing anyway."
            break
        fi
        sleep 1
    done
}

# Fix any view left with SQL SECURITY DEFINER pointing at a definer
# account that doesn't exist on this server (typical after a schema-only
# import from an old instance - see docker/fix-view-security.php).
fix_view_security() {
    php "${APP_ROOT}/docker/fix-view-security.php" || echo "fix-view-security: failed, continuing normal boot"
}

# Apply any pending DB schema upgrade automatically, so a plain "docker
# compose pull && docker compose up -d" is enough after a new image - no
# more clicking through the browser's upgrade confirmation screen by
# hand. This is also what brings a migrated, older-schema database up to
# this fork's latest schema (OTP, MFA, ...) automatically on its very
# first boot, not just on a later restart.
auto_migrate() {
    echo "Auto-migrate: checking whether a DB schema upgrade is pending..."

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
}

if needs_boot_maintenance; then
    if start_private_apache; then
        fix_view_security

        if [ "${SYSPASS_AUTO_MIGRATE:-yes}" = "yes" ]; then
            auto_migrate || echo "Auto-migrate: failed, continuing normal boot (the app will fall back to the manual upgrade screen)"
        fi

        stop_private_apache
    fi
fi

exec "$@"
