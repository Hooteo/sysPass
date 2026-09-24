#!/bin/sh
set -e

# MYSQL_USER/MYSQL_PASSWORD (mariadb's own official image) create this
# app DB user on first boot, but only grant it real privileges if
# MYSQL_DATABASE is also set - which this stack deliberately never does
# (see docker-compose.yml: pre-creating that schema breaks a fresh
# install's own wizard with "The database already exists"). Without this
# script the user exists but has GRANT USAGE only - no access to
# anything - until something else grants it, which for a migration
# (schema-only dump, no mysql.user carried over) never happens on its
# own.
#
# GRANT works even on a schema that doesn't exist yet (verified): it
# doesn't create the database, so it doesn't reintroduce the
# "already exists" problem either way - the install wizard, or an
# imported dump, creates the schema afterwards and this grant already
# covers it.
if [ -n "${SYSPASS_DB_NAME:-}" ] && [ -n "${MYSQL_USER:-}" ]; then
    mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
        GRANT ALL PRIVILEGES ON \`${SYSPASS_DB_NAME}\`.* TO '${MYSQL_USER}'@'%';
        FLUSH PRIVILEGES;
EOSQL
    echo "db-grant-init: granted ${MYSQL_USER}@% on ${SYSPASS_DB_NAME}"
fi
