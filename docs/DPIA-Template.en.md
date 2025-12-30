---
title: "Data Protection Impact Assessment (DPIA) – ArbeitszeitCheck"
version: "1.0"
status: "Template"
last_updated: "2025-12-29"
---

> **Purpose of this template**
>
> This DPIA template is provided for organizations using **ArbeitszeitCheck (TimeGuard)** as a time tracking solution in the context of German labor law (ArbZG) and GDPR/DSGVO.  
> It supports fulfillment of Art. 35 GDPR (Data Protection Impact Assessment).  
> **This template does not constitute legal advice.** Organizations remain responsible for adapting and validating it with their Data Protection Officer (DPO) and, where applicable, the works council and legal counsel.

---

## 1. General Information

- **Controller (Organization)**
  - Name:
  - Address:
  - Commercial register / registration no.:
  - Contact person for this DPIA:
  - Email / Phone:

- **Data Protection Officer (DPO)**
  - Name:
  - Internal / external:
  - Contact details:

- **Supervisory Authority (Supervisory Data Protection Authority)**
  - Name:
  - Address:
  - URL:

- **Project / System Name**
  - Name: **ArbeitszeitCheck (TimeGuard)** – Time Tracking System
  - Version:
  - System owner (role / department):

- **Scope of this DPIA**
  - ☐ Introduction of ArbeitszeitCheck as new time tracking system  
  - ☐ Major change to existing time tracking system  
  - ☐ Extension / integration (e.g. with payroll, ProjectCheck)  
  - **Short description of the change / project:**

---

## 2. Description of the Processing (Art. 35(7)(a) GDPR)

### 2.1 Purposes of Processing

Describe the purposes in clear, concrete terms. At minimum, cover:

- **Legal time tracking under German labor law (ArbZG)**
  - Documentation of daily working time (start/end/breaks)
  - Verification of maximum working hours and mandatory breaks
  - Verification of rest periods
- **HR and payroll processes**
  - Calculation of working time, overtime, and absences
  - Provision of data for payroll and internal HR administration
- **Compliance and audit**
  - Demonstration of compliance with ArbZG during audits
  - Internal controls, audit logs, and investigations of irregularities
- **Optional / additional purposes (must have separate legal basis if required)**
  - Project/cost center allocation for cost accounting
  - Aggregated statistics for workforce planning

> **Important:** Explicitly state that the system **must not** be used for covert performance or behavior monitoring without a separate legal basis and works council agreement.

### 2.2 Description of Processing Operations and Workflows

Describe, for each main workflow, how personal data is processed:

1. **Daily time recording**
   - Clock-in / clock-out via Nextcloud app
   - Manual corrections with justification and approval workflow
   - Absence recording (vacation, sick leave, special leave)

2. **Manager & HR workflows**
   - Approval of absence requests
   - Approval of time corrections
   - Review of compliance violations
   - Generation of reports (e.g. overtime, absences, violations)

3. **System & compliance workflows**
   - Automatic compliance checks (breaks, max hours, rest periods)
   - Automatic notifications to employees and HR
   - Audit logging of access and changes
   - Data retention and deletion after expiry of retention period

### 2.3 Categories of Data Subjects

- **Employees** (full-time, part-time, trainees, interns, working students)
- **Temporary workers / freelancers (if included in the system)**
- **Managers / supervisors**
- **HR staff and system administrators** (for logging and access control)

### 2.4 Categories of Personal Data

Minimum data set (data minimization principle):

- Identification data:
  - User ID (Nextcloud account ID)
  - Name / display name
  - Organizational unit / department
  - Manager assignment
- Time tracking data:
  - Workday date
  - Start time, end time
  - Break start, break end
  - Calculated working time
  - Status (active, completed, correction requested, approved, rejected)
  - Justification texts for manual entries / corrections
- Absence data:
  - Absence type (vacation, sick leave, other leave types)
  - Start and end date
  - Number of days
  - Status (requested, approved, rejected)
  - Justification / comments
- Compliance data:
  - Recorded violations (e.g. missing break, exceeded max hours, insufficient rest)
  - Violation date and type
  - Severity (info, warning, error)
  - Resolution status, justification
