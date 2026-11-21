ArbeitszeitCheck (WorkTimeCheck)

[![Nextcloud](https://img.shields.io/badge/Nextcloud-32–35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com/)
[![PHP](https://img.shields.io/badge/PHP-8.1–8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](LICENSE)

**[English](#english)** · **[Deutsch](#deutsch)**

Clock in. Stay within the rules you set — on your Nextcloud. Search tip: **WorkTimeCheck**.

---

## English

**Clock in. Stay within the rules you set.**

ArbeitszeitCheck records clock-in, clock-out and breaks on the Nextcloud you already host. Pick Germany (ArbZG), Austria (AZG/ARG) or Switzerland (ArG), match holiday regions, approve absences, run reports (including DATEV) and keep an audit log.

**Free web app** (AGPL-3.0-or-later). Companion apps: https://nextcloud.software-by-design.de/

### Why teams install it

- Clock-in / out, pauses, resume, manual entries with a reason
- DE / AT / CH profiles with matching holiday regions
- Technical break and rest checks against your configured profile
- Absences with approval; entitlement modes; CSV import via `occ`
- Dashboards and reports (incl. DATEV); audit log; optional month finalization
- Optional allow-lists (Nextcloud admins keep access)

### Clear limits

This app is **not legal advice**. You stay responsible for working-time models, collective agreements, configuration and how recorded data is used. Technical violation reports are not a third-party certification.

**Calendar note:** ArbeitszeitCheck does **not** sync with the Nextcloud Calendar app (CalDAV). The in-app month view is separate; optional e-mails may attach `.ics` for manual import.

### Requirements

- Nextcloud 32–35 · PHP 8.1–8.5 · MySQL/MariaDB or PostgreSQL

### Install

**App Store (recommended):** Apps → search **ArbeitszeitCheck** or **WorkTimeCheck** → download and enable.

**From Git:**

```bash
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck.git /path/to/nextcloud/apps/arbeitszeitcheck
cd /path/to/nextcloud
php occ app:enable arbeitszeitcheck
```

### Documentation

- Changelog: [`CHANGELOG.md`](CHANGELOG.md) / [`CHANGELOG.de.md`](CHANGELOG.de.md)
- Docs index: [`docs/README.md`](docs/README.md) — user manuals, DACH rule notes, GDPR guide, developer overview

### Security & support

Report security issues privately to the maintainer (`appinfo/info.xml`).  
**Software by Design GbR** · [nextcloud.software-by-design.de](https://nextcloud.software-by-design.de/) · [info@software-by-design.de](mailto:info@software-by-design.de) · [Support packages](https://nextcloud.software-by-design.de/en/support.html#packages)

### License

[AGPL-3.0-or-later](LICENSE).

---

## Deutsch

**Einstempeln. Innerhalb der Regeln bleiben, die Sie setzen.**

ArbeitszeitCheck erfasst Kommen, Gehen und Pausen in der Nextcloud, die Sie schon betreiben. Länderprofil für Deutschland (ArbZG), Österreich (AZG/ARG) oder die Schweiz (ArG), Feiertagsregionen, Abwesenheiten mit Freigabe, Berichte (inkl. DATEV) und Audit-Log.

**Kostenlose Web-App** (AGPL-3.0-or-later). Companion-Apps: https://nextcloud.software-by-design.de/

### Warum Teams es einsetzen

- Kommen/Gehen, Pausen, Fortsetzen, manuelle Einträge mit Begründung
- Profile DE / AT / CH mit Feiertagsregionen
- Technische Pausen- und Ruheprüfungen am konfigurierten Profil
- Abwesenheiten mit Freigabe; Anspruch-Modi; CSV-Import per `occ`
- Dashboards und Berichte (inkl. DATEV); Audit-Log; optionale Monatsfinalisierung
- Optionale Freigabelisten (Nextcloud-Administratoren behalten den Zugriff)

### Klare Grenzen

Diese App ist **keine Rechtsberatung**. Sie bleiben verantwortlich für Arbeitszeitmodelle, Tarif- und Betriebsregeln, Konfiguration und die Auswertung erfasster Daten. Technische Verstoßmeldungen sind keine Fremdzertifizierung.

**Kalender:** Keine Synchronisation mit der Nextcloud-Kalender-App (CalDAV). Die Monatsansicht gehört zur App; optional können E-Mails mit `.ics` zum manuellen Import versendet werden.

### Voraussetzungen

- Nextcloud 32–35 · PHP 8.1–8.5 · MySQL/MariaDB oder PostgreSQL

### Installation

**App Store (empfohlen):** Apps → **ArbeitszeitCheck** oder **WorkTimeCheck** suchen → herunterladen und aktivieren.

**Aus Git:** siehe englischen Abschnitt.

### Dokumentation

- Changelog: [`CHANGELOG.md`](CHANGELOG.md) / [`CHANGELOG.de.md`](CHANGELOG.de.md)
- Docs: [`docs/README.md`](docs/README.md)

### Sicherheit & Support

Sicherheitsmeldungen privat an den Maintainer (`appinfo/info.xml`).  
**Software by Design GbR** · [Website](https://nextcloud.software-by-design.de/) · [info@software-by-design.de](mailto:info@software-by-design.de) · [Support-Pakete](https://nextcloud.software-by-design.de/de/support.html#packages)

### Lizenz

[AGPL-3.0-or-later](LICENSE).

# Release tarballs

App Store download URLs. Do not delete — store release records link here permanently.
