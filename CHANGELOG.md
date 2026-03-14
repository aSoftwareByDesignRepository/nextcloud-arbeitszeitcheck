# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.3 – 2025-03-14

### Fixed

- **ArbZG compliance**: Corrected break check logic (9h/45min branch now reachable; check ≥9h before ≥6h)
- **Manager logic**: `employeeHasManager()` now uses `getManagerIdsForEmployee()` instead of `getColleagueIds()`
- **Reporting**: `getTeamHoursSummary()` respects period parameter (week/month)
- **Admin users**: `hasTimeEntriesToday` is now per-user, not system-wide
- **UserSettingsMapper**: Fixed falsy zero/empty-string handling in getIntegerSetting, getFloatSetting, getStringSetting
- **Routing**: Moved exportUsers route above getUser to fix route shadowing
- **Version1009 migration**: Replaced MySQL backtick SQL with portable QueryBuilder; use OCP\DB\Types
- **Duplicate notifier**: Removed double registration from Application.php boot()
- **API security**: Generic error messages instead of raw exception output (SubstituteController, GdprController)
- **PDF export**: Returns HTTP 422 with clear message instead of silent CSV fallback
- **LIKE injection**: WorkingTimeModelMapper::searchByName() uses escapeLikeParameter()
- **XSS**: Modal titles escaped in components.js; compliance-violations.js innerHTML escaped
- **Admin-settings form**: Added CSRF requesttoken
- **AbsenceService DI**: Fixed constructor argument order (IDBConnection)
- Admin holidays and settings: English source strings for l10n keys
- UserDeletedListener: inject TeamMemberMapper and TeamManagerMapper
- XSS: sanitise team names in admin-teams.js

### Changed

- **CSS**: Shadow-light variable, scoped resets, dark-mode color-mix fixes, semantic color variables, navigation height/z-index
- **Clock buttons**: Double-submit guard (disabled during API calls)
- **initTimeline()**: Max retry count (20) to prevent infinite loop
- **Accessibility**: aria-label on header buttons, label for admin user search, aria-modal on welcome dialog, English l10n keys in navigation
- **Docs**: Removed internal docs; added docs/README; corrected repo URLs
- **Manager dashboard**: Injected l10n from PHP so JS translations work
- Constants.php for magic numbers; user-facing error messages

### Added

- **Version1010 migration**: Compound indices on at_entries, at_violations, at_holidays, at_absences

## 1.1.2 – 2025-03-07

### Changed

- **Long-term refactor**: Replaced all `\OC::$server` usage with proper OCP APIs and constructor injection
- CSPService: Injected ContentSecurityPolicyNonceManager via constructor
- Controllers: Removed manual cspNonce (configureCSP handles it); injected IURLGenerator, IConfig where needed
- PageController: Injected IURLGenerator, IConfig; passes urlGenerator to templates
- HealthController: Injected IDBConnection for database check
- ProjectCheckIntegrationService: Injected LoggerInterface instead of OC::$server->getLogger()
- Templates: Replaced `\OC::$server` with `\OCP\Server::get()` (OCP public API)
- Added GitHub Actions release workflow (`.github/workflows/release.yml`)
- Updated PageControllerTest with full constructor mocks

## 1.1.1 – 2025-01-07

### Fixed

- Resolved duplicate route names in absence API (absence#store, absence#show, absence#update, absence#delete)
- Corrected settings class names in info.xml to use full OCA namespace
- Added declare(strict_types=1) to routes.php

### Changed

- Removed non-existent screenshot references from info.xml until real screenshots are captured

## 1.1.0 – 2025-01-04

### Added

- ProjectCheck integration for project time tracking
- Additional migrations for schema updates

## 1.0.3 – 2025-01-03

### Added

- Further database schema refinements

## 1.0.2 – 2025-01-02

### Added

- Working time models
- User working time model assignments

## 1.0.1 – 2025-01-01

### Added

- Absence management
- Audit logging
- User settings
- Compliance violation tracking

## 1.0.0 – 2024-12-29

### Added

- Initial release
- German labor law (ArbZG) compliant time tracking
- Clock in/out and break tracking
- Time entry management (create, edit, delete, manual entries)
- Basic compliance checks (max 8h/day, break requirements)
- GDPR-compliant data processing
- English and German translations
- WCAG 2.1 AAA accessibility compliance
