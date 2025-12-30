# Privacy Policy – ArbeitszeitCheck / TimeGuard

**Last updated:** 2025-12-29  
**Applies to:** The use of the ArbeitszeitCheck / TimeGuard app within your organization’s self‑hosted Nextcloud instance.

> **Important:** This document is a **template** and does **not** constitute legal advice.  
> Every organization remains responsible for its own GDPR/DSGVO and labor law compliance and should have this policy reviewed by qualified legal counsel and, where applicable, the works council.

---

## 1. Controller and Contact Details

**Controller (Art. 4(7) GDPR)**  
The controller for all personal data processed via ArbeitszeitCheck / TimeGuard is the organization operating the Nextcloud instance (e.g. your company, public authority, or association).

Insert your details here:

- **Organization name:** _[Your Company Name]_  
- **Address:** _[Street, Postal Code, City, Country]_  
- **Phone:** _[Phone number]_  
- **Email:** _[General contact email]_  

**Data Protection Officer (DPO), if applicable (Art. 37 GDPR)**  

- **DPO name:** _[Name or “Data Protection Officer”]_  
- **Address:** _[Address if different]_  
- **Email:** _[DPO email]_  
- **Phone:** _[DPO phone]_  

---

## 2. Scope of this Privacy Policy

This privacy policy explains how ArbeitszeitCheck / TimeGuard processes personal data when used in your organization’s Nextcloud instance. It covers:

- Recording of working time (clock‑in/clock‑out, breaks)  
- Management of absences (vacation, sick leave, special leave, unpaid leave)  
- Compliance monitoring with German working time regulations (Arbeitszeitgesetz – ArbZG)  
- Reporting and exports for HR/payroll and legal compliance purposes  
- Optional integration with the ProjectCheck app (project time tracking)

The app is designed to support **German labor law** and **GDPR/DSGVO** requirements. It processes **employee time data**, not content data such as files, emails, or messages.

---

## 3. Purposes of Processing (Art. 5(1)(b), Art. 6(1)(c), (f) GDPR)

ArbeitszeitCheck processes personal data strictly for the following purposes:

1. **Compliance with labor law obligations (legal obligation, Art. 6(1)(c) GDPR)**  
   - Mandatory recording of working time (ECJ C‑55/18, BAG case law)  
   - Enforcement of maximum daily and weekly working hours  
   - Monitoring of required rest periods and breaks  
   - Documentation of Sunday, public holiday, and night work  
   - Provision of evidence to supervisory authorities and courts

2. **HR administration and payroll support (Art. 6(1)(b), (c) GDPR)**  
   - Calculation of working hours and overtime  
   - Management and documentation of absences and vacation entitlements  
   - Provision of reports and exports (e.g. DATEV, CSV) for payroll/accounting systems  

3. **Organizational planning and transparency (legitimate interest, Art. 6(1)(f) GDPR)**  
   - Team and department overviews for managers  
   - Planning of staffing and absences  
   - Ensuring operational continuity  

4. **Optional: Project and cost center tracking (Art. 6(1)(b), (f) GDPR)**  
   - Allocation of working time to projects or cost centers  
   - Project controlling and customer billing  
   - This is **logically and technically separated** from the core, legally required working time tracking.

ArbeitszeitCheck **must not** be used for disproportionate performance monitoring or surveillance of employees. Any additional use beyond the purposes listed above requires a **separate legal basis** and, where applicable, agreement with the works council.

---

## 4. Categories of Personal Data Processed

ArbeitszeitCheck follows the **data minimization** principle (Art. 5(1)(c) GDPR). The app is designed to store **only the data required** for legal time tracking and HR administration.

### 4.1 Core Time Tracking Data

- **User identifier** (Nextcloud user ID)  
- **Workday records**:
  - Start time and end time of each work period  
  - Break start and end times  
  - Total daily working time and break durations (derived)  
  - Status of the entry (active, completed, break, pending approval, rejected)  
  - Indicator whether an entry was **recorded manually** (manual correction)  
  - Mandatory justification text for manual corrections/late entries  
- **Compliance attributes** (derived):
  - Violations of maximum daily working hours  
  - Weekly average working hours (for compliance with 48‑hour rule)  
  - Insufficient breaks or rest periods  
  - Flags for Sunday, public holiday, and night work  

### 4.2 Absence and Vacation Data

- Type of absence (e.g. vacation, sick leave, special leave, unpaid leave)  
- Start and end date of absence  
- Number of working days affected (calculated)  
- Approval status (pending, approved, rejected)  
- Reason text for absence (if provided)  
- Vacation entitlement and vacation days used per calendar year  

### 4.3 User and Configuration Data

