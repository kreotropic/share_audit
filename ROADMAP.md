<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Share Audit Dashboard — Roadmap

## Current state (v0.4.0)

The app is **published on the App Store** (`min-version` 31, `max-version`
34) and functionally complete: three review rounds (security, pre-submission
and a line-by-line quality audit) were run and closed before 0.3.0 — see
[CHANGELOG.md](CHANGELOG.md) for what each version fixed. 0.4.0 added soft
delete (recycle bin) for shares and Nextcloud 34 support. The app has a test
suite (`phpunit`, `tests/Unit/`, 73 tests) and CI
(`.github/workflows/ci.yml`: l10n, php, frontend). Everything below is
already implemented and working:

### Delivered

**Dashboard**
- Counters per share type (clickable cards → open "All shares" pre-filtered)
- Share creation trend (last 12 months)
- Internal vs external donut + top sharers
- Embedded **Exposure** section: 0–100 score, reach breakdown (internal /
  external / public) with per-category drill-down, and a ranking of top
  public exposure

**All shares**
- Table of every share on the instance
- Column-header filters (type, path, owner, recipient, password,
  expiration), sorting and **server-side** pagination
- **CSV** export of the filtered view (respects active filters)
- Deterministic sort order across MySQL/MariaDB and PostgreSQL (0.4.0)

**Security alerts**
- Detects public links with no password, no expiration, exposing a
  sensitive file type, already expired / expiring soon, open to anonymous
  upload without a password (file drop), and group shares with edit/reshare
  granted to large groups — with **configurable rules** (Settings tab)
- Breakdown by category (bar chart)
- Individual and **bulk** actions: generate a password, set an expiration
  (7/30/90d), revoke. Generated passwords are shown once.
- Copy public-link URL and "Open in Files" on each alert
- Every revocation and remediation is logged to Nextcloud's audit channel
  (requires the `admin_audit` app enabled)

**Lookup & Orphans**
- **Orphan shares**: shares whose owner is disabled or deleted, with bulk
  revoke and a dashboard badge
- **Access lookup** (reverse drill-down): search by user, group or email
  and list **every file/folder that recipient can reach**, with *revoke all
  access* (server-side batches of 500)

**Deleted shares — recycle bin (0.4.0)**
- Revoking a share (through this app, or natively via Files/`occ`/the
  sharing OCS API) is no longer irreversible: it's kept for a configurable
  retention window (30 days by default, in Settings) in a "Deleted shares"
  tab before permanent purge
- **Restore** (recreates the share, and best-effort preserves the original
  public-link URL/token) or **delete permanently**, individually or in bulk
- Daily automatic purge (`TimedJob`) of expired entries
- The app's first database migration (`oc_shareaudit_deleted`)

**Personal view (Personal settings → My shares audit)**
- Each user audits and fixes their own risky shares
- **Widget** on the Nextcloud dashboard showing links that need attention
- Admin toggle (Settings tab) to disable this view and the widget
  instance-wide, for admins who want share auditing to stay an
  administration-only concern

**Release**
- i18n **EN + pt-PT** (`build/l10n.py` regenerates the frontend bundles;
  `--check` runs in CI and as part of `krankerl package`, failing the build
  instead of relying on discipline)
- README, screenshots, `krankerl.toml` + `.nextcloudignore` for packaging
- `min-version` 31 (NC 30 is no longer supported — orphan-share revoke
  depends on a parameter only available from NC 31 onward), `max-version` 34

---

## Next up — G2: acknowledge/exception on alerts

The highest-impact item left, and it **doesn't** depend on App Store
traction.

**Problem:** in practice, every instance has public links that are
intentionally passwordless (a public page, a newsletter). With no way to
mark "this is accepted", the alert count never reaches zero — and a
permanently red counter stops being looked at after ~2 weeks.

**Fix:** a new `oc_shareaudit_ack` table (`share_id`, `rule_code`,
`acknowledged_by`, `acknowledged_at`, optional `note`). `getAlerts()` will
exclude (or mark as "acknowledged", with a show/hide filter) any
`(share_id, rule_code)` pair present in the table. Needs:
- `AckController` (`POST /api/alerts/{id}/ack`, `DELETE` to remove the
  exception), admin-only.
