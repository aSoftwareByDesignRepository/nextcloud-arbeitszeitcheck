# Timer- und Resume-Verhalten: Vollständige Dokumentation

## Übersicht

Dieses Dokument beschreibt das vollständige Verhalten des Timers und des Resume-Mechanismus (Ausstempeln und Wiedereinstempeln) in der ArbeitszeitCheck-App.

## Timer-Verhalten

### Timer startet nur bei eingestempeltem Status
- ✅ Timer startet nur, wenn Status `'active'` oder `'break'` ist
- ✅ Timer startet nicht bei `'clocked_out'` oder `'paused'`
- ✅ Implementiert in: `arbeitszeitcheck-main.js` (Zeile 68)

### Timer stoppt automatisch
- ✅ **Beim Ausstempeln:** Timer stoppt sofort in `clockOut()` Funktion
- ✅ **Status-Änderung:** Timer stoppt, wenn Status auf `'clocked_out'` oder `'paused'` wechselt
- ✅ **Status-Check:** Alle 5 Sekunden wird Status vom Backend geprüft
- ✅ **Doppelte Prüfung:** Timer prüft auch im Loop, ob Benutzer noch eingestempelt ist
- ✅ Implementiert in: `arbeitszeitcheck-main.js` (Zeile 116-157, 685-695)

### Timer zeigt korrekte Zeit
- ✅ **Nach Resume:** Timer zeigt nur aktive Arbeitszeit (Pause-Zeit wird abgezogen)
- ✅ **Pause-Zeit:** Wird automatisch in breaks JSON gespeichert beim Resume
- ✅ **Berechnung:** `current_session_duration` vom Backend berücksichtigt alle Pausen
- ✅ Implementiert in: `TimeTrackingService::getStatus()` und `clockIn()` (Resume)

## Resume-Verhalten (Ausstempeln und Wiedereinstempeln)

### Gleicher Tag - Resume erlaubt
- ✅ **Keine 11h Ruhezeit erforderlich:** Am gleichen Tag ist Resume ohne Ruhezeit erlaubt
- ✅ **Arbeitsunterbrechung:** Wird als Arbeitsunterbrechung behandelt, nicht als neue Schicht
- ✅ **Max. Stunden-Prüfung:** Resume wird verhindert, wenn 10h überschritten würden
- ✅ Implementiert in: `TimeTrackingService::clockIn()` (Zeile 77-136)

### Pause-Zeit wird automatisch erfasst
- ✅ **Beim Resume:** Pause-Zeit (zwischen Clock-Out und Clock-In) wird in breaks JSON gespeichert
- ✅ **Automatische Markierung:** Wird als `automatic: true` mit Reason markiert
- ✅ **Von Arbeitszeit abgezogen:** Pause-Zeit wird automatisch von Session-Dauer abgezogen
- ✅ Implementiert in: `TimeTrackingService::clockIn()` (Zeile 138-160)

### Verschiedene Tage - 11h Ruhezeit erforderlich
- ✅ **Ruhezeit-Prüfung:** Zwischen verschiedenen Tagen ist 11h Ruhezeit erforderlich (ArbZG §5)
- ✅ **Verhindert Resume:** Resume wird verhindert, wenn < 11h Ruhezeit
- ✅ **Detaillierte Fehlermeldung:** Zeigt letztes Schichtende und frühestmöglichen Start
- ✅ Implementiert in: `TimeTrackingService::clockIn()` (Zeile 116-136)

## Edge Cases abgedeckt

### 1. Ausstempeln und sofortiges Wiedereinstempeln
- ✅ **Gleicher Tag:** Erlaubt ohne 11h Ruhezeit
- ✅ **Pause wird erfasst:** Zeit zwischen Clock-Out und Clock-In wird als Pause gespeichert
- ✅ **Timer korrekt:** Zeigt nur aktive Arbeitszeit

### 2. Mehrere Resume-Perioden
- ✅ **Alle Pausen erfasst:** Jede Clock-Out-Period wird in breaks JSON gespeichert
- ✅ **Korrekte Berechnung:** Alle Pausen werden von Gesamtarbeitszeit abgezogen

### 3. Timer nach Resume
- ✅ **Korrekte Anzeige:** Timer zeigt nur aktive Arbeitszeit (ohne Pausen)
- ✅ **Synchronisation:** Timer synchronisiert sich alle 5 Sekunden mit Backend
- ✅ **Status-Updates:** Timer reagiert sofort auf Status-Änderungen

