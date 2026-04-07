# ArbeitszeitCheck — User guide (English)

This guide describes features from an **end-user and team lead** perspective. It is shipped with the app in `docs/` for administrators who want a short reference to share internally.

For **legal / GDPR** topics, see [GDPR-Compliance-Guide.en.md](GDPR-Compliance-Guide.en.md). For **technical / ArbZG implementation** details, see [Compliance-Implementation.en.md](Compliance-Implementation.en.md).

---

## 1. What this app does

ArbeitszeitCheck records **working time**, checks **German working time law (ArbZG)** rules (breaks, rest periods, limits where configured), manages **absences** (leave, sick leave, etc.) with approvals where your organization uses them, and offers **reports and exports**.

All data stays in your **Nextcloud** instance.

---

## 2. Calendars: in-app view vs Nextcloud Calendar

| Topic | What to expect |
|--------|------------------|
| **Calendar page inside ArbeitszeitCheck** | The app has its own **month calendar** view (working time and absences). This is **not** the separate “Calendar” app. |
| **Nextcloud Calendar app** | ArbeitszeitCheck **does not** sync absences into the Nextcloud **Calendar** app (no CalDAV feed from this app). |
| **Old calendars** | If calendars were created in the past when a different integration existed, they **remain** in the Calendar app until you delete them there. The app does not remove them automatically. |
| **Email with `.ics` attachment** | In some workflows, the app can send **email** with an iCalendar file so people can **import an event manually** into any calendar client. That is optional mail, not live two-way sync. |

---

## 3. Roles (typical setup)

- **Employee**: Record time, request absences, view own data.
- **Substitute** (if used): Approve or reject coverage for an absence.
- **Manager / team lead**: Approve absences for team members, see team views where permitted.
- **Administrator**: Global settings, users, holidays, compliance options, exports.

Exact permissions depend on your Nextcloud groups and app configuration.

---

## 4. Everyday tasks

- **Clock in / out** and **breaks**: Use the time tracking UI; follow your organization’s rules for corrections and comments.
- **Absences**: Create requests; wait for approval if your workflow requires it. Vacation balances and carryover (**Resturlaub**) may be shown if your admin configured them.
- **Manager dashboard** (if you are a team lead): Under **Pending approvals**, absence requests list each person with the **absence type in your language** (e.g. vacation vs sick leave), not raw internal codes.
- **Reports**: Generate period reports or exports your admin allows (CSV, DATEV, etc.).
- **Compliance**: The app may flag violations (e.g. missing breaks); your employer defines how those are handled.

---

## 5. Holidays (Germany)

Statutory and optional holidays depend on the **federal state (Bundesland)** and settings your **administrator** maintains under the holidays / admin area. The app uses this data for working-day calculations and checks—not for pushing events into the Nextcloud Calendar app.

---

## 6. Privacy and data

- Personal data is processed for **time recording and HR-related processes** as configured by your organization.
- Use **GDPR export / deletion** features only as allowed by policy and retention rules (see the GDPR guide linked above).

---

## 7. Getting help

- **Issues with the product**: Contact your internal IT or the person who runs Nextcloud.
- **Bugs in the app**: Your administrator can report issues via the repository linked in the app metadata on the App Store page.

---

*Document version: aligned with app 1.1.x. For the exact shipped version, see `appinfo/info.xml`.*
