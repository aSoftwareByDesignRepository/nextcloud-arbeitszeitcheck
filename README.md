# ArbeitszeitCheck / TimeGuard

**Legally compliant time tracking system for German labor law (ArbZG) and GDPR**

**ArbeitszeitCheck** (German) / **TimeGuard** (English) is a comprehensive, legally compliant time tracking system specifically designed for German organizations. It fully implements German labor law (Arbeitszeitgesetz - ArbZG) requirements including mandatory break times, maximum working hours, rest periods, and Sunday/holiday work tracking.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-27%2B-green.svg)](https://nextcloud.com/)

## Features

### 🏛️ **German Labor Law Compliance (ArbZG)**
- ✅ **Working time limits**: 8 hours/day (10 max), 48 hours/week average
- ✅ **Rest periods**: Minimum 11 hours between shifts
- ✅ **Break requirements**: 30 minutes after 6 hours, 45 minutes after 9 hours
- ✅ **Night work tracking**: Automatic detection (11 PM - 6 AM)
- ✅ **Sunday & holiday work**: Detection and justification requirements
- ✅ **Real-time compliance monitoring** with automatic violation alerts
- ✅ **Audit trails** for regulatory compliance

### 🔒 **GDPR & Data Protection**
- ✅ **Data minimization**: Only collects legally required data
- ✅ **Employee rights**: Full access, rectification, and deletion rights
- ✅ **Purpose limitation**: Data used only for labor law compliance
- ✅ **Data retention**: Automatic deletion after 2 years
- ✅ **Audit logging**: Complete trail of all data operations

### 👥 **Employee Portal**
- ✅ **One-click clock in/out** with real-time timer
- ✅ **Break time management** with automatic validation
- ✅ **Personal dashboard** with working hours overview
- ✅ **Absence management**: Vacation, sick leave, and other absences
- ✅ **Time entry corrections** with approval workflows
- ✅ **Export personal data** (PDF, CSV, JSON formats)

### 👔 **Manager Dashboard**
- ✅ **Team overview** with real-time status monitoring
- ✅ **Approval workflows** for absences and time corrections
- ✅ **Compliance monitoring** across the team
- ✅ **Working hours reports** and analytics
- ✅ **Absence calendar** and planning tools

### 🔗 **ProjectCheck Integration** (Optional)
- ✅ **Seamless project association** when both apps are installed
- ✅ **Automatic project data sync** between applications
- ✅ **Cost center tracking** and budget monitoring
- ✅ **Unified project time reporting** across both systems

### ♿ **Accessibility (WCAG 2.1 AAA)**
- ✅ **Full keyboard navigation** support
- ✅ **Screen reader compatibility** with proper ARIA labels
- ✅ **High contrast support** and color-blind friendly design
- ✅ **Responsive design** for mobile and tablet devices
- ✅ **Focus management** and skip links

## Requirements

- **Nextcloud**: 27.0 or later
- **PHP**: 8.1 or later
- **Database**: MySQL/MariaDB, PostgreSQL, or SQLite
- **Optional**: ProjectCheck app for enhanced project management integration

## Installation

### From Nextcloud App Store (Recommended)

1. Go to **Settings** → **Apps** in your Nextcloud instance
2. Search for "ArbeitszeitCheck"
3. Click **Install** and enable the app
4. Configure settings as administrator

### Manual Installation

```bash
# Download the app
cd /path/to/your/nextcloud/apps/
git clone https://github.com/nextcloud/arbeitszeitcheck.git arbeitszeitcheck

# Install dependencies
cd arbeitszeitcheck
npm install
npm run build

# Enable the app
php occ app:enable arbeitszeitcheck
```

### Docker Installation

For Docker environments (using `docker-compose`), you need to build the frontend assets:

**Option 1: Use automated build script (recommended)**
```bash
cd apps/arbeitszeitcheck
./build-docker.sh
```

**Option 2: Build manually with docker-compose**
```bash
# Install dependencies and build
docker-compose exec nextcloud bash -c "cd /var/www/html/apps/arbeitszeitcheck && npm install && npm run build:dev"

# Clear cache
docker-compose exec nextcloud php occ files:scan --all
```

**Option 3: Build inside container**
```bash
# Enter container
docker-compose exec nextcloud bash

# Build
cd /var/www/html/apps/arbeitszeitcheck
npm install
npm run build:dev
php occ files:scan --all
exit
```

**Option 4: Build on host (if volumes are mounted)**
```bash
cd /path/to/nextcloud/apps/arbeitszeitcheck
npm install
npm run build:dev
```

**Troubleshooting:**
- If the app shows nothing: Frontend not built - run `npm run build`
- If app icon is missing: Check `app.svg` exists and clear browser cache
- If CSS/JS not loading: Clear Nextcloud cache with `php occ files:scan --all`

## Configuration

### Administrator Settings

Access **Settings** → **Administration** → **ArbeitszeitCheck** to configure:

- **Working Time Models**: Define different working time arrangements
- **Compliance Settings**: Configure German state for holiday calculations
- **Data Retention**: Set automatic data cleanup policies
- **Notification Settings**: Configure email and system notifications

### User Settings

Users can access their personal settings at **Settings** → **Personal** → **ArbeitszeitCheck** to:

- Set vacation entitlement and working hours
- Configure notification preferences
- Export personal time tracking data

## Usage

### For Employees

1. **Clock In/Out**: Use the one-click buttons in the main dashboard
2. **Manage Breaks**: Start and end breaks as required by law
3. **View Time Entries**: Review and correct your time entries
4. **Request Absences**: Submit vacation and other absence requests
5. **Export Data**: Download your time tracking data for records

### For Managers

1. **Monitor Team**: View real-time status of team members
2. **Approve Requests**: Review and approve absence requests
3. **Compliance Monitoring**: Track labor law compliance across the team
4. **Generate Reports**: Create working hours and compliance reports

## API Documentation

The app provides a RESTful API for integration with external systems:

### Time Tracking Endpoints

```http
POST /apps/arbeitszeitcheck/api/clock/in
POST /apps/arbeitszeitcheck/api/clock/out
GET /apps/arbeitszeitcheck/api/clock/status
POST /apps/arbeitszeitcheck/api/break/start
POST /apps/arbeitszeitcheck/api/break/end
```

### Time Entries

```http
GET /apps/arbeitszeitcheck/api/time-entries
POST /apps/arbeitszeitcheck/api/time-entries
GET /apps/arbeitszeitcheck/api/time-entries/{id}
PUT /apps/arbeitszeitcheck/api/time-entries/{id}
DELETE /apps/arbeitszeitcheck/api/time-entries/{id}
```

### Absences

```http
GET /apps/arbeitszeitcheck/api/absences
POST /apps/arbeitszeitcheck/api/absences
GET /apps/arbeitszeitcheck/api/absences/{id}
PUT /apps/arbeitszeitcheck/api/absences/{id}
DELETE /apps/arbeitszeitcheck/api/absences/{id}
POST /apps/arbeitszeitcheck/api/absences/{id}/approve
POST /apps/arbeitszeitcheck/api/absences/{id}/reject
```

## Security & Compliance

### Data Protection

- All data is encrypted at rest and in transit
- Automatic data deletion after retention period
- Comprehensive audit logging of all operations
- Role-based access control

### Labor Law Compliance

The app automatically enforces:
- Maximum daily working hours (10 hours)
- Mandatory break times
- Minimum rest periods between shifts
- Night work regulations
- Sunday and holiday work restrictions

### GDPR Compliance

- **Right to access**: Employees can export their complete data
- **Right to rectification**: Time entry correction workflows
- **Right to erasure**: Data deletion functionality
- **Data portability**: Export in machine-readable formats

## Integration with ProjectCheck

When both ArbeitszeitCheck and ProjectCheck apps are installed:

- **Unified Project Selection**: Projects from ProjectCheck appear in time tracking
- **Automatic Cost Calculation**: Hourly rates from ProjectCheck are used
- **Budget Monitoring**: Time tracking affects project budgets
- **Seamless Data Flow**: Time entries sync between both applications

## Development

### Building from Source

```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Build frontend assets
npm run build

# Run tests
composer test
npm test
```

### Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Submit a pull request

### Testing

```bash
# Run PHP tests
composer test

# Run JavaScript tests
npm test

# Run accessibility tests
npm run test:a11y
```

## Support & Documentation

- **User Manual**: Comprehensive documentation for employees and managers
- **Administrator Guide**: Setup and configuration instructions
- **API Documentation**: Complete API reference
- **GDPR Guide**: Data protection compliance information

## License

This project is licensed under the AGPL-3.0 License - see the [LICENSE](LICENSE) file for details.

## Credits

Developed by Alexander Mähl for Nextcloud GmbH.

Special thanks to the German labor law experts who provided compliance guidance and the Nextcloud community for their valuable feedback.

## Disclaimer

This software is designed to assist with labor law compliance, but organizations remain responsible for ensuring their own legal compliance. Always consult with legal experts for your specific situation.

---

**ArbeitszeitCheck** - Making time tracking legally compliant and user-friendly.