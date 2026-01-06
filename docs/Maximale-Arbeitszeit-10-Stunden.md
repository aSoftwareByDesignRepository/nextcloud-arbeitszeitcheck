# Maximale Arbeitszeit: 10-Stunden-Prüfung und Validierung

## Gesetzliche Grundlage

### ArbZG §3 - Tägliche Arbeitszeit

**Gesetzliche Anforderungen:**

1. **Regelarbeitszeit:** 8 Stunden pro Tag
2. **Verlängerung auf 10 Stunden:** Nur zulässig, wenn:
   - Der Durchschnitt über 6 Monate (oder 24 Wochen) 8 Stunden pro Tag nicht überschreitet
   - Die Verlängerung muss innerhalb von 6 Monaten ausgeglichen werden

**Wichtig:** Die 10-Stunden-Grenze bezieht sich auf die **reine Arbeitszeit (ohne Pausen)**, nicht auf die Gesamtdauer von Start bis Ende!

## Implementierung

### 1. Frontend-Validierung (Echtzeit)

**Wo:** In der Time-Entry-Form (`templates/time-entries.php`)

**Was wird geprüft:**
- Berechnet: `Arbeitszeit = (Ende - Start) - Pausenzeit`
- Prüft: `Arbeitszeit > 10 Stunden?`

**Verhalten:**

**Warning Mode (Standard):**
- ✅ Warnung wird angezeigt
- ✅ Speichern ist möglich
- ✅ Violation wird erstellt
- ✅ Benachrichtigung wird versendet

**Strict Mode (Optional):**
- ❌ Speichern wird **verhindert**
- ❌ Fehlermeldung wird angezeigt
- ❌ TimeEntry wird **nicht** gespeichert

**Code:**
```javascript
// Berechnet reine Arbeitszeit (ohne Pausen)
const workingDurationHours = totalDurationHours - breakDurationHours;

if (workingDurationHours > maxWorkingHours) {
    if (strictMode) {
        // Verhindert Speichern
        endTimeInput.setCustomValidity(errorMessage);
        return false;
    } else {
        // Zeigt Warnung, erlaubt Speichern
        OC.Notification.showTemporary(warningMessage, { type: 'warning' });
    }
}
```

### 2. Backend-Validierung

**Wo:** `TimeEntry::validate()` und `TimeEntryController`

**Was wird geprüft:**
- `getWorkingDurationHours()` - reine Arbeitszeit (ohne Pausen)
- Prüft: `Arbeitszeit > maxDailyHours` (Standard: 10 Stunden)

**Verhalten:**

**Warning Mode (Standard):**
- ✅ Validierung gibt Warnung zurück
- ✅ TimeEntry wird gespeichert
- ✅ Compliance-Prüfung erstellt Violation
- ✅ Log-Eintrag wird erstellt

**Strict Mode (Optional):**
- ❌ Validierung gibt Fehler zurück
- ❌ TimeEntry wird **nicht** gespeichert
- ❌ HTTP 400 Bad Request mit Fehlermeldung

**Code:**
```php
// In TimeEntry::validate()
$workingHours = $this->getWorkingDurationHours();
if ($workingHours !== null && $workingHours > 10) {
    $errors['workingHours'] = sprintf(
        'Working hours (excluding breaks) exceed the legal maximum of 10 hours per day (ArbZG §3). Current: %.2f hours',
        $workingHours
    );
}
```

### 3. Echtzeit-Compliance-Prüfung

**Wann:** Nach dem Speichern eines TimeEntry (wenn STATUS_COMPLETED)

**Was wird geprüft:**
- `getWorkingDurationHours()` - reine Arbeitszeit (ohne Pausen)
- Prüft: `Arbeitszeit > 10 Stunden?`

**Ergebnis:**
- ✅ Violation wird erstellt (`TYPE_EXCESSIVE_WORKING_HOURS`)
- ✅ Benachrichtigung wird versendet
- ✅ Wird im Compliance-Dashboard angezeigt

**Code:**
```php
// In ComplianceService::checkExcessiveWorkingHoursWithResult()
$workingDuration = $timeEntry->getWorkingDurationHours();
if ($workingDuration !== null && $workingDuration > 10) {
    // Erstellt Violation
    $violation = $this->violationMapper->createViolation(...);
    // Sendet Benachrichtigung
    $this->notificationService->notifyComplianceViolation(...);
}
```

### 4. Timer-Warnung (Dashboard)

