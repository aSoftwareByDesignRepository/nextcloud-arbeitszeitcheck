## 1.1.2 – 2025-03-07
### Geändert
- Langfristiges Refactoring: Ersetzung aller `\OC::$server`-Verwendungen durch OCP-APIs und Konstruktor-Injection
- CSPService: ContentSecurityPolicyNonceManager per Konstruktor injiziert
- Controller: manuelles cspNonce entfernt (configureCSP übernimmt dies); IURLGenerator und IConfig injiziert, wo nötig
- PageController: IURLGenerator und IConfig injiziert; übergibt urlGenerator an Templates
- HealthController: IDBConnection für Datenbank-Check injiziert
- ProjectCheckIntegrationService: LoggerInterface statt OC::$server->getLogger() injiziert
- Templates: `\OC::$server` durch `\OCP\Server::get()` (öffentliche OCP-API) ersetzt
- GitHub-Actions-Release-Workflow hinzugefügt (`.github/workflows/release.yml`)
- PageControllerTest mit vollständigen Konstruktor-Mocks aktualisiert

## 1.1.1 – 2025-01-07
### Behoben
- Doppelte Routen-Namen in der Abwesenheits-API behoben (absence#store, absence#show, absence#update, absence#delete)
- Klassen-Namen der Settings in info.xml korrigiert, um den vollständigen OCA-Namespace zu verwenden
- `declare(strict_types=1)` zu routes.php hinzugefügt

### Geändert
- Nicht vorhandene Screenshot-Referenzen aus info.xml entfernt, bis echte Screenshots verfügbar sind

## 1.1.0 – 2025-01-04
### Hinzugefügt
- ProjectCheck-Integration für Projektzeiterfassung
- Zusätzliche Migrationen für Schema-Updates

## 1.0.3 – 2025-01-03
### Hinzugefügt
- Weitere Verfeinerungen des Datenbankschemas

## 1.0.2 – 2025-01-02
### Hinzugefügt
- Arbeitszeitmodelle
- Zuweisung von Arbeitszeitmodellen zu Nutzern

## 1.0.1 – 2025-01-01
### Hinzugefügt
- Abwesenheitsverwaltung
- Audit-Logging
- Benutzer-Einstellungen
- Tracking von Compliance-Verstößen

## 1.0.0 – 2024-12-29
### Hinzugefügt
- Erste Veröffentlichung
- Arbeitszeiterfassung gemäß deutschem Arbeitszeitgesetz (ArbZG)
- Kommen-/Gehen- und Pausen-Erfassung
- Verwaltung von Zeiteinträgen (Erstellen, Bearbeiten, Löschen, manuelle Einträge)
- Grundlegende Compliance-Prüfungen (max. 8h/Tag, Pausenanforderungen)
- DSGVO-konforme Datenverarbeitung
- Deutsche und englische Übersetzungen
- WCAG-2.1-AAA-Accessibility-Compliance

