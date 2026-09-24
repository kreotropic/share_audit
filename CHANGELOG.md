<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Changelog

All notable changes to Share Audit Dashboard are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Security
Follow-up to the 0.7.0 review, which found the fixes above incomplete:
- A Talk conversation's token still reached an auditor through the *Access
  lookup* (the "who can reach this" search): it listed every conversation with
  its token, took the token back as the lookup key, and an empty recipient
  listed every Talk share on the instance with the token in each row. The
  lookup now identifies a conversation to an auditor by an opaque handle (a
  keyed hash with the instance's secret) and finds it by its *name*; a token
  passed in finds nothing, and an empty recipient lists nothing. An admin
  still gets the token, since an admin may have it. The same hole was open a
  substring at a time through *All shares*' search and its recipient filter,
  and through sorting by recipient: for an auditor those no longer match or
  order by a conversation's token either (a conversation is still found by its
  name).
- Redacting a token by showing the conversation's name failed for a
  conversation with no name: the token was its own fallback name, so it came
  out in the recipient, its display name and its label, in the list, the CSV,
  the orphan list and the recycle bin. A conversation nobody named is now
  shown as *Unnamed conversation* and no field carries its token; the
  recycle bin shares the one redaction with the other lists instead of
  keeping its own copy.
- Restoring a public link that had a password no longer creates it open for a
  moment and puts the password back afterwards: it is created protected by a
  strong temporary password and the original one is swapped in once it exists,
  so an interruption or a failed clean-up leaves a link nobody can open, never
  an open one. This also lets such a link be restored on an instance that
  enforces passwords for public links, where creating it without one was
  refused.
- Restoring a link whose original token had since been taken by another share
  no longer leaves two links on one URL. The database index on the token is not
  unique, so the restore relied on a constraint that is not there and quietly
  succeeded; it now checks first, and keeps the link with a new token (or, if it
  had a password, refuses and keeps the backup, as before).

### Fixed
- The dashboard's *Internal vs external* donut counted every Talk conversation
  as internal, and the exposure map's *Public* "View" button opened only public
  file links, leaving public conversations out of a list that its own count
  included. Both now come from the same classification as the exposure score,
  so a category's number and the list behind it are the same shares.
- A Talk conversation the exposure map could not look up (Talk missing, or its
  tables not what this expects) was counted as internal, so an instance of
  public conversations could score zero. It is now counted as *Other* — what
  could not be classified, weighed like external — never as safe.

## [0.7.0]

