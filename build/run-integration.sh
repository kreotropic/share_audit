#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Runs the integration suite (tests/Integration) on the disposable instances
# described in build/README.md, inside their containers, against their real
# databases and web servers.
#
#   build/run-integration.sh                 # MariaDB, then PostgreSQL
#   build/run-integration.sh mysql           # one engine
#   build/run-integration.sh pgsql --filter RestoreConcurrencyTest
#
# Anything after the engine goes to phpunit. The first run of an engine builds
# its instance (a couple of minutes); later runs reuse it. Needs `composer
# install` to have been run on the host once — the app directory, vendor/
# included, is what the container mounts.
set -euo pipefail
cd "$(dirname "$0")/.."

engine="${1:-both}"
[ $# -gt 0 ] && shift
phpunit_args="$*"

run() {
    local project="$1" compose="$2" container="$3"
    echo "=== $container"
    docker compose -p "$project" -f "$compose" up -d
    # PHP's opcache revalidates files once a minute, so a change to lib/ would go
    # unseen by the web server for up to that long. A restart makes it certain.
    docker restart "$container" > /dev/null
    for _ in $(seq 1 60); do
        if docker exec -u www-data "$container" php occ status 2> /dev/null | grep -q 'installed: true'; then
            break
        fi
        sleep 5
    done
    docker exec -u www-data "$container" php occ app:enable share_audit_dashboard > /dev/null
    docker exec -e SHARE_AUDIT_INTEGRATION=disposable-instance -u www-data "$container" \
        sh -c "cd custom_apps/share_audit_dashboard && php vendor/bin/phpunit -c phpunit.integration.xml $phpunit_args"
}

case "$engine" in
    mysql) run shareaudit-my build/docker-compose.mysql.yml shareaudit-my-app ;;
    pgsql) run shareaudit-pg build/docker-compose.pgsql.yml shareaudit-pg-app ;;
    both)
        run shareaudit-my build/docker-compose.mysql.yml shareaudit-my-app
        run shareaudit-pg build/docker-compose.pgsql.yml shareaudit-pg-app
        ;;
    *) echo "usage: $0 [mysql|pgsql|both] [phpunit args]" >&2; exit 2 ;;
esac
