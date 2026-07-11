# Changelog

All notable changes to Share Audit Dashboard are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [0.3.0]

### Security
- Share deletion — single, bulk, orphan revoke and recipient revoke-all —
  now always goes through `IShareManager` instead of a raw SQL `DELETE`, so
  federated unshare (OCM), `ShareDeletedEvent` and provider-specific cleanup
  run; a direct DB delete is now only a documented fallback (owner account
  gone, provider app disabled), and a genuinely retryable failure (a locked
  file, an unreachable storage backend) is reported back as failed instead
  of being forced through that fallback.
- Bulk endpoints (revoke, orphan revoke, revoke-all for a recipient) are
  capped and chunked so an unbounded selection can no longer tie up a PHP
  worker for minutes; `revoke-all` for a recipient with a very large number
  of shares now runs in server-side batches of 500 instead of one
  synchronous request.
- The security-alerts cache is now invalidated as soon as a link is fixed or
  revoked, instead of only expiring after its normal TTL — the alerts view
  no longer shows an already-fixed item as still insecure right after acting
  on it.
- Minimum supported Nextcloud version raised to **31** — orphan-share revoke
  relies on `IShareManager::getShareById()`'s `$onlyValid` parameter, which
  does not exist on Nextcloud 30.
- The exposure score no longer treats a share type this version of the app
  doesn't recognize (e.g. one added in a future Nextcloud release) as safe —
  it's now weighted the same as an external share instead of falling back to
  internal, and shown as its own "Other" slice (with an explanatory tooltip)
  in the exposure breakdown whenever it's non-zero.
- The recipient drill-down's `shares`/`revoke-all` endpoints are now
  rate-limited, matching the same endpoint's `search` action.

### Added
- **Portuguese (Portugal)** translation of the whole interface, plus
  `build/l10n.py` to regenerate the frontend `l10n/*.js` bundles from the
  `.json` sources and report missing or orphaned strings; `l10n.py --check`
  now also gates `krankerl package`.
- Security alerts: copy/open-in-Files actions on individual alerts, and a
  clearable active-filter indicator.
- All shares: a table caption describing the view.
- Personal view: an option to include link tokens in CSV export, with an
  explicit warning about what that means.
- Admin setting to turn the personal "My shares audit" page and its
  dashboard widget off instance-wide, for admins who want sharing audits to
  stay an admin-only concern. Turning it off also hides the "My shares
  audit" entry from the Settings → Personal sidebar entirely, instead of
  leaving a link that only leads to a "disabled" notice.
- Access lookup (recipient drill-down) now has the same page-size selector
  and pagination as the rest of the app, backed by a paginated
  `RecipientLookupService::getShares()`, instead of a fixed unpaginated
  500-row cap with a "narrow your search" warning.
- Two new configurable security-alert rules: a public link open for
  anonymous upload without a password (file drop, or full create+update
  access), and a native group share granting edit or reshare permission to
  a group above a configurable member-count threshold (default 20).
- The exposure/type "Other" bucket (share types this version doesn't
  recognize) now shows an explanatory tooltip on the dashboard's "Shares by
  type" chart and stat cards too, not just the exposure map.

### Changed
- Personal view header, summary cards and table captions restyled to match
  the admin dashboard's look (icon cards, `· `-separated header, consistent
  table styling).

### Fixed
- On LDAP/AD-backed instances, where internal account ids are opaque GUIDs,
  names were effectively invisible in two places: every listing's owner and
  recipient columns showed the raw uid (they now resolve display names,
  batched per unique id, with the id kept as a secondary line), and the
  Access lookup search could never find a person by name because it only
  matched the stored `share_with` id — it now also resolves the query
  against user and group display names and matches those ids exactly.
- Several UI strings introduced alongside the above were missing from
  `l10n/*.json`, so pt_PT users saw English text on the newest features.
- The dashboard widget's title/empty-state text and the admin settings
  section name ("Share Audit" in the sidebar) were never translatable at
  all — `build/l10n.py` only scanned the frontend (`src/`), missing every
  PHP-side `IL10N::t()` call (`lib/Dashboard/MyAlertsWidget.php`,
  `lib/Settings/AdminSection.php`). It now also scans `lib/**/*.php`, and
  the 5 strings this caught are translated.
- The "with expiration" / "without expiration" filter (All shares column
  filter, and the underlying flag used by exports) now treats an
  already-expired date as "without expiration" instead of counting it as
  still protected.
- The dashboard widget's title no longer truncates in the narrow widget
  panel ("Shares needing attention" → "Share alerts").
- The "Public link" label (and its longer pt_PT translation) no longer
  gets ellipsis-truncated in the dashboard's "Shares by type" chart.

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
