# Administrator Guide – ArbeitszeitCheck (TimeGuard)

**Version:** 1.0.0  
**Last Updated:** 2025-12-29

## Table of Contents

1. [Introduction](#introduction)
2. [Installation](#installation)
3. [Initial Configuration](#initial-configuration)
4. [User Management](#user-management)
5. [Working Time Models](#working-time-models)
6. [Compliance Configuration](#compliance-configuration)
7. [Manager Assignment](#manager-assignment)
8. [Reports and Exports](#reports-and-exports)
9. [Audit Logs](#audit-logs)
10. [GDPR Compliance](#gdpr-compliance)
11. [Troubleshooting](#troubleshooting)
12. [Best Practices](#best-practices)
13. [Maintenance](#maintenance)

---

## Introduction

This guide is for administrators responsible for configuring and managing ArbeitszeitCheck (TimeGuard) in their organization.

### Administrator Responsibilities

- **System configuration**: Set up global settings and compliance rules
- **User management**: Assign working time models, configure vacation entitlements
- **Compliance monitoring**: Review violations, ensure legal compliance
- **Data management**: Handle exports, retention policies, GDPR requests
- **Support**: Assist users with questions and issues

### Prerequisites

- Nextcloud administrator access
- Understanding of German labor law (ArbZG)
- Understanding of GDPR/DSGVO requirements
- Access to organization's HR policies

---

## Installation

### From Nextcloud App Store

1. Log in as Nextcloud administrator
2. Go to **"Apps"** → **"Organization"** or **"Productivity"**
3. Search for **"ArbeitszeitCheck"** or **"TimeGuard"**
4. Click **"Download and enable"**
5. Wait for installation to complete

### Manual Installation

1. Download the app from GitHub releases
2. Extract to `apps/arbeitszeitcheck/` directory
3. Run `occ app:enable arbeitszeitcheck`
4. Database schema will be created automatically

### Post-Installation Checklist

- [ ] App is enabled and accessible
- [ ] Database tables created (check with `occ db:list-tables arbeitszeitcheck`)
- [ ] Admin settings page accessible
- [ ] No errors in Nextcloud log

---

## Initial Configuration

### Accessing Admin Settings

1. Log in as administrator
2. Go to **"Settings"** → **"Administration"** → **"ArbeitszeitCheck"**
3. Or navigate directly to `/apps/arbeitszeitcheck/admin/settings`

### Global Settings

#### Compliance Settings

**Automatic Compliance Check:**
- **Enabled** (recommended): System automatically checks for violations daily
- **Disabled**: Manual compliance checks only

**Violation Notifications:**
- **Enabled** (recommended): Users and HR receive notifications about violations
- **Disabled**: Violations logged but no notifications sent

**Require Break Justification:**
- **Enabled**: Users must provide justification when missing breaks
- **Disabled**: Missing breaks logged without justification requirement

#### Working Time Limits

**Maximum Daily Hours:**
- Default: `10` hours
- Range: 1-24 hours
- **Legal requirement**: 8 hours standard, extendable to 10 hours (ArbZG §3)

**Minimum Rest Period:**
- Default: `11` hours
- Range: 1-24 hours
- **Legal requirement**: Minimum 11 consecutive hours between shifts (ArbZG §5)

**Default Working Hours:**
- Default: `8` hours per day
- Used for new users without assigned working time model

#### Regional Settings

**German State (Bundesland):**
- Select your state: `NW`, `BY`, `BW`, `HE`, `NI`, `RP`, `SL`, `BE`, `BB`, `HB`, `HH`, `MV`, `SN`, `ST`, `SH`, `TH`
- Used for public holiday calendar
- **Important**: Ensures correct holiday tracking for compliance

#### Data Retention

**Retention Period:**
- Default: `2` years
- Range: 1-10 years
- **Legal requirement**: Minimum 2 years for time records (ArbZG)
- Data older than retention period can be deleted (GDPR deletion requests)

### Saving Settings

After configuring settings:
1. Click **"Save Settings"**
2. Verify changes are applied
3. Check audit log for configuration changes

---

## User Management

### Accessing User Management

1. Go to **"Administration"** → **"ArbeitszeitCheck"** → **"Users"**
2. Or navigate to `/apps/arbeitszeitcheck/admin/users`

### User Overview

The user management page shows:
- **User list**: All Nextcloud users
- **Working time model**: Assigned model per user
- **Vacation entitlement**: Days per year
- **Status**: Active/inactive, has time entries today

### Assigning Working Time Models

**To assign a working time model to a user:**

1. Find the user in the list
2. Click **"Edit"** or **"View Details"**
3. Select **"Working Time Model"** from dropdown
4. Set **"Vacation Days Per Year"**
5. Set **"Start Date"** (when model becomes effective)
6. Optionally set **"End Date"** (for temporary assignments)
7. Click **"Save"**

**Important:**
- Users without assigned model use **default working hours** from global settings
- Working time models determine required hours for overtime calculation
- Changes take effect immediately (or on start date if specified)

### Configuring Vacation Entitlement

**To set vacation days:**

1. Open user details
2. Set **"Vacation Days Per Year"**
3. Vacation entitlement is calculated per calendar year
4. System tracks used vs. remaining days

**Note:** Vacation entitlement is separate from absence types (sick leave, special leave).

### Manager Assignment

**To assign a manager to a user:**

1. Open user details
2. Set **"Manager"** field (select from user list)
3. Manager can:
   - Approve/reject absence requests
   - Approve/reject time entry corrections
   - View team overview and compliance
4. Click **"Save"**

**Manager Hierarchy:**
- Users can have one direct manager
- Managers can have multiple team members
- Managers can also be employees (have their own manager)

### Bulk User Import

**To import users from CSV:**

1. Prepare CSV file with columns:
   - `user_id` (required): Nextcloud user ID
   - `working_time_model_id` (optional): Model ID
   - `vacation_days_per_year` (optional): Vacation entitlement
   - `manager_id` (optional): Manager's user ID
2. Go to **"Users"** → **"Import"**
3. Upload CSV file
4. Review import preview
5. Confirm import

**Export Users:**

1. Go to **"Users"** → **"Export"**
2. Select format: `CSV` or `JSON`
3. Download file

---

## Working Time Models

### Accessing Working Time Models

1. Go to **"Administration"** → **"ArbeitszeitCheck"** → **"Working Time Models"**
2. Or navigate to `/apps/arbeitszeitcheck/admin/working-time-models`

### Creating a Working Time Model

**To create a new model:**

1. Click **"Create Working Time Model"**
2. Fill in:
   - **Name**: Descriptive name (e.g., "Full-time 40h")
   - **Description**: Optional description
   - **Type**: `full_time`, `part_time`, `flexible`, `shift_work`
   - **Weekly Hours**: Hours per week (e.g., 40.0)
   - **Daily Hours**: Hours per day (e.g., 8.0)
   - **Is Default**: Mark as default for new users
3. Click **"Save"**

**Model Types:**
- **Full-time**: Standard full-time employment
- **Part-time**: Part-time employment with fixed hours
- **Flexible**: Flexible working hours (Gleitzeit)
- **Shift work**: Shift-based schedules

### Editing Working Time Models

**To edit a model:**

1. Find the model in the list
2. Click **"Edit"**
3. Modify fields as needed
4. Click **"Save"**

**Important:**
- Changes affect all users assigned to this model
- Historical data is not recalculated automatically
- Consider creating a new model version instead of editing existing

### Deleting Working Time Models

**To delete a model:**

1. Find the model in the list
2. Click **"Delete"**
3. Confirm deletion

**Restrictions:**
- Cannot delete if users are assigned to it
- Reassign users to another model first
- Historical assignments are preserved

---

## Compliance Configuration

### Compliance Dashboard

**To view compliance overview:**

1. Go to **"Administration"** → **"ArbeitszeitCheck"** → **"Compliance"**
2. See:
   - **Total violations**: Count by severity
   - **Compliance percentage**: Overall compliance rate
   - **Recent violations**: Latest compliance issues
   - **Violations by type**: Breakdown by violation type

### Violation Types

The system detects:

- **Missing Break**: No break after 6 hours
- **Insufficient Break**: Less than required break time
- **Daily Hours Exceeded**: More than 10 hours in a day
- **Insufficient Rest Period**: Less than 11 hours between shifts
- **Weekly Hours Exceeded**: More than 48 hours average per week
- **Night Work**: Work between 11 PM and 6 AM
- **Sunday Work**: Work on Sunday
- **Holiday Work**: Work on public holiday

### Resolving Violations

**To resolve a violation:**

1. Go to **"Compliance"** → **"Violations"**
2. Find the violation
3. Click **"Resolve"**
4. Add resolution comment
5. Click **"Save"**

**Note:** Resolving a violation marks it as addressed but does not change historical data. It serves as documentation for compliance audits.

### Compliance Reports

**To generate compliance reports:**

1. Go to **"Compliance"** → **"Reports"**
2. Select date range
3. Choose report type:
   - **Summary**: Overview of violations
   - **Detailed**: Full violation list
   - **By User**: Violations per user
   - **By Type**: Violations by type
4. Export as CSV, JSON, or PDF

---

## Manager Assignment

### Assigning Managers

**To assign a manager:**

1. Go to **"Users"** → Select user → **"Edit"**
2. Set **"Manager"** field
3. Save

**Manager Capabilities:**
- View team overview (who's clocked in, hours worked)
- Approve/reject absence requests
- Approve/reject time entry corrections
- View team compliance status
- Access team reports

### Manager Dashboard

Managers can access:
- **Team Overview**: Current status of team members
- **Pending Approvals**: Absence requests and time corrections awaiting approval
- **Team Compliance**: Compliance status for team
- **Team Reports**: Working hours and absence reports

---

## Reports and Exports

### Available Reports

**Daily Report:**
- Summary for a specific day
- Hours worked, breaks taken, compliance status

**Weekly Report:**
- Summary for a week
- Total hours, average per day, overtime

**Monthly Report:**
- Summary for a month
- Total hours, required hours, overtime balance

**Overtime Report:**
- Overtime calculation for date range
- Breakdown by user or team

**Absence Report:**
- Absence statistics
- Vacation days used, sick leave, other absences

**Team Report:**
- Multi-user report
- Compare team members' hours and absences

### Export Formats

**CSV:**
- For Excel or spreadsheet software
- Includes all data fields
- Suitable for further analysis

**JSON:**
- Machine-readable format
- Complete data structure
- For integration with other systems

**PDF:**
- Formatted report
- Suitable for printing or archiving
- Includes charts and summaries

**DATEV:**
- Payroll integration format
- Requires DATEV configuration (Beraternummer, Mandantennummer)
- ASCII format compatible with DATEV software

### DATEV Export Configuration

**To configure DATEV export:**

1. Go to **"Administration"** → **"ArbeitszeitCheck"** → **"Settings"**
2. Set **"DATEV Beraternummer"** (consultant number)
3. Set **"DATEV Mandantennummer"** (client number)
4. Save settings

**DATEV Export Fields:**
- Personalnummer (employee number)
- Datum (date)
- Lohnart (wage type)
- Stunden (hours)
- Additional fields as configured

---

## Audit Logs

### Accessing Audit Logs

1. Go to **"Administration"** → **"ArbeitszeitCheck"** → **"Audit Log"**
2. Or navigate to `/apps/arbeitszeitcheck/admin/audit-log`

### Audit Log Features

**View Logs:**
- Filter by date range
- Filter by user
- Filter by action type
- Filter by entity type (time entry, absence, settings, etc.)

**Action Types:**
- `time_entry_created`, `time_entry_updated`, `time_entry_deleted`
- `absence_created`, `absence_approved`, `absence_rejected`
- `settings_updated`
- `compliance_violation_created`, `compliance_violation_resolved`
- `user_working_time_model_assigned`

**Export Audit Logs:**
- Export filtered logs as CSV or JSON
- Includes all metadata (user, timestamp, action, changes)

### Audit Log Statistics

**To view statistics:**

1. Go to **"Audit Log"** → **"Statistics"**
2. See:
   - Total log entries
   - Entries by action type
   - Entries by user
   - Recent activity

---

## GDPR Compliance

### Data Export (Art. 15)

**To export user data:**

1. Go to **"Users"** → Select user → **"Export Data"**
2. Or user can export their own data via **"Settings"** → **"Personal"** → **"Export Personal Data"**

**Export includes:**
- All time entries
- All absences
- User settings
- Compliance violations
- Audit logs

### Data Deletion (Art. 17)

**To handle deletion requests:**

1. User requests deletion via **"Settings"** → **"Personal"** → **"Delete Personal Data"**
2. System respects **2-year retention period** (ArbZG requirement)
3. Only data older than retention period is deleted
4. Audit logs and compliance violations are retained for legal compliance

**Important:**
- Deletion respects legal retention requirements
- Some data must be retained for labor law compliance
- Users are informed about retained data

### Processing Records (Art. 30)

**To maintain processing records:**

1. Use the **Processing Activities Record Template** (`docs/Processing-Activities-Record-Template.en.md`)
2. Document:
   - Data categories processed
   - Purpose of processing
   - Legal basis (Art. 6(1)(c) - legal obligation)
   - Data recipients (HR, payroll, managers)
   - Retention periods
   - Security measures

### Data Protection Impact Assessment (Art. 35)

**To conduct DPIA:**

1. Use the **DPIA Template** (`docs/DPIA-Template.en.md`)
2. Assess risks and mitigation measures
3. Document necessity and proportionality
4. Review with Data Protection Officer

---

## Troubleshooting

### Common Issues

**Users can't clock in:**
- Check if user has active time entry
- Verify rest period (11 hours) is met
- Check Nextcloud log for errors
- Verify user permissions

**Compliance violations not detected:**
- Verify **"Automatic Compliance Check"** is enabled
- Check background job is running (`occ job:list`)
- Verify compliance rules are configured correctly
- Check Nextcloud cron is configured

**Reports not generating:**
- Check date range is valid
- Verify user has data in date range
- Check Nextcloud log for errors
- Verify export format is supported

**DATEV export failing:**
- Verify DATEV configuration (Beraternummer, Mandantennummer)
- Check date range (must be within retention period)
- Verify user has time entries in date range
- Check Nextcloud log for errors

### Checking System Health

**Health Check Endpoint:**

```bash
curl https://your-nextcloud.com/apps/arbeitszeitcheck/health
```

**Response:**
```json
{
  "status": "ok",
  "version": "1.0.0",
  "database": "connected",
  "background_jobs": "running"
}
```

### Log Files

**Nextcloud Log:**
- Location: `data/nextcloud.log`
- Filter: `grep arbeitszeitcheck data/nextcloud.log`

**Common Log Messages:**
- `[arbeitszeitcheck] User clocked in` - Normal operation
- `[arbeitszeitcheck] Compliance violation detected` - Violation found
- `[arbeitszeitcheck] ERROR` - Error occurred

---

## Best Practices

### Configuration

1. **Set realistic limits**: Configure max daily hours based on your organization's policies
2. **Enable compliance checks**: Always enable automatic compliance checking
3. **Configure notifications**: Enable violation notifications for timely awareness
4. **Set retention period**: Configure retention based on legal requirements (minimum 2 years)

### User Management

1. **Assign working time models**: Assign appropriate models to all users
2. **Set vacation entitlements**: Configure vacation days per user
3. **Assign managers**: Set up manager hierarchy for approval workflows
4. **Regular reviews**: Review user assignments quarterly

### Compliance

1. **Monitor violations**: Review compliance dashboard regularly
2. **Resolve violations**: Address violations promptly with users
3. **Document exceptions**: Use resolution comments to document exceptions
4. **Regular audits**: Conduct quarterly compliance audits

### Data Management

1. **Regular backups**: Backup Nextcloud database regularly
2. **Export reports**: Export monthly reports for archival
3. **Retention policy**: Follow retention policy strictly
4. **GDPR requests**: Handle GDPR requests promptly (within 30 days)

### Security

1. **Access control**: Limit admin access to authorized personnel only
2. **Audit logs**: Review audit logs regularly for suspicious activity
3. **Password policy**: Enforce strong passwords via Nextcloud
4. **Updates**: Keep Nextcloud and app updated

---

## Maintenance

### Regular Tasks

**Daily:**
- Monitor compliance violations
- Check system health endpoint
- Review error logs

**Weekly:**
- Review pending approvals (if manager)
- Export weekly reports
- Check audit logs for anomalies

**Monthly:**
- Generate monthly reports
- Review user assignments
- Check retention policy compliance
- Review compliance statistics

**Quarterly:**
- Conduct compliance audit
- Review and update working time models
- Review GDPR compliance
- Update documentation

### Database Maintenance

**Check table sizes:**
```sql
SELECT table_name, 
       ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = 'nextcloud'
  AND table_name LIKE 'oc_at_%'
ORDER BY size_mb DESC;
```

**Archive old data:**
- Export data older than retention period
- Archive to external storage
- Delete from database (if retention period exceeded)

### Backup and Restore

**Backup:**
```bash
# Backup database
mysqldump -u nextcloud -p nextcloud > arbeitszeitcheck_backup.sql

# Backup app files
tar -czf arbeitszeitcheck_app_backup.tar.gz apps/arbeitszeitcheck/
```

**Restore:**
```bash
# Restore database
mysql -u nextcloud -p nextcloud < arbeitszeitcheck_backup.sql

# Restore app files
tar -xzf arbeitszeitcheck_app_backup.tar.gz
```

---

## Support and Resources

### Documentation

- **User Manual**: `docs/User-Manual.en.md`
- **API Documentation**: `docs/API-Documentation.en.md`
- **GDPR Compliance Guide**: `docs/GDPR-Compliance-Guide.en.md`
- **DPIA Template**: `docs/DPIA-Template.en.md`

### Community Support

- **GitHub Issues**: https://github.com/nextcloud/arbeitszeitcheck/issues
- **Nextcloud Forums**: https://help.nextcloud.com/c/apps/arbeitszeitcheck

### Professional Support

For enterprise support and custom development:
- Contact: [Your support contact]
- Email: [Your email]

---

**Last Updated:** 2025-12-29  
**Version:** 1.0.0
