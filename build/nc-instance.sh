#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Starts/stops the disposable single-instance Nextcloud containers defined in
# docker-compose.nc.yml, one per version declared in appinfo/info.xml's
# <nextcloud min-version="31" max-version="35"/>, so each can be brought up or
# torn down independently while checking the app against it. See
# build/README.md.
#
#   build/nc-instance.sh up 33          # bring up NC33 (builds on first run)
#   build/nc-instance.sh down 33
#   build/nc-instance.sh down --all
#   build/nc-instance.sh ls             # versions, ports, running state
#
# Update VERSIONS/PORTS below if info.xml's declared range ever changes.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSIONS=(31 32 33 34 35)
PORTS=(8090 8091 8092 8093 8094)

port_for() {
    local v="$1"
    for i in "${!VERSIONS[@]}"; do
        if [ "${VERSIONS[$i]}" = "$v" ]; then
            echo "${PORTS[$i]}"
            return
        fi
    done
    echo "unknown version: $v (declared: ${VERSIONS[*]})" >&2
    exit 2
}

up() {
    local v="$1" port container
    port="$(port_for "$v")"
    container="shareaudit-nc$v-app"
    NC_VERSION="$v" NC_PORT="$port" \
        docker compose -p "shareaudit-nc$v" -f build/docker-compose.nc.yml up -d

    # Docker creates the custom_apps/ mountpoint (for the app bind mount
    # below it) as root before the entrypoint runs, and that entrypoint does
    # not reliably chown a custom_apps it finds already existing back to
    # www-data -- confirmed on NC31, where this left the auto-install's own
    # "is the writable apps path actually writable" check failing
    # permanently ("Cannot write into \"apps\" directory", install aborted,
    # not retried). Fix it ourselves, racing the entrypoint's own install
    # attempt with a short retry loop since the directory may not exist yet
    # in the first instant after `up -d` returns.
    for _ in $(seq 1 10); do
        docker exec "$container" chown www-data:root /var/www/html/custom_apps 2> /dev/null && break
        sleep 1
    done

    for _ in $(seq 1 60); do
        if docker exec -u www-data "$container" php occ status 2> /dev/null | grep -q 'installed: true'; then
            break
        fi
        sleep 5
    done
    # If the entrypoint's own install already gave up before our chown above
    # landed, it will not retry on its own -- do it ourselves.
    if ! docker exec -u www-data "$container" php occ status 2> /dev/null | grep -q 'installed: true'; then
        docker exec -u www-data "$container" php occ maintenance:install \
            --database=sqlite --admin-user=ncadmin --admin-pass="shareaudit-nc$v-verify"
    fi
    docker exec -u www-data "$container" php occ app:enable share_audit_dashboard > /dev/null
    # Its setup modal blocks every click otherwise.
    docker exec -u www-data "$container" php occ app:disable firstrunwizard > /dev/null 2>&1 || true

    echo "NC $v ready: http://localhost:$port  (ncadmin / shareaudit-nc$v-verify)"
}

down() {
    local v="$1" port
    port="$(port_for "$v")"
    NC_VERSION="$v" NC_PORT="$port" \
        docker compose -p "shareaudit-nc$v" -f build/docker-compose.nc.yml down -v
}

ls_versions() {
    local v state
    for v in "${VERSIONS[@]}"; do
        # A missing container makes `docker inspect` print a blank line to
        # stdout before it fails, so check the trimmed result rather than
        # relying on `||` (that would append "stopped" after the blank line).
        # The trailing `|| true` keeps `set -e` from treating that failure
        # (under `pipefail`) as fatal.
        state="$(docker inspect -f '{{.State.Status}}' "shareaudit-nc$v-app" 2> /dev/null | tr -d '[:space:]')" || true
        [ -z "$state" ] && state="stopped"
        printf 'NC %-4s port %-6s %s\n' "$v" "$(port_for "$v")" "$state"
    done
}

cmd="${1:-}"
[ $# -gt 0 ] && shift

case "$cmd" in
    up)
        [ $# -eq 1 ] || { echo "usage: $0 up <version>" >&2; exit 2; }
        up "$1"
        ;;
    down)
        if [ "${1:-}" = "--all" ]; then
            for v in "${VERSIONS[@]}"; do down "$v"; done
        else
            [ $# -eq 1 ] || { echo "usage: $0 down <version>|--all" >&2; exit 2; }
            down "$1"
        fi
        ;;
    ls) ls_versions ;;
    *) echo "usage: $0 {up|down|ls} [version|--all]" >&2; exit 2 ;;
esac
