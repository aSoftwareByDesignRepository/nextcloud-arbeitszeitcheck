# Workflow / Role / Edge-case matrix (test inventory)

This document is the **test coverage inventory** for `apps/arbeitszeitcheck`.
It maps **routes → workflows → required roles/permissions** and highlights **edge cases** that must be covered in PHPUnit + Playwright.

Roles referenced:
- **Employee**: regular authenticated user acting on own data
- **Manager**: can manage team members (via **Nextcloud groups** *or* **app teams** `use_app_teams=1`)
- **Admin**: Nextcloud admin group
- **Substitute**: user selected as substitute approver for an absence request

Team modes that must be covered for all permission-sensitive scenarios:
- **GroupTeams**: legacy “team” derived from Nextcloud group membership
- **AppTeams**: app-owned teams (`at_teams`, `at_team_members`, `at_team_managers`)

Source of truth for routes: `appinfo/routes.php`.

## Pages (GET, HTML)
- **`page#index` `/`**: Employee/Manager/Admin
- **`page#dashboard` `/dashboard`**: Employee/Manager/Admin
- **`page#timeEntries` `/time-entries`**: Employee/Manager/Admin
- **`page#absences` `/absences`**: Employee/Manager/Admin
- **`page#reports` `/reports`**: Employee + Manager/Admin (scope differs)
- **`page#calendar` `/calendar`**: Employee/Manager/Admin
- **`page#timeline` `/timeline`**: Employee/Manager/Admin
- **`page#settings` `/settings`**: Employee/Manager/Admin
- **`manager#dashboard` `/manager`**: Manager/Admin; Employee should be denied/redirected
- **`substitute#index` `/substitution-requests`**: Substitute (only meaningful if there are pending substitute approvals)
- **`compliance#dashboard` `/compliance`**: Employee/Manager/Admin (scope differs)
- **`compliance#violations` `/compliance/violations`**: Employee/Manager/Admin (scope differs)
- **`compliance#reports` `/compliance/reports`**: Manager/Admin (team/org scope), Employee (self scope) depending on implementation
- **Admin pages `/admin/*`**: Admin only

## Health
- **`health#check` `/health` (GET)**: should be reachable while authenticated; response structure + status codes

## Time tracking (clock/break)
Routes:
- POST `/api/clock/in`, POST `/api/clock/out`, GET `/api/clock/status`
- POST `/api/break/start`, POST `/api/break/end`, GET `/api/break/status`

Role expectations:
- **Employee**: allowed for self; must not affect other users.
- **Manager/Admin**: same as employee for self; *no cross-user action* via params.

Edge cases to test:
- **Already active**: clock-in rejected when active; status stable
- **Paused resume**: clock-in resumes paused entry (auto-break appended)
- **Clock-out semantics**: clock-out pauses (does not necessarily set endTime) and is resumable
- **Break sequencing**: cannot start break when not active; cannot end break when not on break
- **Daily max**: entry auto-completes when maximum reached
- **Rest period**: resume/entry start blocked/flagged according to configured minimum rest period

## Time entries (CRUD + overlap + corrections)
Routes:
- HTML: GET `/time-entries/create`, GET `/time-entries/{id}`, GET `/time-entries/{id}/edit`
- API: GET/POST `/api/time-entries`, GET/PUT/POST/DELETE `/api/time-entries/{id}`
- Extras: GET `/api/time-entries/check-overlap`, POST `/api/time-entries/{id}/request-correction`
- Stats: GET `/api/time-entries/stats`, `/overtime`, `/overtime/balance`
- Delete impact: GET `/api/time-entries/{id}/deletion-impact`

Role expectations:
- **Employee**: full CRUD for **own** entries, within edit-window constraints
- **Manager/Admin**: may view team reports; must not be able to directly mutate another user’s entries except via explicit approval workflows (corrections) if supported

