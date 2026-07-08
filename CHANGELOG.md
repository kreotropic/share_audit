# Changelog

All notable changes to Share Audit Dashboard are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [0.2.1]

### Added
- **Portuguese (Portugal)** translation of the whole interface, plus
  `build/l10n.py` to regenerate the frontend `l10n/*.js` bundles from the
  `.json` sources and report missing or orphaned strings.
- **Page‑size selector** — Security alerts (5 / 15 / 25 / 50 / **All**) and All
  shares (25 / 50 / 100). Picking *All* on Security alerts loads every alert on
  one page, so “Select all” can act across the whole set rather than one page.
- **Clickable stat cards** — click *User*, *Group*, *Public link* or *Email* on
  the dashboard to open All shares already filtered to that share type;
  *Total shares* opens the unfiltered list.
- **Active tab indicator** — an accent bar under the selected tab.

### Changed
- **Tabs restructured (7 → 5)**: *Access lookup* and *Orphan shares* merged into
  *Lookup & Orphans*, and the *Exposure map* moved into the *Dashboard*.
- **Charts recoloured** with a distinct colour per share type and per alert
  category; severity badges and issue tags now follow the same palette.
- Alert category labels are no longer truncated.
- All shares: the record count moved to a pagination bar at the bottom
  (range · Previous / Page X of Y / Next); *Export CSV* and *Per page* sit at
  the top right.
- The colour palette now lives in CSS custom properties (`css/admin.css`)
  instead of hardcoded hex values scattered across components.

### Fixed
- **Dark theme.** Chart bars, badges, tags and bar tracks used hardcoded
  light‑theme colours and were unreadable on a dark background. The palette now
  ships lighter variants for `data-theme-dark` / `data-theme-dark-highcontrast`
  and for “follow system” under `prefers-color-scheme: dark`; neutrals derive
  from Nextcloud's own theme variables.
- **Plural strings were never translated.** Plural entries were keyed by their
  singular instead of Nextcloud's `_singular_::_plural_` key, so
  `translatePlural()` always fell back to English (“20 items need attention”).
  `build/l10n.py` now enforces the correct key format.
- The page‑size dropdown carried ~150px of invisible dead space — `NcSelect`
  forces `min-width: 260px` — which pushed the toolbar controls away from the
  right edge.
- `NcSelect`'s dropdown menu was wider than its toggle: it is appended to
  `<body>` and sized to `max-content`. It now renders inline and matches the
  control's width.
- Reserved the table's scrollbar gutter so opening a column filter no longer
  nudges the layout.

### Docs
- README restructured (problem statement, installation, usage, known
  limitations, translations, development); screenshots regenerated against the
  current UI; roadmap updated to separate what shipped from what is deferred
  until after launch.

## [0.2.0]

### Added
- **Security alerts remediation** — add a generated password, set an expiration,
  or revoke insecure public links, individually or in bulk; configurable rules.
- **Orphan shares** — list and bulk‑revoke shares owned by disabled/deleted
  accounts.
- **Exposure map** — internal / external / public reach, a 0‑100 exposure score,
  top public sharers, and click‑through drill‑down to the filtered list.
- **Access lookup** — reverse drill‑down by recipient (user / group / email):
  see every file they can reach and revoke all access.
- **Header filters** on the All shares table (type, path, owner, recipient,
  password, expiration), server‑side column sorting, and CSV export.
- **Dashboard charts** — 12‑month creation trend, shares‑by‑type bars, and an
  internal‑vs‑external donut, all theme‑aware.
- **Personal view** — “My shares audit” under Personal settings: any user can
  audit and fix their own shares.
- **Dashboard widget** — highlights the current user’s links that need attention.

### Changed
- Reworked the dashboard: attention banners at the top (collapsible), stat cards
  with per‑type icons that hide empty categories, and a responsive trend chart.

## [0.1.0]

### Added
- Initial release: admin dashboard with per‑type counters, a filterable and
  paginated list of all shares, basic security alerts, and CSV export.
