# Views & Pages Analysis

## Overview

The arbeitszeitcheck app has multiple views. This document explains what each view does, why it exists, and how they relate.

## User Views (PageController)

| Route | Template | Purpose |
|-------|----------|---------|
| `/` | dashboard | Main entry: clock in/out, today's stats, recent entries |
| `/dashboard` | dashboard | Same as `/` – dashboard() is used by index() |
| `/time-entries` | time-entries | List, add, edit time entries |
| `/absences` | absences | Request and manage vacation, sick leave, etc. |
| `/reports` | reports | Create and download working time reports |
| `/calendar` | calendar | Calendar view of time entries and absences |
| `/timeline` | timeline | Chronological view of working time |
| `/settings` | settings | Personal working time settings |

**Note:** `index.php` is a legacy multi-view template (dashboard, time-entries, absences, settings). PageController uses dedicated templates (e.g. `dashboard`, `time-entries`), so `index.php` is not used by current routes. The header may reference it for the logo link.

## Compliance Views (ComplianceController)

| Route | Template | Purpose |
|-------|----------|---------|
| `/compliance` | compliance-dashboard | Overview: status, score, recent violations |
| `/compliance/violations` | compliance-violations | Full list with date/severity filters |
| `/compliance/reports` | compliance-reports | Summary: by type, by severity |

All three are linked under one "Compliance" nav item. Sub-navigation (tabs) lets users switch between Overview, Violations, and Reports.

## Manager & Admin Views

| Route | Controller | Purpose |
|-------|------------|---------|
| `/manager` | ManagerController | Manager dashboard: team, approvals, compliance |
| `/substitution-requests` | SubstituteController | Approve/decline substitution requests |
| `/admin` | AdminController | Admin dashboard |
| `/admin/users` | AdminController | User management |
| `/admin/settings` | AdminController | App settings |
| `/admin/working-time-models` | AdminController | Working time models |
| `/admin/audit-log` | AdminController | Audit log |
| `/admin/teams` | AdminController | Organization/teams |

## Consolidation Notes

- **index vs dashboard:** Both `/` and `/dashboard` render the same content. index() delegates to dashboard(). No change needed.
- **Calendar vs Timeline:** Different presentations (grid vs list). Both are useful; keep both.
- **Compliance:** Three pages serve distinct purposes (overview, detail list, summary). Kept separate but connected via sub-tabs.

## WCAG 2.1 AA

All views use:
- Semantic HTML (headings, sections, tables with scope)
- ARIA labels where needed (aria-label, aria-current, role)
- Sufficient color contrast (CSS variables)
- Focus visible for keyboard users
- Responsive layout (breakpoints for mobile)
