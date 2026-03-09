# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.2] - 2025-03-07

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

## [1.1.1] - 2025-01-07

### Fixed

- Resolved duplicate route names in absence API (absence#store, absence#show, absence#update, absence#delete)
- Corrected settings class names in info.xml to use full OCA namespace
- Added declare(strict_types=1) to routes.php

### Changed

- Removed non-existent screenshot references from info.xml until real screenshots are captured

## [1.1.0] - 2025-01-04

### Added

- ProjectCheck integration for project time tracking
- Additional migrations for schema updates

## [1.0.3] - 2025-01-03

### Added

- Further database schema refinements

## [1.0.2] - 2025-01-02

### Added

- Working time models
- User working time model assignments

## [1.0.1] - 2025-01-01

### Added

- Absence management
- Audit logging
- User settings
- Compliance violation tracking

## [1.0.0] - 2024-12-29

### Added

- Initial release
- German labor law (ArbZG) compliant time tracking
- Clock in/out and break tracking
- Time entry management (create, edit, delete, manual entries)
- Basic compliance checks (max 8h/day, break requirements)
- GDPR-compliant data processing
- English and German translations
- WCAG 2.1 AAA accessibility compliance
