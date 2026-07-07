# Share Audit Dashboard

**See and audit every share on your Nextcloud — in the browser, not the CLI.**

Share Audit Dashboard gives administrators a single, visual overview of every
share on the instance (user, group, public link, email, federated, Talk), flags
the risky ones, and lets you fix them in bulk. Regular users get their own
personal view to audit and clean up the files *they* share.

It fills a gap the community has asked about for years: Nextcloud can list shares
on the command line (`occ sharing:list`), but there was no visual, filterable,
actionable dashboard — and no easy way to answer *“who can reach this data?”* or
*“which of our public links have no password?”*

![Dashboard](screenshots/1-dashboard.png)

## Features

### For administrators (Admin settings → Share Audit)

- **Dashboard** — totals per share type, a 12‑month creation trend, an
  *internal vs external* exposure donut, top sharers, and attention banners for
  insecure links and orphaned shares.
- **All shares** — a filterable, sortable, server‑side paginated table of every
  share on the instance. Filters live in the column headers (type, path, owner,
  recipient, password, expiration). Export the filtered view to **CSV**.
- **Security alerts** — public links with no password, no expiration, or exposing
  a sensitive file type. Fix them individually or in **bulk**: add a generated
  password, set an expiration, or revoke. Rules are configurable.
- **Orphan shares** — shares still owned by disabled or deleted accounts, with
  bulk revoke. A classic offboarding risk Nextcloud does not surface.
- **Exposure map** — how far your data reaches (internal / external / public), a
  0–100 exposure score, and the users with the most public links. Click a
  category to drill into the filtered list.
- **Access lookup** — search a user, group or email and see **every file and
  folder they can reach**, with *revoke all access* — built for audits and
  offboarding suppliers.

### For every user (Personal settings → My shares audit)

- Review the files and folders **you** share, and fix your own risky public links
  (add password / set expiration / revoke) — scoped strictly to your own shares.
- A **dashboard widget** highlights your links that need attention right on the
  Nextcloud dashboard.

## Screenshots

| All shares (header filters, CSV export) | Security alerts (bulk fixes) |
|---|---|
| ![All shares](screenshots/2-all-shares.png) | ![Security alerts](screenshots/3-security-alerts.png) |

| Exposure map | Access lookup (who can reach this?) |
|---|---|
| ![Exposure](screenshots/4-exposure.png) | ![Access lookup](screenshots/5-access-lookup.png) |

| Personal view (My shares audit) | Dashboard widget |
|---|---|
| ![Personal](screenshots/6-personal.png) | ![Widget](screenshots/7-widget.png) |

## Installation

1. Copy this folder into your Nextcloud `apps/` (or `custom_apps/`) directory as
   `share_audit_dashboard`.
2. Enable it: `occ app:enable share_audit_dashboard`.
3. Admins: **Settings → Administration → Share Audit**.
   Users: **Settings → Personal → My shares audit**.

Requires Nextcloud 30–33 and PHP 8.1+.

## Development

```bash
npm install       # install frontend dependencies
npm run build      # production build
npm run watch      # rebuild on change
```

The frontend is Vue 3 + `@nextcloud/vue`; the backend reads `oc_share` directly
via a mapper and exposes an admin‑only (and a per‑user) JSON API. See the code
under `lib/` and `src/`.

## Roadmap

Planned features (soft delete / recycle bin for shares, ownership transfer,
email compliance reports, and more) are documented in [ROADMAP.md](ROADMAP.md).

## License

[AGPL‑3.0‑or‑later](LICENSE) © Ricardo Ferreira.
