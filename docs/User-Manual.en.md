# User Manual – ArbeitszeitCheck (TimeGuard)

**Version:** 1.0.0  
**Last Updated:** 2025-12-29

## Table of Contents

1. [Introduction](#introduction)
2. [Getting Started](#getting-started)
3. [Daily Time Tracking](#daily-time-tracking)
4. [Managing Your Time Entries](#managing-your-time-entries)
5. [Absence Management](#absence-management)
6. [Dashboard and Overview](#dashboard-and-overview)
7. [Compliance and Violations](#compliance-and-violations)
8. [Exporting Your Data](#exporting-your-data)
9. [Settings](#settings)
10. [Troubleshooting](#troubleshooting)
11. [Keyboard Shortcuts](#keyboard-shortcuts)

---

## Introduction

**ArbeitszeitCheck (TimeGuard)** is a legally compliant time tracking system designed for German organizations. It helps you track your working hours while ensuring compliance with German labor law (Arbeitszeitgesetz - ArbZG).

### What This System Does

- Records your daily working time (start, end, breaks)
- Ensures compliance with legal requirements (maximum hours, breaks, rest periods)
- Manages your vacation and absence requests
- Provides transparency over your working time data
- Supports your rights under GDPR (export, correction, deletion)

### What This System Does NOT Do

- **No surveillance**: The system tracks only legally required time data, not detailed activity monitoring
- **No performance evaluation**: Time data is used for compliance and payroll, not for individual performance assessment
- **No location tracking**: No GPS tracking unless explicitly enabled with your consent

---

## Getting Started

### First Login

1. Log into your Nextcloud instance
2. Click on **ArbeitszeitCheck** in the app menu
3. Complete the **onboarding tour** (appears automatically on first use)
4. Review your **personal settings** (vacation entitlement, working hours)

### Understanding Your Dashboard

The main dashboard shows:

- **Current status**: Whether you're currently clocked in
- **Today's summary**: Hours worked today, break time taken
- **Quick actions**: Clock in/out buttons
- **Recent entries**: Your latest time entries
- **Notifications**: Important alerts (e.g., missing breaks, violations)

---

## Daily Time Tracking

### Clocking In

**To start tracking your working time:**

1. Click the **"Clock In"** button on the dashboard
2. Optionally, select a **project** (if ProjectCheck integration is enabled)
3. Optionally, add a **description**
4. Click **"Clock In"**

**What happens:**
- The system records your start time
- A timer starts counting your working hours
- You'll see your current status on the dashboard

**Important:**
- You can only have **one active time entry** at a time
- The system checks that you've had at least **11 hours rest** since your last shift
- If you try to clock in too early, you'll see a warning message

### Taking Breaks

**To start a break:**

1. Click **"Start Break"** while clocked in
2. The timer pauses
3. Your break time is tracked separately

**To end your break:**

1. Click **"End Break"**
2. The timer resumes
3. Your break duration is recorded

**Legal Requirements:**
- After **6 hours** of work, you must take at least **30 minutes** break
- After **9 hours** of work, you must take at least **45 minutes** break
- The system will remind you if you're missing required breaks

### Clocking Out

**To stop tracking your working time:**

1. Click the **"Clock Out"** button
2. The system records your end time
3. Your total working hours and break time are calculated
4. You'll see a summary of your day

**What's calculated:**
- **Total duration**: Time from clock-in to clock-out
- **Break duration**: Sum of all break periods
- **Working duration**: Total duration minus break time

---

## Managing Your Time Entries

### Viewing Your Time Entries

**To see all your time entries:**

1. Go to **"Time Entries"** in the navigation
2. Use filters to find specific entries:
   - **Date range**: Select start and end dates
   - **Status**: Filter by status (active, completed, pending approval, etc.)
3. Use pagination to browse through entries

**Views available:**
- **List view**: Table of all entries
- **Calendar view**: Visual calendar showing entries by date
- **Timeline view**: Chronological timeline of work periods

### Manual Time Entry

**If you forgot to clock in/out:**

1. Go to **"Time Entries"** → **"Add Manual Entry"**
2. Enter:
   - **Date**: The date you worked
   - **Hours**: Number of hours worked
   - **Description**: **Mandatory** justification (e.g., "Forgot to clock in")
   - **Project**: Optional project assignment
3. Click **"Save"**

**Important:**
- Manual entries require a **justification** (this is mandatory for audit purposes)
- Manual entries can be edited or deleted later (unlike automatic clock-in/out entries)
- Your manager may review manual entries

### Requesting Corrections

**If you notice an error in a completed time entry:**

1. Find the time entry in your list
2. Click **"Request Correction"**
3. Fill in:
   - **Justification**: Why the correction is needed (mandatory)
   - **Corrected date**: If the date was wrong
   - **Corrected hours**: If the hours were wrong
   - **Corrected description**: If the description needs updating
4. Click **"Submit Request"**

**What happens:**
- Your request is sent to your manager
- The entry status changes to **"Pending Approval"**
- You'll receive a notification when it's approved or rejected
- You can view the status in your time entries list

**Note:** You can only request corrections for entries that are **completed** and not already pending approval.

### Editing Time Entries

**You can edit:**
- Manual entries (entries you created manually) - only from the last 2 weeks
- Entries with status **"pending approval"** (correction requests)
- Automatic entries with status **"completed"** - only from the last 2 weeks

**You cannot edit:**
- Entries older than 2 weeks (use "Request Correction" instead)
- Entries that are **"approved"** (use "Request Correction" instead)
- Active entries (ongoing time tracking)

**Note:** The 2-week restriction ensures data integrity and compliance with audit requirements. For older entries, use the "Request Correction" feature which requires manager approval.

**To edit:**
1. Find the entry in your list
2. Click **"Edit"**
3. Make your changes
4. Click **"Save"**

### Deleting Time Entries

**You can only delete:**
- Manual entries (entries you created manually)

**You cannot delete:**
- Automatic clock-in/out entries
- Entries that have been approved

**To delete:**
1. Find the entry in your list
2. Click **"Delete"**
3. Confirm the deletion

---

## Absence Management

### Requesting Vacation

**To request vacation:**

1. Go to **"Absences"** → **"Request Absence"**
2. Select **"Vacation"** as the type
3. Enter:
   - **Start date**: First day of vacation
   - **End date**: Last day of vacation
   - **Reason**: Optional description
4. Click **"Submit Request"**

**What happens:**
- Your request is sent to your manager
- Status is set to **"Pending"**
- You'll see your remaining vacation days (if configured)
- You'll receive a notification when approved/rejected

**Important:**
- The system calculates **working days** automatically (excludes weekends and holidays)
- You can see how many vacation days you have remaining
- Overlapping requests are not allowed

### Reporting Sick Leave

**To report sick leave:**

1. Go to **"Absences"** → **"Request Absence"**
2. Select **"Sick Leave"** as the type
3. Enter:
   - **Start date**: First day of illness
   - **End date**: Last day of illness (or expected return date)
   - **Reason**: Optional (no medical diagnosis required)
4. Click **"Submit Request"**

**Note:** The system does **not** require medical details. Only report the absence period.

### Other Absence Types

You can also request:
- **Special Leave**: For special circumstances
- **Unpaid Leave**: Leave without pay

### Viewing Absence Status

**To check your absence requests:**

1. Go to **"Absences"**
2. You'll see all your requests with their status:
   - **Pending**: Waiting for manager approval
   - **Approved**: Request approved
   - **Rejected**: Request rejected (see manager comment)
   - **Deleted**: Request was canceled

### Canceling an Absence Request

**To cancel a pending request:**

1. Find the request in your list
2. Click **"Delete"**
3. Confirm cancellation

**Note:** You can only cancel requests with status **"Pending"**.

---

## Dashboard and Overview

### Personal Dashboard

Your dashboard provides:

- **Today's summary**: Hours worked, break time, current status
- **Quick actions**: Clock in/out, start/end break buttons
- **Recent entries**: Your latest time entries
- **Overtime balance**: Your current overtime (positive or negative)
- **Upcoming absences**: Approved absences in the near future
- **Compliance status**: Any current violations or warnings

### Calendar View

**To see your time entries in calendar format:**

1. Go to **"Calendar"** in the navigation
2. Navigate by month
3. Click on a date to see details
4. Color coding shows:
   - **Green**: Compliant days
   - **Yellow**: Warnings (e.g., missing break)
   - **Red**: Violations (e.g., exceeded hours)

### Timeline View

**To see a chronological timeline:**

1. Go to **"Timeline"** in the navigation
2. See all your work periods in chronological order
3. Visual representation shows:
   - Work periods (blue bars)
   - Break periods (gray bars)
   - Gaps between shifts

### Reports

**To view reports:**

1. Go to **"Reports"** in the navigation
2. Select report type:
   - **Daily**: Today's summary
   - **Weekly**: This week's summary
   - **Monthly**: This month's summary
   - **Overtime**: Overtime calculation
   - **Absence**: Absence statistics

---

## Compliance and Violations

### Understanding Violations

The system automatically checks for compliance with German labor law:

- **Missing break**: No break after 6 hours of work
- **Insufficient break**: Less than required break time
- **Daily hours exceeded**: Worked more than 10 hours in a day
- **Insufficient rest**: Less than 11 hours between shifts
- **Sunday/holiday work**: Work on Sunday or public holiday

### Viewing Your Violations

**To see your compliance violations:**

1. Go to **"Compliance"** → **"Violations"**
2. Filter by:
   - **Type**: Type of violation
   - **Severity**: Info, warning, or error
   - **Status**: Resolved or unresolved
   - **Date range**: Specific time period

### Resolving Violations

**To mark a violation as resolved:**

1. Find the violation in your list
2. Click **"Resolve"**
3. Optionally add a comment explaining how it was resolved

**Note:** Some violations (e.g., missing break) cannot be retroactively resolved but serve as documentation for future compliance.

### Compliance Status

**To see your overall compliance:**

1. Go to **"Compliance"** → **"Dashboard"**
2. See:
   - **Compliance percentage**: How compliant you are
   - **Violation count**: Number of unresolved violations
   - **Recent violations**: Latest compliance issues

---

## Exporting Your Data

### Export Time Entries

**To export your time entries:**

1. Go to **"Time Entries"**
2. Click **"Export"**
3. Select format:
   - **CSV**: For Excel or spreadsheet software
   - **JSON**: For programmatic access
   - **PDF**: For printing or archiving
   - **DATEV**: For payroll (if configured)
4. Select date range
5. Click **"Download"**

### Export Absences

**To export your absences:**

1. Go to **"Absences"**
2. Click **"Export"**
3. Select format (CSV, JSON, PDF)
4. Select date range
5. Click **"Download"**

### GDPR Data Export

**To export all your personal data (GDPR right to access):**

1. Go to **"Settings"** → **"Personal"** → **"ArbeitszeitCheck"**
2. Click **"Export Personal Data"**
3. A JSON file will be downloaded containing:
   - All time entries
   - All absences
   - Your settings
   - Compliance violations
   - Audit logs

**This export is comprehensive and includes all data the system stores about you.**

---

## Settings

### Personal Settings

**To access your personal settings:**

1. Go to **"Settings"** → **"Personal"** → **"ArbeitszeitCheck"**
2. Configure:
   - **Vacation entitlement**: Days per year
   - **Working hours**: Your contract hours
   - **Notification preferences**: Email and in-app notifications

### Notification Preferences

You can configure notifications for:

- **Break reminders**: Reminder after 6 hours without break
- **Clock-out reminders**: Reminder if you forgot to clock out
- **Violation alerts**: Notifications about compliance violations
- **Approval notifications**: When your requests are approved/rejected

---

## Troubleshooting

### "I can't clock in"

**Possible reasons:**
- You already have an active time entry → Clock out first
- Less than 11 hours since last shift → Wait until rest period is met
- System error → Contact IT support

### "I forgot to clock in/out"

**Solution:**
1. Create a **manual time entry** (see "Manual Time Entry" above)
2. Provide a **justification** explaining why
3. Your manager may review it

### "My time entry is wrong"

**Solution:**
1. If it's a **manual entry**: Edit it directly
2. If it's an **automatic entry**: Request a correction (see "Requesting Corrections" above)

### "I can't see my manager's approval"

**Solution:**
- Check your **notifications** (bell icon)
- Go to **"Absences"** or **"Time Entries"** and check the status
- Status will show: **"Pending"**, **"Approved"**, or **"Rejected"**

### "The system says I'm missing a break"

**What to do:**
- This is a **warning**, not an error
- You should take the required break (30 min after 6 hours, 45 min after 9 hours)
- Future entries will be checked for compliance
- You cannot retroactively add breaks to past entries, but the violation serves as documentation

### "I can't delete a time entry"

**Possible reasons:**
- It's an **automatic entry** (clock-in/out) → These cannot be deleted (tamper-proof)
- It's **approved** → Use correction request instead
- It's **pending approval** → Wait for manager decision

---

## Keyboard Shortcuts

For keyboard navigation (WCAG 2.1 AAA compliant):

- **Tab**: Navigate between elements
- **Enter/Space**: Activate buttons and links
- **Escape**: Close modals and dialogs
- **Arrow keys**: Navigate in lists and tables
- **Ctrl/Cmd + F**: Search/filter (where available)

**All functionality is accessible via keyboard** - no mouse required.

---

## Getting Help

### Support Resources

- **User Guide**: This manual
- **FAQ**: See FAQ section
- **IT Support**: Contact your organization's IT department
- **GitHub Issues**: https://github.com/nextcloud/arbeitszeitcheck/issues (for bug reports)

### Reporting Issues

**If you encounter a problem:**

1. Check the **Troubleshooting** section above
2. Check if it's a known issue in GitHub
3. Report the issue with:
   - What you were trying to do
   - What happened instead
   - Error messages (if any)
   - Browser and version

---

## Privacy and Your Rights

### Your Data Rights (GDPR)

You have the right to:

- **Access your data**: Export all your data (see "GDPR Data Export" above)
- **Correct your data**: Request corrections for errors
- **Delete your data**: Request deletion (subject to legal retention periods)
- **Port your data**: Export in machine-readable formats

### Data Retention

- Time entries are retained for **minimum 2 years** (German labor law requirement)
- Some data may be retained longer for legal compliance
- You'll be informed if deletion is not possible due to retention requirements

### Transparency

- You can see **all your data** at any time
- You can see **who has access** to your data (managers, HR)
- You can see **audit logs** of changes to your data

---

**Last Updated:** 2025-12-29  
**Version:** 1.0.0
