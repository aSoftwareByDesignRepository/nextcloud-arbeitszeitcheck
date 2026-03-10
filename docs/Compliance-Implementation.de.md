# Compliance-Implementierung: Echtzeit-Prüfung nach ArbZG

> **Hinweis:** Dieses Dokument beschreibt die **technische Umsetzung** der ArbZG‑Compliance in ArbeitszeitCheck.  
> Es ist eine rein technische Beschreibung und **keine Rechtsberatung** oder juristische Stellungnahme.  
> Für eine rechtliche Bewertung von Arbeitszeitmodellen und Regelungen wenden Sie sich bitte an Ihre Rechtsabteilung oder einen Fachanwalt für Arbeitsrecht.

## Gesetzliche Grundlagen

### Arbeitszeitgesetz (ArbZG) - Pausenpflicht (§4)

**Gesetzliche Anforderungen:**

1. **Arbeitszeit bis 6 Stunden:**
   - Keine gesetzliche Pausenpflicht

2. **Arbeitszeit mehr als 6 bis 9 Stunden:**
   - **Mindestens 30 Minuten Pause erforderlich**
   - Pause kann in Abschnitte von jeweils mindestens 15 Minuten aufgeteilt werden
   - **Wichtig:** Arbeitnehmer dürfen nicht länger als 6 Stunden ohne Ruhepause beschäftigt werden

3. **Arbeitszeit mehr als 9 Stunden:**
   - **Mindestens 45 Minuten Pause erforderlich**
   - Pause kann in Abschnitte von jeweils mindestens 15 Minuten aufgeteilt werden

**Rechtliche Konsequenzen bei Verstößen:**
- Ordnungswidrigkeit nach ArbZG §22
- Bußgeld bis zu 15.000 € für den Arbeitgeber
- Haftungsrisiko bei Arbeitsunfällen
- Mögliche Schadensersatzansprüche

### Aufzeichnungspflicht

**ArbZG §16 Abs. 2:**
- Arbeitgeber müssen die über 8 Stunden hinausgehende Arbeitszeit aufzeichnen
- Aufzeichnungen müssen mindestens 2 Jahre aufbewahrt werden

**BAG/EuGH-Urteile:**
- Arbeitgeber sind verpflichtet, die gesamte Arbeitszeit zu erfassen
- Empfehlung: Auch Pausenzeiten dokumentieren, um Compliance nachweisen zu können

## Best Practices aus der Industrie

### Personio (Marktführer Zeiterfassung)

**Implementierungsansatz:**
1. **Echtzeit-Prüfung:** Compliance wird sofort bei der Erfassung geprüft
2. **Konfigurierbare Modi:**
   - **Warnmodus:** Benutzer wird gewarnt, kann aber speichern (mit Begründung)
   - **Strict-Modus:** Speichern wird verhindert, wenn Pausenpflicht nicht erfüllt
3. **Automatische Pausenkorrektur:** Optional kann das System fehlende Pausen automatisch abziehen
4. **Sofortige Benachrichtigungen:** Verstöße werden sofort an Benutzer und HR gemeldet

**Vorteile:**
- Frühe Erkennung von Verstößen
- Proaktive Compliance
- Reduziertes rechtliches Risiko
- Bessere Nachweisbarkeit

### Andere Systeme (Flintec, Almas Industries)

- **Echtzeit-Prüfung** wird als Best Practice empfohlen
- **Batch-Verarbeitung** nur als Backup/Retrospective
- **Sofortige Alarmierung** bei kritischen Verstößen

## Implementierungsstrategie

### Architektur-Entscheidungen

**1. Echtzeit-Prüfung (Primary)**
- Compliance wird **sofort** beim Abschluss eines TimeEntry geprüft
- Verstöße werden **sofort** in der Datenbank gespeichert
- Benachrichtigungen werden **sofort** versendet

**2. Tägliche Batch-Prüfung (Backup)**
- Läuft weiterhin als Backup
- Erkennt Verstöße, die bei der Echtzeit-Prüfung übersehen wurden
- Prüft historische Daten nach Regeländerungen

**3. Prüfpunkte:**
- ✅ Beim Erstellen eines TimeEntry (wenn STATUS_COMPLETED)
- ✅ Beim Aktualisieren eines TimeEntry (wenn Status zu COMPLETED wechselt)
- ✅ Beim Genehmigen durch Manager (wenn Status zu COMPLETED wechselt)
- ✅ Täglich im Batch-Job (Backup)

### Konfigurierbare Modi

**1. Warning Mode (Standard)**
- Verstöße werden erkannt und gespeichert
- Benutzer wird gewarnt, kann aber speichern
- Begründung wird empfohlen, aber nicht erzwungen
- Benachrichtigungen werden versendet

**2. Strict Mode (Optional)**
- Verstöße werden erkannt
- **Speichern wird verhindert**, wenn kritische Verstöße vorliegen
- Begründung ist **erforderlich** bei Verstößen
- Benachrichtigungen werden sofort versendet

**3. Auto-Correction Mode (Optional)**
- Fehlende Pausen werden automatisch von der Arbeitszeit abgezogen
- Benutzer wird informiert
- Begründung wird empfohlen

## Technische Implementierung

### Prüfungslogik

**Wann wird geprüft:**
1. Nach `TimeEntryMapper::insert()` - wenn `status === STATUS_COMPLETED`
2. Nach `TimeEntryMapper::update()` - wenn `status === STATUS_COMPLETED` oder zu `STATUS_COMPLETED` wechselt
3. In `TimeEntryController::create()` - nach erfolgreichem Insert
4. In `TimeEntryController::update()` - nach erfolgreichem Update
5. In `ManagerController::approve()` - nach Genehmigung

