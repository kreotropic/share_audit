# Changelog

All notable changes to Share Audit Dashboard are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

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
