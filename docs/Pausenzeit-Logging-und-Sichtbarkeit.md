# Pausenzeit-Logging und Sichtbarkeit

## Übersicht

**Ja, Pausenzeiten werden vollständig geloggt und sind sichtbar!** Dies ist wichtig für die Compliance mit dem deutschen Arbeitszeitgesetz (ArbZG) und die Nachweisbarkeit.

## 1. Datenbank-Speicherung

### Gespeicherte Felder

**In der Tabelle `at_entries` (TimeEntry):**

1. **`breakStartTime`** (DateTime, nullable)
   - Startzeit der aktuellen/letzten Pause
   - Format: `YYYY-MM-DD HH:MM:SS`

2. **`breakEndTime`** (DateTime, nullable)
   - Endzeit der aktuellen/letzten Pause
   - Format: `YYYY-MM-DD HH:MM:SS`

3. **`breaks`** (TEXT/JSON, nullable)
   - JSON-Array für mehrere Pausen pro Tag
   - Format: `[{"start": "2025-01-15T12:00:00+00:00", "end": "2025-01-15T12:30:00+00:00"}, ...]`
   - Wichtig für Compliance: Ermöglicht Nachweis von aufgeteilten Pausen (z.B. 2x 15 Min = 30 Min)

### Berechnete Werte

**Methoden in `TimeEntry`:**

- `getBreakDurationHours(): float`
  - Berechnet die Gesamtpausenzeit in Stunden
  - Summiert alle Pausen aus `breaks` (JSON) + aktuelle Pause (`breakStartTime`/`breakEndTime`)

- `getWorkingDurationHours(): ?float`
  - Berechnet die reine Arbeitszeit (ohne Pausen)
  - Formel: `(endTime - startTime) - breakDuration`

## 2. Audit-Log (Vollständige Protokollierung)

### Was wird geloggt?

**Bei jedem TimeEntry-Event wird ein Audit-Log-Eintrag erstellt mit:**

- **`oldValues`** (JSON): Zustand vor der Änderung
- **`newValues`** (JSON): Zustand nach der Änderung

**Enthaltene Pausenzeiten-Informationen:**

```json
{
  "breakStartTime": "2025-01-15T12:00:00+00:00",
  "breakEndTime": "2025-01-15T12:30:00+00:00",
  "breaks": [
    {"start": "2025-01-15T12:00:00+00:00", "end": "2025-01-15T12:15:00+00:00"},
    {"start": "2025-01-15T12:15:00+00:00", "end": "2025-01-15T12:30:00+00:00"}
  ],
  "breakDurationHours": 0.5,
  "workingDurationHours": 7.5,
  "durationHours": 8.0
}
```

### Geloggte Aktionen

**Pausenbezogene Events:**

1. **`start_break`**
   - Wann: Beim Start einer Pause
   - Enthält: `breakStartTime` in `newValues`

2. **`end_break`**
   - Wann: Beim Ende einer Pause
   - Enthält: `breakEndTime` und `breakDurationHours` in `newValues`

3. **`time_entry_created`**
   - Wann: Beim Erstellen eines TimeEntry
   - Enthält: Alle Pausenzeiten in `newValues`

4. **`time_entry_updated`**
   - Wann: Beim Aktualisieren eines TimeEntry
   - Enthält: `oldValues` (vorher) und `newValues` (nachher) mit Pausenzeiten

5. **`time_entry_correction_approved`**
   - Wann: Beim Genehmigen durch Manager
   - Enthält: Pausenzeiten in `newValues`

### Compliance-Violations

**Bei Verstößen gegen Pausenpflicht:**

- **`missing_break`** Violation wird erstellt
- Enthält: `time_entry_id`, `date`, `description`
- Verknüpft mit dem TimeEntry (inkl. Pausenzeiten)

## 3. UI-Sichtbarkeit

### Dashboard

**Aktuelle Pause (wenn aktiv):**
- Zeigt: Pausentimer in Echtzeit
- Format: `HH:MM:SS`
- Zeigt: Startzeit der Pause

**Letzte Einträge (Tabelle):**
- Spalte: **"Break"**
- Zeigt: `breakDurationHours` in Stunden (z.B. "0.5 h")
- Format: Gerundet auf 2 Dezimalstellen

### Time Entries Seite

**Haupttabelle:**
- Spalte: **"Break"**
- Zeigt: `breakDurationHours` für jeden Eintrag
- Format: Stunden (z.B. "0.5 h")

**Zusätzliche Spalten:**
- **"Duration"**: Gesamtdauer (Start bis Ende)
- **"Working"**: Reine Arbeitszeit (ohne Pausen)

### Timeline

**Zeitstrahl-Ansicht:**
- Zeigt: Pausenzeiten in der Beschreibung
- Format: `"8h 0m (Break: 0h 30m)"`
- JavaScript rendert: `breakDurationHours` aus API-Response

### Compliance Dashboard

**Verstöße-Ansicht:**
- Zeigt: Verstöße gegen Pausenpflicht
- Verknüpft: Mit TimeEntry (inkl. Pausenzeiten)
- Details: Können angeklickt werden für vollständige Informationen

## 4. API-Responses

### TimeEntry Summary

**Jeder TimeEntry enthält in API-Responses:**

