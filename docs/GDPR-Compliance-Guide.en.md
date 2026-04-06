# GDPR Compliance Guide – ArbeitszeitCheck (TimeGuard)

> **Important:** This guide explains how ArbeitszeitCheck can be operated in a GDPR-compliant way. It does **not** replace legal advice. Each organization remains responsible for its own compliance and should involve its Data Protection Officer (DPO) and legal counsel.

## 1. Role of ArbeitszeitCheck in Your GDPR Compliance

ArbeitszeitCheck is designed to support German organizations in complying with:

- **GDPR/DSGVO** – especially Art. 5, 6, 13–22, 25, 30, 32, 35
- **German labor law (ArbZG)** – mandatory time recording and working time limits

The app focuses on **data minimization**, **purpose limitation**, and **employee rights** while enforcing legal working time rules.

You, as the controller, must:

- Define and document purposes and legal bases
- Inform employees transparently (Art. 13 GDPR)
- Configure roles, retention, and integrations appropriately
- Maintain records of processing activities (Art. 30)
- Perform a DPIA if required (Art. 35)

ArbeitszeitCheck provides technical features and documentation templates to help you meet these obligations.

## 2. Legal Basis and Purpose Limitation (Art. 5, 6 GDPR)

### 2.1 Recommended Primary Legal Basis

For mandatory time tracking under German law, the recommended legal basis is:

- **Art. 6(1)(c) GDPR – Legal obligation**
  - Compliance with **ArbZG** and related national regulations

Optionally, you may also rely on:

- **Art. 6(1)(b) GDPR – Performance of the employment contract** (e.g. payroll-relevant working time data)
- **Art. 6(1)(f) GDPR – Legitimate interest** (e.g. defense against legal claims), with a documented balancing test

### 2.2 Purpose Limitation

You must clearly define and document that ArbeitszeitCheck is used for:

- Fulfilment of legal working time documentation duties (ArbZG)
- HR and payroll processes
- Health and safety (enforcing breaks and rest periods)
- Compliance and audit documentation

**Not allowed** without a separate legal basis and works council agreement:

- Covert performance or behavior monitoring
- Creation of detailed performance profiles from time data
- Use of data for disciplinary purposes beyond what is legally permitted

ArbeitszeitCheck is designed to separate **mandatory compliance data** from **optional project/cost center tracking** to support purpose limitation.

## 3. Data Minimization and Configuration

### 3.1 Default Data Set

Out of the box, ArbeitszeitCheck only requires:

- User ID (Nextcloud account ID)
- Basic identity (name, department, manager)
- Time records (date, start, end, breaks, duration)
- Absence types (without medical diagnosis)
- Compliance violations (type, date, severity)
- Audit logs (who changed what, when)

No special categories of personal data (Art. 9 GDPR) are required or processed by default.

### 3.2 Optional Data You Should Control

ArbeitszeitCheck supports optional features that you should carefully evaluate:

- **Project / cost center assignment**
  - Use only if needed for billing / controlling
  - Avoid overly granular analytics at individual level
- **Free-text fields (descriptions, comments)**
  - Train users **not** to enter sensitive data (e.g. diagnoses, political views)
- **Integrations (e.g. DATEV export)**
  - Ensure that only necessary fields are exported
- **Email with `.ics` attachments (optional)**
  - Some workflows send iCalendar files by email so recipients can import events into a calendar client manually. This is **not** automatic synchronization with the Nextcloud Calendar app; evaluate purpose and recipients under Art. 6 GDPR like any other notification.

Use the **admin settings** in ArbeitszeitCheck to:

- Restrict optional fields where possible
- Define clear internal rules for what may be entered into free-text fields

## 4. Transparency and Employee Information (Art. 13 GDPR)

You must inform employees in clear language about:

- What data is collected in ArbeitszeitCheck
- For which purposes and on which legal basis
- Who receives the data (internally and externally)
- How long data is stored
- Their rights under GDPR
- Contact details of the DPO

Recommended steps:

1. Create an **internal data protection information sheet** for employees.
2. Reference ArbeitszeitCheck explicitly as the time tracking system.
3. Provide links to:
   - Internal documentation / intranet pages
   - The DPIA (summary) where appropriate
   - This GDPR compliance guide

ArbeitszeitCheck does not generate privacy notices or legal templates. Reuse your organisation’s existing GDPR documentation (privacy notices, records of processing, DPIA) and add ArbeitszeitCheck there as the system used for time tracking and compliance.

## 5. Data Subject Rights in ArbeitszeitCheck

ArbeitszeitCheck supports core GDPR rights operationally. As controller you must define **processes** and **SLAs**.

### 5.1 Right of Access (Art. 15)

- Employees can:
  - View their own time entries and absences in the employee portal
  - Export their personal data in machine-readable formats (CSV/JSON)
- HR can provide additional exports on request (e.g. PDFs for specific periods)