**Wo:** Dashboard-Timer (`js/arbeitszeitcheck-main.js`)

**Was wird geprüft:**
- Aktuelle Session-Dauer (ohne Pausen)
- Prüft: `Arbeitszeit >= 8 Stunden?` (Warnung)
- Prüft: `Arbeitszeit >= 10 Stunden?` (Fehler)

**Verhalten:**

**Bei 8+ Stunden:**
- ⚠️ Timer wird gelb (Warning)
- ℹ️ Info-Benachrichtigung wird angezeigt
- 📝 Hinweis auf 6-Monats-Ausgleich

**Bei 10+ Stunden:**
- 🔴 Timer wird rot (Error)
- ⚠️ Warn-Benachrichtigung wird angezeigt (alle 1 Stunde)
- 📝 Hinweis auf ArbZG §3

**Code:**
```javascript
const workingHours = workingSeconds / 3600;
const maxWorkingHours = 10;

if (workingHours >= maxWorkingHours) {
    // Exceeded - show error
    timerEl.classList.add('timer-error');
    OC.Notification.showTemporary('Warning: Exceeded 10 hours...', { type: 'error' });
} else if (workingHours >= 8) {
    // Approaching - show warning
    timerEl.classList.add('timer-warning');
    OC.Notification.showTemporary('Note: Approaching maximum...', { type: 'info' });
}
```

## Konfiguration

### Admin-Einstellungen

**`max_daily_hours`** (Standard: `10`)
- Maximale tägliche Arbeitszeit in Stunden
- Bereich: 1-24 Stunden
- **Rechtliche Grundlage:** ArbZG §3

**`compliance_strict_mode`** (Standard: `0` = deaktiviert)
- `0` = Warning Mode: Warnung, aber Speichern möglich
- `1` = Strict Mode: Speichern wird verhindert

### Beispiel-Konfigurationen

**Standard (Warning Mode):**
```php
max_daily_hours = 10
compliance_strict_mode = 0
```
- Warnungen werden angezeigt
- Speichern ist möglich
- Violations werden erstellt

**Strict Mode:**
```php
max_daily_hours = 10
compliance_strict_mode = 1
```
- Speichern wird verhindert bei >10h
- Fehlermeldung wird angezeigt
- Violations werden erstellt

**Konservativ (8 Stunden Maximum):**
```php
max_daily_hours = 8
compliance_strict_mode = 1
```
- Verhindert jede Überschreitung von 8 Stunden
- Strengste Einstellung

## Wichtige Details

### Reine Arbeitszeit vs. Gesamtdauer

**Korrekt (reine Arbeitszeit):**
```
Start: 08:00
Ende: 19:00
Pause: 1 Stunde (12:00-13:00)
→ Arbeitszeit: 10 Stunden ✅ (genau am Limit)
```

**Falsch (Gesamtdauer):**
```
Start: 08:00
Ende: 19:00
Pause: 1 Stunde
→ Gesamtdauer: 11 Stunden
→ Arbeitszeit: 10 Stunden ✅ (korrekt berechnet)
```

**Das System prüft immer die reine Arbeitszeit (ohne Pausen)!**

### Berechnung

**Formel:**
```
Arbeitszeit = (Ende - Start) - Pausenzeit
```

**Beispiel:**
- Start: 08:00
- Ende: 19:00
- Pause: 1 Stunde (12:00-13:00)
- **Arbeitszeit:** 11 Stunden - 1 Stunde = **10 Stunden** ✅

### Mehrere Pausen

**Unterstützt:**
- Mehrere Pausen pro Tag
- Aufgeteilte Pausen (z.B. 2x 15 Min)
- Alle Pausen werden von der Arbeitszeit abgezogen

**Beispiel:**
- Start: 08:00
- Ende: 19:00
- Pause 1: 12:00-12:30 (30 Min)
- Pause 2: 15:00-15:15 (15 Min)
- **Arbeitszeit:** 11 Stunden - 0.75 Stunden = **10.25 Stunden** ❌ (überschreitet Limit)

## UI-Feedback

### Timer-Anzeige

**Normal (< 8 Stunden):**
- 🟢 Grüner Timer
- Keine Warnung

**Warnung (8-10 Stunden):**
- 🟡 Gelber Timer (pulsierend)
- Info-Benachrichtigung
- Hinweis auf 6-Monats-Ausgleich

