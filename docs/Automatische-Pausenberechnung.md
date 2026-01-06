# Automatische Pausenberechnung (ArbZG §4)

## Übersicht

Wenn keine Pause manuell eingegeben wird, berechnet das System automatisch die gesetzlich notwendige Pausenzeit (ArbZG §4) und trägt sie ein. Die Pause wird von der Arbeitszeit abgezogen.

## Gesetzliche Grundlage

**ArbZG §4 - Ruhepausen:**

- **6-9 Stunden Arbeitszeit:** 30 Minuten Pause erforderlich
- **9+ Stunden Arbeitszeit:** 45 Minuten Pause erforderlich
- **< 6 Stunden Arbeitszeit:** Keine Pause erforderlich

**Wichtig:** Die Pause wird von der **reinen Arbeitszeit** abgezogen, nicht von der Gesamtdauer!

## Funktionsweise

### Automatische Berechnung

**Wann wird die automatische Pause berechnet?**
- Beim Erstellen eines TimeEntry (wenn keine Pause eingegeben wurde)
- Beim Aktualisieren eines TimeEntry (wenn keine Pause eingegeben wurde)
- Nur für abgeschlossene Einträge (mit Start- und Endzeit)

**Wie wird die Pause platziert?**
- Die Pause wird **in der Mitte der Arbeitszeit** platziert
- Beispiel: 8:00-17:00 (9 Stunden) → Pause: 12:15-12:45 (30 Min)

**Berechnung:**
```
Gesamtdauer = Ende - Start
Erforderliche Pause = calculateRequiredBreakMinutes(Gesamtdauer)
Pausenstart = Start + (Gesamtdauer - Pausendauer) / 2
Pausenende = Pausenstart + Pausendauer
```

### Beispiel

**Eingabe:**
- Start: 08:00
- Ende: 17:00
- Pause: (nicht eingegeben)

**Automatische Berechnung:**
- Gesamtdauer: 9 Stunden
- Erforderliche Pause: 30 Minuten (6-9 Stunden)
- Pausenstart: 12:15 (Mitte der Arbeitszeit)
- Pausenende: 12:45

**Ergebnis:**
- Arbeitszeit: 8.5 Stunden (9 Stunden - 0.5 Stunden Pause)
- Pause: 12:15-12:45 (automatisch generiert)

### Markierung

Die automatische Pause wird als solche markiert:
```json
{
  "start": "2025-01-15T12:15:00+01:00",
  "end": "2025-01-15T12:45:00+01:00",
  "duration_minutes": 30,
  "automatic": true,
  "reason": "Automatically added: Legal break requirement (ArbZG §4)"
}
```

## Implementierung

### Backend

**Methode:** `TimeTrackingService::calculateAndSetAutomaticBreak(TimeEntry $timeEntry)`

**Logik:**
1. Prüft, ob bereits eine Pause eingegeben wurde
2. Berechnet Gesamtdauer (Ende - Start)
3. Prüft, ob Pause gesetzlich erforderlich ist
4. Platziert Pause in der Mitte der Arbeitszeit
5. Speichert Pause in `breaks` JSON-Array
6. Markiert Pause als automatisch generiert

**Aufruf:**
- `TimeEntryController::store()` - Beim Erstellen
- `TimeEntryController::update()` - Beim Aktualisieren
- `TimeEntryController::apiStore()` - Beim API-Erstellen

### Frontend

**Keine Änderungen erforderlich:**
- Die automatische Pause wird im Backend berechnet
- Benutzer sehen die korrekte Arbeitszeit (ohne Pause)
- Die automatische Pause wird in der UI angezeigt (wenn Pausen sichtbar sind)

## Arbeitszeitberechnung

### Korrekte Berechnung

**Formel:**
```
Arbeitszeit = (Ende - Start) - Pausenzeit
```

**Beispiel:**
- Start: 08:00
- Ende: 17:00
- Automatische Pause: 12:15-12:45 (30 Min)
- **Arbeitszeit:** 9 Stunden - 0.5 Stunden = **8.5 Stunden** ✅

### Berücksichtigung

Die automatische Pause wird korrekt berücksichtigt in:
- ✅ `getDurationHours()` - Reine Arbeitszeit (ohne Pausen)
- ✅ `getWorkingDurationHours()` - Reine Arbeitszeit (ohne Pausen)
- ✅ `getBreakDurationHours()` - Gesamte Pausenzeit
- ✅ Compliance-Prüfungen (10-Stunden-Grenze)
- ✅ Pausenprüfungen (30/45 Minuten)

## Verhalten

### Wenn Pause eingegeben wurde

**Eingabe:**
- Start: 08:00
- Ende: 17:00
- Pause: 12:00-13:00 (manuell eingegeben)

**Ergebnis:**
- ✅ Keine automatische Pause wird hinzugefügt
- ✅ Manuelle Pause wird verwendet
- ✅ Arbeitszeit: 8 Stunden (9 Stunden - 1 Stunde Pause)

### Wenn keine Pause eingegeben wurde

**Eingabe:**
- Start: 08:00
- Ende: 17:00
- Pause: (nicht eingegeben)

**Ergebnis:**
- ✅ Automatische Pause wird hinzugefügt (12:15-12:45)
- ✅ Arbeitszeit: 8.5 Stunden (9 Stunden - 0.5 Stunden Pause)
- ✅ Pause wird als automatisch markiert

