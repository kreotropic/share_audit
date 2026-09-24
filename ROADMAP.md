<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Share Audit Dashboard — Roadmap

## Current state (v0.7.0)

The app is **published on the App Store** (`min-version` 31, `max-version`
35) and functionally complete: three review rounds (security, pre-submission
and a line-by-line quality audit) were run and closed before 0.3.0 — see
[CHANGELOG.md](CHANGELOG.md) for what each version fixed. 0.4.0 added soft
delete (recycle bin) for shares and Nextcloud 34 support; 0.5.0 added German
and Spanish translations and a Nextcloud Playground preview; 0.6.0 added
accepting alerts as exceptions, transferring orphan shares to another account,
Talk conversations and Deck cards shown by name, search and sort by name, and
Nextcloud 35 support; 0.7.0 added read-only auditor access (issue #16), closed
a self-initiated security review of 0.6.0 (cache/UID isolation, Talk-token
redaction, restore atomicity, audit-log completeness — see CHANGELOG.md's
0.7.0 *Security* section), and distinguished an orphan share whose file is
also gone from one that can still be transferred (issue #21). The app has a
test suite (`phpunit`, `tests/Unit/`, 393 tests, plus 180 integration tests in `tests/Integration/`) and CI
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
- Column-header filters (type, path or share name, owner, recipient,
  password, expiration), sorting and **server-side** pagination. The *Path*
  filter also matches the name a share was given (the label of a public link),
  and the row shows it under the path — GitHub issue
  [#14](https://github.com/kreotropic/share_audit/issues/14)
- **CSV** export of the filtered view (respects active filters), with a *Share
  name* column
- **Talk conversations and Deck cards by name**: a share made into one shows the
  conversation's name (a private one-to-one, its two people) or the card's title
  and board, how many people it reaches, and a mark on a conversation that is
  public or open to every user, instead of the token or card number — GitHub
  issue [#18](https://github.com/kreotropic/share_audit/issues/18). Read from
  Talk's and Deck's own tables, once per page and fenced, so a missing or changed
  app leaves the raw key (see `RecipientDetailsResolver`). The Recipient filter
  matches those names, and Deck shares have their own label and filter.
- Deterministic sort order across MySQL/MariaDB and PostgreSQL (0.4.0)

**Security alerts**
- Detects public links with no password, no expiration, exposing a
  sensitive file type, already expired / expiring soon, open to anonymous
  upload without a password (file drop), and group shares with edit/reshare
  granted to large groups — with **configurable rules** (Settings tab)
- Breakdown by category (bar chart)
- **Search and sort by name**: a search box over the alert list matches the
  file/folder name, the share's own name (the label of a public link), the
  owner (user id or display name) and, for group shares, the group — every
  word must match somewhere, ignoring case. The list can also be
  sorted by name (A–Z / Z–A, natural order). Both work on top of the category
  filter and paging; the chart follows the search. GitHub issues
  [#12](https://github.com/kreotropic/share_audit/issues/12) and
  [#14](https://github.com/kreotropic/share_audit/issues/14).
- Individual and **bulk** actions: generate a password, set an expiration
  (7/30/90d, or your Sharing settings' default — capped where expiration is
  enforced), revoke. Generated passwords are shown once.
- Copy the public link, open it in a new tab, or "Open in Files", from each alert
- Every revocation and remediation is logged to Nextcloud's audit channel
  (requires the `admin_audit` app enabled)
- **Acknowledge/exception** (per (share, rule) pair, optionally with a
  note): an intentionally-accepted alert (e.g. a public newsletter link) can
  be dismissed instead of permanently inflating the count — closes GitHub
  issue [#5](https://github.com/kreotropic/share_audit/issues/5). A "Show
  acknowledged" toggle reviews or undoes any exception. The app's **second**
  database migration (`oc_shareaudit_ack`).

**Lookup & Orphans**
- **Orphan shares**: shares whose owner is disabled or deleted, with bulk
  revoke and a dashboard badge
- **Transfer ownership of orphan shares** ([issue #13](https://github.com/kreotropic/share_audit/issues/13)):
  hand them to another account instead of revoking, in bulk, from a user
  picker. A share moves only when the new owner already reaches the file in
  their own file tree (a Team Folder they belong to, an external storage), may
  share it and holds at least the permissions it grants — the same rule
  `IShareManager` applies when a share is created; each one that can't move is
  reported with its reason. The creator changes only when it was the departed
  owner (as in `occ files:transfer-ownership`), a group share's per-user rows
  follow it, and the audit log records it. User, group and public-link shares
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
  depends on a parameter only available from NC 31 onward), `max-version` 35

---

## G2 — acknowledge/exception on alerts (delivered)

Implemented as designed: `AckController`/`AckService` +
`oc_shareaudit_ack` (`share_id`, `rule_code`, `acknowledged_by`,
`acknowledged_at`, optional `note`, unique on `(share_id, rule_code)`,
migration `Version0006Date20260920160000`). `SecurityAnalyzerService::
getAlerts()` takes an `$includeAcknowledged` flag: false (every existing
caller — admin view, personal view, the dashboard widget, `countAlerts()`)
drops an acknowledged issue from its alert and the alert itself once none
are left, recomputing severity from what remains; true (the alerts view's
"Show acknowledged" toggle) returns everything, each issue annotated with
who accepted it, when, and any note, so exceptions can be reviewed or
undone (`unacknowledge()`). Covers all current rules, including
`group_share_editable`/`public_upload`. Closes GitHub issue
[#5](https://github.com/kreotropic/share_audit/issues/5) ("Mark as already
reviewed"). Bulk acknowledge (`AckController::bulkAcknowledge()`, `POST
/api/alerts/bulk-ack`, "Acknowledge all" next to the other bulk actions)
was added right after first use surfaced the need — each selected alert
keeps its own issue set (unlike revoke/password/expiration, which apply
uniformly), so each item in the request names its own `ruleCodes`. See
CHANGELOG.md for the release this lands in.

**Deliberately left out of this pass** — none block shipping, revisit if
they turn out to matter in practice:
- **Bulk *un*acknowledge.** The "Show acknowledged" filter still only
  removes an exception one row at a time; a symmetric bulk action would
  reuse the same `BulkActionBar`/`AckController` plumbing bulk-acknowledge
  already added, so it's a small lift whenever it's asked for.
- **Orphaned `shareaudit_ack` rows.** A share's exceptions aren't cleaned up
  when it's later revoked/purged — harmless (an id is never reused, so a
  stale row can never match a future alert) and, in practice, small in
  number. Same call already made for the missing `share_with`/`path`
  indexes below; revisit with the same "wait for evidence" bar.
- **DE/ES/FR translations for the 7 new UI strings** were done directly (not
  reviewed by the community translators credited for those languages in
  CHANGELOG.md) — worth a native-speaker pass before the next release.
  The 18 strings of the orphan-transfer UI and the 9 of the Talk/Deck recipient
  (conversation and card names) are in the same state.
  EN and PT-PT are the maintainer's own and authoritative as always.

With G2 done, every remaining backlog item below is explicitly gated on App
Store traction (or is a GitHub issue awaiting the maintainer's own
prioritization) — there's no other currently-identified "ungated" item to
promote here automatically.

---

## Issue #16 — read-only auditor access (delivered in 0.7.0)

First increment done, on branch `feature/readonly-viewer-access`: an admin
names one or more groups (Settings → *Auditor groups*, `IAppConfig` value
`auditor_groups`) whose members become read-only viewers of the whole
instance's audit data — `AccessService::getScope()` centralizes the
decision (admin / auditor / no access), and `AdminController::
requireViewer()` is the single guard every read-only endpoint (`stats`,
`index`/*All shares*, `alerts`, `export`, orphan listing, the exposure map,
recipient search/lookup, deleted-share listing) calls; every endpoint that
changes something — including Settings itself — keeps the existing
`requireAdmin()` and carries no `#[NoAdminRequired]`, so Nextcloud's own
`SecurityMiddleware` blocks a non-admin before the controller even runs. A
new `PageController` (`GET /`) and `templates/viewer.php` give an auditor
their own entry point, since Settings → Administration is closed to them; an
admin who follows that link is redirected to their usual Settings page
instead. Public-link tokens are stripped from `alerts()`'s payload and
`export()`'s CSV for anyone but an admin (`AccessScope::canSeeTokens()`),
regardless of the `includeTokens` the request asks for. `ControllerAccessTest`
is a structural test (same reflection-based approach as
`ControllerLimitRangeTest`) that fails if a new route is added without being
consciously classified as admin-only or viewer-read, or if the matching guard
call goes missing from its body. Closes the read-only-access half of GitHub
issue [#16](https://github.com/kreotropic/share_audit/issues/16) — the
account starts as a Nextcloud "auditor" who genuinely cannot revoke, restore
or transfer anything, enforced server-side as the issue asked, not merely
hidden in the interface. Thanks
[@McKoy61](https://github.com/McKoy61).

**Deliberately left for a second increment** — the issue's optional
"manager sees their reports' shares" idea:
- **No reverse lookup exists for "who manages me".** Nextcloud stores a
  user's own managers (`IUser::getManagerUids()`, JSON in `oc_preferences`)
  but has no built-in index the other way; the plan is
  `IUserConfig::getValuesByUsers('settings', 'manager')` (NC 32+, with a
  slower `callForAllUsers()` fallback kept for the still-supported NC 31) to
  build a per-request "my direct reports" set, no transitivity.
- **Every read-side query needs a real owner-scope parameter.** The existing
  `owners` filter key's `!empty()` collapse (an *empty* array silently
  meaning "no filter" instead of "match nothing" — exactly backwards for
  "this manager has zero reports") was fixed in 0.7.0's security pass
  (`ShareMapper::applyFilters()` now short-circuits an empty `owners` array
  to a never-true condition) — but a manager's scope should still be a
  distinct `scopeOwners` key rather than reusing `owners`, so a manager
  scope and an orphan-owner scope can never be confused for each other.
  Threads through `ShareCollectorService`,
  `SecurityAnalyzerService`, `ExposureMapService`, `OrphanShareService`,
  `RecipientLookupService` and `SoftDeleteService`/`DeletedShareMapper`.
- **The manager view stays an explicit, separate Settings toggle**
  (`manager_view_enabled`, off by default) — it exposes what is arguably HR
  data (who reports to whom, inferred from having *any* access at all), a
  decision the admin should make on purpose, unlike the auditor-group list
  which is opt-in by construction (an empty list already means "nobody").
- **DE/ES/FR translations for the 6 new UI strings** were done directly
  (same caveat as G2's own translations above) — EN and PT-PT are the
  maintainer's own and authoritative.

---

## Post-launch — only if there's traction

These features stay **on hold until the app gets traction on the App
Store**. Listed by impact. Technical specs are kept here so the thinking
already done isn't lost.

| # | Feature | Depends on | Effort | Impact |
|---|---------|-----------|--------|--------|
| 1 | Notify the owner (alerts and remediations) | — | 1-2 days | Medium |
| 2 | Exposure history/trend | — | 2-3 days | Medium |
| 3 | Weekly email digest for admins | — | 2-3 days | Medium |
| 4 | Compliance reports by email | (2) | 3-4 days | Medium |
| 5 | Per-group policies | — | 4-5 days | Medium |
| 6 | Signed PDF/HTML report (external audits) | — | 3-4 days | Medium- |

---

### 1. Notify the owner (alerts and remediations)

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

### 2. Exposure history / trend

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

### 3. Weekly email digest for admins

Distinct from #4 (which is more formal/periodic and depends on the history
from #2). This one is a light, frequent digest: a weekly `TimedJob` +
`IMailer`, summarizing **new** insecure links, **new** orphans, and score
movement since the last digest. It's what keeps the app in use past the
second week, even before the full history (#2) exists — it can start by
comparing against just the previous week's snapshot, without waiting for
the full time series.

Do this after G2/G3, so the digest already reflects "acknowledged" alerts
(no point emailing weekly about something the admin already marked as an
exception). Implement before or alongside #4, not after.

---

### 4. Compliance reports by email

Scheduled delivery of a periodic summary (insecure links, orphans, exposure
score) to administrators. The current `ReportService` only generates the
CSV list — it would be extended to produce the report, plus a `TimedJob` to
send it. Benefits from feature 3's history to show deltas ("+12 public
links since the last report").

---

### 5. Per-group policies

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

### 6. Signed PDF/HTML report, for compliance/external audits

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

- **List Talk conversations open to guest/free join, directly — not only
  when a file happens to be shared into them.** Clarified via issue
  [#18](https://github.com/kreotropic/share_audit/issues/18) (2026-09-23,
  [@michel-thomas](https://github.com/michel-thomas)): the app only ever
  learns about a Talk conversation through an `oc_share` row, which only
  exists when a *file* was shared into it — a room used purely for
  chat/calls, with no file ever shared into it, has no such row and so
  never appears, "public and open to anyone" or not. Their actual use case
  ("share audit could prevent visio-squatting") wants every open/public
  conversation listed regardless of whether a file was ever shared into it
  — that needs a new read straight from `talk_rooms`
  (`RecipientDetailsResolver::describeRoomOpenness()`, added in 0.7.0 for
  the exposure score above, already resolves a token's openness and is most
  of the hard part) rather than another `oc_share` filter. Same gap exists
  for Deck (a board's membership lives in `oc_deck_board_acl`, not
  `oc_share` at all) — michel-thomas checked and found no equivalent
  "open/guest" concept on the Deck side to audit.
- ~~`RecipientLookupService`/`RecipientController` (the reverse "who has
  access to X" drill-down) still hands a Talk conversation's bare token back
  to an auditor~~ — done (after 0.7.0, see CHANGELOG.md's *Unreleased*
  section): an auditor identifies a conversation by an opaque handle
  (`RecipientLookupService::roomHandle()`, an HMAC with the instance secret,
  reversed by scanning the conversations that have shares) and finds it by
  name; the text search, the recipient filter and the sort in *All shares*
  no longer reach a conversation's token either (`ShareMapper`'s
  `hideRoomTokens`).
- ~~`SoftDeleteService::restore()` has no protection against two concurrent
  restores of the same recycle-bin entry~~ — done (after 0.7.0, see
  CHANGELOG.md's *Unreleased* section). The impact had been understated as "a
  duplicate": both requests write the original token onto their link, and
  `oc_share.token` has no unique index, so the result is two or more live links
  on one URL, and revoking one leaves the file reachable through the rest.
  A restore now claims the entry (`DeletedShareMapper::claim()`) inside one
  transaction that rolls everything back on failure.
- **The integration suite is not in CI yet, and there is no matrix across
  Nextcloud 31–35.** `tests/Unit/` mocks `IDBConnection`/`IQueryBuilder` and
  never touches a real database, so a DB-engine matrix over it would pass
  identically on every engine regardless of real dialect differences (e.g. the
  NULL-sort-order divergence `ShareMapper::NULLABLE_SORT_COLUMNS` exists to work
  around). After 0.7.0 there is a real layer, `tests/Integration/`, which runs
  inside a Nextcloud container against its real database and web server (login,
  CSRF, admin/auditor roles, concurrent restores, token secrecy) —
  `build/run-integration.sh`, on MariaDB and PostgreSQL, Nextcloud 35. What is
  left: run it in CI (it needs Docker and the ~1.5 GB Nextcloud image, a few
  minutes per engine), and repeat it on the older Nextcloud versions the app
  declares.
- ~~Exposure score: Talk conversations are classified too coarsely~~ — done
  (0.7.0, part of the security pass): a Talk conversation open to anyone
  with the link (`Room::TYPE_PUBLIC`) now counts toward *public* instead of
  always *internal* (`ExposureMapService::classifyRoomShares()`, backed by
  `ShareMapper::countRoomSharesByToken()` +
  `RecipientDetailsResolver::describeRoomOpenness()`). A conversation open
  to any *logged-in* user (`openTo === 'users'`, not a public link) still
  counts as internal, matching a group share's own reach. A conversation that
  cannot be resolved counts as *other* (weighed like external), not internal,
  and the dashboard donut and the "View" buttons read the same classification
  (`ExposureMapService::getCounts()`/`filterFor()`).
- **Deck still has no dashboard bucket, colour or internal/external call of
  its own** — its counters keep counting under *other*, which weighs like
  *external*. Not addressed in 0.7.0.
- **Access lookup does not see access through a conversation, a circle or a Deck
  board.** `RecipientLookupService` matches `share_with`, so a person who reads a
  file because they are in a Talk conversation, a circle or a board's ACL is not
  listed as reaching it, and a conversation is found by its token, not its name.
  The lists show the names now; this is the audit view that would need to expand
  them.
- ~~Recycle bin and CSV still show the raw key of a Talk or Deck recipient~~ —
  done (0.7.0), as a side effect of redacting a Talk conversation's bare
  token (a real credential) from the same two places: both now call
  `RecipientDetailsResolver::decorate()` and show the resolved name by
  default. An admin (or an admin's CSV export with *Include link tokens*
  ticked) still sees the raw token in the recycle bin / CSV, same as they
  already could for a public link's — that part is by design, not a gap.
- **Talk participants list.** The recipient shows a headcount and, for a
  one-to-one, the two people; the names of a group conversation's participants (and
  the members of a group or circle inside it) are one query away in
  `talk_attendees` but are not shown — a tooltip or drawer would need a bounded
  read per conversation.
- **Sort *All shares* by file name.** The table sorts by the full path, so two
  files called `notas.txt` in different folders don't sort next to each other.
  `oc_filecache` already holds the basename in its own indexed `name` column
  (`f.name`, already joined and used by the sensitive-extension check), so it
  needs no cross-engine string splitting: add `'name' => 'f.name'` to
  `ShareMapper::SORT_COLUMNS` and `NULLABLE_SORT_COLUMNS` (nulls last, like
  `path`) and a sort control on the *Path* header. No open issue asks for it —
  the alerts list, which [#12](https://github.com/kreotropic/share_audit/issues/12)
  was about, sorts by name already.
- **Transfer of orphan shares: what it leaves out.** Email, federated, Talk and
  other share types keep their state outside `oc_share`'s owner column (a
  remote server, a room, a mail token), so a local update wouldn't reach it —
  they're skipped, with the reason. A share whose *creator* isn't the departed
  owner (a reshare) keeps that creator, as in `occ files:transfer-ownership`;
  if the creator has since lost access to the file, Nextcloud treats the share
  as invalid and it stays broken after the transfer — checking the creator too
  would catch it. It also updates `oc_share` directly rather than through
  `IShareManager::updateShare()`, whose `onlyValid` parameter (needed for a
  disabled owner) is confirmed only on Nextcloud 33 while the app supports 31
  to 35 — worth switching once 31/32 are checked or dropped.
- **Orphans on LDAP/AD.** An account disabled in the directory can still show as
  *enabled* in Nextcloud when the sync doesn't map that state, so its shares
  aren't flagged as orphans (and can't be transferred or revoked from here).
  Worth documenting, and a directory-side double-check if it turns out to bite.
- **Orphan detection cost on large instances.** `getOrphanOwners()` needs one
  lookup per distinct owner to tell "deleted" from "active" (cached 90 s). With
  many departed users, a daily job filling an orphan-cache table would spare the
  request path — same "wait for evidence" bar as the missing indexes below.
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
  larger instances. G2's migration (`oc_shareaudit_ack`) shipped without
  bundling this, so it'll need its own migration whenever it's justified.
