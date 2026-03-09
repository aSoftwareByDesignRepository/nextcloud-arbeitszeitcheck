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
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-projectcontroll.git /tmp/arbeitszeitcheck-src
cp -r /tmp/arbeitszeitcheck-src/apps/arbeitszeitcheck /path/to/nextcloud/apps/

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

Alle Dokumente liegen im Ordner `apps/arbeitszeitcheck/docs` und sind zweisprachig (DE/EN), soweit sinnvoll.

- **Benutzer‑Doku**
  - `Benutzerhandbuch.de.md` – User Guide für Mitarbeiter
  - `User-Manual.en.md` – User Guide (English)
  - `FAQ.de.md` / `FAQ.en.md` – Häufige Fragen
- **Admin‑Doku**
  - `Administrator-Handbuch.de.md` – Admin‑Handbuch (DE)
  - `Administrator-Guide.en.md` – Admin Guide (EN)
  - `Troubleshooting-Guide.en.md` – Fehlerbehebung
- **Entwickler & API**
  - `Developer-Documentation.en.md` – Architektur, Code‑Struktur, Tests
  - `API-Documentation.en.md` – REST‑API‑Referenz
- **Compliance & Datenschutz**
  - `Compliance-Implementation.de.md` / `Compliance-Implementation.en.md` – Technische Umsetzung der ArbZG‑Regeln
  - `ArbZG-Compliance-Analyse.md` – Aktueller Abdeckungsgrad der ArbZG‑Paragraphen (Überblick)
  - `DSGVO-Compliance-Guide.de.md` / `GDPR-Compliance-Guide.en.md` – Betrieb unter DSGVO
  - `Datenschutzerklaerung.de.md` / `Privacy-Policy.en.md` – anpassbare Datenschutz‑Vorlagen
  - `DPIA-Template.en.md` – Vorlage für Datenschutz‑Folgenabschätzung (Art. 35 DSGVO)
  - `Processing-Activities-Record-Template.en.md` – Vorlage Verzeichnis der Verarbeitungstätigkeiten (Art. 30 DSGVO)
  - `Works-Council-Agreement-Template.de.md` – Muster‑Betriebsvereinbarung

Interne technische Notizen (z. B. `Automatische-Pausenberechnung.md`, `Maximale-Arbeitszeit-10-Stunden.md`, `Compliance-Umsetzung-Status.md`, `Timer-Resume-Verhalten.md`) dokumentieren die detaillierte Logik der Compliance‑Engine und sind primär für Entwickler/Reviewer gedacht.

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