- UI: an "Acknowledge" button per alert row, and a "show acknowledged"
  filter in the alerts view (for audit purposes — they don't disappear,
  they just drop out of the active count).
- Needs its own migration (`lib/Migration/`) — this will be the app's
  **second** migration (the first, `oc_shareaudit_deleted`, shipped in 0.4.0
  with soft delete).
- Must cover **all** current rules, including the two most recent
  (`group_share_editable`, `public_upload`), not just the original three.
- Reuse the existing test pattern in `tests/Unit/` for the new
  `acknowledged` logic.

**Effort/impact:** medium effort, high impact — no native NC tool offers
this.

---

## Post-launch — only if there's traction

These features stay **on hold until the app gets traction on the App
Store**. Listed by impact. Technical specs are kept here so the thinking
already done isn't lost.

| # | Feature | Depends on | Effort | Impact |
|---|---------|-----------|--------|--------|
| 1 | Transfer ownership (orphans) | — | 2-3 days | Medium+ |
| 2 | Notify the owner (alerts and remediations) | — | 1-2 days | Medium |
| 3 | Exposure history/trend | — | 2-3 days | Medium |
| 4 | Weekly email digest for admins | — | 2-3 days | Medium |
| 5 | Compliance reports by email | (3) | 3-4 days | Medium |
| 6 | Per-group policies | — | 4-5 days | Medium |
| 7 | Signed PDF/HTML report (external audits) | — | 3-4 days | Medium- |

---

### 1. Transfer ownership of orphan shares

Detection and bulk revoke already exist; missing the **non-destructive**
alternative: reassign the share to another user when someone leaves and a
colleague takes over their work (UX inspiration:
`occ files:transfer-ownership`).

- `OrphanShareService::transferShare(shareId, newOwnerId)` — updates
  `uid_owner` and `uid_initiator` in `oc_share`
- Verify the new owner has access to the file (via `filecache`, group, or
  external storage)
- `POST /api/orphans/transfer` + a target-user picker modal
- **LDAP/AD:** users disabled in AD can show as *enabled* in Nextcloud if
  the sync doesn't map that state — document this and consider a
  double-check
- **Performance:** on instances with many deleted users, consider a daily
  background job populating an orphan-cache table

---

### 2. Notify the owner (alerts and remediations)

Two parts, to be done together:

**a) A "Notify" action on alerts.** A third action for the *"Sensitive file
type"* alert, where revoking or setting a password can be too aggressive:
warn whoever shared it instead.
- `POST /api/shares/{id}/notify` → `INotificationManager::notify()` to
  `uid_owner`
- Add `"Notify all owners"` to the bulk actions
- Use the native notification API (shows up in the Nextcloud UI, not just
  by email)

**b) Automatically notify on any admin remediation.** Today, **any**
remediation the admin performs (`setPassword`, `setExpiration`, `revoke` in
`ShareActionController`) changes someone else's share with no warning — the
owner gets a password they don't know, or loses their link with no
explanation.
- `INotificationManager::notify()` to `uid_owner` on **every**
  `ShareActionController` action, with a message specific to the action
  ("The administrator set a password on your share X" /
  "...changed the expiration..." / "...revoked...").
- An alternative **"ask the owner to fix it"** action instead of the admin
  fixing it directly — a notification with a deep link to the owner's own
  personal view. This is what turns the app from a "policing tool" into a
  "governance tool".

Do this after G2 (acknowledge), to reuse the same alert-action UI that G2
will touch.

---

### 3. Exposure history / trend

The Exposure section shows the **current** state. Missing: how it evolved
over time.

- `oc_shareaudit_exposure_history` table with daily snapshots
- Background job writing the per-category counters
- `ExposureMapService::getExposureTrend(days)` + a line chart in the view

> Can't be reconstructed retroactively from `oc_share`: revoked shares
> disappear (or, since 0.4.0, go to the recycle bin — but that isn't an
> aggregated time series either). Hence the need for snapshots.

Business case for prioritizing this early: cheap to build, and gives a
"we're improving" story to show management.

---

### 4. Weekly email digest for admins

