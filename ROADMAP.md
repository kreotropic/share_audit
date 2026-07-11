# Share Audit Dashboard — Roadmap

> This is the project's only planning document. The history of what has
> already been done (including the three security/quality review rounds of
> July 2026) lives in the [CHANGELOG.md](CHANGELOG.md) and in the git
> history.

## Current state (v0.3.0)

**Development frozen for release.** The app is functionally complete, went
through three review rounds (security, pre-submission and a line-by-line
quality audit) with every item resolved, and has a test suite + CI. There
are no known code blockers for the App Store submission.

### Delivered

**Dashboard**
- Counters per share type (clickable cards → open "All shares" pre-filtered),
  12-month creation trend, internal-vs-external donut, top sharers
- **Exposure** section: 0–100 score, reach breakdown (internal / external /
  public / other) with drill-down, and a ranking of public exposure

**All shares**
- Server-side table (header filters, sorting, pagination) of every share on
  the instance, with an "open in Files" link per row
- **CSV** export of the filtered view; public-link tokens only enter the
  file with an explicit opt-in and warning (they are bare credentials)

**Security alerts**
- Five configurable rules: public link without a password, without an
  expiration date, exposing a sensitive file type, **anonymous upload
  without a password** (file drop), and a **group share granting
  edit/reshare above N members** (default 20)
- "Expiring soon" / "already expired" categories; clickable per-category
  breakdown (filters the list); copy link and open-in-Files on each alert
- Individual and bulk actions (chunked): generate a password, set an
  expiration (7/30/90 days), revoke — generated passwords shown exactly once

**Lookup & Orphans**
- **Access lookup** (reverse drill-down, paginated): everything a
  user/group/email can reach, with batched *revoke all access*
- **Orphan shares**: shares owned by disabled/deleted accounts, with bulk
  revoke validated server-side against the real orphan set

**Personal view + widget**
- Every user audits and fixes their own shares (owner **or** initiator);
  "Share alerts" widget on the Nextcloud dashboard
- Admin toggle to disable the personal view and the widget instance-wide
  (the entry disappears from the sidebar — no dead page left behind)

**Robustness and integrity**
- Every revocation goes through `IShareManager` (correct OCM/events/cleanup;
  the direct-DB fallback is documented, logged, and reserved for
  gone-owner / provider-unavailable cases) and is recorded in the **audit
  log** (`admin_audit`)
- Caches invalidated on mutation; heavy counts computed in pure SQL;
  rate limiting on the personal and lookup endpoints
- `phpunit` suite (`tests/Unit/`) + CI (l10n, lint, tests, frontend build)
- i18n EN + pt-PT; `build/l10n.py --check` gates `krankerl package`