Edge cases:
- **Ownership/IDOR**: accessing/updating/deleting another user’s entry is denied
- **Edit window**: updates outside window rejected unless admin exception exists (if any)
- **Overlap**: overlap detection works with excludeEntryId
- **Cross-midnight**: export/report splitting behavior and duration computations
- **Breaks representation**: legacy single-break fields vs JSON `breaks[]`
- **Correction workflow**:
  - request requires justification
  - status transitions (completed → pending_approval → completed/rejected)
  - manager approval applies proposed changes, rejection restores original
  - “no manager” auto-approval behavior (if implemented)

## Absences (CRUD + approvals + substitute flow)
Routes:
- HTML: GET `/absences/create`, GET `/absences/{id}`, GET `/absences/{id}/edit`
- API: GET/POST `/api/absences`, GET/PUT/DELETE `/api/absences/{id}`
- Workflow: POST approve/reject/cancel/shorten
- Helpers: GET `/api/colleagues`, GET `/api/absences/stats`
- Substitute: GET `/api/substitution-requests`, POST approve/decline

Role expectations:
- **Employee**: create/update/delete own requests (subject to status); view own; cancel/shorten own where allowed
- **Substitute**: can approve/decline substitute-pending requests where they are the designated substitute; must not act on others
- **Manager/Admin**: can approve/reject for team members; cannot approve self via manager endpoints

Edge cases:
- **Status machine**: pending → approved/rejected/cancelled; substitute_pending → substitute_approved/substitute_declined → pending_manager → approved/rejected (depending on flow)
- **Working-days calculation**: holidays/weekends; state selection
- **Colleagues list**: only team colleagues; both team modes

## Manager APIs
Routes:
- GET team overview/pending approvals/compliance/team hours/teams, calendar
- POST approve/reject absence
- POST approve/reject time-entry correction

Role expectations:
- **Manager/Admin**: allowed; scope limited to managed employees/teams
- **Employee**: denied

Edge cases:
- **Team mode parity**: same behaviors in GroupTeams and AppTeams
- **Mixed approvals list**: time corrections + absences, pagination/filters

## Compliance APIs
Routes:
- GET violations, GET violation, POST resolve
- GET status, GET report
- POST run-check, GET check-rest-period

Role expectations:
- **Employee**: may see own violations/status; report endpoints should be self-scoped unless manager/admin
- **Manager/Admin**: see team (and org-wide for admin if implemented); resolve within scope

Edge cases:
- **Unauthorized access**: violation detail should not leak existence/ownership via errors (ensure correct status codes)
- **Resolve flow**: idempotency, already resolved, invalid IDs

## Reports APIs
Routes:
- GET daily/weekly/monthly/overtime/absence/team

Role expectations:
- **Employee**: self scope
- **Manager/Admin**: team/org scopes (as implemented)

Edge cases:
- **Scope params**: userId/userIds/teamId/managed toggles (where supported)
- **Large range limits**: enforce max window if present in services

## Admin APIs (Admin only)
Routes:
- settings, holidays (legacy + state), statistics, audit logs (+ export)
- users (+ export), working-time models, assignments history
- app teams management + config toggle

Edge cases:
- **Teams config toggle**: switching between GroupTeams/AppTeams does not break permissions
- **Delete impact**: team deletion impact correctness
- **Holiday CRUD**: duplicates, date/state validation, legacy endpoint parity

## Exports
Routes:
- GET `/export/*` + GET `/api/export/datev/config`

Role expectations:
- **Employee**: export self
- **Manager/Admin**: export team/org where implemented

Edge cases:
- **Range limit**: `MAX_EXPORT_DATE_RANGE_DAYS`
- **DATEV formatting**: required columns, stable ordering, midnight split option

## GDPR
Routes:
- GET `/gdpr/export`, POST `/gdpr/delete`

Role expectations:
- **Employee**: self only

Edge cases:
- **Retention**: deletes only eligible entries; leaves required records intact
- **Export integrity**: includes expected domains (entries, absences, settings, violations, audit)