- Working time model assigned to the user (e.g. full‑time, part‑time, shift model)  
- Contractual weekly/daily working hours  
- Core hours or flexible time rules (if configured)  
- Night work, Sunday, and holiday rules  
- Notification preferences (e.g. reminders to clock out, break reminders, missing entry alerts)  
- Manager assignment (for approval workflows)  

### 4.4 Audit Log Data

For accountability and tamper‑protection, ArbeitszeitCheck stores an **audit trail** of relevant actions:

- Who created, changed, or deleted time entries, absences, or settings  
- When the action took place (timestamp)  
- What changed (old vs. new values, where technically feasible)  
- IP address and browser user‑agent (configurable, may be disabled by the controller if not required)

### 4.5 Optional Project / Cost Center Data

If the optional ProjectCheck integration is enabled:

- Project or cost center identifiers  
- Association between time entries and projects/cost centers  
- Optional client/customer references  

Project‑level data is kept **separate** from core legal time records; legal compliance does **not** depend on associating entries with projects.

### 4.6 Data Not Collected by Default

ArbeitszeitCheck **does not** require or implement:

- Screen recordings, keystroke logging, or activity monitoring  
- Content of documents, emails, or chat messages  
- Detailed location data or GPS tracking  
- Biometric data (e.g. fingerprints, face recognition)  

Any such processing would require **separate tools, a separate legal basis, and explicit transparency** well beyond this app.

---

## 5. Legal Bases for Processing (Art. 6 GDPR)

Depending on national law and the concrete employment context, the following legal bases typically apply:

1. **Art. 6(1)(c) GDPR – Legal obligation**  
   - Compliance with ArbZG requirements for recording and monitoring working time  
   - Provision of evidence to supervisory authorities and courts  

2. **Art. 6(1)(b) GDPR – Performance of the employment contract**  
   - Calculation and documentation of working hours and overtime  
   - Vacation and absence management  
   - Preparation of payroll and related HR processes  

3. **Art. 6(1)(f) GDPR – Legitimate interests**  
   - Transparent and efficient staff planning  
   - Avoidance of burnout and health risks from excessive working hours  
   - Documentation for internal audits and compliance management  

4. **Art. 6(1)(a) GDPR – Consent (only for optional features)**  
   - Optional additional tracking that goes beyond strict legal requirements (e.g. GPS location, detailed project analytics)  
   - Any such features must be **explicitly enabled** by the controller and explained in **separate information and consent texts**.

ArbeitszeitCheck is explicitly **not** based on consent for the core time recording obligations: the employer is legally required to record working time.

---

## 6. Recipients and Categories of Recipients (Art. 13(1)(e), 13(3) GDPR)

Within your organization, access to data from ArbeitszeitCheck is typically restricted as follows:

- **Employees (data subjects)**  
  - Can see their own working time records, absences, vacation balances, and compliance notifications.

- **Direct managers / team leads**  
  - See time and absence data of their team members as required for approval workflows, planning, and compliance.

- **HR department / personnel administration**  
  - Has extended access for global administration, reporting, and compliance checks.

- **Payroll / accounting**  
  - Receives exports (e.g. DATEV, CSV) for salary calculation and payroll processes.

- **System administrators (Nextcloud/IT)**  
  - Technical access to the application and database for maintenance and backup purposes; they must be bound by confidentiality and internal policies.

No data is transmitted to the developer of the app (unless you actively send logs or data for support purposes) and no data is sent to third parties by default.

If data is transferred to external processors (e.g. hosting providers, IT service providers), **data processing agreements (Art. 28 GDPR)** must be concluded by the controller.

---

## 7. Data Retention and Deletion (Art. 5(1)(e) GDPR)

ArbeitszeitCheck supports configurable retention periods. By default, the system is designed to meet at least the **two‑year retention** typically required for working time records in Germany.

Typical configuration (can be adapted by the controller):

- **Time records (working time, breaks, violations):**  
  - Retention: at least **2 years** after the end of the calendar year in which the record was created.  
  - After expiry: automatic, secure deletion or anonymization.

- **Absence data and vacation records:**  
  - Retention aligned with employment and payroll documentation requirements (often up to 3–10 years, depending on national law).  

- **Audit logs:**  
  - Retention as long as necessary to ensure traceability, compliance, and defense of legal claims; then deletion or anonymization.

Concrete retention periods must be **configured and documented by your organization** in line with local law and internal policies. ArbeitszeitCheck provides technical means to enforce these policies but does not replace your legal assessment.

---

## 8. Employee Rights (Art. 12–22 GDPR)

Employees (data subjects) have the following rights with respect to their data in ArbeitszeitCheck:

1. **Right of access (Art. 15 GDPR)**  
   - Employees can view their working time records and absences directly in the app.  
   - They can export their data in machine‑readable formats (e.g. CSV, JSON) and, where implemented, PDF.