### 4. Maximale Arbeitszeit
- ✅ **Prüfung vor Resume:** Resume wird verhindert, wenn 10h überschritten würden
- ✅ **Korrekte Berechnung:** Berücksichtigt bereits geleistete Stunden + Resume-Stunden

## Implementierungsdetails

### Backend: Resume-Logik
**Datei:** `lib/Service/TimeTrackingService.php`
**Methode:** `clockIn()` (Zeile 70-193)

**Schritte:**
1. Prüft, ob pausierter Eintrag für heute existiert
2. Prüft, ob gleicher Tag (keine 11h Ruhezeit erforderlich)
3. Berechnet Gesamtarbeitszeit (completed + paused entry)
4. Prüft, ob 10h überschritten würden
5. Prüft Ruhezeit (nur bei verschiedenen Tagen)
6. Speichert Pause-Zeit in breaks JSON
7. Setzt Status auf ACTIVE
8. Aktualisiert updatedAt

### Backend: Status-Berechnung
**Datei:** `lib/Service/TimeTrackingService.php`
**Methode:** `getStatus()` (Zeile 395-466)

**Berechnung:**
1. Session-Dauer = jetzt - startTime
2. Subtrahiert alle Pausen (inkl. Clock-Out-Perioden) aus breaks JSON
3. Subtrahiert aktive Pause (wenn vorhanden)
4. Gibt `current_session_duration` zurück (nur aktive Arbeitszeit)

### Frontend: Timer-Logik
**Datei:** `js/arbeitszeitcheck-main.js`
**Methode:** `initTimer()` (Zeile 55-260)

**Verhalten:**
1. Startet nur bei `'active'` oder `'break'` Status
2. Verwendet `current_session_duration` vom Backend als Basis
3. Prüft alle 5 Sekunden Status vom Backend
4. Stoppt sofort, wenn Status auf `'clocked_out'` oder `'paused'` wechselt
5. Stoppt automatisch bei 10h (automatisches Clock-Out)

## Rechtliche Korrektheit

### ArbZG §3 - Maximale Arbeitszeit
- ✅ **Korrekt:** Prüft reine Arbeitszeit (ohne Pausen)
- ✅ **Korrekt:** 10 Stunden Maximum
- ✅ **Korrekt:** Resume wird verhindert, wenn 10h überschritten würden

### ArbZG §5 - Ruhezeiten
- ✅ **Korrekt:** 11 Stunden zwischen verschiedenen Tagen
- ✅ **Korrekt:** Gleicher Tag = Arbeitsunterbrechung (keine Ruhezeit erforderlich)
- ✅ **Korrekt:** Resume wird verhindert bei < 11h Ruhezeit (verschiedene Tage)

## Alle Oberflächen abgedeckt

### ✅ Dashboard Timer
- Timer startet/stoppt korrekt
- Zeigt korrekte Zeit nach Resume
- Synchronisiert sich mit Backend

### ✅ Clock-In/Clock-Out
- Resume-Logik vollständig implementiert
- Pause-Zeit wird automatisch erfasst
- Compliance-Prüfungen vollständig

### ✅ Manuelle Eingaben
- Ruhezeit-Prüfung implementiert
- Gleicher Tag wird korrekt behandelt

### ✅ API-Endpunkte
- Alle Endpunkte verwenden gleiche Logik
- Konsistente Fehlermeldungen

## Zusammenfassung

**Alle Features sind vollständig implementiert:**

1. ✅ **Timer stoppt beim Ausstempeln** - Sofort und zuverlässig
2. ✅ **Timer zeigt korrekte Zeit nach Resume** - Pause-Zeit wird abgezogen
3. ✅ **Resume am gleichen Tag erlaubt** - Keine 11h Ruhezeit erforderlich
4. ✅ **Pause-Zeit wird automatisch erfasst** - In breaks JSON gespeichert
5. ✅ **Max. Stunden-Prüfung** - Resume wird verhindert bei Überschreitung
6. ✅ **Ruhezeit nur zwischen Tagen** - ArbZG §5 korrekt umgesetzt
7. ✅ **Alle Edge Cases abgedeckt** - Vollständige Abdeckung aller Szenarien
8. ✅ **Konsistenz über alle Oberflächen** - Einheitliches Verhalten

---

**Stand:** 2025-01-XX  
**Status:** ✅ Vollständig implementiert und dokumentiert