> ⚠️ **Known limitation:** revocations are **permanent** — the share
> disappears from `oc_share`. Soft delete (item #2 below) is what fixes
> this, and the README documents it as a limitation.

---

## Before the App Store submission

The first item is the only remaining *content* task; the rest is release
mechanics.

- [ ] **Screenshots with clean demo data.** The current ones (2026-07-08)
  contain test paths and predate the newest features (new alert rules,
  category filter, copy link, restyled personal view). Retake all 7 with
  plausible demo data (`_seed.php` helps) and drop them into `screenshots/`
  under the same names — the README and `info.xml` already point at them.
- [ ] Push the repository to `github.com/kreotropic/share_audit` — the
  screenshot URLs in `info.xml` point at
  `raw.githubusercontent.com/.../master/screenshots/`; the App Store
  validates them on upload.
- [ ] Register the app on [apps.nextcloud.com](https://apps.nextcloud.com)
  and obtain the **signing certificate** (generate the CSR, submit it, keep
  the key safe).
- [ ] `krankerl package` (runs `npm ci`, `l10n.py --check` and the build)
  and sign the tarball (`openssl dgst -sha512 -sign ...`).
- [ ] Clean-install test of the tarball on **NC 31** and **NC 33** (the
  declared range) — dashboard, alerts, orphans, personal view, widget.
- [ ] Upload the release + tag `v0.3.0` on GitHub.

---

## Post-launch — only if the app gains traction

Ordered by impact. The specs are recorded here so the thinking already done
is not lost.

| # | Feature | Depends on | Effort | Impact |
|---|---------|-----------|--------|--------|
| 1 | Alert acknowledgements/exceptions | migration | 2-3 days | High |
| 2 | Soft delete for shares | migration | 4-5 days | High |
| 3 | Notify the owner on admin remediations | — | 1-2 days | Medium+ |
| 4 | Ownership transfer (orphans) | — | 2-3 days | Medium+ |
| 5 | Weekly email digest for admins | — | 2 days | Medium |
| 6 | Exposure history / trend | — | 2-3 days | Medium |
| 7 | Compliance reports by email | (6) | 3-4 days | Medium |
| 8 | Per-group policies | migration | 4-5 days | Medium |
| 9 | PDF/HTML report for external audits | — | 3-4 days | Medium- |

> **Note on migrations:** #1, #2 and #8 all need the app's first migration
> (`lib/Migration/`). If two of them land close together, coordinate them
> into a single batch instead of multiplying migrations.

### 1. Alert acknowledgements / exceptions

The #1 functional gap identified in review: every instance has public links
that are *intentionally* passwordless (a public page, a newsletter). With no
way to mark "this is accepted", the alert counter never reaches zero — and a
permanently red counter stops being looked at after two weeks.

- Table `oc_shareaudit_ack` (`share_id`, `rule_code`, `acknowledged_by`,
  `acknowledged_at`, optional `note`)
- `getAlerts()` excludes (or marks as "accepted", with a show/hide filter)
  the `(share_id, rule_code)` pairs present in the table — they don't
  disappear, they leave the active count
- Admin-only `AckController`: `POST /api/alerts/{id}/ack`, `DELETE` to
  remove the exception
- UI: an "Accept" button per alert and a "show accepted" filter (auditable)

### 2. Soft delete for shares (recycle bin)

Revoking is irreversible — the share disappears from `oc_share`. Upstream
issue #50734 describes a user hand-editing the database to recover links.

- Table `oc_shareaudit_deleted` with every share field plus `deleted_at`,
  `deleted_by`, `purge_after`, `note`; configurable TTL (30/60/90 days) and
  a daily `PurgeDeletedSharesJob` (TimedJob)
- `SoftDeleteService` (softDelete / restore / permanentDelete /
  purgeExpired) + `SoftDeleteController` + a "Recently deleted shares" view
  with a countdown and bulk restore/purge
- **Recorded challenges:** preserving the token on restore
  (`createShare()` generates a new token — create via the API and then
  `UPDATE` the token; fallback: accept the new token and notify the owner);
  registering a `BeforeShareDeletedEvent` listener so deletions made
  through Nextcloud's native UI are captured too; monitoring table growth
  on large instances.

### 3. Notify the owner on admin remediations

Today, any admin remediation (`setPassword` / `setExpiration` / `revoke`)
changes someone else's share without warning — the owner gains a password
they don't know, or loses the link with no explanation.

- `INotificationManager::notify()` to the `uid_owner` on **every**
  `ShareActionController` action, with an action-specific message
- Alternative action **"ask the owner to fix it"**: a notification with a
  deep link to the owner's own personal view — this is what turns the app
  from a "policing tool" into a "governance tool"
- "Notify all owners" among the bulk actions

### 4. Ownership transfer for orphan shares

The **non-destructive** alternative to bulk-revoking orphans: reassign the
share when someone leaves and a colleague takes over the work.

- `OrphanShareService::transferShare(shareId, newOwnerId)` — updates
  `uid_owner`/`uid_initiator`, first verifying the new owner can access the
  file (filecache, group, or external storage)
- `POST /api/orphans/transfer` + a user-picker modal
- **LDAP/AD:** users disabled in AD can show up as *enabled* in Nextcloud
  if the sync doesn't map the state — document it
- UX reference: `occ files:transfer-ownership`

### 5. Weekly email digest for admins

Distinct from #7 (more formal, depends on history): a light digest —
weekly `TimedJob` + `IMailer` with **new** insecure links, **new** orphans
and the score's movement since the previous digest (storing just the
previous week's snapshot is enough; it doesn't need #6's full time series).
This is what keeps the app in use past the second week; build it before or
alongside #7, not after.

### 6. Exposure history / trend

- Table `oc_shareaudit_exposure_history`, a daily background job recording
  the per-category counters, `getExposureTrend(days)` + a line chart
- Not reconstructible retroactively (revoked shares vanish from
  `oc_share`) — hence the snapshots
- Business case: "we are improving" is the argument the admin shows
  management — worth more, earlier, than the effort table alone suggests

### 7. Compliance reports by email

A scheduled periodic summary (insecure links, orphans, score) to the
administrators; extends `ReportService` + a `TimedJob`. Benefits from #6 to
show deltas ("+12 public links since the last report").

### 8. Per-group policies

Rules/exceptions per group instead of global-only — e.g. the `Finance`
group can never have passwordless public links, regardless of the global
rule. No native NC tool does this visually.

- Table `oc_shareaudit_group_policy` (`group_id`, `rule_code`, `mode`:
  `enforce`/`forbid`/`inherit`)
- `SecurityAnalyzerService::issuesFor()` resolves the effective rule by
  crossing owner/initiator with `IGroupManager::getUserGroupIds()` before
  falling back to the global default
- UI: a "Per-group policies" section in Settings

### 9. PDF/HTML report for external audits

The CSV is for the admin to work the data; a formatted report — header with
the instance name, generation date/time, covered period, an executive
summary and a simple integrity hash — is for handing to an auditor.
Generate the HTML server-side from the aggregates that already exist;
decide before implementing whether a standalone HTML with a print
stylesheet is enough or a PDF-rendering dependency is worth it. Apply the
same criterion as the CSV: **no tokens** in the report.

---

## Deferred by decision (revisit with evidence from larger instances)

- **Streaming CSV export** — currently materializes up to 100k normalized
  rows in memory; a `StreamResponse` fetching in chunks would eliminate the
  peak. Deferred: no evidence of instances that size.
- **Indexes on `share_with` (autocomplete/recipient search, `ILIKE %…%`)
  and `path` (sorting)** — tolerable at ~300 users. When justified, add via
  migration — coordinate with the app's first migration (#1/#2/#8 above).