2. **Right to rectification (Art. 16 GDPR)**  
   - Incorrect entries can be corrected via a **time correction request** workflow.  
   - Corrections are documented in the audit log.

3. **Right to erasure (Art. 17 GDPR)**  
   - Within the bounds of statutory retention obligations, employees may request erasure of data that is no longer required or unlawfully processed.  
   - Where immediate deletion is not legally possible, data is restricted and deleted after the retention period expires.

4. **Right to restriction of processing (Art. 18 GDPR)**  
   - In certain cases (e.g. disputed correctness), data can be flagged/restricted until the issue is resolved.

5. **Right to data portability (Art. 20 GDPR)**  
   - Employees can receive their time records in structured, commonly used, machine‑readable formats.

6. **Right to object (Art. 21 GDPR)**  
   - For processing based on legitimate interests (e.g. analytics), employees may object.  
   - **Note:** Processing necessary for compliance with legal obligations (Art. 6(1)(c)) cannot simply be stopped based on objection.

To exercise these rights, employees should contact the **Controller** or **DPO** listed in Section 1. Your organization should define clear internal procedures and contact paths.

---

## 9. Automated Decision-Making and Profiling (Art. 22 GDPR)

ArbeitszeitCheck **does not** perform automated decision‑making in the sense of Art. 22 GDPR that produces legal effects or similarly significant impacts on employees.

- Compliance checks and violation flags are **rule‑based** evaluations of working time records (e.g. “more than 10 hours worked”, “insufficient break”).  
- Decisions about consequences (e.g. managerial action, HR measures) are made by **human decision‑makers**, not by the app.

---

## 10. Technical and Organizational Measures (Art. 32 GDPR)

ArbeitszeitCheck is built on the Nextcloud platform and uses its security architecture:

- **Authentication and access control** via Nextcloud accounts and groups  
- **Role‑based views** for employees, managers, HR, and admins  
- **Transport security:** HTTPS/TLS enforced at the Nextcloud layer  
- **Database security:** Uses parameterized queries and Nextcloud’s DB abstraction (QBMapper)  
- **Input validation and output escaping** to mitigate common web vulnerabilities (e.g. XSS, SQL injection)  
- **Content Security Policy (CSP)**: All JS/CSS loaded via Nextcloud asset pipeline, no inline scripts/styles  
- **Audit logging** of critical actions  
- **Configurable background jobs** for compliance checks and notifications  

Your organization remains responsible for:

- Secure configuration of the Nextcloud server (patching, firewall, backups)  
- Proper assignment of roles and permissions  
- Secure storage of encryption keys and database credentials  
- Regular review of logs and violation reports  

---

## 11. Works Council and Co-determination (§87 BetrVG)

Where a **works council (Betriebsrat)** exists, its co‑determination rights under §87 BetrVG must be respected.

ArbeitszeitCheck is designed as a **compliance and documentation tool**, not a surveillance system:

- Focus on legal working time compliance, not performance ranking  
- Aggregated and anonymized statistics can be used for reporting and planning  
- Individual performance monitoring beyond what is legally required must be avoided or separately agreed with the works council.

We recommend:

- Concluding a **works council agreement** specifically for ArbeitszeitCheck  
- Defining clear rules on who can see which data and for what purposes  
- Documenting any additional evaluations (e.g. project analytics)

---

## 12. International Data Transfers

ArbeitszeitCheck itself does not initiate data transfers outside your infrastructure.  
If your Nextcloud instance or database is hosted by a provider outside the EU/EEA, or if support partners from third countries access the system, appropriate safeguards must be in place:

- Adequacy decision (Art. 45 GDPR), or  
- Standard Contractual Clauses (Art. 46 GDPR), and  
- Additional technical and organizational safeguards where necessary.

These aspects are under the responsibility of your organization as controller.

---

## 13. Changes to this Privacy Policy

This privacy policy may need to be updated if:

- Legal requirements change (e.g. new case law, amendments to ArbZG or GDPR),  
- New features are introduced that affect data processing (e.g. new types of analytics, integrations), or  
- Your internal processes or system landscape change.

The controller is responsible for:

- Updating this document,  
- Informing employees transparently about significant changes, and  
- Ensuring that the actual use of ArbeitszeitCheck matches this policy.

---

## 14. Questions and Complaints

Employees may contact:

- **Controller / HR** for any questions regarding working time data and HR use of the system.  
- **Data Protection Officer** for any data protection questions or to exercise their rights.  
- **Supervisory Authority** (Art. 77 GDPR) to lodge a complaint if they believe data processing is unlawful.

Please insert the concrete contact details and supervisory authority information applicable to your organization.

