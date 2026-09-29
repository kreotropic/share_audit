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
| `run-integration.sh` | Runs `tests/Integration` on those two instances (see *Integration tests* below). |
| `docker-compose.nc.yml` | Disposable single-container SQLite Nextcloud instance, parameterised by version. Brought up via `nc-instance.sh`, not by hand. |
| `nc-instance.sh` | Starts/stops one instance per Nextcloud version declared in `info.xml` (see *Version matrix* below). |

## Cross-engine checks

The app supports MySQL/MariaDB and PostgreSQL. It contains no raw SQL: every
query goes through the QueryBuilder, so most of it is portable by
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

Point both at the same Nextcloud version (identical versions are what make the
two outputs diffable), then enable the app and give each the identical fixture:

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
across a year. It is idempotent, so re-run it on both instances before a diff
rather than tearing anything down.

Then dump each instance's output and diff the two. `dump-readpaths.php` walks
every read path (stats, all seven sort columns in both directions, the
filters, alerts, exposure, orphans and recipient lookup) and normalises the
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
  shifts creation times on one instance and not the other, which shows up as a
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

## Version matrix

`appinfo/info.xml` declares `<nextcloud min-version="31" max-version="35"/>`,
but day-to-day development happens against whatever the persistent instance in
`../../docker-compose.yml` runs (currently NC32). That leaves the rest of the
declared range unverified by construction. `nc-instance.sh` brings up a
disposable single-container instance (SQLite, no separate db container --
this is about API/behaviour differences between Nextcloud versions, not the
database engine) for any one version out of that range, so it can be started
or stopped on its own without touching the persistent instance or the
cross-engine ones above:

```bash
build/nc-instance.sh up 33      # http://localhost:8092, ncadmin / shareaudit-nc33-verify
build/nc-instance.sh up 35      # a second one, side by side -- own port, own volumes
build/nc-instance.sh ls         # every declared version: port + running state
build/nc-instance.sh down 33
build/nc-instance.sh down --all
```

Ports are fixed per version (31→8090 … 35→8094) so they never collide with
each other, with the persistent instance, or with the cross-engine ones. `up`
waits for install, enables the app and disables `firstrunwizard` (its setup
modal blocks every click), so the instance is ready to log into as soon as the
command returns. `down` always passes `-v`, so a version's data never survives
being torn down -- there is nothing in a purely version-compatibility check
worth keeping between runs.

If `info.xml`'s declared range changes, update the `VERSIONS`/`PORTS` arrays
at the top of the script to match.

## Integration tests

`tests/Unit` mocks the database and calls controller methods directly, so it
cannot tell what a `WHERE` or an `ORDER BY` really does, what happens when two
requests arrive at once, or what Nextcloud's own login and CSRF checks do
before a controller runs. `tests/Integration` is for exactly those: it runs
**inside** a Nextcloud container, against its real database, and talks to its
real web server over HTTP, logged in like a browser.

```bash
composer install                 # once: the containers mount this directory, vendor/ included
build/run-integration.sh         # MariaDB, then PostgreSQL
build/run-integration.sh pgsql --filter RestoreConcurrencyTest
```

What it covers:

- **`AccessAndCsrfTest`**: every route in `routes.php` as an anonymous
  visitor, a regular account, an auditor and an admin, and again without the
  CSRF token; plus, by effect, that a refused revoke/restore/settings change
  really changed nothing. The classification of routes (which are for auditors)
  is read from `ControllerAccessTest`, so the two cannot drift apart.
- **`RestoreConcurrencyTest`**: several restores of one recycle-bin entry sent
  at the same moment: exactly one may create a link, and no two links may ever
  share a token (`oc_share.token` has a plain index, so the database will not
  stop it). Also a restore racing a purge, and a token that was taken while the
  entry sat in the bin.
- **`AuditorTokenSecrecyTest`**: a Talk conversation's token must not reach an
  auditor, directly or by inference: not in any response, not through a search
  that only matches when a guess is right, and not through an order or a cut-off
  that follows the tokens (the same conversations are dealt new tokens and the
  auditor's view must not move). Each check is paired with the admin doing the
  same, who *does* see the difference, since otherwise a clean result could just mean
  the probe was pointed at nothing. Also that every exposure count equals the
  size of the list behind its "View" button.

Things to know:

- **Never point it at an instance you use.** It creates accounts (`sai_*`) and
  shares, deletes rows, sets the app's auditor groups and, when Talk is not
  installed (as on these instances), creates the two Talk tables the app reads a
  conversation's name from (and drops them again). The bootstrap refuses to run
  unless `SHARE_AUDIT_INTEGRATION=disposable-instance` is set, which the script
  does.
- **The web server caches PHP for up to a minute** (`opcache.revalidate_freq`).
  The script restarts the container first; if you run `phpunit` by hand after
  editing `lib/`, restart it yourself.
- **The race tests need real concurrency**, which is why they go through Apache
  (separate workers, separate database connections) and not through PHP calls in
  the test process. They send several requests per round and repeat rounds,
  because a race is not one that happens every time; a bug they are meant to
  catch shows as more than one success, not as a flaky pass.

