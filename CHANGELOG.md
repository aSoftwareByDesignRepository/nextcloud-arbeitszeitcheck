# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.7.2 - 2026-09-10

### Fixed
- **DutyRotationSollProvider DI cycle** that returned HTTP 503 for Nextcloud.
- **Kiosk sessions:** claim TTL and PIN lockout under contention; used-session handling after TTL; pairing TTL copy aligned to 10 minutes.
- Live session uniqueness migration and Atlas residual coverage hardening.

## 1.7.1 - 2026-09-04

### Fixed
- Non-EN store summaries no longer claim GDPR/RGPD “checks” (aligned with EN/DE).
- **Absence report row order:** `ReportingService::generateAbsenceReport` defaults to chronological (`sort=date`); payroll type-order is opt-in via `sort=type` (report API + CSV export). Preview UI requests `sort=type` for absences.
- **Manual time entry help:** date/start/end guidance is always visible again (night-shift hint not hidden behind Tip).
- **Saldo PDF:** `UserRateLimit` on `overtimeBalancePdf` (30/min) to limit PDF generation abuse.
- **Mobile rest copy:** English fallback parser accepts Slice C Pause/split guidance suffix.
- ArbZG internal analysis path clarified as monorepo docs under `nextcloud-dev/documentation/arbeitszeitcheck/` (not shipped inside the app package).

### Changed
- Docs: `nextcloud-dev/documentation/arbeitszeitcheck/internal/ArbZG-Compliance-Analyse.md` §5 (stamp + manual share `isIntradayWorkInterruption`).

## 1.7.0 - 2026-09-04

### Changed
- **Stamp-path geteilte Arbeitszeit (ArbZG §5):** live clock-in now uses the same safe `isIntradayWorkInterruption` rule as manual entries — morning Gehen and afternoon Kommen on the **same storage calendar day** are allowed when the previous block did not cross midnight. Overnight Wachdienst (e.g. 22:00→06:00 then Kommen at 10:00) still requires elapsed rest. Rest-block messages tell users to use **Pause** for short mid-session breaks. Docs (monorepo, not in app zip): `nextcloud-dev/documentation/arbeitszeitcheck/internal/ArbZG-Compliance-Analyse.md` §3; employee manuals (DE/EN).

### Fixed
- **Authenticated Outlook iCal session feed** (`authenticatedFeed`) now has `#[NoAdminRequired]` so managers / delegated app admins are not blocked by Nextcloud system-admin middleware.
- **AppAccessMiddleware** skips `PublicPage` routes so restricted logged-in sessions cannot break kiosk / health / tokenized calendar feeds.
- **Mobile seat assignment** uses an exclusive capacity lock (parity with terminal device slots) to prevent concurrent over-assignment past the license limit.

- **Delegated app administrators** can open Administration (and related admin APIs: license, kiosk, overtime payouts). Controllers now use `#[NoAdminRequired]` with `AppAdminMiddleware` enforcing `PermissionService::isAdmin()` instead of Nextcloud system-admin middleware.
- Access-denied pages for missing app-admin rights use the ArbeitszeitCheck access-denied template (clear message + next step) instead of the generic core 403.
- Footer / legacy header admin links follow `PermissionService::isAdmin()` (same as the main nav).
- Delegated app admins cannot clear the last app-admin seat (including removing themselves with an empty list).
- Disabled accounts on the app-admin list no longer retain app-admin powers.

### Security
- Defense in depth: overtime payout admin actions still assert app-admin after middleware; employee `myHistory` remains excluded from the admin gate.

### Changed
- Nextcloud **35** support (`max-version` 35). Symfony Console 7–ready command signatures verified.
- Migration `Version1009…` column type change uses OCP `Types` with an NC32–35 compatible `setType` shim.

## 1.6.14 - 2026-08-24

### Changed

- **Nextcloud:** `max-version` remains **34** (current stable **34.0.3**).

### Fixed

- **Break gate uses calendar-day net hours:** ArbZG §4 / AZG §11 / ArG Art. 15 now look at the peak calendar-day total (and countable breaks already recorded that day), not only the row being saved. Two 4-hour blocks on the same day can no longer skip the 30-minute break. Auto-break in the form uses net working time (span minus entered breaks), so 08:00–14:20 plus 20 minutes of break is not padded up to 30.

## 1.6.13 - 2026-08-24

### Changed

- **Nextcloud:** `max-version` remains **34** (current stable **34.0.3**).

### Fixed

- **Break requirement (ArbZG §4 / AZG §11 / ArG Art. 15):** the 30-minute break applies only after **more than** six hours of work, not at exactly six hours. A shift such as 08:00–14:00 is no longer blocked. The same exclusive threshold applies at the higher bands (DE 9 h → 45 min only after more than nine hours; CH 5.5 / 7 / 9 h).
- **Reports page:** DATEV/admin controls no longer follow `$isAdmin` after the navigation partial overwrites it, so admins still see the DATEV section.

## 1.6.12 - 2026-08-20

### Added

- **Outlook iCal subscriptions (admin):** privacy-safe team absence feeds for Outlook and other calendar clients. One subscription link per **scope + calendar language** (team or org-wide); separate **Create** and **Rotate** actions; encrypted token storage with recoverable admin URLs; responsive design-system table with copy/rotate per row, loading state, and stale-request guards.

### Changed

- **Outlook iCal admin UX:** card grid replaced with an accessible table (scope, language, approved absences, rolling window, subscription URL, actions); legacy pre-migration tokens show a rotate notice until renewed once.
- **l10n:** Outlook subscription strings translated across all shipped locales (en, de, fr, es, da, nl, it, pl, sv, nb, pt_BR).
- **Nextcloud:** `max-version` remains **34** (current stable **34.0.3**).

### Fixed

- **Outlook iCal create API:** admin UI now calls the canonical create route (`/api/admin/outlook-ical/create`) so new links are created reliably (no spurious 404).

### Security

- **Outlook iCal tokens:** subscription secrets encrypted at rest (`token_encrypted`); public feed auth remains hash-only; unique constraint on `(tenant_id, team_id, feed_language_code)` prevents duplicate active links per scope/language.

## 1.6.9 - 2026-08-19

### Fixed

- **Clock-in / correction API error codes:** return stable, documented error codes on clock-in and correction endpoints so companions and integrations can distinguish transient from permanent failures.

### Changed

- **l10n glossary alignment:** corrected wrong translations (including French "Setup & training") and standardised button labels across all shipped locales to match the cross-app glossary.
- **Help, Support & Us / Get the App:** WCAG 2.1 AA hardening and translated footer copy for all website footer locales.
- **CI:** collapsed GitHub Actions to a single PHP syntax-only smoke job; full test suites stay local.
- **Nextcloud:** `max-version` remains **34** (current stable **34.0.3**).

## 1.6.8 - 2026-08-12

### Added

- **Half-day vacation in days mode:** employees and managers can book a single calendar day as half vacation (`day_fraction=0.5`) without switching the organisation to hours mode. Server computes the debit (never trusts client `days`); allocation honors trusted stored `0.5`; multi-day half requests are rejected; mail/UI show fractional days; one-click “Half day today” / next-workday shortcut with WCAG 2.1 AA length radios.
- **Admin employee list access filter:** filter the employees page by “Can open ArbeitszeitCheck” vs “All Nextcloud accounts” (default follows access restriction). Server-enforced via `AdminEmployeeDirectoryService`; picker mode unchanged. Filter panel, hidden-count banner, truncated scan banner, CSV export with filter suffix, WCAG 2.1 AA UI, unit/integration/E2E and mutation coverage.
- **Brazilian Portuguese (pt_BR):** UI catalog (`l10n/pt_BR.json` / `pt_BR.js`) for store and in-app locale coverage.

### Fixed

- **Admin notifications save under lock contention:** vacation year-mode / policy saves retry briefly when the DB lock is busy (`aria-busy` + live-region “still busy” feedback) instead of failing the first contested click.
- **Admin policy / vacation year-mode UX:** clearer busy behaviour, focus-safe controls, and design-system CSS contracts on notifications and related admin surfaces.

## 1.6.7 - 2026-08-12

### Fixed

- **Opaque “400” overlay on clock-in (mobile web):** non-JSON / HTML error bodies from reverse proxies or gateways are no longer injected into toasts via `innerHTML`. Toasts escape all title/message text; `AzcApi.mapApiError` and dashboard `callApi` replace HTML, bare status codes, and guest-box noise with a localized “HTTP %s — reload” message. `callApi` always sends `Accept: application/json`.
- **Manual Ruhezeit after overnight (ArbZG §5):** `checkRestPeriodForStartTime` no longer treats “same calendar day as end” as a blanket exemption. Intraday geteilte Arbeitszeit still skips rest only when the previous block started and ended on that same day; Wachdienst that crossed midnight (e.g. 22:00→06:00) still requires elapsed rest before a morning start.
- **Stamp-path Ruhezeit:** live clock-in keeps elapsed-only rest (no calendar-day shortcut) so night-shift ends after midnight cannot clear the 11h gate. Mid-session interruptions remain Pause, not Gehen→Kommen.
- **Paused resume + different ProjectCheck project:** completes the paused row at its pause instant (keeps original project billing) then starts a fresh session — no overwrite of earlier hours onto another project.
- **ProjectCheck attach vs picker:** `userMayAttachProjectCheckProjectToOwnTime` prefers ProjectCheck’s `canUserAddTimeEntryForProject` so the attach gate matches the picker.
- **Dashboard desklet/widget errors:** unexpected failures return HTTP 500 with a safe message (not opaque 400 “Action failed”); unauthenticated actions return 401; `getSummary` failures no longer abort a successful stamp.
- **Version metadata:** `appinfo/version` was still `1.6.5` while `info.xml` said `1.6.6` — aligned for 1.6.7.

### Changed

- **Dashboard project help:** clarifies ArbeitszeitCheck = attendance (one active session); multi-project billing belongs in ProjectCheck — do not use out→in as a project switcher.

## 1.6.6 - 2026-08-08

### Fixed

- **Manager employee absence create (NC 34):** `ManagerController::createEmployeeAbsence` no longer calls protected `Request::getContent()` (fatal Error on every manager sick/vacation record). Reads the JSON body via public `IRequest::getParams()`, matching `createEmployeeTimeEntry` and the employee absence path.

## 1.6.5 - 2026-08-04

### Fixed

- **Admin notifications layout (HTTP 500):** unescaped `%` in premium help copy (`50% = 0.50`) crashed L10N vsprintf → guest/error shell (`body-login` + `guest.css`) centered the sidebar and checkbox labels. Literal percents use `%%`; JS placeholder strings go through `TemplateL10n`.
- **Admin notifications interaction jump:** `initVacationUnitMigration` referenced `l10n` outside `init()` (`ReferenceError`) and soft-keyboard reveal scrolled checkboxes/radios into a visualViewport feedback loop — clicking controls no longer throws or yanks the page.
- **Focus/select page jumps (global):** soft-keyboard helper never scrolls for selects, date/time, or desktop focus; only reveals typed fields when the keyboard has shrunk the visual viewport; jump nav stays in document flow (no sticky chrome) on all admin settings pages.
- **Notifications page clarity:** six card-level jump pills with matching card titles (Absences & vacation, Calendar & email, Overtime alerts/bank, Hour premiums, HR office) and a one-line lead — same words in nav and headings.
- **Premium categories table UX:** fixed column widths, left-aligned category labels, proper checkbox touch targets, and `%` beside the percent field (no more stacked/centered mess).
- **BANSS vacation-unit migrate crash window:** pending flag + committed audit detection finishes the IConfig unit flip if the process dies after DB commit (never leaves hour magnitudes labeled as days). Heal also runs on every `getUnit()` read path (not only Apply).
- **DATEV premiums vs closed months:** sealed months always export frozen month-closure premium buckets (NN-06), including mid-month ranges; half-open exclusive end from ExportController no longer bleeds +1 day into the next month.
- **Anniversary mode switch:** calendar→anniversary requires explicit missing-hire acknowledgement when employment starts are missing (`VAC_YEAR_MISSING_HIRE_ACK_REQUIRED`).
- **Vacation mutations during unit migrate:** create/update/shorten/approve/cancel vacation refuse with `VAC_UNIT_MIGRATE_IN_PROGRESS` while the migration lock or pending crash-window flag is set.
- **Month-close premium seal:** policy load + classification share one exclusive `azc/pp/policy` lock with admin policy save so sealed snapshots cannot mix versions.
- **BANSS migration UX:** visible 7.7 callout + “Use 7.7” control; confirm dialogs show the conversion factor.
- **Employee app hours preview:** debit preview is hours-only (no holiday-blind weekday estimate).
- **Anniversary approval lock:** `FOR UPDATE` scope follows hire-anniversary windows (not blind calendar Jan–Dec).
- **Vacation year mode flip:** refreshes open allocations for access-allowed users (AC-101.4); audit records hire-ack count + refresh count.
- **Hours-mode rollover:** `VacationRolloverService` receives `VacationUnitService` so carryover writes keep `carryover_hours` (no silent clear-to-days).
- **Migrate-idle on balance CLI/rollover:** import + automatic rollover refuse while unit migration is in progress.
- **Migrate-idle TOCTOU:** writers hold `LOCK_SHARED` on `azc/vu/migrate` for the whole critical section (`withIdleShared` / absence shared hold); migrate still takes exclusive.
- **Year-mode flip race:** exclusive `azc/vy/mode` + migrate-idle shared around config write + AC-101.4 refresh; reports `allocationsFailedCount`; refuses during unit migrate.
- **Bachus admin UX:** vacation year/unit as giant choice cards; long help + FIFO/overlap/DATEV collapsed under “More options”; short first-paint copy.
- **Premium policy busy:** concurrent admin save returns HTTP 409 `PREMIUM_POLICY_BUSY` (injectable `ILockingProvider` for deterministic tests).
- **Premium `tagged_multi`:** no longer sums money into `total_valued_hours`; unique wall-clock `total_classified_hours`; DST night-window coverage.
- **Mobile clock-in (Kommen):** mutating API calls now always send a JSON body with `Content-Type: application/json`, so reverse proxies/WAFs no longer return opaque HTTP 400 without a message. Error envelopes include stable `error_code` values; mobile surfaces server messages (or localized fallbacks) instead of English “Request failed (400)”.
- **Web / desklet clock actions:** clock-out, break start/end, daily-max enforcement, and dashboard desklet POSTs now always send a JSON body (same proxy-safe contract as mobile). Desklet and widget errors forward business-rule messages and `error_code` instead of a generic “Action failed”.
- **Desklet project selection:** when ProjectCheck linking is enabled, the dashboard desklet shows an accessible project picker before Kommen and posts `projectCheckProjectId` with clock-in; daily-maximum callout hides clock-in until the next day. When ProjectCheck is installed but linking is off, the desklet shows a clear neutral callout (same guidance as the full dashboard / mobile home).
- **Desklet template vars:** workspace partial reads `$_['deskletConfig']` / `$_['l']` (OCP\Template assign), so project options and JSON config actually reach the rendered HTML.
- **Utils.ajax empty POST:** bare mutating calls via `ArbeitszeitCheckUtils.ajax` now send `JSON.stringify({})` with `Content-Type: application/json` (no Content-Type-without-body).
- **Admin kiosk/license/tariff/holidays DELETE/POST:** shared `normalizeMutatingFetchInit` forces a JSON body whenever Content-Type claims JSON (or when the body is missing on mutating verbs), closing the same proxy-400 class on revoke/clear-license/holiday-delete paths.
- **Mobile project selection:** home dashboard and manual time-entry expose ProjectCheck assignable projects when admin linking is enabled; accessible project picker before Kommen/Weiterarbeiten and on create-entry.
- **Today’s hours:** `getTodayHours()` no longer clamps to the daily maximum, so UI and error copy report real worked hours.
- **Lock conflicts:** HTTP 423 responses include `error_code=locked` and a duplicated `message` field for clients.