Distinct from #5 (which is more formal/periodic and depends on the history
from #3). This one is a light, frequent digest: a weekly `TimedJob` +
`IMailer`, summarizing **new** insecure links, **new** orphans, and score
movement since the last digest. It's what keeps the app in use past the
second week, even before the full history (#3) exists — it can start by
comparing against just the previous week's snapshot, without waiting for
the full time series.

Do this after G2/G3, so the digest already reflects "acknowledged" alerts
(no point emailing weekly about something the admin already marked as an
exception). Implement before or alongside #5, not after.

---

### 5. Compliance reports by email

Scheduled delivery of a periodic summary (insecure links, orphans, exposure
score) to administrators. The current `ReportService` only generates the
CSV list — it would be extended to produce the report, plus a `TimedJob` to
send it. Benefits from feature 3's history to show deltas ("+12 public
links since the last report").

---

### 6. Per-group policies

Alerts today are global rules (`SettingsService::RULES` applies
instance-wide). The proposal is to let rules/exceptions be tied to specific
groups — e.g., the `Finance` group can never have passwordless public
links, regardless of the global rule.

Sketch:
- `oc_shareaudit_group_policy` table (`group_id`, `rule_code`, `mode`:
  `enforce`/`forbid`/`inherit`).
- `SecurityAnalyzerService::issuesFor()` resolves the effective rule by
  cross-referencing the `owner`/`uid_initiator`'s groups (via
  `IGroupManager::getUserGroupIds()`) before falling back to the global
  default.
- UI: a new "Per-group policies" section in Settings, with a group picker +
  rules.

**Effort:** bigger than the items above (new table + group-vs-global
precedence resolution + management UI). No native NC tool does this
visually — a real differentiator, but not a quick win.

---

### 7. Signed PDF/HTML report, for compliance/external audits

The current CSV (`ReportService`) is for the admin to work the data; a
formatted report — header with instance name, generation date/time, period
covered, an executive summary (counts, score, top exposures) and a simple
integrity signature/hash — is for handing to an external auditor.

Minimal sketch: generate HTML server-side (a dedicated template) from the
aggregates already computed by
`ShareCollectorService`/`SecurityAnalyzerService`/`ExposureMapService`, and
convert to PDF (evaluate whether a PDF-rendering dependency is worth
pulling in, or whether a standalone HTML with a print stylesheet is enough
for the use case — decide before implementing, don't assume a library
upfront). Like the CSV, the report must not include access tokens.

---

## Minor backlog

- ~~Screenshots with clean demo data~~ — done (2026-08-02): all 7
  screenshots retaken against the real dev instance, with realistic share
  data and the current UI (including 0.4.0's "Deleted shares" tab); the
  dashboard widget now shows the full page instead of an isolated crop of
  the card, for consistency with the rest of the set.
- **`build/l10n.py` only scans `src/` — a regression, not a new gap.** This
  was fixed on 2026-07-11 (extended to `lib/**/*.php` to catch backend
  `IL10N->t()`/`->n()` calls), but that fix never made it to GitHub — it was
  one of the local-only commits dropped in the 2026-07-15 realignment
  (`git reset --hard origin/master`), and GitHub's parallel history never
  reintroduced it. Confirmed 2026-08-02: `lib/Settings/AdminSection.php`,
  `lib/Settings/PersonalSection.php` and `lib/Dashboard/MyAlertsWidget.php`
  still use `IL10N->t()` with no coverage from the script. Redo the glob
  extension to `lib/**/*.php`.
- **CSV export streaming** — `ShareCollectorService::getAllForExport()`
  materializes up to 100k rows in memory before responding
  (`ReportService::buildCsv()`). Swap for a `StreamResponse` (or the
  AppFramework's streaming callback) that iterates in chunks (e.g. 1000
  rows via `findShares($filters, 1000, $offset)` in a loop) and writes
  straight to output. Deferred (2026-07-09): bigger effort, no real
  evidence yet of instances with tens of thousands of shares — revisit when
  that evidence exists.
- Missing an index on `share_with` (autocomplete/recipient search,
  `ILIKE %...%`) and on `path` (sorting). Tolerable on a ~300-user instance
  (tens of thousands of rows); decision deferred until there's evidence of
  larger instances. When it's justified, add via migration — coordinate
  with G2 (acknowledge), which will need a migration anyway.