### Added
- **Read-only access for non-admin auditors.** An admin can now name one or
  more groups, in Settings → *Auditor groups*, whose members get their own
  page (a "Share Audit Dashboard" icon in the top app menu — they don't have
  administrator rights, so Settings → Administration stays closed to them)
  showing the same instance-wide dashboard, *All shares*, *Security alerts*,
  *Lookup & Orphans* and *Deleted shares* an admin sees. They can never set a
  password or expiration, revoke, restore, transfer a share, or change the
  settings, and every such action is enforced on the server, not just hidden
  in the interface — a request to one of those endpoints from an auditor
  account is refused regardless of what the browser sends. Public-link tokens
  (the bare credential in `Copy link` and the CSV's *Token* column) are never
  handed to an auditor either. Thanks
  [@McKoy61](https://github.com/McKoy61)
  ([#16](https://github.com/kreotropic/share_audit/issues/16)).

### Security
A self-initiated review of 0.6.0 found the following, all fixed here:
- The instance-wide and personal alerts views shared one cache with no
  namespace between them — an account whose uid happened to collide with the
  cache's own internal key for the global view could, for up to a minute,
  have been served the wrong scope's alerts. Cache keys are now
  unambiguously separated.
- A share listing or count scoped to one user (an owner filter, or "my
  shares") silently dropped that scope for an account whose uid is the
  literal string `"0"`, returning every share on the instance instead of
  just that user's own. Every such filter now treats `"0"` (and every other
  uid) correctly.
- A Talk conversation's bare token — which lets anyone holding it join a
  public room — reached the share list, CSV export, the orphan and
  recycle-bin listings and the personal view's recipient column regardless
  of role, the same way a public link's token used to before it was gated to
  administrators. It's now redacted the same way for anyone without that
  access, shown as the conversation's resolved name instead.
- Restoring a share from the recycle bin could, if reapplying its original
  password/link token failed (most likely because that token had since been
  reused by another share), leave a brand-new, unprotected public link live
  while discarding the only backup that had the password — instead of
  reporting failure. It now undoes the share it just created and keeps the
  backup so the restore can be retried once the conflict clears.
- Turning off the personal "My shares audit" page (Settings → Personal) now
  also closes its API; it previously only hid the page and its dashboard
  widget.
- The *sensitive file type* alert rule could miss a link that also had a
  password set and a comfortably-future expiration date — it's now checked
  regardless of those.
- "Revoke all" for a recipient no longer reports success and clears the list
  when some of that recipient's shares could not actually be revoked; it now
  shows how many remain and reloads the real list instead.
- A Talk conversation open to anyone with the link now counts toward the
  exposure score's *public* share of reach instead of always being treated
  as internal.
- Restoring a share, permanently purging a recycle-bin entry, changing
  settings (in particular the auditor-groups list) and accepting or undoing
  an alert exception are now recorded to Nextcloud's admin audit log,
  alongside the revoke/transfer/export actions that already were.

### Fixed
- Distinguished an orphan share (owner account disabled or deleted) whose
  file has *also* been deleted separately from one whose file is still
  there: only the latter can be transferred to a new owner — attempting to
  transfer the other is now refused server-side too, not just hidden in the
  interface — and the recycle bin now marks such an entry the same way and
  disables *Restore* for it, instead of a restore that can only ever fail.
  Thanks [@michel-thomas](https://github.com/michel-thomas)
  ([#21](https://github.com/kreotropic/share_audit/issues/21)).
- *All shares*' per-column search filters had a button that looked like it
  cleared the search but actually resubmitted it unchanged; it now has a
  working *Clear filter* action instead. Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#14](https://github.com/kreotropic/share_audit/issues/14)).

## [0.6.0]

### Added
- **Accept an alert as an exception.** Some alerts are intentional — a newsletter
  link that is meant to be public, say — and used to stay in the list, and in
  the count, for ever. An admin can now *Accept* an alert, with an optional note
  saying why, one at a time or in bulk with *Acknowledge all*: the accepted
  reason stops counting, and the alert leaves the list once none of its reasons
  is left. A *Show acknowledged* switch brings them back with who accepted each
  one, when and why, so an exception can be reviewed or undone. An exception
  covers one reason on one share, so accepting *No expiration* on a link does
  not hide it if it later exposes a sensitive file. The alert badge, the
  dashboard widget and the personal view leave accepted alerts out too. **This
  release adds a database table (`oc_shareaudit_ack`)**, which Nextcloud creates
  when the app is updated. Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#5](https://github.com/kreotropic/share_audit/issues/5)).
- **Nextcloud 35 support.** The app is declared compatible with Nextcloud
  35 (`max-version` 35), so Nextcloud no longer warns about it before
  upgrading. Checked on real Nextcloud 34.0.4 and 35.0.0 instances (PHP 8.5,
  SQLite, MariaDB and PostgreSQL): both migrations, the alert / acknowledge /
  recycle-bin flows and every admin API endpoint work — apart from the *All*
  page size, fixed below
  ([#20](https://github.com/kreotropic/share_audit/issues/20)).
- **Open a public link from its alert.** Every alert with a public link has an
  *Open link in a new tab* action next to *Copy link*, so a link whose file
  name means nothing without context can be judged by what it actually shows.
  The link opens with `noopener`, so the page it opens cannot reach back into
  the admin page. Thanks [@michel-thomas](https://github.com/michel-thomas)
  ([#7](https://github.com/kreotropic/share_audit/issues/7)).
- **"Set expiry" follows your sharing policy.** The action used a fixed 30
  days; it now starts from what *Administration settings → Sharing* defines
  for public links — the default number of days when a default expiration is
  switched on (30 days when it isn't). Where expiration is *enforced*, no
  period beyond the allowed maximum is offered, and a longer request is capped
  to it instead of failing. It applies to the row action, the bulk *Set
  expiry* and the personal view. Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#6](https://github.com/kreotropic/share_audit/issues/6)).
- **Search the security alerts.** A search box in the alert list's toolbar
  narrows it to the alerts whose file or folder name, share name (the label
  of a public link), owner (user id or display name) or group contain every
  word typed, ignoring case. The category chart follows the
  search; the tab's badge keeps counting every alert. The box stays on
  screen, with *Clear search*, when nothing matches. On narrow windows the
  search drops to a line of its own instead of squeezing the other controls.
  Thanks [@michel-thomas](https://github.com/michel-thomas)
  ([#14](https://github.com/kreotropic/share_audit/issues/14)).
- **Sort the security alerts by name.** *Name (A–Z)* and *Name (Z–A)* join the
  existing orderings; digits sort naturally (`file2` before `file10`), case is
  ignored, and alerts with no name come last in both directions. Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#12](https://github.com/kreotropic/share_audit/issues/12)).
- The alert's details drawer shows the share's own name (*Share name*) when it
  has one, and the tooltip on the file name carries it too.
- **Talk conversations and Deck cards are shown by name, not by their key.** A
  share made into a Talk conversation used to list its token (`kz6giye3`) as the
  recipient, and a Deck share the card's number, which says nothing about who can
  read the file. The recipient now shows the conversation's name — or, for a
  private one-to-one, its two people — with how many participants and groups it
  has, and a *Public conversation* or *Open conversation* mark when anyone with
  the link, or any user of the instance, can join it. A Deck share shows the
  card's title, its board and how many people the board reaches. It appears in
  *All shares*, *Orphan shares* and the personal view, and the *Recipient* filter
  finds a share by the name on screen (a card also by its board's title). Deck
  shares are labelled *Deck* now (they were *Other*) and can be filtered by it.
  Talk and Deck are optional: without them, or if their tables change, the list
  shows the raw key as before. The CSV export keeps the raw key, as it does for
  accounts, and its *Type* column says `deck` for a Deck share. Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#18](https://github.com/kreotropic/share_audit/issues/18)).
- **Find a share by its name in *All shares*.** The *Path* filter now also
  matches the name a share was given (the label of a public link), not only where
  its file is, and the row shows that name under the path — so a link called
  "Q3 Budget — external review" can be found without knowing which folder it is
  in. The CSV export follows the filter, as before, and has a new *Share name*
  column after *Password*: the columns it already had keep their positions
  (*Token*, when included, is still the last). Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#14](https://github.com/kreotropic/share_audit/issues/14)).
- **Transfer orphan shares to another account.** On *Lookup & Orphans*, select
  shares whose owner is disabled or deleted and choose *Transfer selected*
  instead of revoking them: pick the colleague who takes over, and each share
  keeps working under the new owner — same link, same recipients. Handy when
  someone leaves and a teammate inherits their work. A share moves only when the
  new owner can already reach the file (a Team Folder they belong to, an
  external storage), may share it and holds at least the permissions the share
  grants; the others stay where they were, and the result names the reason for
  each. Files in the departed person's own storage can't be reached by anyone
  else — move them first with `occ files:transfer-ownership`. User, group and
  public-link shares are supported, and every transfer is recorded in the audit
  log. Thanks [@michel-thomas](https://github.com/michel-thomas)
  ([#13](https://github.com/kreotropic/share_audit/issues/13)).

### Changed
- **Security alerts are one line each.** An alert used to take ~140px over
  three lines; it is now a single 44px row (severity, file, path, reasons and
  icon actions). Owner, date and share token moved into a details drawer,
  opened with the chevron or Enter on the row (one alert open at a time).
  Accepting (with its optional note) and revoking now confirm in a step that
  opens under the row.
- **Bulk actions float over the list.** Selecting alerts no longer grows the
  toolbar from one line to three and pushes the list down. The toolbar stays a
  single fixed-height line of filters; the selection count and bulk actions
  appear in a floating bar at the bottom of the list, which folds
  *Add password* and *Set expiry* into a *More* menu when the list is narrow.
  The personal *My shares audit* view uses the same list.
- **The note on PHP JIT crashes is corrected.** It called them an ARM64 bug
  triggered by this app. They are a regression in PHP's JIT compiler (first
  reported on PHP 8.5.5, still present on 8.5.10) that hits any app enabled or
  updated under the JIT, on x86_64 as well as aarch64 — tracked upstream as
  [php/php-src#22558](https://github.com/php/php-src/issues/22558). The README
  now says so, and that switching the JIT off is the workaround
  ([#3](https://github.com/kreotropic/share_audit/issues/3)).
- **French labels refined.** Several labels of the French translation were
  reworded. Thanks [@QwazarFR](https://github.com/QwazarFR)
  ([#19](https://github.com/kreotropic/share_audit/pull/19)).

### Fixed
- **Choosing *All* items per page failed on Nextcloud 34 and 35.** Those
  versions reject any `limit` request parameter outside 1–500 unless the
  endpoint declares its own range, and *All* sends `limit=0` — so on the
  alerts, orphan shares, deleted shares and access-lookup lists it ended in an
  error (a 500 on 34.0.x, a 400 on 35) while working on 32 and 33. The four
  endpoints now declare a 0–500 range, and a test fails if a paginated
  endpoint is added without making that choice.
- **The *Confirm* step of a destructive action stands out.** Revoking (orphan
  shares, *Revoke all access*) or permanently deleting shares asks *"Revoke N
  shares?"* with *Confirm* / *Cancel*, but every button looked the same, so it
  was easy to miss which one commits the action. *Confirm* is now red and
  *Cancel* a discreet button. The cause was wider than those buttons: since
  `@nextcloud/vue` 9 a button's look comes from `variant`, and the app still
  passed the old `type`, which is ignored — so no button in the app rendered
  with its intended emphasis. That is fixed everywhere: the active tab and the
  current page number are filled, *Save* is a primary button, and secondary
  actions are subtle. Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#8](https://github.com/kreotropic/share_audit/issues/8)).
- **A public link's own name was never read.** The name given to a link share
  is stored in the `label` column of `oc_share`, but the app read `share_name`,
  a legacy column that is always empty — so the alert and *All shares* APIs
  returned no share name, and a share deleted outside the app's own delete
  action (a raw database row, e.g. by another app or `occ`) lost its label in
  the recycle bin. Both now read `label`; the recycle bin's own column keeps
  its name.
- The personal *My shares audit* view showed an *Acknowledge* button that did
  nothing (regular users have no acknowledge endpoint); it is hidden there now.
- Alert checkboxes had no accessible name, and the icon-only actions are
  labelled for screen readers.
- **Restoring a deleted share whose expiration had passed always failed.**
  Nextcloud refuses to create a share that expires in the past, so a share
  revoked with an expiration that then elapsed in the recycle bin (or that had
  already expired when it was revoked) could never be restored. It is restored
  now, without the stale expiration, and the result says so. The restore error
  also told every failure "the file may no longer exist"; it now gives the real
  reason — a missing file, an invalid recipient or permissions, or an entry that
  is already gone from the bin.
- **The Security alerts toolbar lines up.** *Select all* sat 16px to the right
  of the rows' checkboxes, and the sort, page-size and search boxes were three
  different heights (36, 36 and 30px) on slightly different centre lines. They
  now share one height and one line, and *Select all* is in the checkbox column.
  The page-size dropdown no longer sits 2px high beside buttons and checkboxes in
  the other lists either.

## [0.5.0]

### Added
- **German, Spanish and French** translations of the whole interface.
  French contributed by [@QwazarFR](https://github.com/QwazarFR)
  ([#10](https://github.com/kreotropic/share_audit/pull/10)).
- **Try it in Nextcloud Playground** — a one-click, browser-only demo
  instance (no install required) with Share Audit Dashboard pre-installed
  and a handful of shares already seeded, so the Dashboard, Security alerts
  and Lookup & Orphans views have something to show immediately. See the
  README for the link.
- **Jump to a specific page** on every paginated list (All shares, Security
  alerts, Orphan shares, Deleted shares, Access lookup) instead of only
  stepping one page at a time — useful once a list runs into the hundreds
  of pages. Contributed by [@QwazarFR](https://github.com/QwazarFR)
  ([#17](https://github.com/kreotropic/share_audit/pull/17), fixes
  [#11](https://github.com/kreotropic/share_audit/issues/11)).

### Fixed
- **Soft-delete failed for user shares** (`share_type` 0), the most common
  share type: it silently never landed in the recycle bin — the share was
  still deleted, only the safety-net copy was lost, with no visible error at
  the time. Caused by the retention entity's zero-value defaults matching
  real values (`share_type` 0, `permissions` 0, an empty owner) closely
  enough that Nextcloud's own change-tracking treated setting them as a
  no-op and omitted the column from the database insert. Thanks
  [@dauni](https://github.com/dauni) for the precise diagnosis
  ([#15](https://github.com/kreotropic/share_audit/issues/15)).
- **Generating a password for a public link could fail** ("The action could
  not be completed.") on instances where the `password_policy` app enforces
  a minimum password length longer than this app's own 14-character
  default. The generator now generates at least as many characters as the
  instance's configured policy requires. Thanks
  [@michel-thomas](https://github.com/michel-thomas)
  ([#9](https://github.com/kreotropic/share_audit/issues/9)).

### Documentation
- Documented a known ARM64 + PHP JIT segfault (opcache tracing JIT) some
  users hit on enabling the app, with the `opcache.jit=0` mitigation. This
  is a PHP/Zend JIT compiler issue on its ARM64 backend, not an app bug —
  see [#3](https://github.com/kreotropic/share_audit/issues/3).

## [0.4.0]

### Added
- **Soft delete (recycle bin) for shares.** A revoked share — whether
  revoked through this app or unshared through native Nextcloud (Files app,
  another app, `occ`, the sharing OCS API) — is now kept for a configurable
  retention window (default 30 days, `Settings` → Recycle bin) before being
  permanently purged, instead of disappearing immediately and irreversibly.
  New "Deleted shares" tab: restore an entry (recreates the share, and best-
  effort preserves the original public-link URL and password) or delete it
  permanently, individually or in bulk. A daily background job purges
  expired entries. This is the app's first database migration.
- **Nextcloud 34 support** (`max-version` raised from 33 to 34).

### Fixed
- **Sort order is now deterministic across MySQL/MariaDB and PostgreSQL.**
  MySQL sorts `NULL` before every value and PostgreSQL after it, so sorting
  the shares table by path, recipient or expiration could return the same
  rows in a different order on each engine — or, combined with a `LIMIT`
  (top sharers, recipient autocomplete), a genuinely different *set* of
  rows, since an unbroken tie at the cutoff was decided arbitrarily per
  engine. Nullable sort columns now get an explicit "nulls last" tiebreaker,
  and every grouped query paired with a `LIMIT` has a deterministic
  secondary sort key. Verified by running an identical fixture against both
  engines and diffing every read path (`build/README.md`).

## [0.3.0] - 2026-07-15

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
  stay an admin-only concern.
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
- Several UI strings introduced alongside the above were missing from
  `l10n/*.json`, so pt_PT users saw English text on the newest features.
- The "with expiration" / "without expiration" filter (All shares column
  filter, and the underlying flag used by exports) now treats an
  already-expired date as "without expiration" instead of counting it as
  still protected.
- The "My shares audit" personal settings page was capped at `max-width:
  1000px` (unlike the admin view), forcing an unnecessary horizontal scroll
  on wide viewports; it now uses the full width. Its Recipient column also
  never read `recipientDisplayName` from the API response, always showing
  the raw uid/UUID instead of the person's name.
- The personal view's nav link stayed visible with a "disabled by your
  administrator" notice when an admin turned the feature off, instead of
  disappearing entirely — `PersonalSettings::getSection()` now returns
  `null` in that case, per `ISettings::getSection()`'s own contract.

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
