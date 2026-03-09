# Roles and Permissions — ArbeitszeitCheck

This document is the **authoritative definition** of roles and permissions for the ArbeitszeitCheck application. It is intended for compliance, auditing, and legal defensibility (e.g. labour law, data protection, and evidence in dispute).

**Document version:** 1.0  
**Application:** ArbeitszeitCheck (arbeitszeitcheck)  
**Last updated:** 2025-03-07

---

## 1. Role definitions

Roles are **not** stored in the database. They are derived from:

- **Nextcloud group membership** (admin group)
- **Shared group membership** (team = users who share at least one group with the acting user)
- **Resource-level assignment** (substitute is designated per absence)

| Role | Definition | How it is determined |
|------|------------|------------------------|
| **Admin** | Nextcloud administrator | User is in the Nextcloud admin group (`groupManager->isAdmin($userId)`). |
| **Manager** | User who may approve/reject absences and time-entry corrections for a set of employees | When **app teams** disabled: user shares at least one Nextcloud group with those employees. When **app teams** enabled: user is a manager of a team (or descendant) that contains those employees. Admins are always managers for all users. |
| **Substitute** | User designated to approve/decline a specific absence request (Vertretungs-Freigabe) | Stored on the absence record (`absence.substitute_user_id`). Only colleagues in the same team/group may be selected as substitute. Only the designated substitute may approve or decline that absence while it is in status `substitute_pending`. |
| **Employee** | Any authenticated user of the app | Logged-in user. Can manage only their own time entries, absences, and view their own reports/compliance. |

**Important:** A user can hold multiple roles (e.g. Admin and Manager, or Employee and Substitute for a given absence).

**App teams vs groups:** Admin setting `use_app_teams` switches team resolution. When `0` (default): Nextcloud groups (same-group members = team). When `1`: app-owned teams (`at_teams`, `at_team_members`, `at_team_managers`) – managers see members of teams they manage; colleagues for substitute selection come from teams the user is in.

---

## 2. Permission matrix

### 2.1 Admin-only actions

Only users in the **Nextcloud admin group** may access these. Routes are **not** marked with `NoAdminRequired`, so Nextcloud enforces admin before the controller runs.

| Action | Route / area | Enforced by |
|--------|----------------|-------------|
| Access admin dashboard | `/admin` | Nextcloud (no NoAdminRequired) |
| Manage app settings (global) | Admin settings API | Nextcloud |
| Manage users (working time models, etc.) | Admin users API | Nextcloud |
| View/export audit logs | Admin audit API | Nextcloud |
| Manage working time models | Admin working-time-models API | Nextcloud |
| Run compliance report (global) | Compliance run-check (admin-only branch) | Controller check `groupManager->isAdmin()` |

### 2.2 Manager actions

**Who is a manager for an employee:**  
User A may act as manager for employee E if and only if:

- A is a Nextcloud admin, **or**
- A and E share at least one Nextcloud group (A’s “team” includes E).

**Enforcement:** All manager actions are guarded by `PermissionService::canManageEmployee($managerUserId, $employeeUserId)` (which implements the rule above).

| Action | Resource | Condition |
|--------|----------|-----------|
| View manager dashboard | — | User is manager for at least one employee **or** is admin |
| Approve absence | Absence (owner = employee) | `canManageEmployee(actor, absence.userId)` |
| Reject absence | Absence (owner = employee) | `canManageEmployee(actor, absence.userId)` |
| Approve time entry correction | Time entry (owner = employee) | `canManageEmployee(actor, entry.userId)` |
| Reject time entry correction | Time entry (owner = employee) | `canManageEmployee(actor, entry.userId)` |
| View team overview / pending approvals / team compliance / team hours / absence calendar / pending corrections | — | Same as “View manager dashboard” (team or admin) |
| View report for another user | Report (target user) | `canViewUserReport(actor, targetUserId)` → self, or admin, or `canManageEmployee(actor, targetUserId)` |
| View compliance data for another user | Compliance (target user) | Same as report: self, or admin, or `canManageEmployee(actor, targetUserId)` |
| Resolve compliance violation | Violation (owner = employee) | Admin or `canManageEmployee(actor, violation.userId)`. Owner cannot resolve own (separation of duties). |

### 2.3 Substitute actions

