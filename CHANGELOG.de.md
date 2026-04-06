## 1.1.9 – 2026-04-05

### Entfernt

- **Nextcloud-Kalender-App (CalDAV)**: Synchronisation von Abwesenheiten in die Kalender-App ist entfernt; Migration `Version1012Date20260406120000` entfernt die Tabelle `at_absence_calendar`. Bereits angelegte Kalender in der Kalender-App bleiben bestehen, bis Nutzer sie dort löschen.

### Geändert

- **Feiertage / Kalenderlogik**: In der Klasse `HolidayService` gebündelt.

### Behoben

- **AdminController**: Doppelte `use`-Anweisung für `HolidayService` führte zu einem PHP-Fatal (u. a. beim Laden durch PHPUnit).

### Dokumentation

- Nutzerhandbücher EN/DE (`docs/User-Manual.*`), README- und Entwicklerdokumentation aktualisiert; Hilfsskript `docker/run-app-phpunit.sh` für PHPUnit im Container.

## 1.1.7 – 2026-04-05

### Hinzugefügt

- **Resturlaub / Urlaubsübertrag**: Pro Nutzer und Kalenderjahr Eröffnungsbestand `carryover_days` in `at_vacation_year_balance`; globale Admin-Einstellung für Ablauf des Vorjahresurlaubs (Monat/Tag, Standard 31.03.). `VacationAllocationService` wendet FIFO auf genehmigten Urlaub an (nach `start_date`, dann `id`) und teilt Arbeitstage vor/nach Ablauf, sodass Resturlaub zuerst verbraucht wird, solange er gültig ist.
- **Validierung & Freigaben**: Urlaubsanträge werden bei Manager-Freigabe (und bei Auto-Approve) erneut geprüft, damit parallele Anträge nach Genehmigung das Kontingent nicht überziehen.
- **API & UI**: `AbsenceController::stats` liefert Anspruch, Übertrag, Summen und ablaufbezogene Felder; Dashboard und Abwesenheiten zeigen eine klare Urlaubsübersicht; Admin-Einstellungen enthalten Ablauffelder.
- **DSGVO**: `UserDeletedListener` löscht Urlaubs-Jahresbestände bei Kontolöschung.
- **Migration / Massenpflege**: `occ arbeitszeitcheck:import-vacation-balance` importiert CSV `user_id,year,carryover_days` mit `--dry-run`.

### Tests

- Unit-Tests für `VacationAllocationService`; erweiterte Tests für `AbsenceService` und zugehörige Controller.

## 1.1.6 – 2026-03-27

### Hinzugefügt

- **Entwicklung**: CLI `occ arbeitszeitcheck:generate-test-data` für deterministische Demo-Daten (Zeiteinträge, Abwesenheiten, optional Verstöße, Demo-App-Team) zum Testen von UI, Berichten und Workflows.
- **Exporte**: `TimeEntryExportTransformer` bündelt Feldzuordnung und CSV-Aufbereitung für Zeiteintrags-Exporte; `ExportController` delegiert daran für eine einheitliche, testbare Pipeline.

### Behoben

- **Berichte-UI**: Berichtstyp-Karten werden bei teambezogenem Scope nicht mehr fälschlich deaktiviert.
- **Berichte (Tests)**: CSV-Download-Test nutzt `DataDownloadResponse::render()` für den Dateiinhalt.
- **Team-Berichte**: Nutzer-IDs werden vor Berechtigungsprüfung und Aggregation dedupliziert (keine Doppelzählung bei Mehrfach-Teams).
- **Abwesenheits-Badges**: Besser lesbare, theme-sichere Kontraste für Urlaub / Krank / Homeoffice / Sonstiges.

### Geändert

- **Kompatibilität (Dev)**: Lokale Entwicklungsumgebungen an Nextcloud 33.x ausgerichtet (z. B. offizielles `nextcloud`-Docker-Image).
- **Berichte-Layout**: Zu aggressive Vollbreiten-Regel für das Parameterformular zurückgenommen (verbessert Scroll/Layout).
- **Berichte-UI**: Anpassungen an Templates, JavaScript und Styles auf der Berichtsseite; Admin-Einstellungen mit zugehörigem Hook.
- **Reporting**: Anpassungen in `ReportController` und `ReportingService` passend zum Export-Refactoring.

### Tests

- Unit-Tests für `TimeEntryExportTransformer`; erweiterte `ReportController`-Tests; `ExportController`-Tests an neue Verdrahtung angepasst.

## 1.1.4 – 2026-03-25

