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

    # That request is what writes a fresh <upgradeKey> into config.xml
    # when an upgrade is actually pending (Init::checkUpgrade(), inside
    # the same PHP request curl just made - this route renders its own
    # page directly (HTTP 200) whether or not a key was generated, so
    # the response itself carries no reliable signal; config.xml is the
    # only place to check). But curl returning doesn't guarantee that
    # write has landed on disk yet as far as a separate read from this
    # shell script is concerned (bind-mount/overlay write-back can lag a
    # beat behind the response completing). Reading config.xml
    # immediately afterwards can catch it mid-write and see the
    # still-empty key from before, which used to make this function
    # wrongly conclude "no upgrade needed" and skip applying it entirely
    # - every real request after that then correctly redirects to the
    # upgrade screen forever, since nothing ever actually ran the
    # upgrade (confirmed in production: logs showed exactly one "no
    # upgrade needed" at boot followed by "Upgrade needed" on every
    # single request after it). Retry the read a few times before
    # accepting "empty" as the real answer - a few hundred ms of extra
    # boot time on every normal restart is a small price for not getting
    # silently stuck like this again.
    tries=0
    upgradeKey=""
    while [ "$tries" -lt 5 ]; do
        upgradeKey=$(sed -n 's:.*<upgradeKey>\(.*\)</upgradeKey>.*:\1:p' "${APP_ROOT}/app/config/config.xml")
        [ -n "$upgradeKey" ] && break
        tries=$((tries + 1))
        sleep 0.3
    done

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

# SYSPASS_SESSION_TIMEOUT provisions the "Session timeout" (Configuration
# > General) the same way SYSPASS_PASSWORD_SALT etc. provision their own
# fields - but unlike those, this one is enforced on EVERY boot, not just
# when config.xml is first created. Configuration > General's own save
# still works day-to-day; this exists for when it needs to be pinned
# from outside the UI (declarative deploys), and as a safety net - a
# still-unexplained boot once reset this value to a default despite
# having been saved through the UI (see README.md), and re-applying the
# env var on every restart means that can't silently stick even if it
# happens again.
enforce_session_timeout() {
    [ -n "${SYSPASS_SESSION_TIMEOUT:-}" ] || return 0

    configFile="${APP_ROOT}/app/config/config.xml"
    [ -f "$configFile" ] || return 0

    current=$(sed -n 's:.*<sessionTimeout>\(.*\)</sessionTimeout>.*:\1:p' "$configFile")

    if [ "$current" != "${SYSPASS_SESSION_TIMEOUT}" ]; then
        echo "Enforcing SYSPASS_SESSION_TIMEOUT=${SYSPASS_SESSION_TIMEOUT} (config.xml had ${current:-<none>})"
        sed -i "s:<sessionTimeout>.*</sessionTimeout>:<sessionTimeout>${SYSPASS_SESSION_TIMEOUT}</sessionTimeout>:" "$configFile"
        # Config::loadConfig() prefers this cache over re-reading
        # config.xml when it isn't older than the XML's own mtime - the
        # sed above just updated that mtime, so this is only a safety
        # net for the case where both happen within the same filesystem
        # timestamp tick.
        rm -f "${APP_ROOT}/app/cache/config.cache"
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

enforce_session_timeout

exec "$@"