| Action | Resource | Condition |
|--------|----------|-----------|
| View substitution requests | — | Authenticated user; list filtered to absences where `absence.substitute_user_id = currentUser` |
| Approve substitution | Absence | `absence.substitute_user_id === currentUser` and status is `substitute_pending` |
| Decline substitution | Absence | `absence.substitute_user_id === currentUser` and status is `substitute_pending` |

**Enforcement:** Substitute identity is enforced in `AbsenceService::approveBySubstitute` and `declineBySubstitute` (comparison with `absence.getSubstituteUserId()`). Controllers only pass the current user ID; no separate “substitute role” is stored.

### 2.4 Employee (own data) actions

| Action | Resource | Condition |
|--------|----------|-----------|
| Create/read/update/delete own time entries | Time entry | `entry.userId === currentUser` |
| Create/read/update/delete own absences (when status allows) | Absence | `absence.userId === currentUser` |
| Request correction for own time entry | Time entry | `entry.userId === currentUser` |
| View own reports / compliance / exports / GDPR export | Own data | `currentUser` only (or via manager/admin as above) |

**Enforcement:** Time-entry ownership is checked in `TimeEntryController` (e.g. `entry.getUserId() !== $userId` → 403). Absence ownership and status rules are enforced in `AbsenceService`.

---

## 3. Implementation (single source of truth)

- **PermissionService** (`lib/Service/PermissionService.php`) is the single place that implements:
  - `isAdmin(string $userId): bool`
  - `canManageEmployee(string $managerUserId, string $employeeUserId): bool`
  - `canAccessManagerDashboard(string $userId): bool`
  - `canViewUserReport(string $viewerUserId, string $targetUserId): bool` (self, admin, or canManageEmployee)
  - `canViewUserCompliance(string $viewerUserId, string $targetUserId): bool` (same rule as report)
  - `canResolveViolation(string $actorUserId, string $violationOwnerUserId): bool` (admin or canManageEmployee)

- **TeamResolverService** continues to provide `getTeamMemberIds($userId)` and `canUserManageEmployee($approver, $employee)`; **PermissionService** uses it and adds the admin override so that admins can manage any employee.

- Controllers **must** use PermissionService (or, for substitute, the absence’s `substitute_user_id`) for any permission check. They must **not** duplicate logic (e.g. ad-hoc `groupManager->isAdmin` + `teamResolver->getTeamMemberIds`).

- **Audit logging:** All approval/rejection and other sensitive actions are logged (e.g. audit log, justification on time entries). Permission denials can be logged by PermissionService. The following are audited with old/new values and `performedBy`: working time model CRUD; user working time model assignments (work schedule, vacation days); team CRUD and team member/manager add/remove; compliance violation resolution; time entry correction approve/reject (full before/after); time entry auto-completion at ArbZG 10h daily limit.

---

## 4. Route-to-role summary

| Route area | Required role | Notes |
|------------|----------------|-------|
| `/admin/*` | Admin | Nextcloud-enforced |
| `/manager`, `/api/manager/*` | Manager (or admin) | Dashboard redirects non-managers to main app |
| `/substitution-requests`, `/api/substitution-requests/*` | Any authenticated; data scoped to current user as substitute | |
| `/`, `/dashboard`, `/time-entries`, `/absences`, `/reports`, `/compliance`, etc. | Any authenticated | Data scoped to own or to team/admin via PermissionService where applicable |
| `/api/reports/*` (team/cross-user) | Self, or admin, or manager for target user | `PermissionService::canViewUserReport` |
| `/api/compliance/*` (cross-user / resolve) | Self, or admin, or manager for target/violation owner | `PermissionService::canViewUserCompliance` / `canResolveViolation` |

---

## 5. Legal and compliance notes

- **Separation of duties:** Approvals (absences, time corrections) are restricted to managers (including admins) or substitutes as defined above; employees cannot approve their own manager-level actions.
- **Traceability:** All approval/rejection and material changes are logged (audit log, justification fields) so that who did what and when can be reconstructed.
- **Data minimization:** Users see only their own data or data for employees they are allowed to manage (team or admin).
- **Consistency:** One service (PermissionService) and this document define “who can do what”; controllers delegate to them and do not implement ad-hoc permission logic.

This document should be kept in sync with code changes to permission checks and referenced in any compliance or legal documentation (e.g. DPIA, processing records, works council agreements).