```json
{
  "id": 123,
  "startTime": "2025-01-15T08:00:00+00:00",
  "endTime": "2025-01-15T17:00:00+00:00",
  "breakStartTime": "2025-01-15T12:00:00+00:00",
  "breakEndTime": "2025-01-15T12:30:00+00:00",
  "breaks": [
    {"start": "2025-01-15T12:00:00+00:00", "end": "2025-01-15T12:30:00+00:00"}
  ],
  "breakDurationHours": 0.5,
  "workingDurationHours": 8.5,
  "durationHours": 9.0
}
```

**Wichtig:** Seit Version 1.1.0 ist auch `breaks` (JSON) im Summary enthalten!

## 5. Export-Funktionen

### CSV/PDF Export

**Enthaltene Pausenzeiten:**

- Spalte: "Break Duration" (in Stunden)
- Spalte: "Working Duration" (in Stunden)
- Spalte: "Total Duration" (in Stunden)

### Compliance Reports

**Enthaltene Informationen:**

- Pausenzeiten pro Tag
- Verstöße gegen Pausenpflicht
- Verknüpfung mit TimeEntries (inkl. Pausenzeiten)

## 6. Rechtliche Relevanz

### ArbZG §4 - Pausenpflicht

**Nachweisbarkeit:**

✅ **Pausenzeiten werden vollständig dokumentiert:**
- Start- und Endzeiten jeder Pause
- Gesamte Pausendauer
- Aufgeteilte Pausen (wenn mehrere)

✅ **Audit-Trail:**
- Jede Änderung wird protokolliert
- Vorher/Nachher-Vergleich möglich
- Zeitstempel für alle Aktionen

✅ **Compliance-Verstöße:**
- Werden sofort erkannt und dokumentiert
- Verknüpft mit TimeEntry (inkl. Pausenzeiten)
- Nachweisbar für Behörden

### Aufbewahrungspflicht

**ArbZG §16 Abs. 2:**
- Aufzeichnungen müssen 2 Jahre aufbewahrt werden
- ✅ Pausenzeiten sind Teil der Aufzeichnungen
- ✅ Audit-Logs werden ebenfalls 2 Jahre aufbewahrt

## 7. Technische Details

### Datenbank-Schema

```sql
CREATE TABLE at_entries (
  id INT PRIMARY KEY,
  user_id VARCHAR(255),
  start_time DATETIME,
  end_time DATETIME,
  break_start_time DATETIME NULL,
  break_end_time DATETIME NULL,
  breaks TEXT NULL,  -- JSON array
  ...
);
```

### Code-Referenzen

**Speicherung:**
- `lib/Db/TimeEntry.php`: Entity mit Pausenzeiten-Feldern
- `lib/Db/TimeEntryMapper.php`: Datenbank-Zugriff

**Berechnung:**
- `TimeEntry::getBreakDurationHours()`: Summiert alle Pausen
- `TimeEntry::getWorkingDurationHours()`: Berechnet Arbeitszeit ohne Pausen

**Logging:**
- `lib/Db/AuditLogMapper.php`: Speichert oldValues/newValues
- `TimeEntry::getSummary()`: Enthält alle Pausenzeiten-Informationen

**UI-Anzeige:**
- `templates/dashboard.php`: Zeigt Pausenzeiten in Tabelle
- `templates/time-entries.php`: Zeigt Pausenzeiten in Tabelle
- `js/arbeitszeitcheck-main.js`: Rendert Pausenzeiten in Timeline

## 8. Verbesserungen in Version 1.1.0

### Was wurde verbessert?

1. **`breaks` (JSON) im Summary:**
   - ✅ Jetzt im `getSummary()` enthalten
   - ✅ Wird im Audit-Log gespeichert
   - ✅ Ermöglicht Nachweis von aufgeteilten Pausen

2. **Echtzeit-Compliance-Prüfung:**
   - ✅ Pausenzeiten werden sofort geprüft
   - ✅ Verstöße werden sofort dokumentiert
   - ✅ Verknüpfung mit TimeEntry (inkl. Pausenzeiten)

## 9. Best Practices

### Für Administratoren

1. **Regelmäßige Prüfung:**
   - Audit-Logs regelmäßig prüfen
   - Compliance-Verstöße überwachen
   - Pausenzeiten in Reports analysieren

2. **Backup:**
   - Audit-Logs regelmäßig exportieren
   - Datenbank-Backups inkl. Pausenzeiten
   - 2-Jahres-Aufbewahrung sicherstellen

### Für Benutzer

1. **Korrekte Erfassung:**
   - Pausen immer erfassen
   - Aufgeteilte Pausen dokumentieren
   - Bei Verstößen Begründung angeben

## 10. Häufige Fragen

**Q: Werden Pausenzeiten wirklich geloggt?**  
A: ✅ Ja, vollständig! In Datenbank, Audit-Log und Compliance-Violations.

**Q: Sind Pausenzeiten in der UI sichtbar?**  
A: ✅ Ja, in Dashboard, Time Entries, Timeline und Compliance-Dashboard.

**Q: Werden aufgeteilte Pausen erfasst?**  
A: ✅ Ja, über das `breaks` JSON-Feld (mehrere Pausen pro Tag).

**Q: Kann ich Pausenzeiten nachträglich ändern?**  
A: ✅ Ja, aber alle Änderungen werden im Audit-Log protokolliert.

**Q: Werden Pausenzeiten exportiert?**  
A: ✅ Ja, in CSV/PDF-Exports und Compliance-Reports.

---

**Stand:** Version 1.1.0  
**Letzte Aktualisierung:** 2025-01-XX
