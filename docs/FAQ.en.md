# Frequently Asked Questions (FAQ) – ArbeitszeitCheck

**Last Updated:** 2025-12-29

## General Questions

### What is ArbeitszeitCheck?

**ArbeitszeitCheck** (also known as **TimeGuard** in English) is a legally compliant time tracking system designed specifically for German organizations. It helps companies meet German labor law (Arbeitszeitgesetz - ArbZG) requirements while ensuring GDPR/DSGVO compliance.

### Who should use this app?

- **German companies** that need to comply with ArbZG (mandatory time recording)
- **Organizations** that want GDPR-compliant time tracking
- **HR departments** managing employee working hours
- **Employees** who need to track their working time

### Is this app free?

Yes, ArbeitszeitCheck is **open-source** and licensed under AGPL-3.0. You can use it freely, but you must comply with the license terms (if you modify it, you must share your modifications).

### What versions of Nextcloud are supported?

ArbeitszeitCheck supports Nextcloud **27, 28, and 29** (and future LTS versions). The app requires PHP 8.1 or later.

---

## Installation & Setup

### How do I install ArbeitszeitCheck?

**From App Store (Recommended):**
1. Go to **Settings** → **Apps** in your Nextcloud
2. Search for "ArbeitszeitCheck"
3. Click **Install** and enable

**Manual Installation:**
```bash
cd /path/to/nextcloud/apps/
git clone https://github.com/nextcloud/arbeitszeitcheck.git
cd arbeitszeitcheck
npm install && npm run build
php occ app:enable arbeitszeitcheck
```

### Do I need to configure anything after installation?

Yes, administrators should:
1. Configure **global settings** (German state, working hours limits)
2. Set up **working time models** for different employee groups
3. Assign **working time models** to users
4. Configure **vacation entitlements** per user
5. Set up **manager assignments** for approval workflows

See the [Administrator Guide](Administrator-Guide.en.md) for detailed instructions.

### Can I use this in Docker?

Yes, ArbeitszeitCheck is fully compatible with Docker. The app includes a build script (`build.sh`) for Docker environments.

---

## Time Tracking

### How do I clock in/out?

1. Go to the **ArbeitszeitCheck** app
2. Click the **"Clock In"** button to start tracking
3. Click **"Clock Out"** when finished
4. The system automatically records your start time, end time, and calculates your working hours

### What if I forget to clock in or out?

You can create a **manual time entry**:
1. Go to **"Time Entries"** → **"Add Manual Entry"**
2. Enter the date and hours worked
3. Provide a **justification** (mandatory for audit purposes)
4. Save the entry

**Note:** Manual entries can be reviewed by your manager.

### Can I edit my time entries?

- **Manual entries**: Yes, you can edit or delete them
- **Automatic entries** (clock-in/out): No, these are tamper-proof for legal compliance
- **Completed entries**: You can request a correction (requires manager approval)

### How do breaks work?

- Click **"Start Break"** while clocked in
- The timer pauses automatically
- Click **"End Break"** to resume
- The system tracks your total break time

**Legal Requirements:**
- After **6 hours** of work: Minimum **30 minutes** break required
- After **9 hours** of work: Minimum **45 minutes** break required

---

## Compliance & Legal

### What legal requirements does this app enforce?

ArbeitszeitCheck enforces:
- **Maximum daily hours**: 8 hours (extendable to 10 hours)
- **Maximum weekly hours**: 48 hours average (over 6 months)
- **Rest periods**: Minimum 11 hours between shifts
- **Break requirements**: 30 min after 6 hours, 45 min after 9 hours
- **Night work tracking**: Work between 11 PM and 6 AM
- **Sunday/holiday work**: Detection and documentation

### What happens if I violate compliance rules?

The system will:
1. **Detect the violation** automatically
2. **Alert you** via notification
3. **Alert HR** for review
4. **Record the violation** in the compliance log
5. **Prevent certain actions** (e.g., clock-in if rest period not met)

Violations can be resolved with proper documentation.

### Is my data GDPR compliant?