Recommended:

- Define a documented procedure for Art. 15 requests
- Use the built-in exports as primary tool

### 5.2 Right to Rectification (Art. 16)

- Employees cannot arbitrarily change historical records
- Instead, they submit **correction requests** with justification
- Managers/HR approve or reject these requests
- All changes are audit-logged (before/after values)

This ensures both **data accuracy** and **tamper resistance**, while keeping a full audit trail for compliance.

### 5.3 Right to Erasure and Retention Limits (Art. 17 GDPR vs. legal retention)

- ArbeitszeitCheck implements a **minimum 2-year retention** for time records (ArbZG)
- The GDPR erasure right is therefore **limited by legal retention duties**
- The app provides a **GDPR deletion function** that:
  - Removes data older than the configured retention period
  - Cleans up user-specific settings beyond what is necessary

Recommended:

- Document your retention policy for time, absence, and audit data
- Communicate clearly to employees that some data cannot be deleted before legal retention expires

### 5.4 Data Portability (Art. 20)

- Users can export their data via the employee portal (CSV/JSON)
- HR can export additional formats (e.g. DATEV or PDF summaries)

Ensure that:

- You have an internal process for providing structured exports on request
- You document which formats you support and for which data sets

## 6. Security and Technical/Organizational Measures (Art. 32 GDPR)

ArbeitszeitCheck integrates with Nextcloud’s existing security model:

- Authentication and 2FA provided by Nextcloud
- TLS encryption (HTTPS) required for secure access
- Role-based access control (employee, manager, HR, admin)
- Audit logging of sensitive operations

Your responsibilities:

- Operate Nextcloud and ArbeitszeitCheck on **secure, patched servers**
- Enforce **HTTPS-only** access
- Configure **role assignments** carefully (principle of least privilege)
- Regularly review access logs and system updates
- Integrate ArbeitszeitCheck into your **backup and recovery strategy**

Map these to your Art. 32 TOMs document, including:

- Access control
- Encryption
- Availability and resilience
- Incident response processes

## 7. Documentation Obligations (Art. 30, 35 GDPR)

ArbeitszeitCheck fits into your existing GDPR documentation; it does not replace it or ship legal templates.

Recommended workflow:

1. Extend your **record of processing activities** (Art. 30) to include ArbeitszeitCheck as a processing activity (time tracking, absence management, compliance monitoring).
2. Perform and document a **DPIA** (Art. 35) if your DPO deems it necessary (often yes for employee time tracking) and explicitly reference ArbeitszeitCheck in scope and risk analysis.
3. If a works council exists, document co-determination and any works council agreements (BetrVG §87) in your internal documentation.

Store signed versions and final documents in your internal documentation system (not in this app repository).

## 8. Works Council and Co-Determination (BetrVG §87)

Time tracking systems typically fall under **co-determination** for:

- Start and end times of daily working hours
- Temporary shortening or extension of working time
- Introduction and use of technical devices designed to monitor employee behavior

ArbeitszeitCheck is intentionally **not** a surveillance tool, but:

- Working and absence data are sensitive for employees
- Evaluations can have significant impact (e.g. on performance talks)

If a works council exists:

- Involve it early
- Use the provided **works council agreement template** as a basis
- Clearly define:
  - Which evaluations are permissible
  - Who may access which data
  - How long data may be retained

## 9. Recommended Configuration Checklist

Before going live, verify at least:

1. **Legal basis documented** (Art. 6) – primarily Art. 6(1)(c) GDPR for ArbZG
2. **Purposes clearly defined** and communicated (compliance, HR, payroll)
3. **Roles configured** in ArbeitszeitCheck:
   - Employees only see their own data
   - Managers see only their teams
   - HR sees required data for all staff
   - Admins have technical access only
4. **Retention settings** in admin panel configured to your policy (≥ 2 years)
5. **Privacy information** for employees updated and distributed
6. **DPIA** performed and documented, if applicable
7. **Record of processing activities** updated
8. **Works council agreement** signed (if required)
9. **Backups and restore tests** cover ArbeitszeitCheck data
10. **Security baseline** of the Nextcloud instance checked (TLS, updates, access control)

## 10. Summary

ArbeitszeitCheck (TimeGuard) provides a technical foundation for legally compliant time tracking under German law with strong support for GDPR principles:

- Data minimization and purpose limitation are built into the data model
- Employee rights are supported via exports, correction workflows and deletion logic
- Role-based access and audit logging enforce accountability
- Documentation templates help you meet Art. 30 and Art. 35 GDPR obligations

However, **compliance is a shared responsibility**:

- The app provides the tools and secure defaults
- Your organization must configure it correctly, define clear policies and involve the DPO and works council where required.

When in doubt, consult your DPO or legal counsel and adapt the templates in `apps/arbeitszeitcheck/docs/` to your specific situation.
