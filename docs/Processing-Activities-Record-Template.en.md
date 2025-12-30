---
title: "Record of Processing Activities – ArbeitszeitCheck (TimeGuard)"
version: "1.0"
status: "Template"
last_updated: "2025-12-29"
---

> **Purpose of this template**
>
> This template supports controllers in fulfilling **Art. 30 GDPR – Records of processing activities** for the use of **ArbeitszeitCheck (TimeGuard)** as a time tracking system.  
> It must be adapted to the specific circumstances of the organization and does **not** replace legal advice.

---

## 1. Controller

- **Name of the controller (company / organization):**
- **Address:**
- **Contact details:**
  - Phone:
  - Email:
- **Data Protection Officer (DPO):**
  - Name:
  - Contact:

---

## 2. Processing Activity 1 – Time Tracking and Working Time Compliance

- **Name of processing activity:**  
  Time tracking and ArbZG-compliant working time documentation using ArbeitszeitCheck

- **Purpose(s) of processing:**
  - Fulfillment of legal obligations under German Working Time Act (ArbZG), including:
    - Documentation of daily working time (start, end, breaks)
    - Compliance monitoring of maximum working hours and rest periods
    - Documentation for audits by authorities
  - Support of HR processes and payroll (calculation of working hours and overtime)
  - Internal compliance and audit (traceability of time records and corrections)

- **Categories of data subjects:**
  - Employees (full-time, part-time)
  - Temporary workers / freelancers (if included)
  - Trainees, interns, working students
  - Managers / supervisors (for access logs and approvals)

- **Categories of personal data:**
  - Identification data:
    - User ID (Nextcloud account ID)
    - Name / display name
    - Organizational unit / department
    - Manager assignment
  - Time tracking data:
    - Date of workday
    - Start time, end time
    - Break start and end
    - Calculated working time and overtime
    - Status (active, completed, correction requested, approved, rejected)
    - Justification text for manual entries and corrections
  - Metadata:
    - Timestamps of changes
    - User account performing the action
    - IP address and user agent (if logged for security / audit)

- **Categories of recipients:**
  - Internal recipients:
    - Direct managers / supervisors (access to team time data)
    - HR department (access to all relevant employee time data)
    - Payroll department (access to aggregated time data / exports)
    - IT administrators (technical access only, based on least privilege)
  - External recipients (if applicable):
    - External payroll provider (e.g. via DATEV export)
    - External auditors / authorities (only as required by law)

- **Transfers to third countries or international organizations:**
  - ☐ No such transfers  
  - ☐ Yes, details:
    - Recipient:
    - Country:
    - Legal basis and safeguards (e.g. SCCs):

- **Retention periods:**
  - Time records:
    - Minimum retention: **2 years** (labor law compliance)
    - Organization-specific retention: … years (documented justification)
  - Aggregated evaluation data:
    - Retention: … years
  - Logs / technical records:
    - Retention: e.g. 2–3 years (security and audit), specify:

- **General description of technical and organizational measures (TOMs):**
  - Access control:
    - Role-based access (employee, manager, HR, admin)
    - Authentication via Nextcloud (optionally with 2FA)
  - Confidentiality and integrity:
    - TLS-encrypted connections
    - Hardening of Nextcloud server
    - Audit logs for critical actions
  - Availability and resilience:
    - Regular backups and tested restore
    - Monitoring and patch management
  - Data minimization:
    - Only necessary fields for time tracking
    - No special categories of personal data

---

## 3. Processing Activity 2 – Absence and Vacation Management

- **Name of processing activity:**  
  Recording and management of absences and vacation via ArbeitszeitCheck

- **Purpose(s) of processing:**
  - Administration of vacation entitlements and absences (vacation, sick leave, other leave)
  - Documentation for payroll and HR
  - Proof of compliance with labor law and internal policies

- **Categories of data subjects:**
  - Employees
  - Trainees, interns, working students

- **Categories of personal data:**
  - Identification data:
    - User ID
    - Name / display name
  - Absence data:
    - Absence type (vacation, sick leave, special leave, unpaid leave, etc.)
    - Start and end date
    - Number of days
    - Status (requested, approved, rejected, canceled)
    - Justification text / comments (if provided)
    - Approver comments and timestamps

- **Categories of recipients:**
  - Direct managers / supervisors
  - HR department
  - Payroll (for relevant absence reporting)

- **Transfers to third countries or international organizations:**
  - ☐ No such transfers  
  - ☐ Yes, details: …

- **Retention periods:**
  - Absence records:
    - Retention aligned with HR/payroll retention policies (e.g. … years)
    - Justify alignment with national labor, tax and commercial law

