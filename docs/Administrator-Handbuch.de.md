# Administrator-Handbuch – ArbeitszeitCheck (TimeGuard)

**Version:** 1.0.0  
**Letzte Aktualisierung:** 2025-12-29

## Inhaltsverzeichnis

1. [Einführung](#einführung)
2. [Installation](#installation)
3. [Erstkonfiguration](#erstkonfiguration)
4. [Benutzerverwaltung](#benutzerverwaltung)
5. [Arbeitszeitmodelle](#arbeitszeitmodelle)
6. [Compliance-Konfiguration](#compliance-konfiguration)
7. [Vorgesetztenzuweisung](#vorgesetztenzuweisung)
8. [Berichte und Exporte](#berichte-und-exporte)
9. [Protokolldaten](#protokolldaten)
10. [DSGVO-Compliance](#dsgvo-compliance)
11. [Fehlerbehebung](#fehlerbehebung)
12. [Best Practices](#best-practices)
13. [Wartung](#wartung)

---

## Einführung

Dieses Handbuch richtet sich an Administratoren, die für die Konfiguration und Verwaltung von ArbeitszeitCheck (TimeGuard) in ihrer Organisation verantwortlich sind.

### Administrator-Verantwortlichkeiten

- **Systemkonfiguration**: Globale Einstellungen und Compliance-Regeln einrichten
- **Benutzerverwaltung**: Arbeitszeitmodelle zuweisen, Urlaubsansprüche konfigurieren
- **Compliance-Überwachung**: Verstöße prüfen, rechtliche Compliance sicherstellen
- **Datenverwaltung**: Exporte, Aufbewahrungsrichtlinien, DSGVO-Anfragen bearbeiten
- **Support**: Benutzer bei Fragen und Problemen unterstützen

### Voraussetzungen

- Nextcloud-Administratorzugriff
- Verständnis des deutschen Arbeitszeitgesetzes (ArbZG)
- Verständnis der DSGVO-Anforderungen
- Zugriff auf HR-Richtlinien der Organisation

---

## Installation

### Aus dem Nextcloud App Store

1. Als Nextcloud-Administrator anmelden
2. Zu **"Apps"** → **"Organisation"** oder **"Produktivität"** gehen
3. Nach **"ArbeitszeitCheck"** oder **"TimeGuard"** suchen
4. Auf **"Herunterladen und aktivieren"** klicken
5. Auf Abschluss der Installation warten

### Manuelle Installation

1. App von GitHub Releases herunterladen
2. In `apps/arbeitszeitcheck/` Verzeichnis entpacken
3. `occ app:enable arbeitszeitcheck` ausführen
4. Datenbankschema wird automatisch erstellt

### Checkliste nach Installation

- [ ] App ist aktiviert und zugänglich
- [ ] Datenbanktabellen erstellt (prüfen mit `occ db:list-tables arbeitszeitcheck`)
- [ ] Admin-Einstellungsseite zugänglich
- [ ] Keine Fehler im Nextcloud-Log

---

## Erstkonfiguration

### Auf Admin-Einstellungen zugreifen

1. Als Administrator anmelden
2. Zu **"Einstellungen"** → **"Administration"** → **"ArbeitszeitCheck"** gehen
3. Oder direkt zu `/apps/arbeitszeitcheck/admin/settings` navigieren

### Globale Einstellungen

#### Compliance-Einstellungen

**Automatische Compliance-Prüfung:**
- **Aktiviert** (empfohlen): System prüft automatisch täglich auf Verstöße
- **Deaktiviert**: Nur manuelle Compliance-Prüfungen

**Verstoß-Benachrichtigungen:**
- **Aktiviert** (empfohlen): Benutzer und HR erhalten Benachrichtigungen über Verstöße
- **Deaktiviert**: Verstöße werden protokolliert, aber keine Benachrichtigungen gesendet

**Pausenbegründung erforderlich:**
- **Aktiviert**: Benutzer müssen Begründung angeben, wenn Pausen fehlen
- **Deaktiviert**: Fehlende Pausen werden ohne Begründungspflicht protokolliert

#### Arbeitszeitgrenzen

**Maximale tägliche Stunden:**
- Standard: `10` Stunden
- Bereich: 1-24 Stunden
- **Gesetzliche Anforderung**: 8 Stunden Standard, erweiterbar auf 10 Stunden (ArbZG §3)

**Mindestruhezeit:**
- Standard: `11` Stunden
- Bereich: 1-24 Stunden
- **Gesetzliche Anforderung**: Mindestens 11 aufeinanderfolgende Stunden zwischen Schichten (ArbZG §5)

**Standard-Arbeitsstunden:**
- Standard: `8` Stunden pro Tag
- Wird für neue Benutzer ohne zugewiesenes Arbeitszeitmodell verwendet

#### Regionale Einstellungen

**Deutsches Bundesland:**
- Ihr Bundesland auswählen: `NW`, `BY`, `BW`, `HE`, `NI`, `RP`, `SL`, `BE`, `BB`, `HB`, `HH`, `MV`, `SN`, `ST`, `SH`, `TH`
- Wird für Feiertagskalender verwendet
- **Wichtig**: Stellt korrekte Feiertagsverfolgung für Compliance sicher

#### Datenaufbewahrung

**Aufbewahrungsfrist:**
- Standard: `2` Jahre
- Bereich: 1-10 Jahre
- **Gesetzliche Anforderung**: Mindestens 2 Jahre für Zeiterfassungen (ArbZG)
- Daten älter als Aufbewahrungsfrist können gelöscht werden (DSGVO-Löschanfragen)

### Einstellungen speichern

Nach Konfiguration der Einstellungen:
1. Auf **"Einstellungen speichern"** klicken
2. Überprüfen, dass Änderungen angewendet wurden
3. Protokolldaten auf Konfigurationsänderungen prüfen

---

## Benutzerverwaltung

### Auf Benutzerverwaltung zugreifen

1. Zu **"Administration"** → **"ArbeitszeitCheck"** → **"Benutzer"** gehen
2. Oder zu `/apps/arbeitszeitcheck/admin/users` navigieren

### Benutzerübersicht

Die Benutzerverwaltungsseite zeigt:
- **Benutzerliste**: Alle Nextcloud-Benutzer
- **Arbeitszeitmodell**: Zugewiesenes Modell pro Benutzer
- **Urlaubsanspruch**: Tage pro Jahr
- **Status**: Aktiv/inaktiv, hat heute Zeiteinträge

### Arbeitszeitmodelle zuweisen

**So weisen Sie einem Benutzer ein Arbeitszeitmodell zu:**

1. Benutzer in der Liste finden
2. Auf **"Bearbeiten"** oder **"Details anzeigen"** klicken
3. **"Arbeitszeitmodell"** aus Dropdown auswählen
4. **"Urlaubstage pro Jahr"** setzen
5. **"Startdatum"** setzen (wann Modell wirksam wird)
6. Optional **"Enddatum"** setzen (für temporäre Zuweisungen)
7. Auf **"Speichern"** klicken

**Wichtig:**
- Benutzer ohne zugewiesenes Modell verwenden **Standard-Arbeitsstunden** aus globalen Einstellungen
- Arbeitszeitmodelle bestimmen erforderliche Stunden für Überstundenberechnung
- Änderungen werden sofort wirksam (oder am Startdatum, falls angegeben)

### Urlaubsanspruch konfigurieren

**So setzen Sie Urlaubstage:**

1. Benutzerdetails öffnen
2. **"Urlaubstage pro Jahr"** setzen
3. Urlaubsanspruch wird pro Kalenderjahr berechnet
4. System verfolgt verwendete vs. verbleibende Tage

**Hinweis:** Urlaubsanspruch ist getrennt von Abwesenheitstypen (Krankmeldung, Sonderurlaub).

### Vorgesetztenzuweisung

**So weisen Sie einem Benutzer einen Vorgesetzten zu:**

1. Benutzerdetails öffnen
2. **"Vorgesetzter"**-Feld setzen (aus Benutzerliste auswählen)
3. Vorgesetzter kann:
   - Abwesenheitsanträge genehmigen/ablehnen
   - Zeiteintragskorrekturen genehmigen/ablehnen
   - Teamübersicht und Compliance einsehen
4. Auf **"Speichern"** klicken

**Vorgesetztenhierarchie:**
- Benutzer können einen direkten Vorgesetzten haben
- Vorgesetzte können mehrere Teammitglieder haben
- Vorgesetzte können auch Mitarbeiter sein (haben ihren eigenen Vorgesetzten)

### Bulk-Benutzerimport

**So importieren Sie Benutzer aus CSV:**

1. CSV-Datei mit Spalten vorbereiten:
   - `user_id` (erforderlich): Nextcloud-Benutzer-ID
   - `working_time_model_id` (optional): Modell-ID
   - `vacation_days_per_year` (optional): Urlaubsanspruch
   - `manager_id` (optional): Vorgesetzten-Benutzer-ID
2. Zu **"Benutzer"** → **"Importieren"** gehen
3. CSV-Datei hochladen
4. Importvorschau prüfen
5. Import bestätigen

**Benutzer exportieren:**

1. Zu **"Benutzer"** → **"Exportieren"** gehen
2. Format auswählen: `CSV` oder `JSON`
3. Datei herunterladen

---

## Arbeitszeitmodelle

### Auf Arbeitszeitmodelle zugreifen

1. Zu **"Administration"** → **"ArbeitszeitCheck"** → **"Arbeitszeitmodelle"** gehen
2. Oder zu `/apps/arbeitszeitcheck/admin/working-time-models` navigieren

### Arbeitszeitmodell erstellen

**So erstellen Sie ein neues Modell:**

1. Auf **"Arbeitszeitmodell erstellen"** klicken
2. Ausfüllen:
   - **Name**: Beschreibender Name (z.B. "Vollzeit 40h")
   - **Beschreibung**: Optionale Beschreibung
   - **Typ**: `full_time`, `part_time`, `flexible`, `shift_work`
   - **Wöchentliche Stunden**: Stunden pro Woche (z.B. 40.0)
   - **Tägliche Stunden**: Stunden pro Tag (z.B. 8.0)
   - **Ist Standard**: Als Standard für neue Benutzer markieren
3. Auf **"Speichern"** klicken

**Modelltypen:**
- **Vollzeit**: Standard-Vollzeitbeschäftigung
- **Teilzeit**: Teilzeitbeschäftigung mit festen Stunden
- **Flexibel**: Flexible Arbeitszeiten (Gleitzeit)
- **Schichtarbeit**: Schichtbasierte Zeitpläne

### Arbeitszeitmodelle bearbeiten

**So bearbeiten Sie ein Modell:**

1. Modell in der Liste finden
2. Auf **"Bearbeiten"** klicken
3. Felder nach Bedarf ändern
4. Auf **"Speichern"** klicken

**Wichtig:**
- Änderungen betreffen alle diesem Modell zugewiesenen Benutzer
- Historische Daten werden nicht automatisch neu berechnet
- Erwägen Sie, eine neue Modellversion zu erstellen, anstatt bestehende zu bearbeiten

### Arbeitszeitmodelle löschen

**So löschen Sie ein Modell:**

1. Modell in der Liste finden
2. Auf **"Löschen"** klicken
3. Löschung bestätigen

**Einschränkungen:**
- Kann nicht gelöscht werden, wenn Benutzer zugewiesen sind
- Benutzer zuerst einem anderen Modell neu zuweisen
- Historische Zuweisungen bleiben erhalten

---

## Compliance-Konfiguration

### Compliance-Dashboard

**So zeigen Sie Compliance-Übersicht an:**

1. Zu **"Administration"** → **"ArbeitszeitCheck"** → **"Compliance"** gehen
2. Sehen:
   - **Gesamtverstöße**: Anzahl nach Schweregrad
   - **Compliance-Prozentsatz**: Gesamt-Compliance-Rate
   - **Aktuelle Verstöße**: Neueste Compliance-Probleme
   - **Verstöße nach Typ**: Aufschlüsselung nach Verstoßtyp

### Verstoßtypen

Das System erkennt:

- **Fehlende Pause**: Keine Pause nach 6 Stunden
- **Unzureichende Pause**: Weniger als erforderliche Pausenzeit
- **Tägliche Stunden überschritten**: Mehr als 10 Stunden an einem Tag
- **Unzureichende Ruhezeit**: Weniger als 11 Stunden zwischen Schichten
- **Wöchentliche Stunden überschritten**: Mehr als 48 Stunden Durchschnitt pro Woche
- **Nachtarbeit**: Arbeit zwischen 23 Uhr und 6 Uhr
- **Sonntagsarbeit**: Arbeit am Sonntag
- **Feiertagsarbeit**: Arbeit an Feiertagen

### Verstöße lösen

**So lösen Sie einen Verstoß:**

1. Zu **"Compliance"** → **"Verstöße"** gehen
2. Verstoß finden
3. Auf **"Lösen"** klicken
4. Lösungs-Kommentar hinzufügen
5. Auf **"Speichern"** klicken

**Hinweis:** Das Lösen eines Verstoßes markiert ihn als bearbeitet, ändert aber keine historischen Daten. Es dient als Dokumentation für Compliance-Audits.

### Compliance-Berichte

**So generieren Sie Compliance-Berichte:**

1. Zu **"Compliance"** → **"Berichte"** gehen
2. Datumsbereich auswählen
3. Berichtstyp wählen:
   - **Zusammenfassung**: Übersicht der Verstöße
   - **Detailliert**: Vollständige Verstoßliste
   - **Nach Benutzer**: Verstöße pro Benutzer
   - **Nach Typ**: Verstöße nach Typ
4. Als CSV, JSON oder PDF exportieren

---

## Vorgesetztenzuweisung

### Vorgesetzte zuweisen

**So weisen Sie einen Vorgesetzten zu:**

1. Zu **"Benutzer"** → Benutzer auswählen → **"Bearbeiten"** gehen
2. **"Vorgesetzter"**-Feld setzen
3. Speichern

**Vorgesetzten-Fähigkeiten:**
- Teamübersicht einsehen (wer eingestempelt ist, gearbeitete Stunden)
- Abwesenheitsanträge genehmigen/ablehnen
- Zeiteintragskorrekturen genehmigen/ablehnen
- Team-Compliance-Status einsehen
- Auf Team-Berichte zugreifen

### Vorgesetzten-Dashboard

Vorgesetzte können zugreifen auf:
- **Teamübersicht**: Aktueller Status der Teammitglieder
- **Ausstehende Genehmigungen**: Abwesenheitsanträge und Zeiteintragskorrekturen, die auf Genehmigung warten
- **Team-Compliance**: Compliance-Status für Team
- **Team-Berichte**: Arbeitsstunden- und Abwesenheitsberichte

---

## Berichte und Exporte

### Verfügbare Berichte

**Tagesbericht:**
- Zusammenfassung für einen bestimmten Tag
- Gearbeitete Stunden, genommene Pausen, Compliance-Status

**Wochenbericht:**
- Zusammenfassung für eine Woche
- Gesamtstunden, Durchschnitt pro Tag, Überstunden

**Monatsbericht:**
- Zusammenfassung für einen Monat
- Gesamtstunden, erforderliche Stunden, Überstundenbilanz

**Überstundenbericht:**
- Überstundenberechnung für Datumsbereich
- Aufschlüsselung nach Benutzer oder Team

**Abwesenheitsbericht:**
- Abwesenheitsstatistiken
- Verwendete Urlaubstage, Krankmeldungen, andere Abwesenheiten

**Teambericht:**
- Multi-Benutzer-Bericht
- Stunden und Abwesenheiten von Teammitgliedern vergleichen

### Exportformate

**CSV:**
- Für Excel oder Tabellenkalkulationssoftware
- Enthält alle Datenfelder
- Geeignet für weitere Analyse

**JSON:**
- Maschinenlesbares Format
- Vollständige Datenstruktur
- Für Integration mit anderen Systemen

**PDF:**
- Formatierter Bericht
- Geeignet zum Drucken oder Archivieren
- Enthält Diagramme und Zusammenfassungen

**DATEV:**
- Lohnabrechnungs-Integrationsformat
- Erfordert DATEV-Konfiguration (Beraternummer, Mandantennummer)
- ASCII-Format kompatibel mit DATEV-Software

### DATEV-Export-Konfiguration

**So konfigurieren Sie DATEV-Export:**

1. Zu **"Administration"** → **"ArbeitszeitCheck"** → **"Einstellungen"** gehen
2. **"DATEV Beraternummer"** (Beraternummer) setzen
3. **"DATEV Mandantennummer"** (Mandantennummer) setzen
4. Einstellungen speichern

**DATEV-Export-Felder:**
- Personalnummer (Mitarbeiternummer)
- Datum
- Lohnart (Lohnart)
- Stunden
- Zusätzliche Felder wie konfiguriert

---

## Protokolldaten

### Auf Protokolldaten zugreifen

1. Zu **"Administration"** → **"ArbeitszeitCheck"** → **"Protokolldaten"** gehen
2. Oder zu `/apps/arbeitszeitcheck/admin/audit-log` navigieren

### Protokolldaten-Funktionen

**Protokolle anzeigen:**
- Nach Datumsbereich filtern
- Nach Benutzer filtern
- Nach Aktionstyp filtern
- Nach Entitätstyp filtern (Zeiteintrag, Abwesenheit, Einstellungen, etc.)

**Aktionstypen:**
- `time_entry_created`, `time_entry_updated`, `time_entry_deleted`
- `absence_created`, `absence_approved`, `absence_rejected`
- `settings_updated`
- `compliance_violation_created`, `compliance_violation_resolved`
- `user_working_time_model_assigned`

**Protokolldaten exportieren:**
- Gefilterte Protokolle als CSV oder JSON exportieren
- Enthält alle Metadaten (Benutzer, Zeitstempel, Aktion, Änderungen)

### Protokolldaten-Statistiken

**So zeigen Sie Statistiken an:**

1. Zu **"Protokolldaten"** → **"Statistiken"** gehen
2. Sehen:
   - Gesamtprotokolleinträge
   - Einträge nach Aktionstyp
   - Einträge nach Benutzer
   - Aktuelle Aktivität

---

## DSGVO-Compliance

### Datenexport (Art. 15)

**So exportieren Sie Benutzerdaten:**

1. Zu **"Benutzer"** → Benutzer auswählen → **"Daten exportieren"** gehen
2. Oder Benutzer kann eigene Daten exportieren über **"Einstellungen"** → **"Persönlich"** → **"Persönliche Daten exportieren"**

**Export enthält:**
- Alle Zeiteinträge
- Alle Abwesenheiten
- Benutzereinstellungen
- Compliance-Verstöße
- Protokolldaten

### Datenlöschung (Art. 17)

**So bearbeiten Sie Löschanfragen:**

1. Benutzer fordert Löschung über **"Einstellungen"** → **"Persönlich"** → **"Persönliche Daten löschen"** an
2. System respektiert **2-Jahres-Aufbewahrungsfrist** (ArbZG-Anforderung)
3. Nur Daten älter als Aufbewahrungsfrist werden gelöscht
4. Protokolldaten und Compliance-Verstöße werden für rechtliche Compliance aufbewahrt

**Wichtig:**
- Löschung respektiert rechtliche Aufbewahrungsanforderungen
- Einige Daten müssen für Arbeitszeitgesetz-Compliance aufbewahrt werden
- Benutzer werden über aufbewahrte Daten informiert

### Verarbeitungsverzeichnis (Art. 30)

**So führen Sie Verarbeitungsverzeichnis:**

1. Verwenden Sie die **Verarbeitungsverzeichnis-Vorlage** (`docs/Processing-Activities-Record-Template.en.md`)
2. Dokumentieren Sie:
   - Verarbeitete Datenkategorien
   - Zweck der Verarbeitung
   - Rechtsgrundlage (Art. 6(1)(c) - rechtliche Verpflichtung)
   - Datenempfänger (HR, Lohnabrechnung, Vorgesetzte)
   - Aufbewahrungsfristen
   - Sicherheitsmaßnahmen

### Datenschutz-Folgenabschätzung (Art. 35)

**So führen Sie DSFA durch:**

1. Verwenden Sie die **DSFA-Vorlage** (`docs/DPIA-Template.en.md`)
2. Risiken und Minderungsmaßnahmen bewerten
3. Notwendigkeit und Verhältnismäßigkeit dokumentieren
4. Mit Datenschutzbeauftragtem prüfen

---

## Fehlerbehebung

### Häufige Probleme

**Benutzer können nicht einstempeln:**
- Prüfen, ob Benutzer aktiven Zeiteintrag hat
- Ruhezeit (11 Stunden) überprüfen
- Nextcloud-Log auf Fehler prüfen
- Benutzerberechtigungen überprüfen

**Compliance-Verstöße werden nicht erkannt:**
- Überprüfen, dass **"Automatische Compliance-Prüfung"** aktiviert ist
- Prüfen, ob Hintergrundjob läuft (`occ job:list`)
- Überprüfen, dass Compliance-Regeln korrekt konfiguriert sind
- Prüfen, dass Nextcloud-Cron konfiguriert ist

**Berichte werden nicht generiert:**
- Prüfen, dass Datumsbereich gültig ist
- Überprüfen, dass Benutzer Daten im Datumsbereich hat
- Nextcloud-Log auf Fehler prüfen
- Überprüfen, dass Exportformat unterstützt wird

**DATEV-Export schlägt fehl:**
- DATEV-Konfiguration überprüfen (Beraternummer, Mandantennummer)
- Datumsbereich prüfen (muss innerhalb Aufbewahrungsfrist sein)
- Überprüfen, dass Benutzer Zeiteinträge im Datumsbereich hat
- Nextcloud-Log auf Fehler prüfen

### Systemgesundheit prüfen

**Health-Check-Endpunkt:**

```bash
curl https://ihr-nextcloud.com/apps/arbeitszeitcheck/health
```

**Antwort:**
```json
{
  "status": "ok",
  "version": "1.0.0",
  "database": "connected",
  "background_jobs": "running"
}
```

### Protokolldateien

**Nextcloud-Log:**
- Speicherort: `data/nextcloud.log`
- Filtern: `grep arbeitszeitcheck data/nextcloud.log`

**Häufige Protokollmeldungen:**
- `[arbeitszeitcheck] User clocked in` - Normaler Betrieb
- `[arbeitszeitcheck] Compliance violation detected` - Verstoß gefunden
- `[arbeitszeitcheck] ERROR` - Fehler aufgetreten

---

## Best Practices

### Konfiguration

1. **Realistische Grenzen setzen**: Maximale tägliche Stunden basierend auf Organisationsrichtlinien konfigurieren
2. **Compliance-Prüfungen aktivieren**: Immer automatische Compliance-Prüfung aktivieren
3. **Benachrichtigungen konfigurieren**: Verstoß-Benachrichtigungen für rechtzeitige Kenntnisnahme aktivieren
4. **Aufbewahrungsfrist setzen**: Aufbewahrung basierend auf rechtlichen Anforderungen konfigurieren (mindestens 2 Jahre)

### Benutzerverwaltung

1. **Arbeitszeitmodelle zuweisen**: Angemessene Modelle allen Benutzern zuweisen
2. **Urlaubsansprüche setzen**: Urlaubstage pro Benutzer konfigurieren
3. **Vorgesetzte zuweisen**: Vorgesetztenhierarchie für Genehmigungsworkflows einrichten
4. **Regelmäßige Überprüfungen**: Benutzerzuweisungen vierteljährlich überprüfen

### Compliance

1. **Verstöße überwachen**: Compliance-Dashboard regelmäßig prüfen
2. **Verstöße lösen**: Verstöße umgehend mit Benutzern bearbeiten
3. **Ausnahmen dokumentieren**: Lösungs-Kommentare verwenden, um Ausnahmen zu dokumentieren
4. **Regelmäßige Audits**: Vierteljährliche Compliance-Audits durchführen

### Datenverwaltung

1. **Regelmäßige Backups**: Nextcloud-Datenbank regelmäßig sichern
2. **Berichte exportieren**: Monatliche Berichte für Archivierung exportieren
3. **Aufbewahrungsrichtlinie**: Aufbewahrungsrichtlinie strikt befolgen
4. **DSGVO-Anfragen**: DSGVO-Anfragen umgehend bearbeiten (innerhalb von 30 Tagen)

### Sicherheit

1. **Zugriffskontrolle**: Admin-Zugriff nur auf autorisiertes Personal beschränken
2. **Protokolldaten**: Protokolldaten regelmäßig auf verdächtige Aktivität prüfen
3. **Passwortrichtlinie**: Starke Passwörter über Nextcloud durchsetzen
4. **Updates**: Nextcloud und App aktuell halten

---

## Wartung

### Regelmäßige Aufgaben

**Täglich:**
- Compliance-Verstöße überwachen
- Systemgesundheits-Endpunkt prüfen
- Fehlerprotokolle prüfen

**Wöchentlich:**
- Ausstehende Genehmigungen prüfen (wenn Vorgesetzter)
- Wöchentliche Berichte exportieren
- Protokolldaten auf Anomalien prüfen

**Monatlich:**
- Monatliche Berichte generieren
- Benutzerzuweisungen prüfen
- Aufbewahrungsrichtlinien-Compliance prüfen
- Compliance-Statistiken prüfen

**Vierteljährlich:**
- Compliance-Audit durchführen
- Arbeitszeitmodelle prüfen und aktualisieren
- DSGVO-Compliance prüfen
- Dokumentation aktualisieren

### Datenbankwartung

**Tabellengrößen prüfen:**
```sql
SELECT table_name, 
       ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = 'nextcloud'
  AND table_name LIKE 'oc_at_%'
ORDER BY size_mb DESC;
```

**Alte Daten archivieren:**
- Daten älter als Aufbewahrungsfrist exportieren
- In externen Speicher archivieren
- Aus Datenbank löschen (wenn Aufbewahrungsfrist überschritten)

### Backup und Wiederherstellung

**Backup:**
```bash
# Datenbank sichern
mysqldump -u nextcloud -p nextcloud > arbeitszeitcheck_backup.sql

# App-Dateien sichern
tar -czf arbeitszeitcheck_app_backup.tar.gz apps/arbeitszeitcheck/
```

**Wiederherstellung:**
```bash
# Datenbank wiederherstellen
mysql -u nextcloud -p nextcloud < arbeitszeitcheck_backup.sql

# App-Dateien wiederherstellen
tar -xzf arbeitszeitcheck_app_backup.tar.gz
```

---

## Support und Ressourcen

### Dokumentation

- **Benutzerhandbuch**: `docs/Benutzerhandbuch.de.md`
- **API-Dokumentation**: `docs/API-Documentation.en.md`
- **DSGVO-Compliance-Leitfaden**: `docs/DSGVO-Compliance-Guide.de.md`
- **DSFA-Vorlage**: `docs/DPIA-Template.en.md`

### Community-Support

- **GitHub Issues**: https://github.com/nextcloud/arbeitszeitcheck/issues
- **Nextcloud Foren**: https://help.nextcloud.com/c/apps/arbeitszeitcheck

### Professioneller Support

Für Enterprise-Support und individuelle Entwicklung:
- Kontakt: [Ihr Support-Kontakt]
- E-Mail: [Ihre E-Mail]

---

**Letzte Aktualisierung:** 2025-12-29  
**Version:** 1.0.0
