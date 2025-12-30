# Troubleshooting Guide – ArbeitszeitCheck

**Last Updated:** 2025-12-29

This guide helps you diagnose and resolve common issues with ArbeitszeitCheck.

---

## Table of Contents

1. [Installation Issues](#installation-issues)
2. [Time Tracking Problems](#time-tracking-problems)
3. [Compliance Violations](#compliance-violations)
4. [Performance Issues](#performance-issues)
5. [Integration Problems](#integration-problems)
6. [Data Export Issues](#data-export-issues)
7. [Permission Errors](#permission-errors)
8. [Browser Compatibility](#browser-compatibility)
9. [Database Issues](#database-issues)
10. [Log Analysis](#log-analysis)

---

## Installation Issues

### App won't install from App Store

**Symptoms:**
- Installation fails with error message
- App appears but cannot be enabled

**Solutions:**

1. **Check Nextcloud version:**
   ```bash
   php occ status
   ```
   Ensure you're running Nextcloud 27, 28, or 29.

2. **Check PHP version:**
   ```bash
   php -v
   ```
   Requires PHP 8.1 or later.

3. **Check app dependencies:**
   ```bash
   php occ app:check-code arbeitszeitcheck
   ```

4. **Manual installation:**
   ```bash
   cd apps/
   git clone https://github.com/nextcloud/arbeitszeitcheck.git
   cd arbeitszeitcheck
   npm install && npm run build
   php occ app:enable arbeitszeitcheck
   ```

### Database tables not created

**Symptoms:**
- App enabled but tables missing
- Errors when accessing features

**Solutions:**

1. **Check database connection:**
   ```bash
   php occ db:list-tables arbeitszeitcheck
   ```

2. **Run migrations manually:**
   ```bash
   php occ maintenance:mode --on
   php occ upgrade
   php occ maintenance:mode --off
   ```

3. **Check database permissions:**
   - Ensure database user has CREATE TABLE permissions
   - Check Nextcloud database configuration

### Frontend assets not loading

**Symptoms:**
- Blank pages or missing styles
- JavaScript errors in console

**Solutions:**

1. **Rebuild frontend:**
   ```bash
   cd apps/arbeitszeitcheck
   npm install
   npm run build
   ```

2. **Clear Nextcloud cache:**
   ```bash
   php occ maintenance:mode --on
   php occ files:scan --all
   php occ maintenance:mode --off
   ```

3. **Check file permissions:**
   ```bash
   chown -R www-data:www-data apps/arbeitszeitcheck/
   ```

---

## Time Tracking Problems

### Cannot clock in

**Symptoms:**
- "Clock In" button disabled or shows error
- Error message: "Already clocked in" or "Insufficient rest period"

**Diagnosis:**

1. **Check for active entry:**
   ```bash
   # Query database directly
   mysql -u nextcloud -p nextcloud -e "SELECT * FROM oc_at_entries WHERE user_id='USERID' AND status='active';"
   ```

2. **Check last entry:**
   ```bash
   mysql -u nextcloud -p nextcloud -e "SELECT * FROM oc_at_entries WHERE user_id='USERID' ORDER BY start_time DESC LIMIT 1;"
   ```

**Solutions:**

- **Already clocked in:** User must clock out first
- **Insufficient rest:** Wait until 11 hours have passed since last shift
- **System error:** Check Nextcloud logs for errors

### Time entries not saving

**Symptoms:**
- Entries disappear after creation
- No error message shown

**Diagnosis:**

1. **Check database:**
   ```bash
   mysql -u nextcloud -p nextcloud -e "SELECT COUNT(*) FROM oc_at_entries WHERE user_id='USERID';"
   ```

2. **Check Nextcloud logs:**
   ```bash
   tail -f data/nextcloud.log | grep arbeitszeitcheck
   ```

**Solutions:**

- **Database connection issue:** Check database credentials
- **Permission issue:** Verify database user permissions
- **Transaction rollback:** Check for constraint violations

### Break time not calculating correctly

**Symptoms:**
- Break duration shows 0 or incorrect value
- Compliance violation for missing break

**Diagnosis:**

1. **Check break records:**
   ```sql
   SELECT * FROM oc_at_entries 
   WHERE user_id='USERID' 
   AND break_start_time IS NOT NULL
   ORDER BY start_time DESC;
   ```

2. **Verify break duration calculation:**
   ```sql
   SELECT 
     TIMESTAMPDIFF(MINUTE, break_start_time, break_end_time) as break_minutes
   FROM oc_at_entries
   WHERE id=ENTRY_ID;
   ```

**Solutions:**

- **Missing break_end_time:** User didn't end break properly
- **Calculation error:** Check timezone handling
- **Manual entry:** Verify break times in manual entries

---

## Compliance Violations

### False positive violations

**Symptoms:**
- Violation detected but user claims compliance
- Violation appears after correction

**Diagnosis:**

1. **Check violation details:**
   ```sql
   SELECT * FROM oc_at_violations 
   WHERE user_id='USERID' 
   AND id=VIOLATION_ID;
   ```

2. **Check related time entry:**
   ```sql
   SELECT * FROM oc_at_entries 
   WHERE id=(SELECT time_entry_id FROM oc_at_violations WHERE id=VIOLATION_ID);
   ```

**Solutions:**

- **Review violation logic:** Check ComplianceService calculation
- **Timezone issue:** Verify UTC conversion
- **Data inconsistency:** Check for manual edits after violation detection

### Violations not detected

**Symptoms:**
- User exceeds limits but no violation created
- Compliance check job not running

**Diagnosis:**

1. **Check background jobs:**
   ```bash
   php occ job:list | grep arbeitszeitcheck
   ```

2. **Check compliance settings:**
   ```bash
   php occ config:app:get arbeitszeitcheck auto_compliance_check
   ```

**Solutions:**

- **Enable compliance check:** Set `auto_compliance_check` to `1`
- **Run job manually:** `php occ job:execute OCA\ArbeitszeitCheck\BackgroundJob\DailyComplianceCheckJob`
- **Check cron:** Ensure Nextcloud cron is configured

---

## Performance Issues

### Slow page loads

**Symptoms:**
- Dashboard takes >5 seconds to load
- API responses slow

**Diagnosis:**

1. **Check database queries:**
   ```bash
   # Enable query logging
   mysql> SET GLOBAL general_log = 'ON';
   mysql> SET GLOBAL log_output = 'table';
   ```

2. **Check for N+1 queries:**
   - Review controller code for loops with database calls
   - Use eager loading where possible

**Solutions:**

- **Add database indexes:**
   ```sql
   CREATE INDEX idx_user_start ON oc_at_entries(user_id, start_time);
   CREATE INDEX idx_user_status ON oc_at_entries(user_id, status);
   ```

- **Enable caching:** Use Nextcloud cache for frequently accessed data
- **Optimize queries:** Review mapper methods for efficiency

### High database load

**Symptoms:**
- Database CPU usage high
- Slow queries in logs

**Solutions:**

1. **Add missing indexes:**
   ```sql
   -- Check existing indexes
   SHOW INDEXES FROM oc_at_entries;
   
   -- Add if missing
   CREATE INDEX idx_start_time ON oc_at_entries(start_time);
   CREATE INDEX idx_end_time ON oc_at_entries(end_time);
   ```

2. **Optimize background jobs:**
   - Run compliance checks during off-peak hours
   - Batch process violations

3. **Archive old data:**
   - Export data older than retention period
   - Move to archive tables

---

## Integration Problems

### ProjectCheck integration not working

**Symptoms:**
- Projects don't appear in time entry form
- Project data not syncing

**Diagnosis:**

1. **Check if ProjectCheck installed:**
   ```bash
   php occ app:list | grep projectcheck
   ```

2. **Check integration service:**
   ```bash
   # Check logs for integration errors
   tail -f data/nextcloud.log | grep ProjectCheck
   ```

**Solutions:**

- **Install ProjectCheck:** Required for integration
- **Check version:** Ensure ProjectCheck 2.0.0+
- **Verify API:** Check ProjectCheck API endpoints accessible

### Calendar sync issues

**Symptoms:**
- Absences not appearing in Nextcloud Calendar
- Duplicate calendar entries

**Solutions:**

- **Check calendar app:** Ensure Calendar app is enabled
- **Verify permissions:** Check user calendar access
- **Clear sync cache:** Remove and re-add calendar subscription

---

## Data Export Issues

### Export fails or incomplete

**Symptoms:**
- Export button does nothing
- Partial data in export file
- Timeout errors

**Diagnosis:**

1. **Check PHP memory limit:**
   ```bash
   php -i | grep memory_limit
   ```

2. **Check execution time:**
   ```bash
   php -i | grep max_execution_time
   ```

**Solutions:**

- **Increase limits:**
   ```ini
   memory_limit = 256M
   max_execution_time = 300
   ```

- **Use pagination:** Export in smaller date ranges
- **Check file permissions:** Ensure write access to temp directory

### DATEV export format incorrect

**Symptoms:**
- DATEV software rejects file
- Missing required fields

**Solutions:**

1. **Verify DATEV configuration:**
   ```bash
   php occ config:app:get arbeitszeitcheck datev_beraternummer
   php occ config:app:get arbeitszeitcheck datev_mandantennummer
   ```

2. **Check export format:**
   - Verify ASCII encoding
   - Check field separators
   - Validate field lengths

3. **Review DATEV specification:**
   - Ensure all required fields present
   - Verify date formats (DDMMYYYY)
   - Check numeric formats

---

## Permission Errors

### User cannot access features

**Symptoms:**
- "Access denied" errors
- Features not visible

**Diagnosis:**

1. **Check user permissions:**
   ```bash
   php occ user:info USERID
   ```

2. **Check app permissions:**
   ```bash
   php occ app:get-value arbeitszeitcheck permissions
   ```

**Solutions:**

- **Verify user exists:** Check Nextcloud user management
- **Check role:** Ensure user has appropriate role (employee, manager, admin)
- **Review access control:** Check controller annotations (@NoAdminRequired)

### Manager cannot approve requests

**Symptoms:**
- Approval buttons not visible
- "Not authorized" errors

**Solutions:**

1. **Verify manager assignment:**
   ```sql
   SELECT * FROM oc_preferences 
   WHERE appid='arbeitszeitcheck' 
   AND configkey='manager_id' 
   AND userid='USERID';
   ```

2. **Check manager relationship:**
   - Ensure manager_id set correctly
   - Verify manager user exists

---

## Browser Compatibility

### Features not working in specific browser

**Symptoms:**
- Buttons don't respond
- Styles broken
- JavaScript errors

**Solutions:**

1. **Check browser console:**
   - Open Developer Tools (F12)
   - Check for JavaScript errors
   - Review network tab for failed requests

2. **Test in different browser:**
   - Verify if issue is browser-specific
   - Check browser version compatibility

3. **Clear browser cache:**
   - Hard refresh (Ctrl+F5 / Cmd+Shift+R)
   - Clear cache and cookies

### Mobile interface issues

**Symptoms:**
- Layout broken on mobile
- Touch targets too small
- Scrolling issues

**Solutions:**

- **Check viewport:** Verify meta viewport tag
- **Test responsive breakpoints:** 320px, 768px, 1024px
- **Verify touch targets:** Minimum 44x44px

---

## Database Issues

### Database connection errors

**Symptoms:**
- "Database error" messages
- App completely non-functional

**Diagnosis:**

1. **Test database connection:**
   ```bash
   mysql -u nextcloud -p -h localhost nextcloud -e "SELECT 1;"
   ```

2. **Check Nextcloud config:**
   ```bash
   php occ config:system:get dbtype
   php occ config:system:get dbname
   ```

**Solutions:**

- **Verify credentials:** Check database username/password
- **Check database server:** Ensure MySQL/PostgreSQL running
- **Review connection limits:** Check max_connections setting

### Data corruption

**Symptoms:**
- Inconsistent data
- Foreign key violations
- Missing relationships

**Solutions:**

1. **Check data integrity:**
   ```sql
   -- Check for orphaned records
   SELECT * FROM oc_at_entries e
   LEFT JOIN oc_users u ON e.user_id = u.uid
   WHERE u.uid IS NULL;
   ```

2. **Repair if needed:**
   ```bash
   php occ maintenance:repair
   ```

3. **Restore from backup:**
   ```bash
   mysql -u nextcloud -p nextcloud < backup.sql
   ```

---

## Log Analysis

### Finding relevant logs

**Nextcloud log location:**
```bash
tail -f data/nextcloud.log | grep arbeitszeitcheck
```

**Filter by severity:**
```bash
grep "ERROR.*arbeitszeitcheck" data/nextcloud.log
grep "WARN.*arbeitszeitcheck" data/nextcloud.log
```

**Filter by user:**
```bash
grep "user_id.*USERID" data/nextcloud.log | grep arbeitszeitcheck
```

### Common error patterns

**Database errors:**
```
[arbeitszeitcheck] ERROR: SQLSTATE[23000]: Integrity constraint violation
```
→ Check foreign key constraints, missing related records

**Permission errors:**
```
[arbeitszeitcheck] ERROR: Access denied for user
```
→ Check user permissions, role assignments

**Validation errors:**
```
[arbeitszeitcheck] ERROR: Invalid input: DATE_FORMAT
```
→ Check date format, timezone handling

---

## Getting Help

If you cannot resolve an issue:

1. **Check documentation:**
   - [User Manual](User-Manual.en.md)
   - [Administrator Guide](Administrator-Guide.en.md)
   - [FAQ](FAQ.en.md)

2. **Search existing issues:**
   - GitHub Issues: https://github.com/nextcloud/arbeitszeitcheck/issues

3. **Create detailed bug report:**
   - Nextcloud version
   - PHP version
   - Database type and version
   - Error messages
   - Steps to reproduce
   - Relevant log excerpts

4. **Contact support:**
   - IT department (for internal issues)
   - GitHub Issues (for app bugs)
   - Nextcloud Forums (for general questions)

---

**Last Updated:** 2025-12-29