**Fehler (> 10 Stunden):**
- 🔴 Roter Timer (pulsierend)
- Warn-Benachrichtigung (alle 1 Stunde)
- Hinweis auf ArbZG §3

### Formular-Validierung

**Warning Mode:**
- ⚠️ Warnung wird angezeigt
- ✅ Speichern-Button bleibt aktiv
- ℹ️ Hinweis auf Compliance-Verstoß

**Strict Mode:**
- ❌ Fehlermeldung wird angezeigt
- ❌ Speichern-Button wird deaktiviert
- 🚫 Speichern wird verhindert

## Compliance-Violations

**Bei Verstoß wird erstellt:**
- **Typ:** `excessive_working_hours`
- **Schweregrad:** `error` (kritisch)
- **Beschreibung:** "Working hours exceeded 10 hours in a single day"
- **Verknüpfung:** Mit TimeEntry (inkl. Pausenzeiten)

**Nachweisbarkeit:**
- ✅ Violation wird in Datenbank gespeichert
- ✅ Verknüpft mit TimeEntry
- ✅ Zeitstempel und Details werden protokolliert
- ✅ Benachrichtigung wird versendet

## Rechtliche Konsequenzen

**Bei Verstößen:**
- Ordnungswidrigkeit nach ArbZG §22
- Bußgeld bis zu 15.000 € für den Arbeitgeber
- Haftungsrisiko bei Arbeitsunfällen
- Mögliche Schadensersatzansprüche

**Wichtig:** Das System unterstützt die Einhaltung, ersetzt aber nicht die rechtliche Beratung!

## Best Practices

### Für Administratoren

1. **Konfiguration prüfen:**
   - `max_daily_hours` auf 10 setzen (ArbZG-konform)
   - `compliance_strict_mode` je nach Unternehmenspolitik

2. **Monitoring:**
   - Compliance-Dashboard regelmäßig prüfen
   - Violations analysieren
   - Trends erkennen

3. **Schulung:**
   - Mitarbeiter über 10-Stunden-Grenze informieren
   - 6-Monats-Ausgleich erklären
   - Compliance-Verstöße besprechen

### Für Benutzer

1. **Timer beobachten:**
   - Bei 8+ Stunden: Warnung beachten
   - Bei 10+ Stunden: Sofort clock out
   - Pausen nicht vergessen

2. **Bei Verstößen:**
   - Begründung angeben (wenn möglich)
   - Mit Manager besprechen
   - Ausgleich planen

## Technische Details

### Prüfpunkte

1. ✅ **Frontend-Validierung** (beim Eingeben)
2. ✅ **Backend-Validierung** (beim Speichern)
3. ✅ **Echtzeit-Compliance-Prüfung** (nach Speichern)
4. ✅ **Timer-Warnung** (während Arbeit)
5. ✅ **Tägliche Batch-Prüfung** (Backup)

### Berechnungsmethode

**Verwendet:**
- `TimeEntry::getWorkingDurationHours()`
- Berechnet: `(endTime - startTime) - breakDuration`
- Berücksichtigt: Alle Pausen (JSON + aktuelle Pause)

**Korrekt:**
- ✅ Prüft reine Arbeitszeit (ohne Pausen)
- ✅ Berücksichtigt mehrere Pausen
- ✅ Korrekte Zeitberechnung

## Häufige Fragen

**Q: Wird die 10-Stunden-Grenze auf die Gesamtdauer oder Arbeitszeit angewendet?**  
A: ✅ Auf die **reine Arbeitszeit (ohne Pausen)** - das ist korrekt nach ArbZG §3.

**Q: Kann ich mehr als 10 Stunden arbeiten?**  
A: ⚠️ Nur in Ausnahmefällen, wenn der 6-Monats-Durchschnitt 8 Stunden nicht überschreitet. Das System warnt/verhindert dies standardmäßig.

**Q: Was passiert, wenn ich mehr als 10 Stunden eingebe?**  
A: 
- **Warning Mode:** Warnung, aber Speichern möglich → Violation wird erstellt
- **Strict Mode:** Speichern wird verhindert → Fehlermeldung

**Q: Gibt es eine Warnung im Timer?**  
A: ✅ Ja! Bei 8+ Stunden (Warnung) und 10+ Stunden (Fehler).

**Q: Werden Verstöße geloggt?**  
A: ✅ Ja, vollständig! In Compliance-Violations, Audit-Log und Benachrichtigungen.

---

**Stand:** Version 1.1.0  
**Letzte Aktualisierung:** 2025-01-XX