### Wenn Pause nicht erforderlich ist

**Eingabe:**
- Start: 08:00
- Ende: 13:00 (5 Stunden)
- Pause: (nicht eingegeben)

**Ergebnis:**
- ✅ Keine automatische Pause wird hinzugefügt (< 6 Stunden)
- ✅ Arbeitszeit: 5 Stunden

## Logging

**Automatische Pause wird geloggt:**
```php
\OCP\Log\logger('arbeitszeitcheck')->info('Automatic break added to time entry', [
    'time_entry_id' => $timeEntry->getId(),
    'user_id' => $timeEntry->getUserId(),
    'total_duration_hours' => 9.0,
    'required_break_minutes' => 30,
    'break_start' => '2025-01-15T12:15:00+01:00',
    'break_end' => '2025-01-15T12:45:00+01:00'
]);
```

## UI-Anzeige

### Time Entry Liste

Die automatische Pause wird angezeigt:
- **Pausenzeit:** 30 Min (automatisch)
- **Arbeitszeit:** 8.5 Stunden (korrekt berechnet)

### Time Entry Details

Die automatische Pause wird detailliert angezeigt:
- **Pause:** 12:15-12:45
- **Dauer:** 30 Minuten
- **Typ:** Automatisch (ArbZG §4)

### Dashboard

Die automatische Pause wird berücksichtigt:
- **Arbeitszeit:** Korrekt (ohne Pause)
- **Pausenzeit:** Wird angezeigt (wenn sichtbar)

## Compliance

### ArbZG §4 Konformität

**Automatische Pause:**
- ✅ Erfüllt gesetzliche Anforderungen (30/45 Min)
- ✅ Wird korrekt von Arbeitszeit abgezogen
- ✅ Wird in Compliance-Prüfungen berücksichtigt
- ✅ Wird in Audit-Log protokolliert

### Compliance-Prüfungen

**10-Stunden-Grenze:**
- Prüft: Arbeitszeit (ohne Pause) > 10 Stunden?
- Beispiel: 8:00-19:00 mit 1h Pause = 10h Arbeitszeit ✅

**Pausenprüfung:**
- Prüft: Pause ≥ 30 Min (6-9h) oder ≥ 45 Min (9h+)?
- Automatische Pause erfüllt diese Anforderungen ✅

## Häufige Fragen

**Q: Wird die automatische Pause immer hinzugefügt?**  
A: ✅ Nur wenn keine Pause manuell eingegeben wurde und Pause gesetzlich erforderlich ist (≥6 Stunden).

**Q: Wo wird die automatische Pause platziert?**  
A: ✅ In der Mitte der Arbeitszeit (z.B. bei 8-17 Uhr: 12:15-12:45).

**Q: Kann ich die automatische Pause bearbeiten?**  
A: ✅ Ja, durch manuelles Eingeben einer Pause wird die automatische Pause überschrieben.

**Q: Wird die automatische Pause von der Arbeitszeit abgezogen?**  
A: ✅ Ja, korrekt! Arbeitszeit = (Ende - Start) - Pausenzeit.

**Q: Wie wird die automatische Pause markiert?**  
A: ✅ Im `breaks` JSON-Array mit `"automatic": true` und `"reason"`.

**Q: Was passiert bei mehreren Pausen?**  
A: ✅ Wenn manuelle Pausen eingegeben werden, wird keine automatische Pause hinzugefügt.

## Technische Details

### Datenstruktur

**Automatische Pause in breaks JSON:**
```json
[
  {
    "start": "2025-01-15T12:15:00+01:00",
    "end": "2025-01-15T12:45:00+01:00",
    "duration_minutes": 30,
    "automatic": true,
    "reason": "Automatically added: Legal break requirement (ArbZG §4)"
  }
]
```

### Berechnungsmethode

**Pausenplatzierung:**
```php
$totalDurationSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();
$breakDurationSeconds = $requiredBreakMinutes * 60;
$breakStartOffset = ($totalDurationSeconds - $breakDurationSeconds) / 2;
$breakStartTime = clone $startTime;
$breakStartTime->modify('+' . round($breakStartOffset) . ' seconds');
```

### Prüfungen

**Wird automatische Pause hinzugefügt?**
1. ✅ TimeEntry hat Start- und Endzeit
2. ✅ Keine manuelle Pause eingegeben (weder breakStartTime noch breaks JSON)
3. ✅ Gesamtdauer ≥ 6 Stunden (Pause erforderlich)

## Best Practices

### Für Benutzer

1. **Pause manuell eingeben:**
   - Wenn Pause zu bestimmter Zeit genommen wurde
   - Wenn mehrere Pausen genommen wurden
   - Wenn Pause länger als gesetzlich erforderlich war

2. **Automatische Pause nutzen:**
   - Wenn keine Pause genommen wurde
   - Wenn Pause vergessen wurde einzutragen
   - Wenn nur Start- und Endzeit bekannt sind

### Für Administratoren

1. **Monitoring:**
   - Prüfen, wie oft automatische Pausen hinzugefügt werden
   - Analysieren, ob Benutzer Pausen regelmäßig vergessen

2. **Schulung:**
   - Benutzer über automatische Pause informieren
   - Erklären, dass Pause von Arbeitszeit abgezogen wird
   - Hinweis auf ArbZG §4 Anforderungen

---

**Stand:** Version 1.1.0  
**Letzte Aktualisierung:** 2025-01-XX