Yes, ArbeitszeitCheck is designed for GDPR/DSGVO compliance:
- **Data minimization**: Only collects legally required data
- **Purpose limitation**: Data used only for labor law compliance
- **Employee rights**: Full access, rectification, and deletion rights
- **Data retention**: Automatic deletion after 2 years (minimum)
- **Audit trails**: Complete logging of all operations

### Can I export my personal data?

Yes, you have the right to export all your data (GDPR Art. 15):
1. Go to **"Settings"** → **"Personal"** → **"ArbeitszeitCheck"**
2. Click **"Export Personal Data"**
3. Download your complete data in JSON format

### Can I delete my data?

Yes, but with limitations:
- Data older than **2 years** can be deleted (GDPR Art. 17)
- Recent data must be retained for **labor law compliance** (ArbZG requirement)
- Audit logs are retained for legal compliance

---

## Absence Management

### How do I request vacation?

1. Go to **"Absences"** → **"Request Absence"**
2. Select **"Vacation"** as the type
3. Enter start and end dates
4. Submit the request
5. Your manager will be notified and can approve/reject

### Can I cancel a vacation request?

Yes, if the request is still **pending approval**:
1. Find the request in your absences list
2. Click **"Delete"**
3. Confirm cancellation

**Note:** You cannot cancel approved requests (contact your manager).

### How are vacation days calculated?

- The system calculates **working days** automatically (excludes weekends and holidays)
- Your **vacation entitlement** is set by your administrator
- Used days are tracked automatically
- Remaining days are displayed on your dashboard

### What types of absences can I request?

- **Vacation**: Regular vacation days
- **Sick Leave**: Illness-related absences
- **Special Leave**: Special circumstances
- **Unpaid Leave**: Leave without pay

---

## Manager Features

### What can managers do?

Managers can:
- **View team overview**: See who's clocked in, hours worked
- **Approve/reject absences**: Review absence requests
- **Approve/reject time corrections**: Review correction requests
- **Monitor compliance**: View team compliance status
- **Generate reports**: Create team reports

### How do I become a manager?

Managers are assigned by administrators. Contact your HR department or system administrator.

### Can I see my team's time entries?

Yes, managers can view:
- Team members' time entries (with appropriate permissions)
- Team working hours summaries
- Team compliance violations
- Team absence calendar

---

## Technical Questions

### Does this work offline?

No, ArbeitszeitCheck requires an active Nextcloud connection. However, the web interface is responsive and works well on mobile devices.

### Can I use this on my phone?

Yes, the app is **fully responsive** and works on:
- Mobile browsers (iOS Safari, Android Chrome)
- Tablet browsers
- Desktop browsers

**Note:** A native mobile app is not available, but the web interface is optimized for mobile use.

### Does this integrate with other Nextcloud apps?

Yes, ArbeitszeitCheck integrates with:
- **ProjectCheck** (optional): Share project data and time tracking
- **Nextcloud Calendar**: Sync absences to calendar
- **Nextcloud Files**: Store exported reports

### Can I use the API?

Yes, ArbeitszeitCheck provides a **RESTful API** for integration. See the [API Documentation](API-Documentation.en.md) for details.

### What databases are supported?

- **MySQL/MariaDB** (recommended)
- **PostgreSQL**
- **SQLite** (for small installations)

---

## Troubleshooting

### I can't clock in. What's wrong?

**Possible reasons:**
- You already have an active time entry → Clock out first
- Less than 11 hours since your last shift → Wait until rest period is met
- System error → Contact IT support

### The system says I'm missing a break, but I took one.

**Possible reasons:**
- Break was shorter than required (30 min after 6 hours, 45 min after 9 hours)
- Break wasn't recorded properly → Check your time entry details
- System calculation error → Contact support

**Note:** You cannot retroactively add breaks to past entries, but the violation serves as documentation.

### My time entry is wrong. How do I fix it?

**If it's a manual entry:**
- Edit it directly from the time entries list

**If it's an automatic entry:**
- Request a correction (requires manager approval)
- Go to the entry → **"Request Correction"** → Fill in justification and corrected data

### I can't see my manager's approval.

**Check:**
- Your **notifications** (bell icon)
- The **status** in your absences/time entries list
- Contact your manager if status is unclear

### The app is slow or not loading.