### Behoben
- **Routing/Kompatibilität**: `indexApi()`-Kompatibilitätsaliases für Legacy-Endpunkte ergänzt, um 500-Fehler in den Nextcloud-Logs zu verhindern.
- **PHP-Fatals**: Konstruktor-Signaturprobleme in `AbsenceService` und `ComplianceService` behoben (konnte die App beim Laden von Services oder beim Speichern von Einstellungen zum Absturz bringen).
- **Reports-Sicherheit**: Vorschau-Endpunkte gehärtet (`start <= end` Validierung + maximale Zeitraumbegrenzung) um DoS-Risiken durch untrusted Parameter zu reduzieren.
- **Admin-“Gesamte Organisation”**: Admin-Organisation-Scope korrekt verarbeitet (`userId=""` = alle aktivierten Nutzer) inklusive passender Zugriffsprüfung, damit Preview/Download konsistent bleiben.
- **Reports-Rendering**: Preview-Darstellung für **Abwesenheiten** und **Compliance** verbessert, sodass sie zur tatsächlichen Ergebnisstruktur passt.

### Geändert
- **Reports-UI-Semantik**: Team-Scope auf Team-Overview-/Export-Semantik eingeschränkt (verhindert irreführende Preview/Downloads).
- **Organisation-Download Hinweis**: UI-Hinweis ergänzt, dass Organisation-Download erst vollständig unterstützt ist, sobald dedizierte Organization-Export-Endpunkte verfügbar sind.

## 1.1.3 – 2025-03-14
### Behoben
- **ArbZG-Compliance**: Pausenprüfung korrigiert (9h/45min-Zweig erreichbar; Prüfung ≥9h vor ≥6h)
- **Manager-Logik**: `employeeHasManager()` nutzt nun `getManagerIdsForEmployee()` statt `getColleagueIds()`
- **Berichte**: `getTeamHoursSummary()` berücksichtigt Periodenparameter (Woche/Monat)
- **Admin-Benutzer**: `hasTimeEntriesToday` pro Benutzer statt systemweit
- **UserSettingsMapper**: Falsy-Null/Leerstring-Behandlung in getIntegerSetting, getFloatSetting, getStringSetting
- **Routing**: exportUsers-Route vor getUser verschoben (Shadowing behoben)
- **Version1009-Migration**: MySQL-Backticks durch portablen QueryBuilder ersetzt; OCP\DB\Types
- **Doppelte Notifier-Registrierung**: Aus Application.php boot() entfernt
- **API-Sicherheit**: Generische Fehlermeldungen statt roher Exceptions (SubstituteController, GdprController)
- **PDF-Export**: HTTP 422 mit klarer Meldung statt stillem CSV-Fallback
- **LIKE-Injection**: WorkingTimeModelMapper::searchByName() verwendet escapeLikeParameter()
- **XSS**: Modal-Titel in components.js escaped; compliance-violations.js innerHTML escaped
- **Admin-Einstellungen**: CSRF-requesttoken ergänzt
- **AbsenceService DI**: Konstruktorargument-Reihenfolge (IDBConnection) korrigiert
- Admin-Feiertage und -Einstellungen: englische Quellstrings für l10n
- UserDeletedListener: TeamMemberMapper und TeamManagerMapper per Injection
- XSS: Team-Namen in admin-teams.js bereinigt

### Geändert
- **CSS**: Shadow-Light-Variable, scopierte Resets, Dark-Mode color-mix, semantische Farben, Navigationshöhe/z-index
- **Uhr-Buttons**: Doppel-Submit-Guard (deaktiviert während API-Aufrufen)
- **initTimeline()**: Max-Retry (20) gegen Endlosschleife
- **Barrierefreiheit**: aria-label auf Header-Buttons, Label für Admin-Suche, aria-modal im Willkommens-Dialog, englische l10n-Keys in Navigation
- **Dokumentation**: Interne Docs entfernt; docs/README ergänzt; Repo-URLs korrigiert
- **Manager-Dashboard**: l10n von PHP an JS übergeben für Übersetzungen
- Constants.php; benutzerfreundliche Fehlermeldungen
- **Zeiteintrags-Export**: Optional (per Admin-Einstellung) können Einträge, die über Mitternacht laufen, im CSV/JSON-Export rein darstellungsbezogen in zwei Kalendertage segmentiert werden (vor/nach 00:00). Die zugrundeliegende Arbeitszeit- und ArbZG-Compliance-Berechnung bleibt unverändert auf Basis des originalen, unsplitteten Zeiteintrags.

### Hinzugefügt
- **Version1010-Migration**: Zusammengesetzte Indizes auf at_entries, at_violations, at_holidays, at_absences

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