### Added

- Companion contract coverage: T-MOB-02 additive-key assertions; Kiosk 1.0.6 version alignment (Employee 1.0.4 unit-aware remains publish-ready off-host).
- Mobile home payload fields `atDailyMaximum` and `projectCheck` for companion clients; callouts when daily max is reached or ProjectCheck linking is installed but disabled.
- Dashboard widget `clockIn` accepts optional `projectCheckProjectId`.
- Desklet config exposes `projectCheck` projects and l10n for the project picker / daily-maximum notice.

## 1.6.4 - 2026-08-02

### Added

- **Portfolio access door:** Administration can switch between Open (everyone with app access) and Restricted mode with explicit user and group allowlists; deleted users are removed from the allowlist automatically.

### Changed

- **l10n:** Expanded EN/DE catalogs (DACH holiday regions, clearer admin and report copy) and refreshed secondary locales.

## 1.6.3 - 2026-07-31

### Added

- **Companion API floor:** OCS capabilities now expose `arbeitszeitcheck.companion.min` (`1`) so DutyCheck / AudioCheck mobile clients can fail closed with `app_outdated` when the server companion contract is missing or newer than the installed app.

## 1.6.2 - 2026-07-27

### Fixed

- **Austrian break-split UX (web):** the time-entry form and correction dialog no longer show “Compliant” for illegal AZG §11 splits that still sum to 30 minutes (e.g. 20+10). Client validation mirrors `BreakSplitValidator`; the server remains authoritative. Bootstrap `complianceParams` now includes `allowedBreakSplitPatterns` like the mobile capabilities block.
- **Admin settings region consistency:** country→region repair uses values written in the same request and skips no-op POSTs (empty body still returns 400), so unit mocks and concurrent saves cannot wipe a just-saved region.
- **Compliance PDF export honesty:** `format=pdf` for compliance reports returns HTTP 422 with a clear error (same as time-entry/absence exports) instead of silently downloading CSV.
- **Capabilities accessibility claim:** advertise WCAG **AA** (matches product/E2E target), not AAA.
- **License trust anchor:** `AZC_VENDOR_PUBLIC_KEY_B64` env override is gated to PHPUnit / explicit `AZC_ALLOW_VENDOR_KEY_OVERRIDE=1` so a compromised environment cannot swap the vendor public key on a live instance.
- **Cross-product license rejection:** AZC2 payloads that carry a foreign `product` marker (e.g. deskcheck) are rejected even if the wire prefix and signature verify.

### Added

- Vitest coverage for client break-split evaluation; PHPUnit contract that AT web bootstrap exposes split patterns; suite legacy-isolation unit + integration guards.

## 1.6.1 - 2026-07-25

### Fixed

- **Support & us layout:** the Administration page now uses the full content width with a two-column hero (copy + trust panel), a full-bleed Check Partner spotlight, and titled option cards (setup, commissioned feature, mobile & terminal). Cache-bust via app version so `immutable` CSS refreshes in browsers.

## 1.6.0 - 2026-07-24

### Fixed

- **Sidebar submenu hierarchy:** Administration/Manager child labels now nest past the parent text column. Two cascade bugs: (1) `#app-navigation .nav-menu a { padding }` wiped submenu indent; (2) `.nav-menu span { flex: 1 }` stretched icon spans (~100px) so labels started mid-sidebar and children looked left-aligned with icons. Icons are fixed-size; indent uses shared tokens + a non-colour nest rail.
- **Swiss 5.5 h break message:** compliance violations for the ArG Art. 15 half-hour threshold no longer truncate to “after 5 hours” (`%2$d` / `(int)` cast). Hours labels are shared via `LaborLawProfile::formatHoursLabel()`.
- **Admin DACH help copy:** daily-max / rest / compliance help texts mention Switzerland (ArG) alongside Germany and Austria.

### Added

- **Austria (DACH phase 1):** the instance can now be configured for Austria. New "Country and region" card in the admin settings (accessible radio cards for Germany/Austria with a country-filtered region picker), all nine Austrian Bundesländer as holiday regions (`AT-B` … `AT-W`), and a complete Austrian statutory holiday catalog (13 nationwide days per ARG §7, Easter-linked days included).
- **Switzerland (DACH phase 2):** Switzerland is a first-class country profile. All 26 cantons (`CH-AG` … `CH-ZH`), Swiss Labour Act (ArG) break tiers (5.5→15 / 7→30 / 9→60), configurable absolute weekly maximum of **45 or 50 hours** (ArG Art. 9), and canton-specific statutory calendars including Geneva Jeûne genevois and Vaud Lundi du Jeûne. Zurich Sechseläuten and Knabenschiessen seed as **half-day** statutory holidays (E-6).
- **Austrian working-time profile:** compliance and time tracking are now driven by a per-country labor-law profile. Austria: 12 h daily / 60 h weekly absolute maximum and 48 h average over 17 weeks (AZG §9), 30-minute break after 6 h (AZG §11) with statutory split patterns continuous ≥30 / 2×15 / 3×10, 11 h daily rest (AZG §12), night window 22:00–05:00 (AZG §12b), weekend/holiday rest per ARG §3. Germany keeps the exact previous ArbZG behaviour (guarded by golden snapshot tests).
- **Per-user labour-law country (E-9):** admins can optionally set `labor_law_country` per employee so working-time rules follow DE/AT/CH independently of the organisation country (cross-border). Holidays stay on the employee's holiday region; empty override follows the organisation.
- **Holiday suggestions:** the holidays admin page now offers curated non-statutory suggestions per region (Good Friday, patron saint days, 24/31 December for Austria) with one-click add as company holidays; days that already exist are marked. Good Friday ships with an explanatory note (statutory status ended 2019, ARG §7a).
- **Per-user regions across the border:** employees may be assigned a region of any supported country (cross-border commuters); the UI groups regions by country and shows a clear note when a user's region differs from the instance country.
- **Mobile capabilities:** the capabilities/bootstrap payload now includes a `compliance` block (country, limits, break tiers, law labels, weekly absolute max) so mobile clients can render correct legal hints; the app version in capabilities is no longer hard-coded.
- **Employee mobile app:** bootstrap `compliance` params drive break-rule copy and minimum break length, with German ArbZG defaults when talking to older servers.

### Changed