- Metadata and audit data:
  - Timestamps of data changes
  - User account performing an action
  - IP address (if logged)
  - User agent (if logged)

Optional / configurable additional data (must be justified separately):

- Project / cost center assignment
- Aggregated reporting fields (department, cost center)
- Any special categories of personal data **must not** be processed (Art. 9 GDPR) unless strictly necessary and with explicit legal basis and safeguards.

### 2.5 Recipients and Access Roles

Identify roles and typical recipients:

- **Employees (data subjects)**
  - Access to their own time records and absences
  - Access to their own compliance violations
- **Managers / supervisors**
  - Access to time and absence data of team members
  - Access to compliance status of team members
- **HR / payroll**
  - Access to all employee time and absence data as required
  - Access to reports and exports (e.g. DATEV)
- **System administrators**
  - Technical access for maintenance and support
  - No use for HR or disciplinary purposes
- **External recipients** (if any)
  - Payroll provider (e.g. via DATEV export)
  - External auditors (limited, documented access)

For each recipient category, specify:

- Role / function
- Legal basis for access
- Scope and purpose of access

### 2.6 Data Flows and Storage Locations

Document:

- System architecture (on-premise Nextcloud instance, hosting location)
- Databases (type, location, backup strategy)
- Interfaces (e.g. payroll export, ProjectCheck integration)
- Network flows (e.g. HTTPS access from employee devices)

Optionally, attach a data flow diagram and reference it here.

---

## 3. Assessment of Necessity and Proportionality (Art. 35(7)(b), Art. 5 GDPR)

### 3.1 Legal Basis (Art. 6 GDPR)

- Primary legal basis:
  - **Art. 6(1)(c) GDPR – Legal obligation** (compliance with ArbZG and related national regulations)
- Additional bases where applicable:
  - Art. 6(1)(b) GDPR – performance of employment contract
  - Art. 6(1)(f) GDPR – legitimate interest (e.g. defense of legal claims), if used, must be balanced and documented

Explicitly state:

- That processing is required for meeting the employer’s legal obligations under ArbZG
- That consent **is not** the primary legal basis for mandatory time tracking

### 3.2 Data Minimization and Purpose Limitation

Explain:

- Which fields are strictly necessary for ArbZG-compliant time tracking
- Which optional fields are disabled by default
- That no detailed surveillance functionality is implemented (no keystroke logging, screen recording, GPS tracking by default, etc.)
- How optional features (e.g. project tracking, GPS) are:
  - Clearly separated from compliance-relevant data
  - Disabled by default
  - Enabled only with separate legal assessment and, where required, consent and works council agreement

### 3.3 Storage Limitation and Retention Periods

Define retention and deletion concepts:

- Time records:
  - Minimum: 2 years retention (ArbZG-related)
  - Organization-specific upper limits (e.g. 3–10 years including limitation periods)
- Absence and vacation data:
  - Retention aligned with HR and payroll documentation requirements
- Audit logs:
  - Retention period (e.g. 2–3 years) with clear justification

Describe:

- Automatic deletion or anonymization processes
- How GDPR Art. 17 and national retention obligations are reconciled

### 3.4 Transparency and Information Obligations

Describe:

- Internal privacy notices for employees (Art. 13 GDPR)
- How information is provided:
  - Intranet / HR portal
  - Employee handbook
  - Within ArbeitszeitCheck (help pages, links)
- Which content is covered:
  - Purposes of processing
  - Legal bases
  - Retention periods
  - Recipients
  - Rights of data subjects
  - DPO contact data

### 3.5 Data Subject Rights Handling

Explain processes for:

- **Right of access (Art. 15)** – self-service export and HR processes
- **Right to rectification (Art. 16)** – time correction workflow
- **Right to erasure (Art. 17)** – respecting legal retention limits
- **Right to data portability (Art. 20)** – machine-readable exports (CSV/JSON)
- **Right to restriction and objection (Art. 18, 21)** – handling within legal framework (note legal obligation limits objection rights)

