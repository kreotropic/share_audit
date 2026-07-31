<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Maintainer tooling

Nothing in this directory ships: `build` is listed in `.nextcloudignore`, so
`krankerl package` leaves it out of the App Store tarball. It exists for
working on the app, not for running it.

| File | What it is |
|---|---|
| `l10n.py` | Regenerates `l10n/*.js` from the `.json` sources and reports missing or orphaned strings. CI runs `--check`. Its commands live in the main README's *Translations build* section. |
| `docker-compose.pgsql.yml` | Disposable PostgreSQL Nextcloud instance, port 8083. |
| `docker-compose.mysql.yml` | Disposable MariaDB Nextcloud instance, port 8084. |
| `seed-fixture.php` | Creates a deterministic set of shares to compare between the two. |
| `dump-readpaths.php` | Prints every read path over that fixture, normalised so two instances can be diffed. |

## Cross-engine checks

The app supports MySQL/MariaDB and PostgreSQL. It contains no raw SQL — every
query goes through the QueryBuilder — so most of it is portable by
construction. What is *not* portable by construction is **ordering**: MySQL
sorts `NULL` before every value and PostgreSQL after it, and a `GROUP BY` with
a `LIMIT` and no tiebreaker returns a different set of rows on each engine
rather than merely a different order. That is what these checks exist to catch;
see `ShareMapper::NULLABLE_SORT_COLUMNS`.

Bring up one instance per engine. Each has its own project name, containers,
port and volumes, so neither can disturb a development instance you actually
use, and both can run alongside other apps' dev tooling:

```bash
docker compose -p shareaudit-pg -f build/docker-compose.pgsql.yml up -d
docker compose -p shareaudit-my -f build/docker-compose.mysql.yml up -d
```

Point both at the same Nextcloud version — identical versions are what make the
two outputs diffable — then enable the app and give each the identical fixture:

```bash
for c in shareaudit-pg-app shareaudit-my-app; do
    docker exec -u www-data $c php occ app:enable share_audit_dashboard
    docker exec -u www-data $c \
        php /var/www/html/custom_apps/share_audit_dashboard/build/seed-fixture.php
done
```

`seed-fixture.php` creates three accounts, two groups and eight shares covering
every share type, links with and without a password and an expiration, a
`share_with` that is NULL beside real recipients, and creation times spread
across a year. It is idempotent — re-run it on both instances before a diff
rather than tearing anything down.

Then dump each instance's output and diff the two. `dump-readpaths.php` walks
every read path — stats, all seven sort columns in both directions, the
filters, alerts, exposure, orphans and recipient lookup — and normalises the
identifiers that legitimately differ:

```bash
for c in shareaudit-pg-app shareaudit-my-app; do
    docker exec -u www-data $c \
        php /var/www/html/custom_apps/share_audit_dashboard/build/dump-readpaths.php > "$c.txt"
done
diff shareaudit-pg-app.txt shareaudit-my-app.txt && echo "identical"
```

A clean run is 36 sections with no `FAILED` line and an empty diff.

Three things to know before you trust a diff:

- **Leave share ids and file ids out of what you compare.** They come from
  auto-increment and sequences, so they differ between instances by
  construction and say nothing about the engine.
- **Link tokens are random per share.** Compare whether a token is present, not
  its value.
- **Re-seed both sides first.** Expirations are relative to now, and anything
  that creates or restores a share (the soft-delete round trip, for instance)
  shifts creation times on one instance and not the other — which shows up as a
  bogus difference in the trend series.

Tear either instance down with `down -v`. The `-v` matters: without it the
volumes survive and the next `up` resumes the old instance rather than building
a clean one.

```bash
docker compose -p shareaudit-pg -f build/docker-compose.pgsql.yml down -v
docker compose -p shareaudit-my -f build/docker-compose.mysql.yml down -v
```

Note that Nextcloud refuses to start on a *lower* version than its data already
has, so lowering the `image:` in a compose file against an existing instance
means tearing it down first.