- **Country switch confirm (E-8):** changing the organisation country (including AT/CH → DE) requires an explicit confirmation; holiday default region resets when it no longer belongs to the new country; explicit max daily/rest limits are kept; other countries' holiday calendars remain in the database.
- **Country-aware personal settings & chrome:** compliance information, footer, and admin help texts follow the effective labour-law country for the signed-in user (ArbZG vs AZG/ARG vs ArG) instead of always citing German law.
- **UI family alignment (Phase 0b):** flatter sidebar chrome (no gradient/accent bar), server-side `IconCatalog` nav icons with brand subtitle/role badge, dialogs use `--azc-radius-lg` / `--azc-shadow-md`, toasts sit bottom-right with MC-style left accent and correct `role="status"` vs `role="alert"`.
- **Toasts & navigation:** success/info/warning toasts use `role="status"` (errors stay `role="alert"`); sidebar active state prefers `pageId` over URI matching for fewer false positives.
- **Time entries table (#12):** the Actions column stays sticky on desktop while other columns scroll horizontally, so Edit/Delete remain reachable on wide screens.
- **Break reminders & dashboard urgency:** break reminder job and break warning levels use the effective country profile tiers for that user (no more hard-coded 9 h → 45 min path on Austrian profiles).
- **Web time-entry form:** auto-break calculation, requirement indicator, and compliance status read break tiers from the same profile bootstrap as mobile (`complianceParams`), so Austrian instances no longer show or enforce a phantom 45-minute ArbZG tier in the browser.
- **Admin wording:** employee holiday picker and section guides say “region” instead of “federal state” (DACH-neutral).

### Fixed

- **Manager/correction AZG break gate:** illegal Austrian break splits (e.g. 20+10) are now rejected on manager create/correct and correction approve/**auto-approve** via the same `blockingIssuesForCompletedEntry` fail-closed gate as employee portal saves (`autoApprove` re-validates defensively even if a caller skipped the pre-check).
- **Edit/correction break floor:** AT 10-minute portions no longer disappear on edit hydration or client validation; clock-form, correction UI, and manager create use the profile/`minBreakMinutes` floor (including per-employee E-9).
- **E-8 confirm UX:** organisation country changes never fall back to `window.confirm`; missing `confirmDialog` fails closed. Remaining admin confirmations (unsaved leave, seat removal, kiosk revoke/PIN) use accessible `confirmDestructiveAction`.
- **Duplicate statutory holidays (race):** the unique index on `at_holidays` (state, date, scope) is verified during the upgrade (and created for databases restored from old backups); a redundant duplicate index from pre-release 1.6.0 builds is dropped. Creating a holiday on an occupied slot answers a friendly 409 instead of a database error; a lost race after the pre-check also maps unique-constraint failures to HTTP 409.
- **Legacy holiday opt-outs preserved:** the deprecated `holidays_initialized_state_years` flag is converted to per-date suppressions during the upgrade, so years that were emptied before the suppression table existed stay empty, and the flag is retired.
- **Region lists unified:** seven previously duplicated (and partially drifted) Bundesland lists were replaced by a single region registry used by services, controllers, commands, and templates.
- **Unset max daily hours default:** when `max_daily_hours` is not configured, admin/settings/forms fall back to the country profile (DE 10 / AT 12) instead of always assuming 10. Explicit admin values are never overwritten on country switch (E-4).
- **Compliance dashboard lead:** page description follows the configured country (Austrian labour law vs German labor law).
- **E-6 kind self-heal:** generated statutory rows whose catalog kind changed (e.g. Zurich Sechseläuten stored as full) are corrected on the next seed reconcile; manual statutory edits are left alone.
- **Capabilities CH feature tag:** Switzerland reports `swiss-arg-compliance` (not `arg-compliance`) to avoid colliding with Austrian ARG naming.
- **Country-aware clock-out / approaching copy:** dashboard timer messages remap legacy ArbZG msgids to the active law label; CH omits averaging-window compensation claims; warning threshold is `maxDailyHours − 2`.
- **Absolute weekly caps tested:** AT 60 h / CH 45 h warn path + DE skip covered by unit + mutation gauntlet.

## 1.5.23 - 2026-07-21

### Changed

- **Kiosk pairing code TTL (temporary):** pending terminal pairing codes now remain valid for **14 days** instead of 10 minutes (`KIOSK_PAIRING_TTL_SECONDS`), so Google Play reviewers can pair the Terminal app from review notes without the code expiring before audit. Admin UI copy and all 10 locale catalogs updated accordingly. **Revert to 600 seconds (10 minutes) after the Terminal app is published.**

## 1.5.22 - 2026-07-21

### Changed

- **Kiosk stamp errors are specific instead of generic:** business-rule rejections during clock in/out and break start/end now return stable machine codes (`KIOSK_ALREADY_CLOCKED_IN`, `KIOSK_NOT_CLOCKED_IN`, `KIOSK_ON_BREAK_END_FIRST`, `KIOSK_BREAK_ALREADY_STARTED`, `KIOSK_NOT_ON_BREAK`, `KIOSK_DAILY_HOURS_LIMIT`, `KIOSK_REST_PERIOD_REQUIRED`, `KIOSK_ACTION_REJECTED`) instead of one opaque `KIOSK_ACTION_INVALID`, so terminals can show actionable messages.
- **Detailed compliance messages pass through:** rest-period and daily-hours rejections keep the detailed, translated server message (which shift ended when, when clock-in is possible again) instead of a generic phrase.
- **HTTP semantics:** kiosk state conflicts (already clocked in, break already started, end break first) answer 409 Conflict; compliance limits stay 400.

### Fixed

- **Kiosk error copy translated:** the new kiosk error messages ship in all 10 supported languages (en/de/fr/es/da/nl/it/pl/sv/nb) with full catalog parity.

## 1.5.21 - 2026-07-20

### Fixed

- **Stuck badge scan cannot be cancelled:** Admin “Cancel scan” no longer depends on acquiring exclusive DB locks. If locks are orphaned (crash or pre-1.5.20 truncated keys), cancel force-aborts: clears the enrollment, purges known lock rows for that employee/tablet, and returns success so a new card can be enrolled immediately.
- **Start / PIN / RFID blocked by orphan locks:** starting a scan and assigning PIN/badge first retry briefly (live changes win by waiting), then purge the stuck keys once and retry — instead of looping on “already running” until the ~1h lock TTL. Live locks are never purged on first contact, so concurrent admin actions stay serialized.
- **Double-click on “Generate PIN” could return HTTP 500:** a unique-constraint race between two concurrent PIN generates is now mapped to the retryable “busy” error instead of an unmapped server error.
- **Cancel hidden after reload:** Cancel stays available whenever a tablet is selected in Kiosk admin, not only during an in-page poll, so stuck server-side scans can always be cleared.
- **German translations broken:** `l10n/de.js` contained a syntax error (strings appended outside the catalog), which disabled the entire German catalog in the browser. All locale catalogs regenerated with full key parity across the 10 supported languages.

### Changed

- **Kiosk maintenance:** expired incomplete enrollments are garbage-collected every 15 minutes so tablets leave enrollment mode even if nobody cancelled.
- **Busy copy:** no longer tells admins to “cancel first” in a way that implies cancel itself might be impossible.

## 1.5.20 - 2026-07-20

### Fixed

- **DB lock keys truncated at 64 chars:** exclusive locks for time tracking, absences, month closure, entitlement snapshots, and kiosk PIN/RFID/enrollment now use short stable keys (`oc_file_locks.key` is VARCHAR(64)). Over-long keys were truncated on insert so `releaseLock` never matched and mutations stayed stuck with LockedException / KIOSK_BUSY until TTL.
- **Stuck lock cleanup on upgrade:** post-migration repair `ClearStuckKioskEnrollmentLocks` clears legacy over-long lock rows left behind by older builds.
- **Kiosk enrollment cancel races:** cancel uses ordered user→terminal locks with short retries, returns stable idle/completed/cancelled outcomes, and no longer deadlocks or leaves Admin on a hard busy failure while a scan finishes.

### Changed

- **Kiosk admin enrollment UX:** numbered wizard steps, clearer assign/cancel controls, and improved enrollment panel feedback during badge scan.

## 1.5.19 - 2026-07-20

### Fixed

- **Kiosk API JSON errors:** unexpected and rate-limit failures now return stable JSON machine codes so Terminal clients can show actionable messages instead of opaque failures.
- **Stamp session recovery:** one-shot kiosk session claims are released when a stamp mutation fails, so terminals can recover instead of staying stuck on a claimed session.
- **PHPUnit full suite:** fixed a fatal from a redeclared template helper so the complete test run no longer aborts mid-suite.

## 1.5.18 - 2026-07-20

### Changed

- **Full-width admin shells:** My Settings and manager month-closures use the wide page shell instead of narrow constrained layouts.
- **Audit log filters:** redesigned filter row layout (dates/actions, then user/action/entity) so fields no longer collapse into empty grid holes from shared page-pattern subgrid rules.
- **Reports export chooser:** replaced nested badge cards with a theme-safe radiogroup of full-width options (solid icon wells, Selected state, keyboard arrow navigation).
- **Admin / manager surface polish:** shared stat tiles and layout tweaks across dashboards, license, kiosk, users, and working-time models for clearer hierarchy and WCAG-friendlier contrast.

### Fixed

- **Audit log / reports fetch races:** ignore stale responses when a newer filter or export-option request is already in flight.

## 1.5.9 - 2026-07-18

### Added

- **Dedicated admin user detail page:** user editing moved off the users list onto its own detail view (route, template, and admin JS), with clearer navigation from the users overview.

### Changed

- **Kiosk admin enrollment UX:** improved enrollment polling, status feedback, and kiosk-facing copy during badge/PIN assignment.
- **Kiosk error messaging:** clearer, actionable messages for common enrollment and credential failures.

### Fixed

- **Kiosk identify/assign races:** identify and assign flows now take enrollment locks so concurrent admin/kiosk actions cannot corrupt in-flight enrollment state.
- **Credential assignment under load:** kiosk credential updates are serialized with enrollment so badge/PIN changes stay consistent with the active session.

## 1.5.8 - 2026-07-18

### Changed

- **Mobile / widget home payload:** `impliedDailyHours` (contract daily target) is included alongside weekly hours so clients can show “Daily target” like the employee dashboard.


- **Session CSRF restored for browser cookie sessions on NoCSRFRequired APIs:** mutating clock/absence/export endpoints that stay `NoCSRFRequired` for mobile Basic auth now re-require a valid request token whenever a Nextcloud session cookie is present. Mobile clients without session cookies are unchanged. Forged `Authorization: Basic …` headers can no longer bypass CSRF while the victim’s session cookie is sent.

### Fixed

- **Absence cancel/delete/shorten race with approve:** those mutations now take the same per-user absence lock and re-read status under the lock.
- **Status-path stale-pause repair / daily-max auto-complete:** side effects run under the time-tracking user lock without nesting an outer DB transaction around a second lock acquisition.
- **Manual time entry create/update overlap races:** overlap check + insert/update share the time-tracking user lock with clock mutations.
- **Month finalize/reopen TOCTOU:** exclusive per-user/month lock around seal and reopen; calendar “today” / month-ended checks use organisation storage timezone.
- **Compliance cron / weekly average windows:** use storage timezone instead of PHP default/`new DateTime()`.
- **Absence business-day gates:** “today” for cancel/shorten/substitute rules and vacation allocation reads use storage timezone.
- **Dashboard load-error callout:** keeps `aria-live="assertive"` for screen readers; clock/break buttons guard double-submit client-side.

## 1.5.7 - 2026-07-18

### Added

- **Auto break setting exposed to clients:** the dashboard widget data (and the mobile home dashboard payload built on it) now includes `autoBreakCalculation`, the personal "automatic ArbZG §4 break insertion" setting, so client apps can explain whether the server may insert breaks automatically.

### Fixed

- **ArbZG §5 clock-in rest check no longer poisoned by future end times:** rest anchors on the latest completed `end_time` strictly before storage "now", so a manual/planned row ending later today (e.g. 17:00 while it is still morning) cannot block stamping or claim “in 16h until 04:00”. Messages now include the calendar date of last end and earliest clock-in. Daily “hours today” clips completed work to the evaluation instant so future tails are not counted early.

## 1.5.6 - 2026-07-18

### Added

- **Mobile home dashboard endpoint:** new `GET /api/mobile/dashboard` returns the full employee home payload (status, vacation entitlement/remaining, week hours, balance) for the proprietary employee mobile app, instead of the lean desklet summary.

### Changed

- **Time-tracking status endpoint usable from the mobile app:** `GET` status no longer requires a CSRF token, matching the other read-only mobile endpoints (still requires an authenticated session or app password).

## 1.5.5 - 2026-07-18

### Changed

- **Vendor public key baked into the app:** AZC2 license verification now uses the production Ed25519 vendor public key by default. Customer servers no longer need `AZC_VENDOR_PUBLIC_KEY_B64` for real licenses from SbdLicenseOps to verify. The env var remains an optional override (tests / experiments only).
- **Clearer signature-mismatch warning:** the admin license page no longer tells admins to configure `AZC_VENDOR_PUBLIC_KEY_B64` as the primary fix.

## 1.5.4 - 2026-07-17

### Changed

- **Kiosk admin UX overhaul:** clearer three-step flow (find employee → allow access → assign badge/PIN), richer status labels, confirmations, and actionable error messages (including license/slot limits with a link to License administration).

### Fixed

- **Kiosk admin APIs required CSRF again:** removed `NoCSRFRequired` from admin JSON endpoints so state-changing kiosk actions are protected like other admin APIs.
- **Race conditions in kiosk pairing, enrollment, and PIN lockout:** exclusive locks around terminal pairing, badge enrollment start/scan, and failed-PIN accounting so concurrent requests cannot double-consume license slots or skip lockout.
- **Badge enrollment only on active terminals:** enrollment now requires a paired (active) terminal instead of accepting pending/revoked ones.
- **CSV credential import size limit:** imports larger than 1 MB are rejected with a clear error instead of loading unbounded input.
- **Sensitive kiosk responses are not cached:** pairing codes and one-time PINs are returned with `Cache-Control: no-store`.

## 1.5.3 - 2026-07-15

### Added

- **Instance-bound AZC2 licenses:** license keys can now carry the Nextcloud instance ID and are rejected when applied on a different instance. The stored license state records the bound instance ID and a fingerprint of the applied key (migration 1033). The admin license page shows the instance ID to include when ordering a license.
- **Daily license maintenance job:** a new background job re-applies license limits once a day, so expired licenses or reduced seat/terminal quotas take effect overnight instead of only on the next admin interaction.
- **Signature-verification warning in admin UI:** if a stored license cannot be verified with the configured vendor public key, the admin page now shows a clear warning callout explaining the mismatch and how to fix it.

### Changed

- **Mobile license gate covers the whole mobile API:** previously only clock/break POST endpoints returned HTTP 402 without a valid mobile license; now all `/api/*` mobile requests are gated (bootstrap, kiosk, and admin endpoints excluded), with an explicit `LICENSE_REQUIRED` error code for clients.
- **Admin license page overhaul:** redesigned layout with seat/terminal usage bars, assignment timestamps, and direct purchase/renewal contact actions.

### Fixed

- **Kiosk terminal pairing could leak device slots:** creating or activating a terminal now reserves the licensed device slot transactionally — if pairing fails midway, the reserved slot is revoked instead of staying occupied.

## 1.5.2 - 2026-07-11

### Added

- **Pre-migration backup before app updates:** new `BackupBeforeUpdate` repair step runs automatically before schema migrations, plus `occ arbeitszeitcheck:upgrade-backup` for manual exports. Upgrade backups are catalogued with integrity checks and covered by unit/integration tests.

### Fixed

- **Manager "Record time for an employee" failed with "Status is required"** ([#27](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/27)): shipped in this build (documented for 1.5.1 but missing from the published tarball). Manager-recorded inserts now set `status` and audit `justification` before validation runs.
- **Data loss after disable during Nextcloud upgrade:** `UninstallRepairFlow` now distinguishes disable from explicit removal — tables and settings are preserved on disable (including auto-disable during server upgrades); full cleanup runs only on app removal.

## 1.5.1 - 2026-06-18

### Fixed

- **Manager "Record time for an employee" failed with "Status is required"** ([#27](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/27)): `createManagerRecordedEntry()` validated a fresh `TimeEntry` before assigning `status` and audit `justification`, so every save was rejected even when date, times, and reason were valid. Validation now infers the completed shape for proposal previews, and manager-recorded inserts set status and justification before validation runs.
- **Update to 1.5.0 threw the whole instance into maintenance mode** ([#24](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/24)): the 1.5.0 pro-rata feature added a 9th constructor dependency (`VacationProrationService`) to `VacationAllocationService`, but the published build's container factory in `Application.php` still passed only 8 arguments. During `occ upgrade` Nextcloud resolves repair steps through the container, which transitively builds `VacationAllocationService`, so the missing argument raised an `ArgumentCountError` and aborted the upgrade — leaving the server in maintenance mode until the app was disabled. The factory now passes all 9 dependencies.
- **PHPUnit segfault in full unit suite** (~test 583): `DashboardDeskletRenderServiceTest` instantiated legacy `\OCP\Template` after hundreds of prior tests, triggering PHP segfault from polluted globals. Template rendering moved to `DashboardDeskletWorkspaceRenderer`; the unit test mocks it, and a dedicated integration test covers real template output.

### Changed

- **Hardened the dependency-injection safety net so this class of upgrade crash cannot ship again.** This was the second `ArgumentCountError` upgrade incident (after 1.4.2); the previous guard only validated the repair-step classes literally listed in `info.xml`, so a *transitive* dependency like `VacationAllocationService` slipped through. The release gate (`scripts/check-repair-step-di.php`) had an additional latent bug — a namespace path-resolution error meant its argument-count check silently never ran. The gate has been rewritten to:
  - correctly resolve every app class to its source file, and
  - validate the constructor arity of **every** service registered in `Application.php` (not just repair steps), using the PHP tokenizer so multi-line factories and promoted constructor properties are handled exactly.
  - Two new automated guards back it up: `ServiceContainerDiRegistrationTest` (static, reflection-based, every registered service) and `ContainerServiceResolutionIntegrationTest`, which resolves all registered services through the real Nextcloud container — the exact path that fails during an upgrade.

## 1.5.0 - 2026-06-16

### Added

- **Pro-rata vacation for partial employment years** ([#23](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/23)): employees who join or leave mid-year now have their annual vacation entitlement reduced to the part of the year actually worked, instead of always receiving the full year's days.
  - New optional **employment start date (Eintrittsdatum)** and **employment end date (Austrittsdatum)** per employee under *Employees → Edit*. Both empty means no proration (full entitlement, unchanged historic behaviour).
  - New global admin setting **Pro-rata vacation → Proration method**: *Full months (Zwölftelung, German default)* — 1/12 per calendar month touched by the employment, with a fraction of half a day or more rounded up (BUrlG §5); or *Exact days* — annual entitlement × covered days ÷ days in year.
  - The admin entitlement preview and the employee-facing "How is my vacation entitlement computed?" explainer both show the reduced figure with a clear note, so the displayed number always matches the usable balance.
  - Proration is recorded in the entitlement trace and historical snapshots for audit purposes.

## 1.4.2 - 2026-06-12

### Fixed

- **Broken app upgrade (1.3.x → 1.4.x):** `EnsureArbeitszeitCheckSchema` was registered in `Application.php` with only `IDBConnection` while the repair step also requires `IConfig`, causing `ArgumentCountError` during `occ upgrade` / web updater and leaving the app stuck on “needs upgrade” with missing navigation routes. All repair steps from `info.xml` are now explicitly registered in the container; **`RepairStepDiRegistrationTest`** and extended **`UpgradeRepairIntegrationTest`** guard against recurrence.

## 1.4.1 - 2026-06-12

### Fixed

- **Data loss after Nextcloud upgrade:** `UninstallRepairFlow` distinguishes disable from removal — tables and settings are preserved on disable (including auto-disable during server upgrades); full cleanup runs only on explicit app removal.

## 1.3.24 - 2026-06-04

### Fixed

- **Dashboard widget**: `DashboardDeskletRenderService` now uses `\OCP\Template` instead of the non-existent `OCP\ITemplateManager`, fixing `Could not load lazy dashboard widget: EmployeeStatusWidget` on the Nextcloud home dashboard.
- **Callout partial**: `alert-callout.php` defaults `$calloutHint` to an empty string so includes without a hint no longer emit PHP notices on the main app pages.

## 1.3.23 - 2026-06-04

### Fixed

- **Install and upgrade path hardened.** `appinfo/info.xml` now declares MySQL/PostgreSQL under `<dependencies>` (required by `MigrationService` for correct schema checks). **`EnsureArbeitszeitCheckSchema`** runs on install and before every post-migration repair chain so missing tables are recreated and failures surface in `occ upgrade` logs instead of a half-updated app with no UI feedback. Schema checks use the **active** table set only (legacy `at_absence_calendar` was dropped in migration 1012 and must not block upgrades). Repair steps use `OC\DB\Connection` for `MigrationService`, matching core `occ upgrade`.
- **Post-migration repair DI.** `ReleaseStuckPendingAbsences` is registered in `Application.php` with `AbsenceService` (same as `BackfillAbsenceDays` + `HolidayService`) so `occ upgrade` never instantiates repair steps with wrong constructor arguments after partial deploys.
- **Admin schema banner.** All admin pages show a critical callout when tables are missing, with pluralized guidance to run `occ upgrade` and deploy the full app bundle.
- **Onboarding API no longer fakes success** when the database is incomplete — returns `SCHEMA_NOT_READY` (503) with a clear message instead of “will be saved after database migration”.
- **Health endpoint** reports incomplete schema (missing table count) before the generic connection check.

### Added

- **`SchemaHealth`**, **`EnsureArbeitszeitCheckSchemaTest`**, and extended **`UpgradeRepairIntegrationTest`** for the production upgrade container path.

## 1.3.22 - 2026-06-04

### Fixed

- **App upgrade / repair steps**: `HolidayService` dependency injection now passes `HolidaySuppressionMapper` as the second constructor argument (was incorrectly `UserSettingsMapper`), fixing fatal errors when enabling or updating the app (`Argument #2 ($suppressionMapper) must be of type HolidaySuppressionMapper`). Deploy the full app bundle — partial file copies leave repair jobs broken.

## 1.3.21 - 2026-06-04

### Added

- **Organisation-wide time recording methods** ([#16](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/16)): Administrators can disable clock in/out (stamping) and/or manual time entries for everyone under **Admin → Global settings → Time recording methods**. Per-employee restrictions remain under **Admin → Employees → Edit → Time recording**. Organisation rules are enforced on every API path (clock, manual entries, dashboard, mobile bootstrap, desklet). Missing clock-in reminders are suppressed when stamping is off. Audit log shows a dedicated label for organisation changes; integration and E2E tests cover org-vs-employee layering and API enforcement.

### Fixed

- **Nextcloud Administration settings panel**: organisation time capture checkboxes now load persisted values (previously always showed both methods enabled).
- **Employee time capture validation**: disabling both methods is rejected before persistence (HTTP 400, not 500).
- **Admin settings status badge**: uses shared WCAG-AA `.azc-badge` variants instead of low-contrast solid fills.

## 1.3.20 - 2026-06-03

### Fixed

- **Callouts and notification icons (all Nextcloud themes)**: unified `templates/common/alert-callout.php` and `css/common/notification-surfaces.css` with theme-safe panel tints and semantic icon wells (same contrast model as the page header). Replaced invisible Lucide `h.01` SVG marks in `IconCatalog` with visible accent dots; added `IconCatalog::renderCalloutWell()` and variant classes (`azc-notif-icon-well--warning`, etc.) so warning/danger/info glyphs stay identifiable on light, dark, high-contrast, and custom themes. Admin notifications save feedback uses matching callout styling and `role="alert"` on errors.

- **Admin → Teams: member/manager picker now finds people by name** ([#14](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/14)): the people picker searched the directory by *user id* only (`IUserManager::search()`), so on instances where the user id is an email, employee number or UUID, typing a person's name returned nothing — most of the directory was effectively hidden. All admin/manager people search now matches **user id OR display name** (shared `UserDirectorySearch` helper) across Teams, the Employees list, the company-status dashboard, the manager month-PDF picker and the scoped-employee picker. The Teams picker additionally excludes already-assigned members/managers **server-side** (`exclude[]`) so a large unit can no longer fill the capped result page with people already on the team, and shows a clear "Showing the first N matches — keep typing to narrow it down" hint (announced to screen readers) when more matches exist.
- **Admin → Employees and time-entry tables: inflexible width / clipped actions** ([#12](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/12)): table-heavy pages now use the wide shell (`time-entries`, `absences`, manager lists, compliance lists); `.table-container` scrolls horizontally only when columns need more space; action columns use `min-width: max-content` so Edit/History buttons stay fully visible on desktop; mobile keeps card reflow (`azc-table--responsive` + `data-label`). Manager scope pages no longer cap list width at 56rem. Playwright regression: `tests/e2e/table-width-desktop.spec.js`.
- **Admin → Holidays: auto-restore now respected when deleting statutory holidays** ([#17](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/17)): deleting a statutory holiday while *Auto-restore* is disabled records a per-`(state, date)` suppression (`at_holiday_suppress`) so the day stays removed in both the admin list and the calendar after a reload; re-enabling auto-restore revives the day and clears the stale suppression. The admin list and the working-day calendar now read from the same DB-backed source, so they can no longer diverge.
- **Admin → Holidays: "Default federal state" select is now functional**: the control on the Holidays page was rendered but never wired up — choosing a state did nothing. It now persists the organisation default state immediately (with disabled/`aria-busy` state, success/error feedback, and rollback on failure) via the existing admin settings API.
- **Admin → Holidays: statutory holidays are forced to full-day**: saving a statutory holiday now always stores `kind=full` (the working-day engine already treats statutory days as full-day), so the table badge can no longer claim "half-day" for a day that is counted as full.
- **Admin → Holidays: honest delete feedback**: removing a statutory holiday while auto-restore is enabled now states the day will be added back automatically, instead of a misleading "Holiday was removed" message.
- **Sachsen-Anhalt statutory holidays** ([#13](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/13)): `GermanStatutoryHolidayCatalog` seeds Epiphany (6 Jan) and Reformation Day for ST while excluding Corpus Christi and All Saints; auto-restore prunes legacy generated rows that used nationwide rules. `occ arbeitszeitcheck:holidays:verify` now reads the DB without reconciling first and fails when extra statutory rows remain (not only when catalog dates are missing).
- **Admin → Employees: entitlement preview UX**: human-readable summary line for HR; full `calculationTrace` JSON only inside a collapsible **Technical details (audit)** block (WCAG-friendly, no raw dump in the main form).
- **Unified date format for Stichtag**: overtime tracking start date uses the same `dd.mm.yyyy` datepicker as assignment validity dates (admin edit dialog and Nextcloud “New account” panel); values are converted to ISO before save.

- **Admin → Employees: overtime Stichtag / opening balance not saved**: `UserOvertimeSettingsService` passed the Nextcloud user id (string) as the audit log `entityId` (must be `?int`), causing a `TypeError` on every Stichtag or Eröffnungssaldo write — with the atomic profile save this rolled back the whole transaction and surfaced as “Benutzer konnte nicht aktualisiert werden”. Audit entries now use `null` entity id (same pattern as time-capture settings).
- **Admin user pickers (Teams, month reopen, overtime audit, vacation simulator)**: replaced the old 50-user dropdown cap with a searchable combobox (`GET /api/admin/users?picker=1`), minimum 2-character search, enabled accounts only, and shared WCAG-friendly styling. Legacy `GET /api/admin/vacation-layers/users` delegates to the same picker API.
- **Admin → Employees list**: browse all accounts with **Previous / Next** pagination (50 per page) and honest search feedback; empty search no longer hides employees beyond the first page.
- **Manager employee filters** (time entries, absences, record-absence form): replaced large `<select>` lists with the shared searchable combobox scoped to the manager’s team (`GET /api/manager/scoped-employees`); admins search the directory, managers only see their team.
- **Admin company-status dashboard widget**: status totals now scan up to 500 enabled accounts (was 200) and note when the directory is larger, with a link hint to **Employees** for the full list.
- **Admin → Employees**: server validation errors from the atomic profile save are now mapped to the matching form fields (instead of only a generic toast), with focus moved to the first invalid control.

### Changed

- **Admin → Employees**: clearer “Find an employee” search with help text; pagination status shows ranges (`1–50 of 150`) or match counts when searching.
- **Admin → Employees (edit dialog load)**: vacation policy for future-dated work-schedule assignments is resolved as of the assignment start date, not only “today”, so opening and saving no longer mis-defaults to “inherit” and triggers a failed save.

## 1.3.15 - 2026-06-03

### Fixed

- **Idempotent employee save**: the edit dialog now tracks the active vacation-policy row (`policyId` + `policyEffectiveFrom`) and only starts a new policy timeline when the work-schedule start date actually changes — repeated “open → save” no longer spawns duplicate `at_user_vacation_policies` rows.
- **Server-side no-op detection**: `AdminUserProfileUpdateService` skips DB writes when work-schedule and vacation-policy payloads are unchanged; open-ended policies are closed consistently before a deliberate reschedule.

## 1.3.14 - 2026-06-03

### Fixed

- **Atomic employee save**: the edit-employee dialog now uses a single `PUT /api/admin/users/{userId}/profile` endpoint that validates all sections up front and persists work schedule, vacation policy, time recording, and overtime inside **one DB transaction** — eliminating partial saves when a later step failed.
- **Admin datepicker on Holidays, Tariff rules, and Audit log**: same script-registration fix as Employees (1.3.13); covered by Playwright asset checks.

### Changed

- **Employee edit modal UX**: sticky footer with clear primary (Save) vs secondary (Cancel) styling, improved spacing, and WCAG 2.1 AA focus rings on actions.
- **Architecture**: consolidated profile update logic in `AdminUserProfileUpdateService`; legacy per-section PUT endpoints delegate to the same service for consistent validation.

## 1.3.13 - 2026-06-03

### Fixed

- **Admin → Employees: “Benutzer konnte nicht aktualisiert werden” on save (true root cause)**: the date picker JavaScript was registered as a *stylesheet* dependency instead of a *script* on the Employees, Holidays, Tariff-rule-sets and Audit-log pages (`css/common/datepicker.css` 404'd and `js/common/datepicker.js` never loaded). With the picker absent, the **Start/End date** fields stayed as plain text in German `dd.mm.yyyy` format and were sent **unconverted** to the server. The strict `Y-m-d` parser on the `vacation-policy` endpoint rejected them with **HTTP 400**, which aborted the multi-step save *after* the work-schedule step had already persisted — exactly matching the report (work-time model saved, overtime Stichtag not saved, generic error even when nothing was changed). Fixed by registering `common/datepicker` as a **script** dependency on all four admin pages.
- **Date-conversion safety net**: the employee save now always converts `dd.mm.yyyy` → ISO `Y-m-d` locally even if the shared date-picker module is unavailable, so a missing/late asset can never again leak an unconverted date to the API.

## 1.3.12 - 2026-06-03

### Fixed

- **Admin → Employees: “Benutzer konnte nicht aktualisiert werden” on save**: editing an employee saved some sections (e.g. work schedule) but aborted the rest (e.g. overtime Stichtag) with a generic error, even when nothing was changed. Root cause: employees without an explicit individual vacation policy defaulted the dialog to **Fixed value per person** with an empty days field, which the server (correctly) rejected, breaking the multi-step save chain. The dialog now defaults to **Inherit from team / model / organisation** when no explicit policy exists (behaviour-equivalent to the previous “no policy” fallthrough, but valid to save).

### Changed

- **Robust, all-or-nothing employee save**: the edit-employee dialog now validates every field on the client *before* sending anything (vacation mode requirements, manual days, override reason, tariff rule set, carryover, overtime year/hours, and start/end date ordering), preventing half-saved employees. Errors are shown inline per field, are screen-reader announced (`role="alert"`, `aria-invalid`, `aria-describedby`), move focus to the first invalid field, and surface the **specific** server reason instead of a generic message. The Save button shows a busy state and blocks double submits.
- **German localization** for the overtime/Stichtag and time-recording sections of the edit dialog and all new validation messages (previously shown in English).

## 1.3.11 - 2026-06-03

### Added

- **Holiday architecture (audit-grade):** `at_holiday_suppress` per-date opt-outs; `GermanStatutoryHolidayCatalog` (Bundesland-aware seeding); `HolidayAdminService` for admin delete/verify; `occ arbeitszeitcheck:holidays:verify`; docs [`docs/Holidays-Data-Model.en.md`](docs/Holidays-Data-Model.en.md); integration and E2E tests for delete ↔ calendar ↔ working days.

### Fixed

- **Admin holidays / statutory auto-restore** ([#17](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/17)): removed API “safety net” that re-injected deleted statutory rows in the admin list while auto-restore was disabled; `HolidayService` now restores missing statutory dates individually when auto-restore is on; working-day math uses **DB holidays only** (no parallel national static calendar); admin UI shows setting-aware delete copy, a warning callout when auto-restore is off, and reloads the table after delete; `Absence::calculateWorkingDays()` fallback uses `computeWorkingDaysForUser`; distributed holiday cache keys include the auto-restore policy so toggling the setting cannot serve stale lists.

## 1.3.10 - 2026-05-30

### Fixed

- **Calendar day details panel** ([#11](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues/11)): side drawer sits below the Nextcloud header (close control no longer hidden under the profile menu); calendar and app navigation stay visible while the panel is open; switch days without closing; close via X, Escape, or click outside; live header offset via `syncAzcOverlayMetrics()`; Vitest coverage in `js/calendar-day-panel.test.js`.

### Changed

- **Overlay tokens** (`--azc-overlay-top` / `--azc-overlay-height`) shared for fixed panels and mobile nav; theme-safe dashboard callouts (WCAG 2.1 AA contrast on all Nextcloud themes).

## 1.3.9 - 2026-05-25

### Added

- **Unified layout system**: `css/common/page-patterns.css` with `.azc-page-stack`, `.azc-shell--wide` / `--minimal`, shared filter grid, empty/loading states; `shellWidth` on page shell (auto-wide for dashboards, admin tables, manager lists).
- **Shared filter panel** partials (`templates/common/azc-filter-panel.php`) and admin **app teams** callouts when `use_app_teams` is off.
- **Dialog API**: `ArbeitszeitCheckComponents.openDialog()`; modals use `inert` on nav/main instead of `aria-hidden` on `#app-content` (live regions stay available).
- **`AzcApi.isApiSuccess()`** for consistent JSON success checks.
- **E2E**: `tests/e2e/layout-smoke.spec.js`, `tests/e2e/a11y-smoke.spec.js` (`@axe-core/playwright`, WCAG 2.1 AA tags).

### Changed

- **All routed pages** wrapped in `.azc-page-stack`; legacy `.section` chrome reset inside the app shell; `.btn` aliases to `.azc-btn` styling under `#app-content.azc-app`.
- **Admin teams** page uses full page shell (`buildAdminShellParams`); navigation exposes a single skip link to app nav (main content skip remains in `page-start`).
- **Personal settings** (Nextcloud user settings) and **SettingsController** load assets via `FrontEndAssetService::registerCore()` only.
- **`tests/WORKFLOW_ROLE_MATRIX.md`**: manager role requires app teams; `/reports` is manager/admin only.

### Fixed

- **Admin users page**: pagination label uses `{shown}` / `{total}` placeholders and `TemplateL10n` for JS export — fixes Internal Server Error from `json_encode($l->t('Showing %d of %d employees'))` without `vsprintf` arguments.
- **Employee absence request form**: `azc-card` layout, visible page title, workflow callouts (auto-approve vs manager/substitute path), fieldsets for request details and substitute (fixes duplicate “Substitute” label), side-by-side dates, clearer optional/required labels, `azc-btn` actions.
- **Time entry create/edit form**: page shell + assets via `TimeEntryController`; timezone and approval callouts; `azc-card` with fieldsets (date/time, breaks, note); aligned break matrix labels; dynamic breaks match PHP a11y; responsive fieldset layout (WCAG 2.1 AA).
- **Absence create/edit/view routes**: `AbsenceController` now uses `PageShellTrait` + form assets (same gap as time-entry create had — broken nav/header without shell); errors render on the list page with a visible callout instead of a blank layout.
- **Time entry edit errors**: denied or failed edits show the list layout with shell, assets, and an error callout (no half-rendered page).
- **Navigation flags**: centralized in `NavigationFlagsService` + `NavigationFlagsTrait` (all page controllers including Manager, Substitute, Compliance) — removes duplicated `getNavigationFlags()` implementations; compliance pages use `forComplianceUser()` (substitution nav hidden).
- **Absences list filter**: legacy `.section` filter replaced with `azc-card` / `azc-filter-grid` (matches time entries list UX).
- **API compliance gate**: `blockingIssuesForCompletedEntry()` + pre-save check in `apiStore` / `update` / `store` — ArbZG §4 break rules always block invalid manual entries server-side (strict mode adds further checks without writing violations before persist).
- **Calendar absence indicators**: month/week cells show readable chips (type label + approval status + tinted icon), not icon-only strokes; week view shows absences; day panel lists status; legend explains approved vs pending; `forced-colors` and theme-safe contrast.
- **Manager month-closure PDF page**: refactored to `azc-card` step wizard (numbered steps, person rows with `azc-btn` downloads, standard-width shell); removed narrow floating column layout.
- **Manager absences & time entries**: legacy `.section` / `.btn` layout replaced with `azc-page-stack`, `azc-card` filter/results blocks, shared `azc-filter-grid`, standard-width centered shell (like month-closures), and `azc-empty-state` / `azc-btn` patterns.
- **Manager dashboard**: migrated from legacy `.section` blocks (broken by shell section reset) to `azc-card` sections, admin-style stat tiles, standard-width centered layout, and `azc-btn` export action.
- **Admin holidays**: refactored to `azc-card` layout, shared `azc-filter-grid` toolbar, `table-container` / `table--hover`, scoped CSS under `azc-app--admin-holidays`, and `azc-btn` row actions (fixes broken `.admin-holidays-page` selectors).
- **Admin vacation layers**: `azc-page-stack` + `azc-card` sections (intro, L0/L1/L2, simulator), `table-container` / `table--hover`, scoped layout under `azc-app--admin-vacation-layers` (removes duplicate page padding and standalone `.layer-card` chrome), `azc-btn` / `azc-badge` throughout.
- **Employee time entries (list)**: timezone callout, stats, month closure, filter panel, and entries table use `azc-card` / `azc-callout` / `azc-filter-grid`; toolbar buttons relocate to the page header; scoped layout under `azc-app--time-entries` (fixes double margins from legacy `.section` / `.stats-grid`).
- **Dashboard overtime balance**: negative and positive balances use main text on tinted pills instead of raw `--color-error` / `--color-success`, so large values stay readable in all Nextcloud themes (WCAG contrast).
- **Unified data tables**: `page-patterns.css` defines one table system under `#app-content.azc-app` (`.table-container` + `.table.table--hover`, action columns, empty/loading cells); all routed list templates and dynamic report/admin tables migrated off legacy `table-responsive` / bare `grid-table`; `TableConventionTest` guards the convention.
- **Manager absences & time entries**: filters moved into the list card as a flat toolbar directly above the table (no nested box or `azc-filter-panel` accent); dedicated filter grid alignment (no subgrid glitches); “Record approved absence” sits below the list.
- **Audit pass (workflows / a11y / security)**: `l10n` for app-teams callouts (en/de); **admin teams** full shell; **working time model delete** fail-closed typed `DELETE`; **calendar day panel** `inert` + focus trap + Escape; **admin settings** `azc-callout` errors; manager dashboard error path includes team-mode URLs.
- **Breadcrumb trail**: simplified shell markup (no separator `<li>` nodes) and scoped styles so the trail reads as one line — primary link, muted section, bold current page — with CSS `/` dividers and ellipsis on long titles.
- **Compliance dashboard cards**: migrated status and violations blocks to `azc-card` header/body layout (title + help left, actions right, sized button icons); removed broken `card-header--with-actions` stacking.
- **Admin dashboard layout**: fixed styles targeting wrong `.admin-dashboard` class (shell uses `azc-app--admin-dashboard`); removed extra `.section` padding; `azc-callout` warning banner; stat cards grid; issues block in `azc-card`.
- **Confirm dialog typed phrase (client)**: if the translated label still contains `%s` after `t()` (e.g. test stubs or missing substitution), the requested phrase (`DELETE`, `REMOVE`, …) is applied so destructive prompts never show a raw placeholder.
- **GDPR delete UX**: when `confirmDialog` is unavailable, settings now surfaces an assertive error instead of failing silently; confirmation results use shared `isConfirmAccepted` / `confirmDialogReason` helpers.
- **Fail-closed destructive confirms**: `Utils.confirmDestructiveAction()` blocks month finalization, month reopen, correction withdraw, and overtime payout (single/bulk) when the dialog API is missing or the user cancels — no silent proceed (audit-critical). All admin delete paths (holidays, teams, vacation layers, tariff retire) now use the same helper.
- **Month closure lock notice (W7)**: shared `templates/common/month-closure-lock.php` with lock icon; shown on time entries when the selected month is finalized.
- **Settings UX**: sections use `azc-card` spacing and `azc-btn` controls for clearer visual hierarchy (WCAG-friendly grouping unchanged).
- **Substitution requests page**: removed duplicate `<h1>` and orphan `</div>` (invalid HTML); styles now target `.azc-app--substitution-requests` so the shell layout applies; empty state uses `azc-empty-state` pattern.
- **Dashboard clock double-submit (W11)**: `setLoadingState()` sets `aria-busy="true"` on clock/break buttons while API calls are in flight (with disabled state and loading label).
- **Reports DATEV discoverability (W23)**: administrators see help text under file format pointing to Global settings → Exports and reporting (CSV/JSON remain on this page).
- **Admin notifications (W13)**: sticky save footer, `beforeunload` when the form has unsaved changes, and `aria-busy` on save while the request is in flight.
- **E2E vacation seed (`ensure-e2e-vacation`)**: `UserVacationPolicyAssignment` no longer pre-initialises `vacation_mode`, so `INSERT` always persists the column (fixes SQL 1364 on strict MariaDB).
- **Clock status API (ArbZG calendar-day)**: `at_daily_maximum` and `session_hours_on_calendar_today` are now always returned (including when clocked out) so clients can block clock-in without an active session; overnight E2E asserts the contract when daily max is reached.
- **E2E clock seed (`ensure-e2e-clock`)**: dev/CI-only OCC command for `e2e_*` users clears active sessions and backdates the last completed entry when ArbZG §5 rest would block Playwright clock-in; wired into `run-e2e-docker.sh`.

### Added

- **Vitest**: `js/common/components.test.js` (focus trap / aria-hidden / typed-confirm label) and `isConfirmAccepted` coverage in `utils.test.js`.

### Fixed

- **Production-grade audit hardening pass** (UX-parity follow-up):
  - **Manager dashboard** (`js/manager-dashboard.js`): fixed an unbalanced paren on the team-overtime “Payout eligible: %s h” line that prevented the file from parsing, hiding the entire team status panel.
  - **Admin users** (`js/admin-users.js`): the two opening-balance year validation messages were calling an undefined `t()` helper; they now use the page-local `auMsg()` so the German/English text is announced correctly via the messaging live region.
  - **Icon catalog** (`js/common/catalog.js`): removed a duplicate `calendar-off` entry that produced silent overrides on load.
  - **Confirm dialog typed phrase** (`js/common/components.js`): when callers passed a non-default `typedConfirmPhrase` (e.g. `REMOVE`), the label still said `Type DELETE to confirm` because the translation already substituted `%s`. The label is now resolved with the *requested* phrase so destructive prompts are honest.
  - **Stylesheets**: replaced the deprecated `clip: rect(...)` screen-reader pattern with `clip-path: inset(50%)` in `css/app.css` and `css/manager-time-entries.css`; reordered `border-color` before `border-left-color` on `.inline-notice--warning/--info` so the longhand override actually wins; replaced two empty `/* */` block comments that tripped stylelint.
- **GDPR data deletion now persists the user-supplied reason**: `GdprController::delete()` reads the `reason` parameter posted by the destructive confirm dialog (`js/settings.js` already sent it), trims/clamps it to 500 chars, and writes it into the `gdpr_data_deletion_request` audit log entry alongside the existing IP/user-agent stamp. Retention-period enforcement is unchanged.
- **Tariff rule sets now produce a complete audit trail**: `AdminController::createTariffRuleSet/updateTariffRuleSet/activateTariffRuleSet/retireTariffRuleSet/deleteTariffRuleSet` each write a structured `tariff_rule_set_*` entry with old/new snapshots (code, version, jurisdiction, status, activation mode, validity window, module list). Previously these admin mutations went through unnoticed.
- **Admin authorization now returns JSON for API/AJAX callers**: `AppAdminMiddleware` no longer serves an HTML 403 page when an unauthorized admin endpoint is hit via `fetch`/XHR or `/api/...` URLs. AJAX consumers now receive `{ ok: false, error: { code: 'admin_required' } }` with HTTP 403, while browser page loads still get the standard `core/403` template. Defensive guards keep the path safe under CLI/test runners. Covered by `AppAdminMiddlewareTest` (HTML, `/api/` path, `XMLHttpRequest` header) and `AppAdminAuthorizationIntegrationTest`.
- **Single source of truth for front-end assets**: removed the duplicate CSS/JS list from `Application::boot()` — every page entry point now goes through `FrontEndAssetService::register{Core,Page}()`, including `app-vanilla.css` which is now imported by `css/app.css`. This eliminates load-order surprises and silent drift between the bootstrap loader and the central asset bundle.

- **Night / overnight shifts (Wachdienst):** ArbZG §3 daily maximum and automatic clock-out now use a single `DailyWorkingHoursCalculator` (calendar-day clipping at midnight). All enforcement paths aligned: live sessions, clock-in, compliance checks, manual entries, automatic breaks, break reminders. Fixes false auto clock-out shortly after midnight. Frontend auto clock-out only when `at_daily_maximum` is true (never client extrapolation alone). User stats and manager week/month totals now use `getWorkingHoursForPeriod()` (same calculator). Compliance “excessive working hours” violations now use `findAllCalendarDaysExceedingMaximum()` (no false positives on legal 22:00–08:00 rows). Audit reference: `docs/DAILY-HOURS-AUDIT.md`. E2E: `tests/e2e/overnight-daily-maximum.spec.js`.

## 1.3.8 - 2026-05-20

### Fixed

- **New account + overtime tracking start date:** the start date is captured reliably when clicking Create (no longer bound to the wrong dialog); the OCS hook runs only for `POST …/cloud/users` without sub-paths (`LoadUsersSettingsArbeitszeitListener`).
- **Manager correction:** `data-entry-summary` is emitted like PHP with JSON hex-encoding; display times (`displayStartTime` / `displayBreaks`); empty clock values show `--` instead of misleading `00:00`.
- **Overtime:** a future tracking start date no longer produces a fictitious negative balance in the current period.
- **Opening balance year:** server validates exactly four digits (2000–2100); client validates before save (`OpeningBalanceYearValidator`).

## 1.3.7 - 2026-05-20

### Added / Changed

- **Overtime tracking anchor day:** the calendar day of the configured `tracking_from` date no longer carries a standalone full daily target into the overtime balance (algorithm version **2**), avoiding misleading “minus one full day” right after onboarding. Opening balance and subsequent days are unchanged.
- **Accounts → New account** (Nextcloud user management): optional “Overtime tracking from” field for ArbeitszeitCheck app administrators; after a successful user creation the same API as **Administration → Employees** is called (`LoadUsersSettingsArbeitszeitListener`, `settings-users-overtime.js` + CSS).
- **Manager correction / clock matrix:** `formatTime` now builds `HH:mm` via `Intl.DateTimeFormat#formatToParts`, avoiding narrow no-break spaces that broke strict `HH:mm` parsing — pre-filled stamp times work reliably.
- **Admin employees:** year inputs for opening overtime / vacation carryover are four-digit text fields with `maxlength` and help text.

### Fixed

- **App enable / migration on Oracle-compatible Nextcloud installs** where physical table names must stay within the 30-character limit (`dbtableprefix` + logical name). The overtime opening-balance table is now `at_user_ot_year_bal` (migration `1025`), and migration `1026` renames the legacy `at_user_overtime_year_balance` table in place on instances that already created it, including PostgreSQL sequence cleanup. No data is copied or dropped except dropping an **empty** stray target table left by a failed prior attempt (same safety model as ProjectCheck table renames).

## 1.3.6 - 2026-05-19

### Added

- **Manager employee time entries & absences — accessible filter bar** (`templates/manager-time-entries.php`, `manager-absences.php`, `css/manager-time-entries.css`, `js/manager-time-entries.js`, `js/manager-absences.js`): two-row CSS grid with aligned labels/controls, WCAG 2.1 AA patterns (fieldset, `aria-live` errors, visually hidden sublabels), client-side date validation, and a **365-day maximum** range enforced in the browser and on list APIs (`ManagerController`).
- **Manager direct correction dialog** on employee time entries (`js/manager-correction-dialog.js`, `js/common/time-entry-clock-form.js`, `lib/Support/TimeEntryClockPayloadBuilder.php`, shared correction l10n partial): European date + clock matrix, optional breaks, server-side payload builder with unit tests.
- **`TemplateL10n` + `templates/common/manager-employee-list-l10n.php`**: safe server-side JS translations (single `json_encode` of plain strings) — fixes Internal Server Error from `json_encode($l->t('…%d…'))` without `vsprintf` arguments on manager list pages.

### Fixed

- **Employee time entries page crash** (`ValueError` in `L10NString` / `vsprintf`) when loading manager list l10n; `%d` strings are now translated with explicit parameters before JSON output.
- **Compliance violations** filter/export UX and admin dashboard overtime onboarding tweaks included in this release train.

### Changed

- User manual (`docs/User-Manual.en.md`, `docs/User-Manual.de.md`) updated for manager list filters and correction workflow.

## 1.3.5 - 2026-05-19

### Added

- **Admin "Vacation entitlement layers" — production-grade UX & accessibility overhaul** (`js/admin-vacation-layers.js`, `templates/admin-vacation-layers.php`, `css/admin-vacation-layers.css`):
  - **Full WAI-ARIA 1.2 combobox** on the simulator user search. The input now advertises `role="combobox"`, `aria-autocomplete="list"`, `aria-controls`, `aria-expanded`, `aria-haspopup` and `aria-activedescendant`; ArrowUp/Down/Home/End traverse the listbox, Enter/Tab commit the selection, Escape closes without changing the value. `aria-selected` is mirrored on every option so screen readers always announce the highlighted candidate (WCAG 2.1.1, 4.1.2).
  - **Explicit "no matches" status entry** for the user-search listbox so screen readers receive a `role="status"` update instead of silent failure (WCAG 4.1.3).
  - **Empty-state guards**: "Add model default" and "Add team policy" buttons disable themselves with a `title` and `aria-disabled` when no working time models / teams exist, and an inline `role="status"` hint explains where to create the prerequisite. The dialogs themselves also bail out with an announced error if the precondition is missing.
  - **Client-side date-range validation** (`effectiveFrom ≤ effectiveTo`, both strict YYYY-MM-DD) with an inline `aria-live="polite"` error on the `effective_to` field — no more silent round-trips that surface as a 400 only after Save.
  - **Tariff rule set selection now required client-side** when the mode is `tariff_rule_based`, mirroring the engine's contract before the round-trip.
  - **Double-submit protection** on both the form drawer save and the simulator: in-flight requests disable the button, set `aria-busy="true"` and swap the label to "Saving…" / "Running simulation…".
  - **Safer focus return on dialog close**: if the trigger was removed from the DOM during the save round-trip the page H1 takes the focus instead of `<body>` (`tabindex="-1"` fallback), keeping keyboard users anchored.
  - **Layer count chips** next to each `L0 / L1 / L2` section title and an inline placeholder hint when no model / team is configured yet.
  - **Hypothetical-team clear button** in the simulator so HR can reset a what-if scenario in one click; a visible legend, fieldset and `role="status"` announcement document the reset.
  - **Free-text user search hint**: when the admin submits a typed-but-unpicked identifier, the result card adds an info banner telling them how to re-search if the wrong person was found. The endpoint already returns a clean 404 for unknown UIDs (admin IDOR guard REQ-EC-10), which the JS now surfaces as a specific, actionable error.
  - **Mobile-first trace table**: the simulator's resolution trace collapses to a card layout below 720 px with `data-label` pseudo-headers, matching the existing `layer-card__history-table` mobile treatment.
  - **`forced-colors: active` rules** preserve every border, focus ring and active-option highlight in Windows High-Contrast mode.
  - **3 px focus outlines** with `outline-offset: 2px` on every interactive surface (buttons, summary toggles, simulator result region), replacing the previous 2 px outline which was borderline against the primary-element fill.
  - **`clip-path: inset(50%)`** for the `.visually-hidden` helper (deprecated `clip: rect(...)` removed).
  - **Test coverage**: 25 new vitest cases in `js/admin-vacation-layers.test.js` for the manual-days parser (including comma / scientific-notation / range / boundary cases), the date-range validator, the combobox keyboard interactions (ArrowDown/Up/Enter/Escape, "no matches" empty-state) and the empty-state guard behaviour. All 40 JS unit tests + 624 PHP unit tests pass.

## 1.3.4 - 2026-05-19

### Added

- **Single source of truth for timezone handling** (`lib/Service/TimeZoneService.php`, `js/common/time.js`, `templates/common/time-bootstrap.php`). All backend code now resolves the organisation storage TZ, the user display TZ and "now" via one injectable service, and all frontend code parses/formats datetimes through one idempotent module. See `docs/Time-And-Timezone-Architecture.en.md` for the full contract.
- **Audit-grade display-TZ enforcement across every user-facing surface.** Manager pending-approval payloads (`ManagerController::getPendingApprovals`), time-entry overlap conflict messages (`TimeEntryController` create/update/manager-create paths), ArbZG §5 rest-period violation messages (`ComplianceService::checkComplianceBeforeClockIn` and `::checkRestPeriodForStartTime`), the clock-out reminder notification (`ClockOutReminderJob`), and the auto break-fallback / daily-maximum auto clock-out notices (`TimeTrackingService`) now route every clock string through `TimeZoneService::formatForDisplay($dt, ..., $userId)` so the affected employee always sees their own wall clock. The time-entries edit form (`templates/time-entries.php`) prefills start/end/break inputs in the user's display TZ as well, matching the dashboard exactly.
- **Drift-safe live timer** for the dashboard. `TimeTrackingService::getStatus()` now ships a `server_now` (ISO-8601 with offset) and `server_timezone` (IANA name) anchor alongside `current_session_duration`. The JS timer pins itself to that anchor via `ArbeitszeitCheckTime.syncFromServer()` and extrapolates with `performance.now()`, so client clock skew, system-clock changes during the session and background-tab throttling can no longer make the timer drift.
- **`common/time.js` is loaded everywhere `common/utils.js` is** (admin, manager, settings, compliance, substitute, all employee pages), and `templates/common/navigation.php` now includes the time bootstrap on every page, so `window.ArbeitszeitCheck.tz` / `window.ArbeitszeitCheck.serverNow` are guaranteed to be available before any client datetime code runs.
- **`TimeClientBootstrap`** (`lib/Support/TimeClientBootstrap.php`) — single registrar for the client timezone stack. Emits config via Nextcloud InitialState + `js/common/time-init.js`, registers scripts idempotently, and is invoked from `time-bootstrap.php`, a `BeforeTemplateRenderedEvent` safety-net listener, and every dashboard widget `load()` so widget-only pages (global NC dashboard) cannot miss the bootstrap.
- **Employee correction request dialog** (`templates/time-entries.php` + `js/time-entry-correction.js`): single-page modal (no multi-step wizard, no `datetime-local`). Uses the same patterns as the manual time-entry form: European date (`dd.mm.yyyy`), hour/minute `<select>`s, optional breaks, mandatory justification (≥10 characters), “currently stored” snapshot table, and WCAG-friendly status/errors.
- **Shared JS translation bundles** for corrections: `templates/common/time-entry-correction-l10n.php` (employee list + dialog) and `templates/common/manager-correction-l10n.php` (manager dashboard approvals + employee time entries “Correct” modal). Keys in `l10n/de.json` and `l10n/en.json`.
- **Server parsing for correction payloads**: `TimeEntryController::buildProposedWorkTimesFromDateAndClock()` accepts `date` + `startTime`/`endTime` as `HH:mm` (overnight shifts: end before start → next calendar day); legacy ISO instants remain supported for API clients.

### Fixed

- **Timer immediately showing the client/server TZ offset after clock-in** (e.g. `02:00:00` for a Berlin user on a UTC container). The session and break timers now use the drift-safe server clock and parse the `startTime` instant via `ArbeitszeitCheckTime.parseInstant`, never raw `new Date(string)` or `Date.parse` against potentially-naive values. The PHP-rendered first frame uses the same `server_now` anchor as the live counter so there is no visible jump on mount.
- **Recent-entries list on the dashboard showing UTC times instead of the user's local clock**. Initial PHP render now consistently converts to `$arbeitszeitCheckUserDisplayTz` before formatting, and the timezone badge next to "Current Status" explicitly tells the user which zone the times are in.
- **`templates/index.php` legacy multi-view template** (kept for `AccessibilityTest` and as a safety net for any forgotten route): now requires `templates/common/user-display-timezone.php` and renders every `getStartTime()` / `getEndTime()` value through a local `setTimezone($arbeitszeitCheckUserDisplayTz)` shim so a fallback render would also produce correct wall-clock output. The file is explicitly documented as legacy at the top so auditors do not mistake it for the primary path.
- **Redirect after opening “Request correction”** on the time-entries list: the global edit handler in `arbeitszeitcheck-main.js` no longer treats `.btn-request-correction` / `.btn-cancel-correction` as edit; the correction script uses `preventDefault` / `stopPropagation`.
- **Unreadable correction validation errors** on dark/high-contrast themes: theme-safe error surfaces in `css/common/components.css` and correction-specific status styling in `css/time-entry-correction.css`.
- **Missing translation keys** for pending-correction banners, withdraw confirm, and related API error strings in `l10n/de.json` / `l10n/en.json`.

### Notes for auditors

- The finalized month-closure PDF (`MonthClosurePdfDocumentBuilder`) intentionally renders clock and date values in the **storage timezone** (`Constants::CONFIG_APP_TIMEZONE`). This is the authoritative legal record and must not vary per-user; the choice is now documented inline alongside `fmtClock()` / `entryDateShort()`.
- CSV / XLSX exports (`TimeEntryExportTransformer`) also render in the storage timezone for the same reason. Conversion happens centrally before formatting, never ad-hoc.
- **Manager direct correction** (`manager-time-entries.js`, `manager-correction-dialog.js`) uses the shared date + HH:mm matrix and optional breaks (same pattern as employee corrections).
- **Admin dashboard:** clickable employee stat tiles with searchable list and CSV export; overtime onboarding banner when many users lack a tracking start date.
- **Manager dashboard:** clickable team compliance stats with per-member links to filtered compliance violations.

### Changed

- `AppLocalNaiveDateTimeNormalizer` is now a thin, pure-static facade kept only for places without dependency injection (entity hydration, migration / repair steps). All services, controllers, mappers and templates use `TimeZoneService` directly. Its `normalizeAtEntryDatetimeStringsInRow` now emits ISO-8601 with offset (`DateTimeInterface::ATOM`) consistently.
- `TimeTrackingService` was migrated to `TimeZoneService` for every "now", every today-window query and every ISO serialisation. Behaviour is unchanged on the hot path; the difference is that the storage zone is now resolved through one auditable code path.
- `ManagerController`, `TimeEntryController`, `ComplianceService` and `ClockOutReminderJob` now take `TimeZoneService` as an explicit constructor dependency; the `Application` DI wiring and unit-test setup were updated to match. This makes the user-facing TZ contract explicit at the type level and gives auditors a single grep target (`TimeZoneService::formatForDisplay`) for every clock string that ever reaches a user.

### Tests

- New: `tests/Unit/Migration/Version1015TimezoneMigrationTest.php` — UTC→Berlin conversion math, invalid input handling, documents double-run hazard, verifies idempotency flag short-circuit and first-run config writes.
- New: `tests/Integration/TimezoneMigrationStateIntegrationTest.php` — post-upgrade `app_timezone` / `tz_utc_to_berlin_migration_done` markers plus live `TimeZoneService` hydrate/ISO contract.
- New: `tests/e2e/timezone-smoke.spec.js` — Playwright guard for the clock-in timer offset bug (`server_now`, ISO instants, `#session-timer-value` within seconds of API duration).

### Docs

- New: `docs/Time-And-Timezone-Architecture.en.md` (and `.de.md`) — the binding architecture for date/time handling.
- Updated: employee/manager correction UX, shared JS l10n partials, and API notes in `docs/Developer-Documentation.en.md`, `docs/User-Manual.{en,de}.md`, and `docs/Compliance-Time-Entry-Workflows.de.md`.

## 1.3.3 - 2026-05-18

### Fixed

- **Admin vacation date inputs** (`PUT /api/admin/users/{userId}/vacation-policy`, `POST /api/admin/vacation-policy/simulate`, `GET /api/admin/vacation-layers`, and **L0/L1/L2** payloads handled by `LayeredVacationDefaultsService`): reject malformed and **overflow** calendar strings (e.g. `2026-02-30`) with **HTTP 400** / field-level validation instead of accepting PHP’s silent normalisation or surfacing **HTTP 500** from `DateTime` edge cases. Shared logic: `OCA\ArbeitszeitCheck\Support\StrictYmdDates`.
- **`completePausedEntry()` preserves an existing `end_time`** on legacy `paused` rows that already carry a frozen end timestamp (status/end_time mismatch). Without this guard the service could overwrite payroll-relevant hours with `updated_at`.
- **`RepairOrphanedPausedEntries`** now sets `ended_reason` and `policy_applied` when it only flips `paused` → `completed` for rows that already have `end_time`, keeping upgrade-time repairs audit-consistent with steps 2–3.

### Tests

- New: `testCompletePausedEntryPreservesExistingEndTime`.
- All 577 unit tests pass.

## 1.3.2 - 2026-05-18

### Added

- **One-click recovery for "paused" time entries** (issue: time-tracking/paused-entry-recovery, addresses upstream reports of "pausierte Einträge nicht bearbeitbar/abschließbar" and "HTTP 500 beim Ausstempeln, keine UI-Methode zum Heilen"). A new dedicated endpoint `POST /api/time-entries/{id}/complete` finalises an entry stuck in `paused` in a single, race-safe step. The end time defaults to `updated_at` (the moment the broken clock-out froze the row) and falls back to `start_time` as a zero-duration safety net; ArbZG §4 (automatic break) and ArbZG §3 (daily maximum) are applied so the resulting `completed` row is compliance-equivalent to a normal clock-out. Every recovery is audit-logged with `time_entry_paused_completed`, ownership is enforced, and the per-user mutation lock is honoured.
- **"Complete session" affordance on the dashboard and time-entries list**. When a session is in `paused`, the dashboard status card now shows a clearly-labelled "Complete session" button next to "Resume after break", and the time-entries list shows a primary "Complete" button per affected row plus a `role="status"` banner explaining the state in plain language. WCAG 2.1 AA: minimum 44×44 touch target, ARIA labels/titles, never colour-only signalling.
- **`TimeTrackingService::completePausedEntry()`** as the canonical programmatic recovery path. The controller is now a thin shell that parses input, delegates to the service, and maps domain exceptions (`BusinessRuleException` → 400/403, `MonthFinalizedException` → 409, `LockedException` → 423, `DoesNotExistException` → 404) — never a generic HTTP 500 for a known business state.
- **`TimeEntryMapper::findAllPausedByUser()`** + post-migration repair step `RepairOrphanedPausedEntries` that idempotently closes any leftover `paused` row on every `occ upgrade`: rows with an `end_time` are flipped to `completed`, rows without one are closed at `updated_at` (or `start_time` as a fallback).
- **Layered vacation entitlement — degraded-state trace flags & impact preview** (issue: hr/vacation-entitlement-hierarchy follow-up). The resolution trace now carries explicit `degraded_org_default_collision` (REQ-ENT-10), `partial_history` (REQ-ENT-13 / EC-11), `clamped` + `raw_*` values (EC-08), `rule_set_status_warning` (EC-05), and `degraded='model_lookup_failed'` (EC-04) markers so auditors can see misconfigurations and best-effort historical resolutions instead of silent fallback. The admin simulator surfaces these flags as labelled chips alongside the result; the employee explainer surfaces a redacted subset (`degraded`, `clamped`, `partial_history`) without leaking any internal IDs (REQ-SEC-05).
- **Impact preview endpoint** `GET /api/admin/vacation-layers/impact?scope={org,model,team}&targetId={int}` (REQ-UX-03). The vacation-layer dialog now shows "Up to N employees may be re-resolved by this change" inline before the admin clicks Save, with WCAG-compliant colour states that are never colour-only (icon + status text + ARIA live region).

### Changed

- **`TimeTrackingController::buildSafeErrorResponse()`** now catches `OCP\Lock\LockedException` explicitly and returns HTTP 423 with a translatable "Another change to your time tracking is in progress" message, eliminating the opaque HTTP 500 reported on parallel clock-out / break-start.
- **Paused / break / rejected status badges** in the time-entries list now use semantic `warning` / `error` styling with descriptive `title` attributes so the state is conveyed via icon, colour, *and* text (WCAG 1.4.1).
- **`LayeredVacationConflictException` for lock contention** (REQ-SEC-04 / EC-07). `LayeredVacationDefaultsService` now wraps Nextcloud's `OCP\Lock\LockedException` so the `AdminController` returns HTTP 409 with a translatable "another administrator is editing this layer" hint instead of a generic 500. The admin JS surfaces the message in the dialog feedback area rather than dismissing the form.

### Fixed

- **Navigation icons script** — remove duplicate `navigation-icons.js` IIFE that registered a second `DOMContentLoaded` handler; expose `window.ArbeitszeitCheckNavigationIcons.apply` for dynamic `[data-lucide]` placeholders after page load (CSP-safe local SVGs).

### Tests

- New: 5 cases in `TimeTrackingServiceTest` covering the paused-entry recovery path.
- New: `LayeredVacationEntitlementEngineTest`, `LayeredVacationDefaultsServiceTest`, and `AdminControllerTest` cases for degraded-state flags, impact preview, and 409 lock mapping.
- All 567 unit tests pass.

## 1.3.1 - 2026-05-12

### Added

- **L3 inherit toggle in the admin user dialog** (REQ-WF-04). The "How should annual vacation be calculated?" dropdown now exposes "Inherit from team / model / organisation" as a first-class option. Selecting it disables manual days / tariff rule set / override reason (since the engine would ignore them) and persists the choice via the `inheritLowerLayers` boolean column **and** the `vacation_mode = 'inherit'` sentinel so both representations stay in sync (REQ-WF-04). The admin user-list payload now surfaces `inheritLowerLayers` so the dialog round-trips the current state.
- **Hypothetical team-membership simulator** (REQ-WF-05). The admin simulator on `/admin/vacation-layers` now lets HR plug in an *imagined* team set for a what-if computation ("what would this employee receive if we moved them to Berlin?") without mutating any team memberships. The engine evaluates L2 against the override and propagates an explicit `hypothetical: true` flag into the trace; the UI shows a dedicated banner so the result is never mistaken for the *real* current entitlement.
- **L0 closed-range overlap detection** (REQ-DAT-03). `OrgVacationDefaultMapper::findOverlappingRanges` actively rejects new organisation defaults that would overlap an existing *closed* validity range. Previously only open-ended overlaps were auto-closed; closed-vs-closed collisions would slip through and only surface at resolution time as `degraded_org_default_collision`. The admin UI also surfaces a `role="alert"` warning banner above the active L0 row if more than one rule is active today, so the conflict is visible before any simulation is run.
- **Progressive-disclosure simulator UX** (REQ-UX-02). The simulator now renders a one-sentence summary (`"On {date}, the employee receives {days} vacation days per year, determined by the {layer}."`) with the resolved number in display-size type, then offers the full layer trace inside a `<details>` element. L2 tie-break candidates (depth / priority / policy ID) are listed in a nested `<details>` so auditors can still drill in, but the "happy path" no longer overwhelms HR with raw JSON.

### Fixed

- **Dialog focus return** (WCAG 2.4.3 / `Focus Order`). The vacation-layer dialog now remembers the trigger element on open and restores focus to it on both explicit close and ESC cancel. Previously focus fell to `<body>` on cancel, forcing keyboard users to re-traverse the page header.
- **Simulator focus & error announcement**. After a simulation the result region (`aria-live="polite"`, `tabindex="-1"`) receives focus so screen readers read the resolved entitlement immediately. Failures are routed through the existing `aria-live` status region instead of only printing into the result card.

### Tests

- New: 7 cases — `LayeredVacationEntitlementEngineTest` adds 3 for hypothetical team injection (override, cleanup, sanitisation); `LayeredVacationDefaultsServiceTest` adds 2 for closed-range overlap rejection vs open-ended auto-trim; `AdminControllerTest` adds 2 for the simulator IDOR 404 and hypothetical-team forwarding.
- All 548 unit tests pass (was 541).

## 1.3.0 - 2026-05-12

### Added

- **Layered vacation entitlement resolution** (issue: hr/vacation-entitlement-hierarchy). The annual vacation entitlement is now resolved through a deterministic, auditable precedence chain: L3 individual policy → L2 team/cohort policy → L1 working-time-model default → L0 organisation default → legacy safe default. Each layer can be configured manually, via the `model_based_simple` formula, or via an active tariff rule set. L3 assignments gain an `inherit_lower_layers` flag (new `inherit` mode) so HR can explicitly defer to the chain without deleting a row. L2 ties are broken deterministically by team depth → priority → smallest team ID. Every resolution emits a structured trace v1 envelope (`algorithm_version`, `as_of_date`, `matched_layer`, `layers_evaluated`, `winner`, `inputs_redacted`) that is persisted in entitlement snapshots for payroll audit (REQ-AUD-01).
- **Admin "Vacation entitlement" page** with WCAG 2.1 AA + responsive layout (`/admin/vacation-layers`): stepper-style precedence overview, separate cards for L0/L1/L2 with full history, native `<dialog>`-based create/edit drawer with inline validation, and a built-in simulator that resolves the entitlement for any employee on any date and displays the full per-layer trace.
- **Employee-facing "How is this calculated?" explainer** on the absences page. Surfaces a redacted, ID-free trace produced by `VacationEntitlementEngine::redactTraceForUser()` so colleagues' policy names and internal references never leak (REQ-SEC-05).
- **Concurrency safety** via `OCP\Lock\ILockingProvider` advisory locks scoped per layer/resource (REQ-SEC-04, EC-07) and per-write transactions through the `TTransactional` trait.
- **Audit-log entries** for every L0/L1/L2 create/delete (`org_vacation_default`, `model_vacation_default`, `team_vacation_policy` audit entities) with before/after JSON payloads (REQ-AUD-02).
- **Feature flag** `arbeitszeitcheck.layered_entitlements_enabled` (default ON). When disabled the engine deterministically routes through L3 → legacy fallback, preserving today's behaviour byte-for-byte.

### Fixed

- **GAP-01** — unified rounding of vacation entitlements across the engine, `VacationAllocationService`, `AbsenceService`, and `EntitlementSnapshotService`. The single canonical implementation `VacationEntitlementEngine::roundDays()` clamps to `[0, 366]` and rounds to 2 decimal places using `PHP_ROUND_HALF_UP`. Earlier paths mixed `(int)round(...)` with `round(value, 2)`, which could shift an employee's annual entitlement by ±1 day on `.5` boundaries.

### Schema

- New tables: `at_org_vacation_defaults` (L0), `at_model_vacation_defaults` (L1), `at_team_vacation_policies` (L2 with FK → `at_teams ON DELETE CASCADE`).
- `at_user_vacation_policies` (L3) gains `inherit_lower_layers BOOLEAN DEFAULT false` (nullable in schema for Nextcloud portability; application treats NULL like false) — golden-file equivalent for every existing row.

### Documentation

- **Developer:** `docs/Developer-Documentation.en.md` — layered L0–L3 resolution, admin routes, audit/locking, production rollback via `layered_entitlements_enabled`, and the `Entity` / `QBMapper::insert` dirty-field pitfall for new layer entities.
- **Operators / end users:** `docs/User-Manual.en.md`, `docs/User-Manual.de.md` — admin **Vacation entitlement** page, emergency config rollback, employee-facing entitlement explainer; `docs/README.md` index updated.
- **Product spec (repo):** `pm/app-ideas/arbeitszeitcheck/vacation-entitlement-hierarchy.md` — adopted status, fact base aligned with shipped code, L2 tie-break text aligned with implementation, migration / backward-compatibility semantics for production upgrades.

### Tests

- New: `LayeredVacationEntitlementEngineTest` (cross-layer precedence, tie-breaking, simulation, trace envelope), `LayeredVacationDefaultsServiceTest` (validation, audit, lock, transactional behaviour), `LayeredVacationEntitlementSchemaTest` (migration schema + FK + idempotency).
- All 511 unit tests pass.

## 1.2.9 - 2026-05-12

### Fixed

- **Install-blocking foreign key crash on PostgreSQL** (issue #4): Migration `Version1014Date20260409120000` declared the `at_mcr_closure_fk` foreign key on `at_month_closure_revision.closure_id` by passing the raw, *unprefixed* string `'at_month_closure'` to `addForeignKeyConstraint()`. Doctrine then emitted SQL that referenced the literal `at_month_closure` relation instead of the prefixed `oc_at_month_closure`, which aborted the install on every PostgreSQL cluster with `SQLSTATE[42P01] / Undefined table: 7 / ERROR: relation »at_month_closure« does not exist`. The FK is now declared via `$schema->getTable('at_month_closure')` so the prefix is applied. MariaDB/MySQL were affected too, but silently — the FK was never created on those engines, leaving the month-closure audit trail without referential integrity.
- **Backfill of the missing month-closure FK on existing installs**: Added `Version1023Date20260512143000`. The migration first removes any orphan `at_month_closure_revision` rows whose `closure_id` does not point to an existing closure (these can only exist as a side-effect of the previously-missing FK on MariaDB) and then adds the FK with `ON DELETE CASCADE`. Fully idempotent — safe to re-run on healthy installs.
- **Locale-fragile "table does not exist" detection in migrations**: `Version1008`, `Version1009`, and `Version1015` previously detected a missing table on fresh installs by string-matching English driver error messages ("doesn't exist", "no such table", …). PostgreSQL localises these messages ("Relation … existiert nicht" on a German cluster), and the check leaked through, surfacing migration failures with a confusing translated database error. Replaced with explicit `IDBConnection::tableExists()` guards.
- **Locale-fragile runtime guards** in `SettingsController::index_api`, `AdminController::getTeams`, and `TeamResolverService`: replaced fragile error-message string matching with `OCP\DB\Exception::getReason() === REASON_DATABASE_OBJECT_NOT_FOUND` — the portable, locale-independent contract guaranteed by Nextcloud's DBAL wrapper. A small message-based fallback is kept only for non-DBAL paths (e.g. test doubles).

### Tests

- **Schema-level regression guard for the FK bug**: New `tests/Unit/Migration/MonthClosureForeignKeyTest` reproduces the migration's schema build against a real Doctrine `Schema` with the Nextcloud table prefix applied, then asserts that the FK references the *prefixed* table name and that re-running the migration is a no-op. This pins down the contract that originally regressed and catches any future migration that accidentally passes a raw, unprefixed table name to `addForeignKeyConstraint()`.

## 1.2.8 - 2026-04-30

### Security

- **CSRF protection on all state-changing endpoints**: Removed `#[NoCSRFRequired]` from POST/PUT/DELETE methods in `AbsenceController`, `TimeEntryController`, `TimeTrackingController`, `ComplianceController`, `SubstituteController`, `SettingsController`, `MonthClosureController`, `GdprController`, and `AdminController`. The frontend already submits `requesttoken` consistently via `ArbeitszeitCheckUtils.ajax`, so all mutating routes now reject cross-site requests by default. GET-only endpoints intentionally remain `#[NoCSRFRequired]` (CSRF is irrelevant for read-only GETs in Nextcloud's framework).
- **No raw exception leakage in JSON responses**: Hardened `AbsenceController::getSafeErrorMessage` so that exception messages are only forwarded when they are explicit business-rule `\Exception` instances; messages containing technical fingerprints (SQL fragments, file paths, stack traces, oversized payloads) are replaced with a generic localized error. Applied the hardened helper to `AbsenceController::store`/`update`. Replaced direct `getMessage()` leakage in `AdminController::getTeams`, `SettingsController::index_api`, and `PageController` page-render error paths with sanitized localized messages.
- **Correct HTTP status for authentication errors**: `SettingsController::update` now returns `HTTP 401 Unauthorized` (was `HTTP 400 Bad Request`) when the request is unauthenticated, matching what API clients and load balancers expect.

### Changed

- **Organization-scope monthly report downloads**: `reports.js` now forwards user IDs resolved during preview to the `report.team` endpoint and falls back to a clear "no organization members had time entries in the selected period" message instead of the misleading "preview first" hint when an organization-wide preview yields zero results.
- **Sanitized dashboard load errors**: `dashboard.js`/`dashboard.css` now surface a localized "Some dashboard data could not be loaded." live-region message instead of raw widget exceptions.
- **Resume-after-break clarity**: Clock-in copy and l10n unified around "Resume after break" instead of the legacy `clock_in_resume` placeholder.

### Accessibility (WCAG 2.1 AA)

- **Main landmark on every page**: 17 page templates now expose a single, properly labelled `<main id="app-content" role="main" aria-label="...">` landmark for assistive technologies (`dashboard`, `index`, `timeline`, `calendar`, `settings`, `personal-settings`, `reports`, `compliance-dashboard`, `compliance-reports`, `compliance-violations`, `working-time-models`, `admin-dashboard`, `admin-teams`, `admin-users`, `admin-holidays`, `manager-dashboard`, `manager-time-entries`, `manager-absences`, `manager-month-closures`).
- **Skip link / `<main>` consistency**: `time-entries`, `absences`, `admin-settings`, `admin-notifications`, `substitution-requests`, and `audit-log` previously had `id="app-content"` on a plain `<div>` while `role="main"` lived on a child wrapper, so the "Skip to main content" target landed on a non-landmark. All six now use `<main id="app-content" role="main">` directly. Removed redundant `role="banner"` from the `<header>` inside `audit-log`'s main region.
- **Accessible names on all data tables**: Added `aria-label`/`aria-labelledby` and screen-reader captions to the holiday list table and to the two notification-matrix tables that previously had no accessible name.
- **Live error announcement on dashboard**: Dashboard error section now lives inside an `aria-live` region so partial widget failures are announced without disrupting focus.
- **Manager dashboard team metrics now announced**: Stat numbers (Team Members / Active Today / Hours Today / Pending Absences) had `aria-hidden="true"` on the value spans, which silenced every metric for screen reader users. Each card now exposes a single, fully readable accessible name (e.g. "5 team members active today") via a `role="group"` wrapper while keeping the visual layout intact.
- **Alert vs live-region conflicts resolved**: Removed conflicting `aria-live="polite"` from `role="alert"` containers in `absences.php` (form error), `admin-settings.php` (global error banner), and three time-entry inline form errors. `role="alert"` already implies assertive announcements, so the previous `polite` override could delay critical validation feedback for assistive technology.
- **Page heading hierarchy normalized**: Every primary page template now exposes exactly one `<h1>` (dashboard, time-entries, absences, calendar, timeline, reports, settings, personal-settings, compliance-dashboard, compliance-reports, compliance-violations, working-time-models, admin-dashboard, admin-users, admin-holidays, admin-settings, admin-notifications, manager-dashboard). Previously most pages started at `<h2>`. Subordinate section headings in time-entries and absences were promoted to `h2`/`h3` so the ladder no longer skips levels. CSS rules for `.section-header h1` were added to inherit the existing `h2` styling.
- **Manager dashboard breadcrumb**: Added the standard "Dashboard › Manager Dashboard" breadcrumb to align with all other primary pages and improve orientation for keyboard and screen reader users.
- **Calendar loading state announced**: The "Loading calendar…" placeholder now uses `role="status"` with `aria-live="polite"` so the loading and ready transitions are announced. The decorative spinner is `aria-hidden`.
- **Focus indicators restored**: `outline: none` was used on the timeline filter checkboxes and the admin user-picker items, breaking keyboard focus visibility. Added `:focus-visible` outlines using the primary color for both, preserving hover styling.
- **Mobile touch targets**: `.btn--sm` was 36 × 36 px on mobile, below WCAG 2.5.5's 44 × 44 advisory. The mobile media query now enforces 44 × 44 px on small buttons; desktop sizing is unchanged.
- **Empty-state row for legacy `index.php` time-entries view**: Restored the missing empty-state row when no entries exist (parity with the other table views in the same template).
- **Reports access fallback navigation**: Replaced an inline `onclick` redirect in `reports.php`'s no-access empty state with a real `<a>` anchor so the dashboard fallback works without JavaScript and inherits standard link semantics.

### Removed

- **Stale Nextcloud personal-settings panel placeholder**: The old `personal-settings.php` panel rendered inside Nextcloud's user-settings shell with hardcoded vacation-days / working-hours fields and reminder checkboxes that were never wired to any backend. Replaced it with a clean, accurate panel pointing the user to the in-app personal settings page (where these preferences are actually persisted via `SettingsController::update`) plus a short GDPR data-rights note. Kept the legacy `index.php` "settings" branch (dead code, but still in the file) but pulled the previously hardcoded `1.0.1` version string from `IAppManager::getAppVersion('arbeitszeitcheck')`.

### Tests

- **AccessibilityTest hardened**: Replaced the "must contain `<button>`" assertion (which permitted pages without keyboard-reachable controls and false-flagged link-only panels) with two stricter checks: (1) explicitly forbid the `<div onclick=…>` anti-pattern and (2) require at least one `<button>` or `<a href>` per audited template. Total suite: 455 tests, 1 652 assertions, all green.

## 1.2.7 - 2026-04-27

### Added

- **Critical workflow audit checklist**: Added `tests/WORKFLOW_AUDIT_CHECKLIST.md` as a concise release checklist for time tracking, manual entry corrections, absences/approvals, month closure, reporting/compliance/export behavior, and public error-surface expectations.

### Changed

- **Time tracking mutation safety**: Clock/break mutations now use user-scoped locks and transactions; status polling remains read-only while automatic break fallback and daily maximum enforcement run through explicit mutation paths/background jobs.
- **API input and error hardening**: Report, export, compliance, manager, and time tracking endpoints now use stricter date/time parsing, safer validation responses, and generic public error messages for unexpected failures.
- **Month-closure enforcement**: Absence update/delete/cancel/shorten/approval/substitute flows now re-check month mutability before applying workflow mutations.

### Fixed

- **Health endpoint fingerprinting**: The public health response no longer exposes app or Nextcloud version fields.

## 1.2.6 - 2026-04-24

### Added

- **Absence approval forensics**: Added `approved_by_user_id` persistence on absence records (approve/reject/auto-approve), with schema migration and API summary output.

### Changed

- **Vacation entitlement snapshot integrity**: Added deterministic key-based upsert on `(user_id, period_key, as_of_date)` and migration-backed unique index enforcement.
- **Concurrency control in critical workflows**: Absence create/update/approve/reject/substitute flows now use user-scoped mutation locks plus transactional rechecks/row locks to prevent race-based overlap and over-approval inconsistencies.
- **Release safety**: Workflow/unit/integration tests were updated and executed against the hardened mutation paths.

### Fixed

- **Legacy snapshot repair path**: Upsert now handles historical malformed rows and concurrent unique-key conflicts safely by retrying as deterministic update.
- **Vacation balance write races**: `VacationYearBalanceMapper::upsert` now resolves concurrent unique-key collisions via re-read/update fallback.

## 1.2.5 - 2026-04-22

### Changed

- **Release packaging refresh**: Bumped app metadata to `1.2.5` and regenerated the signed release artifact set for App Store and GitHub publication.

## 1.2.4 - 2026-04-21

### Changed

- **Publishable release refresh**: Bumped app metadata to `1.2.4` and generated a new signed release artifact set (archive, checksums, and App Store signature) for App Store/GitHub publication.

## 1.2.3 - 2026-04-21

### Changed

- **Release packaging refresh**: Prepared a new signed App Store/GitHub release archive for the current code line using the Docker-based signing workflow.

## 1.2.2 - 2026-04-21

### Fixed

- **Localized decimal inputs in admin settings**: Daily working-hour inputs now reliably accept comma-decimals like `7,74` and preserve two-decimal precision.
- **Legacy hours API payload parsing**: Time-entry endpoints now parse optional decimal hour fields consistently for both comma and dot separators, preventing silent truncation in backward-compatible request formats.

### Changed

- **Input precision hints**: Updated settings input steps/help text to align with two-decimal hour values used in 38.7-hour week scenarios.

## 1.2.1 - 2026-04-21

### Fixed

- **Paused-entry recovery and lifecycle**: Paused entries can now be accessed again in edit/delete workflows and are consistently finalized as `completed` when edited with an end time.
- **Resume behavior for same-day paused sessions**: Clock-in now resumes a same-day paused entry instead of creating duplicate automatic entries, while preserving the pause gap as break history.
- **Historical paused leftovers**: Added migration `Version1020Date20260421000000` to repair all remaining orphaned `paused` rows (including cases not covered by the earlier one-time migration).

## 1.2.0 - 2026-04-21

### Added

- **Vacation entitlement policy engine**: New policy-driven calculation flow with support for `manual_fixed`, `model_based_simple`, `tariff_rule_based`, and `manual_exception`, plus admin simulation endpoint.
- **Tariff rule data model and APIs**: Added versioned tariff rule sets/modules and admin endpoints to create, update, activate, retire, and assign policies to users.
- **Entitlement computation snapshots**: Added persistent entitlement snapshots (`at_entitlement_snapshots`) with calculation trace/policy fingerprint for auditability and diagnostics.
- **Admin notifications page**: New dedicated admin UI (`/admin/notifications`) with HR recipient + event matrix management and a dedicated notifications settings API.

### Changed

- **Vacation allocation integration**: Year allocation now resolves entitlement via `VacationEntitlementEngine` and returns entitlement source/rule-set/trace metadata in allocation payloads.
- **Policy migration compatibility**: Existing user model vacation values are backfilled into policy assignments during migration (`Version1018Date20260420123000`) to keep legacy installs consistent.
- **Admin settings flow**: Absence notification-related controls (carryover expiry/cap, rollover switches, substitute-required types, iCal and substitution-mail toggles) are centralized on admin notifications APIs/UI.
- **Working time model schema**: Added `work_days_per_week` to `at_models` (`Version1019Date20260420150000`) to support entitlement formulas.

### Fixed

- **User deletion cleanup**: Deleting a user now also removes vacation policy assignments and entitlement snapshots, preventing orphaned policy/computation data.

## 1.1.14 - 2026-04-14

### Fixed

- **Approver deadlock (app teams)**: Absence and time-entry correction workflows no longer treat “has colleagues” as “has a manager”. Auto-approval when **no assignable approver** exists now follows `TeamResolverService::hasAssignableManagerForEmployee()` (explicit team managers in app-teams mode; legacy group mode still uses colleagues as a proxy). Prevents requests stuck in “awaiting manager approval” when nobody can approve.
- **Time entry corrections**: Same assignability rule as absences (previously used colleague IDs only).
- **Admin users API requests on `/index.php` instances**: Refresh/edit/history/update actions now reliably resolve app URLs and no longer produce invalid requests like `search=[object PointerEvent]`.
- **Admin teams and settings API reliability on rewrite-less setups**: Central URL resolution now includes a robust `/index.php` fallback when `OC.generateUrl()` is unavailable/incomplete in page context.

### Added

- **Repair step** `ReleaseStuckPendingAbsences`: post-migration repair auto-approves legacy `pending` absences that still match the “no assignable approver” condition (idempotent).
- **Frontend URL security guardrails**: Shared AJAX layer now blocks external cross-origin calls by default (explicit `allowExternal: true` required), with unit tests covering URL normalization and external URL handling.
- **Lint guardrails**: ESLint rules now prevent introducing raw `fetch('/apps/arbeitszeitcheck/...')` and implicit external `fetch(...)` patterns outside approved abstractions.

### Changed

- **UX**: Absences UI shows an informational callout when app teams are enabled and no approver is assigned; detail view shows a defensive warning if an old `pending` row is still stuck (until repair/admin fixes team setup).
- **Frontend architecture**: `ArbeitszeitCheckUtils` now provides centralized `getRequestToken()`, `resolveUrl()`, and `isExternalUrl()` primitives used by page scripts (`admin-users`, `reports`, `settings`, `validation`).
- **Mobile UX consistency (WCAG 2.1 AA focused)**: iPhone-safe-area-aware spacing, improved touch targets, clearer section rhythm, and better visual hierarchy for normal user pages (`dashboard`, `time-entries`, `absences`) and manager pages (`manager-dashboard`, `manager-time-entries`, employee absences view).

### Documentation

- User manuals (EN/DE), `tests/WORKFLOW_ROLE_MATRIX.md`, and developer documentation updated for assignable-manager semantics and repair step.
- README and developer documentation updated with centralized frontend URL policy, strict external-call behavior, and mobile/iOS layout guidance.

## 1.1.13 - 2026-04-13

### Added

- **Month closure grace period and auto-finalization**: Admin setting `month_closure_grace_days_after_eom` (0–90, default 0). After end-of-month, employees have that many calendar days to finalize manually; if the month is still open afterward, a daily background job finalizes it automatically (same snapshot as manual finalize). Pending time entry approvals and open absence workflow states block auto-finalization. Reopening remains admin-only.
- **App-admin allowlist**: New admin setting `app_admin_user_ids` to restrict ArbeitszeitCheck administration to a selected subset of Nextcloud admins. Empty selection keeps backward-compatible behavior (all Nextcloud admins can administer the app).
- **Security role-gating Docker test target**: Added `scripts/test-security-role-gating-docker.sh` wiring via `make test-security-role-gating-docker` and `composer test:security-role-gating:docker` for fast authorization regression checks in containerized setups.

### Changed

- **Month closure UX and API**: Employee UI uses a clearer card layout, visible feedback for success/errors (WCAG-friendly), server-driven `canFinalize` with localized block reasons (feature off, future month, pending approvals). Manual finalize rejects future calendar months. Absence workflow (`pending`, `substitute_pending`, `substitute_declined`) is enforced alongside pending time entry corrections. Unauthorized API access returns 401 where appropriate. Admin settings: dedicated “Month closure” section; grace-days field stays editable with copy explaining it is saved even when closure is off; reopen uses searchable employee picker and clearer administrator vs. employee wording. Form validation error callouts use higher-contrast text and tinted surfaces across themes. Auto-finalize job logs per-user failures for operations.
- **Release/signing workflow hardened for integrity checks**: `make release-signed` now signs the extracted release archive payload (not the local development checkout), validates forbidden development paths are excluded, and repacks the signed archive for deployment/App Store upload.
- **Admin authorization enforcement**: Access to `AdminController` routes now uses middleware-level app-admin checks with a dedicated exception and a consistent 403 response page for authenticated users without app-admin rights.

### Documentation

- **Deployment guidance**: Release docs now explicitly require production deployment from the signed tarball only and document the common integrity-failure pattern (`.git/*` / `node_modules/*` lists) caused by signing a dev tree.
- **Deployment helper script**: Added `release/deploy-from-release.sh` to deploy from signed release archives with safety checks (forbidden path scan, required `signature.json`, optional app disable/enable and `occ integrity:check-app`).
- **Admin operations**: User/developer docs now describe how to configure app-admin allowlisting, what the default fallback is, and how to verify authorization gating in Docker-based test runs.

## 1.1.12 - 2026-04-09

### Added

- **Revision-safe month finalization (optional)**: Admin toggle `month_closure_enabled` (default off). Employees can finalize a full calendar month; the app stores a canonical JSON snapshot, SHA-256 hash chain, append-only revision rows, audit events, and a minimal PDF download. Finalized months are read-only through normal app APIs; administrators may reopen a month with a mandatory reason (audit). Monthly reports for a finalized month use the stored snapshot. Database: `at_month_closure`, `at_month_closure_revision` (migration `Version1014Date20260409120000`).

### Documentation

- User manuals (EN/DE), developer documentation, and compliance notes updated for month closure, retention context, and limits (in-app tamper evidence, not QES).

## 1.1.11 - 2026-04-09

### Added

- **Manager employee absences view**: New in-app page and API for managers/admins to review employee absences with secure scope filtering, pagination, and localized status labels.
- **Working time model copy flow**: Added copy action with modal UX, unique default naming, and safeguards against duplicate submits.

### Changed

- **Manager navigation structure**: Sidebar regrouped into clearer manager/admin submenus; reports moved under manager context; compliance link placement adjusted for reduced top-level clutter.
- **Manager employee time entries UX**: Date defaults and formatting/translation handling improved for clearer filtering behavior.
- **Calendar behavior (rollback cleanup)**: Removed in-progress direct calendar-write functionality and related admin controls/status/test endpoints. The supported behavior remains unchanged: no Nextcloud Calendar app sync; optional `.ics` attachments are sent by email for configured absence workflows.

### Fixed

- **Working time model modals**: Corrected copy modal interaction flow, source-model presentation, and delete-confirmation localization/rendering issues.
- **Absence iCal hardening**: Added stricter status/date guards, recipient deduplication, and privacy-safe event descriptions for substitute/manager recipients.

### Documentation

- User manuals and changelogs updated to reflect the final calendar model (email `.ics` optional, no direct Nextcloud Calendar app sync) and current manager/admin UX structure.

## 1.1.10 - 2026-04-07

### Added

- **Vacation rollover**: `VacationRolloverService`, background job, `occ arbeitszeitcheck:vacation-rollover`, migration `Version1013Date20260407120000` with `at_vacation_rollover_log`; unit tests.

### Changed

- **Frontend l10n**: Shared `templates/common/main-ui-l10n.php` and `teams-l10n.php` so translated strings are available early across pages; related template and JS updates.

### Fixed

- **Manager dashboard — pending absences**: API includes `summary.typeLabel` (server-localized absence type); UI prefers it so cards show translated labels (e.g. German *Urlaub*) instead of raw codes like `vacation`.

### Documentation

- `docs/Developer-Documentation.en.md`: pending-approvals API note for `typeLabel`; user manuals (EN/DE): manager pending approvals show localized absence types.

## 1.1.9 - 2026-04-05

### Removed

- **Nextcloud Calendar app (CalDAV)**: Absence sync into the Calendar app is removed; migration `Version1012Date20260406120000` drops the `at_absence_calendar` table. Calendars previously created in the Calendar app are not deleted automatically.

### Changed

- **Holiday service**: Public holiday calendar logic consolidated in `HolidayService`.

### Fixed

- **AdminController**: Duplicate `use` statement for `HolidayService` caused a PHP fatal error (e.g. when PHPUnit loaded the class).

### Documentation

- User manuals (EN/DE) in `docs/`, README and developer documentation updated; helper script `docker/run-app-phpunit.sh` for containerized PHPUnit.

## 1.1.7 - 2026-04-05

### Added

- **Vacation carryover (Resturlaub)**: Per user and calendar year, opening balance `carryover_days` in `at_vacation_year_balance`; global admin setting for carryover expiry (month/day, default 31 March). `VacationAllocationService` applies FIFO consumption of approved vacation (by `start_date`, then `id`) and splits working days before/after expiry so carryover is used first where still valid.
- **Validation & approvals**: Vacation requests are re-validated when a manager approves (and on auto-approve) so concurrent pending requests cannot overdraw balances after approval.
- **API & UI**: `AbsenceController::stats` exposes entitlement, carryover, totals, expiry-related fields; dashboard and absences pages show a clear vacation summary; admin settings include expiry fields.
- **GDPR**: `UserDeletedListener` removes vacation year balance rows when a user account is deleted.
- **Migration / bulk setup**: `occ arbeitszeitcheck:import-vacation-balance` imports CSV `user_id,year,carryover_days` with `--dry-run`.

### Tests

- Unit tests for `VacationAllocationService`; extended `AbsenceService` and related controller tests.

## 1.1.6 - 2026-03-27

### Added

- **Development tooling**: `occ arbeitszeitcheck:generate-test-data` CLI for deterministic demo data (time entries, absences, optional violations, demo app team) to exercise UI, reports, and workflows locally.
- **Exports**: `TimeEntryExportTransformer` centralizes field mapping and CSV shaping for time-entry exports; `ExportController` delegates to it for a single, testable pipeline.

### Fixed

- **Reports UI**: Report type cards are no longer incorrectly disabled when a team-related scope is selected (team scopes still use the team report API where applicable).
- **Reports (tests)**: Team report CSV download test now reads download bodies via `DataDownloadResponse::render()` (Nextcloud API).
- **Team reports**: Deduplicate user IDs before permission checks and aggregation to avoid double-counting when users appear in multiple teams.
- **Absence type badges**: Stronger, theme-safe contrast for vacation / sick / home office / other badges (readable on pale Nextcloud palettes).

### Changed

- **Compatibility (dev)**: Local development stacks aligned with Nextcloud 33.x (example: official `nextcloud` Docker image).
- **Reports layout**: Reverted an overly aggressive “full width” parameter form rule that could interfere with scrolling/layout on the reports page.
- **Reports UI**: Templates, JavaScript, and styling updates for the reports page; admin settings hook for related options.
- **Reporting**: `ReportController` and `ReportingService` adjustments aligned with the export refactor.

### Tests

- Unit tests for `TimeEntryExportTransformer`; expanded `ReportController` tests; `ExportController` tests updated for the new wiring.

## 1.1.5 - 2026-03-26

### Fixed
- **Admin settings API URL handling**: Prevented duplicate `index.php/index.php` path generation when a route URL is already pre-generated by Nextcloud.
- **Frontend error handling**: Avoided unhandled Promise rejections in callback-based `Utils.ajax()` consumers after expected API failures.

## 1.1.4 – 2026-03-25

### Fixed
- **Routing/compatibility**: Added `indexApi()` compatibility aliases for legacy endpoints to prevent 500 errors in the Nextcloud log.
- **PHP fatal errors**: Fixed constructor signature issues in `AbsenceService` and `ComplianceService` that could crash the app when loading services or saving settings.
- **Reports security hardening**: Hardened report preview endpoints with `start <= end` validation and a maximum date-range limit to reduce DoS risk from untrusted parameters.
- **Admin “whole organization” scope**: Correctly handle admin organization scope (`userId=""` = all enabled users) and enforce access checks so preview/download data stays consistent.
- **Reports rendering**: Improved Preview rendering for **absence** and **compliance** reports to match the actual report data structure.

### Changed
- **Reports UI semantics**: Team scope is limited to the team overview/export semantics that the backend actually returns (prevents misleading previews/downloads).
- **Organization download guidance**: Added explicit UI messaging for organization scope download limitations until organization-wide export endpoints are implemented.

## 1.1.3 – 2025-03-14

### Fixed

- **ArbZG compliance**: Corrected break check logic (9h/45min branch now reachable; check ≥9h before ≥6h)
- **Manager logic**: `employeeHasManager()` now uses `getManagerIdsForEmployee()` instead of `getColleagueIds()`
- **Reporting**: `getTeamHoursSummary()` respects period parameter (week/month)
- **Admin users**: `hasTimeEntriesToday` is now per-user, not system-wide
- **UserSettingsMapper**: Fixed falsy zero/empty-string handling in getIntegerSetting, getFloatSetting, getStringSetting
- **Routing**: Moved exportUsers route above getUser to fix route shadowing
- **Version1009 migration**: Replaced MySQL backtick SQL with portable QueryBuilder; use OCP\DB\Types
- **Duplicate notifier**: Removed double registration from Application.php boot()
- **API security**: Generic error messages instead of raw exception output (SubstituteController, GdprController)
- **PDF export**: Returns HTTP 422 with clear message instead of silent CSV fallback
- **LIKE injection**: WorkingTimeModelMapper::searchByName() uses escapeLikeParameter()
- **XSS**: Modal titles escaped in components.js; compliance-violations.js innerHTML escaped
- **Admin-settings form**: Added CSRF requesttoken
- **AbsenceService DI**: Fixed constructor argument order (IDBConnection)
- Admin holidays and settings: English source strings for l10n keys
- UserDeletedListener: inject TeamMemberMapper and TeamManagerMapper
- XSS: sanitise team names in admin-teams.js

### Changed

- **CSS**: Shadow-light variable, scoped resets, dark-mode color-mix fixes, semantic color variables, navigation height/z-index
- **Clock buttons**: Double-submit guard (disabled during API calls)
- **initTimeline()**: Max retry count (20) to prevent infinite loop
- **Accessibility**: aria-label on header buttons, label for admin user search, aria-modal on welcome dialog, English l10n keys in navigation
- **Docs**: Removed internal docs; added docs/README; corrected repo URLs
- **Manager dashboard**: Injected l10n from PHP so JS translations work
- Constants.php for magic numbers; user-facing error messages

### Added

- **Version1010 migration**: Compound indices on at_entries, at_violations, at_holidays, at_absences

## 1.1.2 – 2025-03-07

### Changed

- **Long-term refactor**: Replaced all `\OC::$server` usage with proper OCP APIs and constructor injection
- CSPService: Injected ContentSecurityPolicyNonceManager via constructor
- Controllers: Removed manual cspNonce (configureCSP handles it); injected IURLGenerator, IConfig where needed
- PageController: Injected IURLGenerator, IConfig; passes urlGenerator to templates
- HealthController: Injected IDBConnection for database check
- ProjectCheckIntegrationService: Injected LoggerInterface instead of OC::$server->getLogger()
- Templates: Replaced `\OC::$server` with `\OCP\Server::get()` (OCP public API)
- Added GitHub Actions release workflow (`.github/workflows/release.yml`)
- Updated PageControllerTest with full constructor mocks

## 1.1.1 – 2025-01-07

### Fixed

- Resolved duplicate route names in absence API (absence#store, absence#show, absence#update, absence#delete)
- Corrected settings class names in info.xml to use full OCA namespace
- Added declare(strict_types=1) to routes.php

### Changed

- Removed non-existent screenshot references from info.xml until real screenshots are captured

## 1.1.0 – 2025-01-04

### Added

- ProjectCheck integration for project time tracking
- Additional migrations for schema updates

## 1.0.3 – 2025-01-03

### Added

- Further database schema refinements

## 1.0.2 – 2025-01-02

### Added

- Working time models
- User working time model assignments

## 1.0.1 – 2025-01-01

### Added

- Absence management
- Audit logging
- User settings
- Compliance violation tracking

## 1.0.0 – 2024-12-29

### Added

- Initial release
- German labor law (ArbZG) compliant time tracking
- Clock in/out and break tracking
- Time entry management (create, edit, delete, manual entries)
- Basic compliance checks (max 8h/day, break requirements)
- GDPR-compliant data processing
- English and German translations
- WCAG 2.1 AAA accessibility compliance