Describe how requests are documented and processed within defined SLAs.

---

## 4. Risk Assessment for Rights and Freedoms (Art. 35(7)(c) GDPR)

### 4.1 Methodology

Describe:

- Risk assessment method (e.g. qualitative: low/medium/high; impact vs. likelihood)
- Criteria for impact (e.g. discrimination risk, job security, financial impact)
- Criteria for likelihood (e.g. technical vulnerabilities, access rights, processes)

### 4.2 Identification of Risks

Consider at least the following risk categories:

1. **Risk of unlawful or excessive monitoring of employees**
   - Overly granular tracking (e.g. per-minute analysis, behavior profiling)
   - Use of reports for covert performance evaluation
2. **Risk of unauthorized access or data leakage**
   - Misconfigured access roles
   - Weak authentication or missing 2FA
3. **Risk of incorrect or incomplete data**
   - Incorrect time entries leading to incorrect salary or disciplinary actions
4. **Risk from insufficient transparency**
   - Employees not understanding what data is collected and why
5. **Risk from insufficient deletion**
   - Data retained longer than necessary
6. **Risk from technical vulnerabilities**
   - Unpatched system, missing encryption, insecure interfaces

For each risk, evaluate:

- **Description**
- **Potential impact on data subjects**
- **Likelihood (before controls)**
- **Impact level (before controls)**
- **Initial risk rating**

### 4.3 Special Considerations for Works Council and Co-Determination

If a works council exists:

- Document whether co-determination under §87 BetrVG is triggered
- Describe:
  - Works council involvement
  - Agreements on acceptable use
  - Rules preventing misuse for surveillance and unjustified performance control

---

## 5. Measures to Address Risks (Art. 35(7)(d), Art. 32 GDPR)

For each identified risk, specify **concrete** technical and organizational measures (TOMs).

### 5.1 Technical Measures

Examples (adapt as needed):

- Encrypted transport (HTTPS/TLS 1.2+)
- Encrypted storage of sensitive fields (e.g. database-level encryption)
- Strong authentication and 2FA via Nextcloud
- Role-based access control (RBAC) with least privilege principle
- Audit logging of access and changes
- Hardening of Nextcloud server (updates, security configuration)
- Regular backups and tested restore procedures
- Segregation of environments (production, test, development)

### 5.2 Organizational Measures

Examples (adapt as needed):

- Binding internal policies on time tracking and data protection
- Access control concepts and approval workflows for granting rights
- Regular employee training (data protection, acceptable use)
- Work instructions for HR and managers on appropriate use of reports
- Clear separation between HR, IT, and line management responsibilities
- Incident response process for data breaches

### 5.3 Residual Risks

For each major risk, document:

- Initial risk rating
- Implemented measures
- Residual risk rating
- Assessment:
  - ☐ Acceptable  
  - ☐ Requires additional measures  
  - ☐ Not acceptable – project must be adapted before go-live

If residual high risk remains, document whether supervisory authority consultation (Art. 36 GDPR) is required.

---

## 6. Involvement of Data Protection Officer and Stakeholders

### 6.1 DPO Review

- DPO has been involved:
  - ☐ Yes
  - ☐ No (justify)
- Date of review:
- DPO recommendations:
- Implemented recommendations:
- Open issues / deviations and justifications:

### 6.2 Works Council Involvement (if applicable)

- Works council involved:
  - ☐ Yes
  - ☐ No (justify)
- Type of involvement:
  - ☐ Consultation
  - ☐ Co-determination / works agreement
- Reference to works agreement / documentation:

### 6.3 Management Decision

- Decision:
  - ☐ Approved
  - ☐ Approved with conditions
  - ☐ Rejected / to be revised
- Date:
- Decision-maker(s):

---

## 7. Summary and Documentation

Provide a concise summary:

- Purpose and scope of the time tracking system
- Main risks identified
- Key measures implemented
- Residual risk assessment
- Final decision (go-live, conditions, follow-up actions)

Attach:

- Technical architecture diagrams (if available)
- Access control matrix
- Works council agreements (if applicable)
- Policies and user guidelines (if available)