- **TOMs (summary):**
  - Same baseline measures as for time tracking
  - Additional:
    - Strict access controls (only managers/HR with need-to-know)
    - Separate handling of sensitive absence types (if applicable)

---

## 4. Processing Activity 3 – Compliance Monitoring and Violation Management

- **Name of processing activity:**  
  Automated and manual detection of working time compliance violations

- **Purpose(s) of processing:**
  - Detection and documentation of:
    - Exceeded maximum working hours
    - Missing or insufficient breaks
    - Violated rest periods
    - Sunday, holiday and night work where applicable
  - Provision of information to employees, managers and HR for preventive and corrective actions
  - Evidence for audits and legal defense, if needed

- **Categories of data subjects:**
  - Employees
  - Managers (as approvers and decision-makers)

- **Categories of personal data:**
  - Identification data (e.g. user ID, name)
  - Time tracking data (see Processing Activity 1)
  - Compliance data:
    - Violation type and category (e.g. missing break, daily hours exceeded)
    - Date, time, duration, severity
    - Status (open, resolved)
    - Resolution details and justification

- **Categories of recipients:**
  - Employee (access to own violations)
  - Manager (access to team violations)
  - HR / compliance (access as needed)

- **Transfers to third countries or international organizations:**
  - ☐ No such transfers  
  - ☐ Yes, details: …

- **Retention periods:**
  - Compliance violations:
    - Retention period (e.g. 2–3 years), justify
    - Alignment with limitation periods and HR practices

- **TOMs (summary):**
  - Strict access control (only roles with legitimate need)
  - Clear policy: data used for compliance and health protection, not for disproportionate performance evaluation
  - Audit logs for access and modifications

---

## 5. Processing Activity 4 – Audit Logging and Security Monitoring

- **Name of processing activity:**  
  Logging of access and changes in ArbeitszeitCheck for security and accountability

- **Purpose(s) of processing:**
  - Detection and investigation of unauthorized or abusive use
  - Demonstration of compliance (accountability, Art. 5(2) GDPR)
  - Support for incident response and forensic analysis

- **Categories of data subjects:**
  - Employees (as users of the system)
  - Managers
  - HR staff
  - Administrators

- **Categories of personal data:**
  - User IDs involved in actions
  - Action types (create, update, delete, approve, reject, login, etc.)
  - Affected entities (e.g. time entry ID, absence ID)
  - Timestamps
  - Technical metadata:
    - IP address (if logged)
    - User agent (if logged)

- **Categories of recipients:**
  - Limited to:
    - Security / IT
    - DPO (for investigations)
    - HR/compliance (where justified)

- **Transfers to third countries or international organizations:**
  - ☐ No such transfers  
  - ☐ Yes, details: …

- **Retention periods:**
  - Audit logs:
    - Retention: e.g. 2–3 years (specify)
    - Justification: need for traceability and incident investigation

- **TOMs (summary):**
  - Separate storage and restricted access to logs
  - Encryption in transit and appropriate access control
  - Regular review and log rotation

---

## 6. Processing Activity 5 – Reporting and Exports (Including DATEV)

- **Name of processing activity:**  
  Generation of reports and exports (CSV, JSON, PDF, DATEV) from ArbeitszeitCheck

- **Purpose(s) of processing:**
  - Provision of time and absence data to:
    - Payroll (including DATEV-compatible exports)
    - Internal HR reports (overtime overview, absence statistics)
    - Compliance reporting (violation overview, audit logs)

- **Categories of data subjects:**
  - Employees
  - Managers

- **Categories of personal data:**
  - Time tracking data (aggregated and/or detailed)
  - Absence data
  - Compliance data (if included in reports)

- **Categories of recipients:**
  - Internal: HR, payroll, management
  - External: payroll providers, tax consultants (if connected)

- **Transfers to third countries or international organizations:**
  - ☐ No such transfers  
  - ☐ Yes, details: …

- **Retention periods:**
  - Exports stored in internal systems:
    - Retention according to payroll / financial archiving rules (e.g. up to 10 years)
    - Clearly defined storage locations and access controls

- **TOMs (summary):**
  - Secure transfer (e.g. SFTP, VPN) where external parties are involved
  - Access control and logging for export creation and download
  - Clear procedures for deletion of obsolete exports

---

## 7. Additional Notes and References

Use this section for:

- References to:
  - DPIA document
  - Internal policies and procedures
  - Works council agreements
  - Technical documentation of ArbeitszeitCheck
- Versioning and review plan for this record:
  - Date of creation:
  - Last review:
  - Next planned review:

