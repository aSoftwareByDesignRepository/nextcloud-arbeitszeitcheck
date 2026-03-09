## ArbeitszeitCheck (TimeGuard) – ArbZG- und DSGVO-konforme Zeiterfassung für Nextcloud

**ArbeitszeitCheck** ist eine Zeiterfassungs‑App für Nextcloud, die explizit auf **deutsches Arbeitszeitgesetz (ArbZG)** und **DSGVO/ GDPR** ausgerichtet ist.  
Die App läuft vollständig innerhalb Ihrer selbst gehosteten Nextcloud‑Instanz – keine externen Cloud‑Dienste, keine Telemetrie.

### Kernfunktionen

- **Rechtskonforme Zeiterfassung**: Kommen/Gehen, Pausen, manuelle Einträge mit Begründung
- **ArbZG‑Compliance**:
  - Max. tägliche Arbeitszeit (8h, erweiterbar auf 10h, reine Arbeitszeit ohne Pausen)
  - Wöchentliche 48‑Stunden‑Durchschnittsprüfung (6‑Monats‑Zeitraum, Manager‑Warnungen)
  - Automatische Pausenberechnung nach ArbZG §4 (30/45 Minuten, nur Pausen ≥ 15 Minuten)
  - Ruhezeiten (11h) mit Blockierung von Clock‑In und manuellen Einträgen
  - Erkennung von Nacht‑, Sonn‑ und Feiertagsarbeit inkl. Dokumentation
- **Abwesenheitsmanagement**: Urlaub, Krankheit, Sonderurlaub, unbezahlter Urlaub mit Genehmigungsworkflow
- **Team‑ und Manager‑Ansicht**: Genehmigungen, Team‑Übersichten, Compliance‑Status
- **Berichte & Exporte**: Tages/Wochen/Monats‑Reports, Overtime‑Reports, Absenzberichte, DATEV‑Export
- **Audit‑Logs**: Lückenlose Nachvollziehbarkeit von Änderungen an Zeiten, Abwesenheiten und Einstellungen
- **DSGVO‑Support**: Exporte, Löschkonzepte (unter Beachtung der gesetzlichen Aufbewahrung), DPIA‑/Verarbeitungsverzeichnis‑Vorlagen

### Installation

**Aus dem Nextcloud App Store (empfohlen)**

1. Als Nextcloud‑Administrator anmelden  
2. **„Apps“ → „Organisation“ / „Produktivität“** öffnen  
3. Nach **„ArbeitszeitCheck“** oder **„TimeGuard“** suchen  
4. App **herunterladen und aktivieren**  

**Manuelle Installation aus Git**

```bash
git clone https://github.com/aSoftwareByDesignRepository/ArbeitszeitCheck.git /path/to/nextcloud/apps/arbeitszeitcheck
cd /path/to/nextcloud
# Optional: PHP‑/JS‑Abhängigkeiten (falls nicht über Release‑Tarball installiert)
# cd apps/arbeitszeitcheck && composer install && npm install
php occ app:enable arbeitszeitcheck
```

Unterstützte Umgebungen:

- **Nextcloud** 32, 33, 34, 35, 36  
- **PHP** 8.1–8.4  
- Datenbanken: MySQL/MariaDB, PostgreSQL, SQLite (für kleinere Installationen)  

### Dokumentation

Die wichtigsten Begleitdokumente liegen im Ordner `docs/`:

- **Architektur & Rollen**
  - `VIEWS_ANALYSIS.md` – Überblick über alle Seiten und Routen
  - `ROLES_AND_PERMISSIONS.md` – Rollen‑ und Berechtigungsmodell (Single Source of Truth)
- **Compliance**
  - `ArbZG-Compliance-Analyse.md` – Abdeckung der ArbZG‑Paragraphen
  - `Compliance-Implementation.de.md` / `Compliance-Implementation.en.md` – technische Umsetzung der ArbZG‑Regeln
  - `GDPR-Compliance-Guide.en.md` – Betrieb der App im Einklang mit DSGVO/GDPR
- **Implementierung**
  - `TRANSLATIONS.md` – Übersetzungskonzept und verwendete Keys
  - `UX-and-Accessibility.md` – UX‑/WCAG‑Leitlinien und Designentscheidungen
  - `Developer-Documentation.en.md` – Architekturüberblick und Hinweise für Beitragende
  - `Store-Publishing.md` – Ablauf für Releases in den Nextcloud App Store

Die Endnutzer‑Oberfläche ist so gestaltet, dass sie ohne separates Handbuch verständlich ist; zusätzliche Handbücher oder rechtliche Vorlagen werden bewusst nicht mit ausgeliefert und können organisationsspezifisch ergänzt werden.

### Nextcloud App Store Assets

- **App‑Metadaten**: `appinfo/info.xml` (Name, Beschreibung, Version, Abhängigkeiten)  
- **Lizenz**: `LICENSE` (AGPL‑3.0 oder kompatibel, siehe Datei)  
- **Icon**: `app.svg` bzw. `img/app.svg`  
- **Screenshots**: im Verzeichnis `screenshots/`  
  - Beschreibung und erwartete Dateien in `screenshots/README.md`  

Diese Dateien werden vom Nextcloud App Store für Listung, Detailseite und Review verwendet.

### Entwicklung & Tests

- PHPUnit‑Tests: `composer test` (bzw. `phpunit` mit bereitgestellter `phpunit.xml`)  
- JS‑Build: `npm run build`, Dev‑Watch: `npm run watch`  
- Test‑Abdeckung:
  - Controller‑Tests (`tests/unit/Controller/*Test.php`)
  - Service‑Tests (z. B. `ComplianceServiceTest`, `TimeTrackingServiceTest`, `OvertimeServiceTest`)
  - Integrationstests für zentrale API‑Flows (`tests/integration/ApiTest.php`)

Weitere Details zur Architektur und zu Beitrag‑Richtlinien finden sich in `docs/Developer-Documentation.en.md`.

