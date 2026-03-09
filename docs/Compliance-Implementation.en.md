# Compliance Implementation: Real-Time Checking According to ArbZG

> **Note:** This document describes the **technical implementation** of ArbZG‑related compliance checks in ArbeitszeitCheck.  
> For a legal‑level overview and consolidated assessment see `ArbZG-Compliance-Analyse.md`.  
> This document is **not legal advice**.

## Legal Foundations

### Working Time Act (ArbZG) - Break Requirements (§4)

**Legal Requirements:**

1. **Working time up to 6 hours:**
   - No legal break requirement

2. **Working time more than 6 to 9 hours:**
   - **Minimum 30 minutes break required**
   - Break can be split into sections of at least 15 minutes each
   - **Important:** Employees must not work longer than 6 hours without a break

3. **Working time more than 9 hours:**
   - **Minimum 45 minutes break required**
   - Break can be split into sections of at least 15 minutes each

**Legal Consequences of Violations:**
- Administrative offense under ArbZG §22
- Fine up to €15,000 for the employer
- Liability risk in case of workplace accidents
- Possible damage claims

### Recording Requirements

**ArbZG §16 Para. 2:**
- Employers must record working time exceeding 8 hours per day
- Records must be kept for at least 2 years

**BAG/EU Court Rulings:**
- Employers are required to record all working time
- Recommendation: Also document break times to prove compliance

## Industry Best Practices

### Personio (Market Leader in Time Tracking)

**Implementation Approach:**
1. **Real-time checking:** Compliance is checked immediately upon entry
2. **Configurable modes:**
   - **Warning mode:** User is warned but can save (with justification)
   - **Strict mode:** Saving is prevented if break requirements are not met
3. **Automatic break correction:** Optionally, the system can automatically deduct required breaks
4. **Immediate notifications:** Violations are immediately notified to users and HR

**Advantages:**
- Early detection of violations
- Proactive compliance
- Reduced legal risk
- Better auditability

### Other Systems (Flintec, Almas Industries)

- **Real-time checking** is recommended as best practice
- **Batch processing** only as backup/retrospective
- **Immediate alerting** for critical violations

## Implementation Strategy

### Architecture Decisions

**1. Real-Time Checking (Primary)**
- Compliance is checked **immediately** when a TimeEntry is completed
- Violations are **immediately** stored in the database
- Notifications are **immediately** sent

**2. Daily Batch Checking (Backup)**
- Continues to run as backup
- Detects violations missed by real-time checking
- Checks historical data after rule changes

**3. Check Points:**
- ✅ When creating a TimeEntry (if STATUS_COMPLETED)
- ✅ When updating a TimeEntry (if status changes to COMPLETED)
- ✅ When approving by manager (if status changes to COMPLETED)
- ✅ Daily in batch job (backup)

### Configurable Modes

**1. Warning Mode (Default)**
- Violations are detected and stored
- User is warned but can save
- Justification is recommended but not required
- Notifications are sent

**2. Strict Mode (Optional)**
- Violations are detected
- **Saving is prevented** if critical violations exist
- Justification is **required** for violations
- Notifications are sent immediately

**3. Auto-Correction Mode (Optional)**
- Missing breaks are automatically deducted from working time
- User is informed
- Justification is recommended

## Technical Implementation

### Checking Logic

**When is it checked:**
1. After `TimeEntryMapper::insert()` - if `status === STATUS_COMPLETED`
2. After `TimeEntryMapper::update()` - if `status === STATUS_COMPLETED` or changes to `STATUS_COMPLETED`
3. In `TimeEntryController::create()` - after successful insert
4. In `TimeEntryController::update()` - after successful update
5. In `ManagerController::approve()` - after approval

**What is checked:**
1. **Break requirements (ArbZG §4):**
   - ≥6 hours: At least 30 minutes break?
   - ≥9 hours: At least 45 minutes break?
2. **Maximum working time (ArbZG §3):**
   - Maximum 10 hours per day?
3. **Rest period (ArbZG §5):**
   - At least 11 hours between shifts?
4. **Sunday work (ArbZG §9):**
   - Worked on Sunday? (Warning)
5. **Holiday work (ArbZG §9):**
   - Worked on holiday? (Warning)
6. **Night work (ArbZG §6):**
   - Worked between 23:00-06:00? (Info)

### Error Handling

**For Real-Time Checking:**
- Checking must **not** prevent saving the TimeEntry (except in Strict Mode)
- Errors in compliance checking are logged but not re-thrown
- TimeEntry is saved anyway (data integrity has priority)

**In Strict Mode:**
- Critical violations prevent saving
- HTTP 400 Bad Request with detailed error message
- TimeEntry is **not** saved

## Data Model

### ComplianceViolation

**Fields:**
- `id`: Unique ID
- `user_id`: Affected user
- `violation_type`: Type of violation (e.g., `missing_break`)
- `description`: Description of violation
- `date`: Date of violation
- `time_entry_id`: Link to TimeEntry
- `severity`: Severity level (`error`, `warning`, `info`)
- `resolved`: Was the violation resolved?
- `resolved_at`: When was it resolved?
- `resolved_by`: Who resolved it?
- `created_at`: When was the violation detected?

**Violation Types:**
- `missing_break`: Missing break (critical)
- `excessive_working_hours`: Exceeding maximum working time (critical)
- `insufficient_rest_period`: Insufficient rest period (critical)
- `weekly_hours_limit_exceeded`: Exceeding weekly working time (warning)
- `night_work`: Night work (info)
- `sunday_work`: Sunday work (warning)
- `holiday_work`: Holiday work (warning)

## Notifications

**Recipients:**
1. **Affected user:** Immediate notification about violation
2. **HR/Manager:** Notification for critical violations
3. **Administrator:** Notification for system-wide issues

**Content:**
- Type of violation
- Date and time
- Affected TimeEntry
- Recommended actions
- Link to compliance overview

## Audit Trail

**Every compliance check is logged:**
- When was it checked?
- Who created/updated the TimeEntry?
- What violations were detected?
- Were notifications sent?

**Purpose:**
- Proof of compliance efforts
- Legal protection
- Analysis and improvement

## Migration and Backward Compatibility

**Existing Data:**
- Daily batch checking continues to run
- Existing violations remain
- New violations are additionally detected

**Configuration:**
- Default: Warning Mode (no breaking changes)
- Strict Mode: Opt-in via admin settings
- Auto-Correction: Opt-in via admin settings

## Performance Considerations

**Optimizations:**
- Compliance checking runs asynchronously (background job) under high load
- Caching of compliance rules
- Batch processing for historical data

**Monitoring:**
- Check duration is measured
- Error rate is monitored
- Notification delivery is logged

## Legal Notes

**Important Disclaimer:**
- This implementation supports compliance with ArbZG
- It does **not** replace legal advice
- Companies should coordinate their compliance strategy with lawyers
- Regional particularities (e.g., collective agreements) must be considered

**Liability Disclaimer:**
- Software is provided "as is"
- No warranty for complete compliance
- Companies are responsible for compliance with laws

## Further Information

- [Working Time Act (ArbZG)](https://www.gesetze-im-internet.de/arbzg/)
- [BAG Ruling on Time Recording Requirement](https://www.bundesarbeitsgericht.de/)
- [Personio Compliance Documentation](https://support.personio.de/hc/de/articles/115000671865)
- [IHK Munich: Working Time and Breaks](https://www.ihk-muenchen.de/)