**Try:**
1. Refresh the page (Ctrl+F5 or Cmd+Shift+R)
2. Clear browser cache
3. Check Nextcloud server status
4. Contact IT support if problem persists

---

## Privacy & Security

### Who can see my time data?

- **You**: Full access to your own data
- **Your manager**: Can see your time entries and absences (for approval)
- **HR/Administrators**: Can see all data (for compliance and reporting)
- **No one else**: Data is protected by Nextcloud's permission system

### Is my data encrypted?

Yes:
- **In transit**: HTTPS/TLS encryption
- **At rest**: Database encryption (if configured in Nextcloud)
- **Access control**: Role-based permissions

### Can I opt out of time tracking?

**No**, if your organization requires time tracking for legal compliance (ArbZG), you cannot opt out. However, you have full transparency and control over your data.

### What data is collected?

**Only legally required data:**
- Start time, end time, break times
- Working duration
- Absence requests
- Compliance violations (for legal documentation)

**NOT collected:**
- Detailed location data (unless explicitly enabled with consent)
- Activity monitoring or screenshots
- Performance evaluation data

---

## Integration with ProjectCheck

### What is ProjectCheck integration?

When both **ArbeitszeitCheck** and **ProjectCheck** apps are installed, they integrate seamlessly:
- Projects from ProjectCheck appear in time tracking
- Time entries can be assigned to projects
- Project budgets are updated automatically
- Unified project reporting

### Do I need ProjectCheck to use ArbeitszeitCheck?

**No**, ProjectCheck is **optional**. ArbeitszeitCheck works perfectly without it. The integration is only active if both apps are installed.

### How do I enable ProjectCheck integration?

1. Install both apps (ArbeitszeitCheck and ProjectCheck)
2. The integration is **automatic** - no configuration needed
3. Projects will appear in time entry forms

---

## Support & Help

### Where can I get help?

- **User Manual**: See `docs/User-Manual.en.md`
- **Administrator Guide**: See `docs/Administrator-Guide.en.md`
- **API Documentation**: See `docs/API-Documentation.en.md`
- **GitHub Issues**: https://github.com/nextcloud/arbeitszeitcheck/issues
- **IT Support**: Contact your organization's IT department

### How do I report a bug?

1. Check if it's a known issue: https://github.com/nextcloud/arbeitszeitcheck/issues
2. Create a new issue with:
   - What you were trying to do
   - What happened instead
   - Error messages (if any)
   - Browser and version
   - Nextcloud version

### Can I contribute to the project?

Yes! ArbeitszeitCheck is open-source. See `CONTRIBUTING.md` for guidelines.

---

## Legal & Compliance

### Is this app legally compliant?

ArbeitszeitCheck is designed to help organizations comply with:
- **German labor law (ArbZG)**: Enforces all mandatory requirements
- **GDPR/DSGVO**: Implements all data protection requirements

**However**, organizations remain responsible for their own legal compliance. Always consult with legal experts for your specific situation.

### Do I need a works council agreement?

**Possibly**, if your organization has a works council (Betriebsrat). The app provides a [Works Council Agreement Template](Works-Council-Agreement-Template.de.md) to help.

### What about data protection impact assessment (DPIA)?

Organizations should conduct a DPIA. The app provides a [DPIA Template](DPIA-Template.en.md) to guide the process.

---

## Miscellaneous

### Can I customize the app?

**Limited customization:**
- Administrators can configure working time models, limits, and settings
- The app follows Nextcloud's design system (consistent with other apps)
- Custom themes are supported via Nextcloud's theming system

**No customization:**
- Core compliance rules (cannot be disabled)
- Data retention requirements (legal requirement)
- GDPR rights (legal requirement)

### Can I use this for performance evaluation?

**No**, ArbeitszeitCheck is designed for **legal compliance only**, not performance evaluation. Using time data for performance evaluation requires separate legal basis and works council agreement.

### Does this track my location?

**No**, by default, ArbeitszeitCheck does **not** track location. Optional GPS tracking can be enabled with explicit consent and clear documentation, but it's not required for legal compliance.

---

**Still have questions?** Check the [User Manual](User-Manual.en.md) or [Administrator Guide](Administrator-Guide.en.md), or open an issue on GitHub.