**Was wird geprüft:**
1. **Pausenpflicht (ArbZG §4):**
   - ≥6 Stunden: Mindestens 30 Minuten Pause?
   - ≥9 Stunden: Mindestens 45 Minuten Pause?
2. **Maximale Arbeitszeit (ArbZG §3):**
   - Täglich maximal 10 Stunden?
3. **Ruhezeit (ArbZG §5):**
   - Mindestens 11 Stunden zwischen Schichten?
4. **Sonntagsarbeit (ArbZG §9):**
   - Wurde an Sonntag gearbeitet? (Warnung)
5. **Feiertagsarbeit (ArbZG §9):**
   - Wurde an Feiertag gearbeitet? (Warnung)
6. **Nachtarbeit (ArbZG §6):**
   - Wurde zwischen 23:00-06:00 gearbeitet? (Info)

### Fehlerbehandlung

**Bei Echtzeit-Prüfung:**
- Prüfung darf **nicht** das Speichern des TimeEntry verhindern (außer Strict Mode)
- Fehler in der Compliance-Prüfung werden geloggt, aber nicht weitergeworfen
- TimeEntry wird trotzdem gespeichert (Datenintegrität hat Priorität)

**Bei Strict Mode:**
- Kritische Verstöße verhindern das Speichern
- HTTP 400 Bad Request mit detaillierter Fehlermeldung
- TimeEntry wird **nicht** gespeichert

## Datenmodell

### ComplianceViolation

**Felder:**
- `id`: Eindeutige ID
- `user_id`: Betroffener Benutzer
- `violation_type`: Art des Verstoßes (z.B. `missing_break`)
- `description`: Beschreibung des Verstoßes
- `date`: Datum des Verstoßes
- `time_entry_id`: Verknüpfung zum TimeEntry
- `severity`: Schweregrad (`error`, `warning`, `info`)
- `resolved`: Wurde der Verstoß behoben?
- `resolved_at`: Wann wurde er behoben?
- `resolved_by`: Wer hat ihn behoben?
- `created_at`: Wann wurde der Verstoß erkannt?

**Verstoß-Typen:**
- `missing_break`: Fehlende Pause (kritisch)
- `excessive_working_hours`: Überschreitung der maximalen Arbeitszeit (kritisch)
- `insufficient_rest_period`: Unzureichende Ruhezeit (kritisch)
- `weekly_hours_limit_exceeded`: Überschreitung der wöchentlichen Arbeitszeit (Warnung)
- `night_work`: Nachtarbeit (Info)
- `sunday_work`: Sonntagsarbeit (Warnung)
- `holiday_work`: Feiertagsarbeit (Warnung)

## Benachrichtigungen

**Empfänger:**
1. **Betroffener Benutzer:** Sofortige Benachrichtigung über Verstoß
2. **HR/Manager:** Benachrichtigung bei kritischen Verstößen
3. **Administrator:** Benachrichtigung bei systemweiten Problemen

**Inhalt:**
- Art des Verstoßes
- Datum und Uhrzeit
- Betroffener TimeEntry
- Empfohlene Maßnahmen
- Link zur Compliance-Übersicht

## Audit-Trail

**Jede Compliance-Prüfung wird protokolliert:**
- Wann wurde geprüft?
- Wer hat den TimeEntry erstellt/aktualisiert?
- Welche Verstöße wurden erkannt?
- Wurden Benachrichtigungen versendet?

**Zweck:**
- Nachweis der Compliance-Bemühungen
- Rechtliche Absicherung
- Analyse und Verbesserung

## Migration und Backward Compatibility

**Bestehende Daten:**
- Tägliche Batch-Prüfung läuft weiterhin
- Bestehende Verstöße bleiben erhalten
- Neue Verstöße werden zusätzlich erkannt

**Konfiguration:**
- Standard: Warning Mode (keine Breaking Changes)
- Strict Mode: Opt-in über Admin-Einstellungen
- Auto-Correction: Opt-in über Admin-Einstellungen

## Performance-Überlegungen

**Optimierungen:**
- Compliance-Prüfung läuft asynchron (Background-Job) bei hoher Last
- Caching von Compliance-Regeln
- Batch-Verarbeitung für historische Daten

**Monitoring:**
- Prüfungsdauer wird gemessen
- Fehlerrate wird überwacht
- Benachrichtigungsversand wird protokolliert

## Rechtliche Hinweise

**Wichtige Disclaimer:**
- Diese Implementierung unterstützt die Einhaltung des ArbZG
- Sie ersetzt **nicht** die rechtliche Beratung
- Unternehmen sollten ihre Compliance-Strategie mit Rechtsanwälten abstimmen
- Regionale Besonderheiten (z.B. Tarifverträge) müssen berücksichtigt werden

**Haftungsausschluss:**
- Die Software wird "wie besehen" bereitgestellt
- Keine Gewährleistung für vollständige Compliance
- Unternehmen sind selbst verantwortlich für die Einhaltung der Gesetze

## Weiterführende Informationen

- [Arbeitszeitgesetz (ArbZG)](https://www.gesetze-im-internet.de/arbzg/)
- [BAG-Urteil zur Zeiterfassungspflicht](https://www.bundesarbeitsgericht.de/)
- [Personio Compliance-Dokumentation](https://support.personio.de/hc/de/articles/115000671865)
- [IHK München: Arbeitszeit und Pausen](https://www.ihk-muenchen.de/)
