# Benutzerhandbuch – ArbeitszeitCheck (TimeGuard)

**Version:** 1.0.0  
**Letzte Aktualisierung:** 2025-12-29

## Inhaltsverzeichnis

1. [Einführung](#einführung)
2. [Erste Schritte](#erste-schritte)
3. [Tägliche Zeiterfassung](#tägliche-zeiterfassung)
4. [Zeiteinträge verwalten](#zeiteinträge-verwalten)
5. [Abwesenheitsverwaltung](#abwesenheitsverwaltung)
6. [Dashboard und Übersicht](#dashboard-und-übersicht)
7. [Compliance und Verstöße](#compliance-und-verstöße)
8. [Daten exportieren](#daten-exportieren)
9. [Einstellungen](#einstellungen)
10. [Fehlerbehebung](#fehlerbehebung)
11. [Tastenkürzel](#tastenkürzel)

---

## Einführung

**ArbeitszeitCheck (TimeGuard)** ist ein rechtskonformes Zeiterfassungssystem für deutsche Organisationen. Es hilft Ihnen, Ihre Arbeitszeiten zu erfassen und gleichzeitig die Einhaltung des deutschen Arbeitszeitgesetzes (ArbZG) sicherzustellen.

### Was dieses System tut

- Erfasst Ihre tägliche Arbeitszeit (Beginn, Ende, Pausen)
- Stellt die Einhaltung gesetzlicher Vorgaben sicher (Höchstarbeitszeit, Pausen, Ruhezeiten)
- Verwaltet Ihre Urlaubs- und Abwesenheitsanträge
- Bietet Transparenz über Ihre Arbeitszeitdaten
- Unterstützt Ihre Rechte nach der DSGVO (Export, Berichtigung, Löschung)

### Was dieses System NICHT tut

- **Keine Überwachung**: Das System erfasst nur gesetzlich erforderliche Zeitdaten, keine detaillierte Aktivitätsüberwachung
- **Keine Leistungsbewertung**: Zeitdaten dienen der Compliance und Entgeltabrechnung, nicht der individuellen Leistungsbewertung
- **Keine Standortverfolgung**: Kein GPS-Tracking, es sei denn, es wird explizit mit Ihrer Einwilligung aktiviert

---

## Erste Schritte

### Erste Anmeldung

1. Melden Sie sich in Ihrer Nextcloud-Instanz an
2. Klicken Sie auf **ArbeitszeitCheck** im App-Menü
3. Schließen Sie die **Einführungstour** ab (erscheint automatisch bei erstem Gebrauch)
4. Überprüfen Sie Ihre **persönlichen Einstellungen** (Urlaubsanspruch, Arbeitsstunden)

### Ihr Dashboard verstehen

Das Hauptdashboard zeigt:

- **Aktueller Status**: Ob Sie aktuell eingestempelt sind
- **Tageszusammenfassung**: Heute gearbeitete Stunden, genommene Pausenzeit
- **Schnellaktionen**: Ein-/Ausstempeln-Buttons
- **Aktuelle Einträge**: Ihre neuesten Zeiteinträge
- **Benachrichtigungen**: Wichtige Hinweise (z.B. fehlende Pausen, Verstöße)

---

## Tägliche Zeiterfassung

### Einstempeln

**So starten Sie die Zeiterfassung:**

1. Klicken Sie auf den **"Einstempeln"**-Button im Dashboard
2. Optional: Wählen Sie ein **Projekt** (wenn ProjectCheck-Integration aktiviert ist)
3. Optional: Fügen Sie eine **Beschreibung** hinzu
4. Klicken Sie auf **"Einstempeln"**

**Was passiert:**
- Das System erfasst Ihre Startzeit
- Ein Timer beginnt, Ihre Arbeitsstunden zu zählen
- Sie sehen Ihren aktuellen Status im Dashboard

**Wichtig:**
- Sie können nur **einen aktiven Zeiteintrag** gleichzeitig haben
- Das System prüft, dass Sie seit Ihrer letzten Schicht mindestens **11 Stunden Ruhezeit** hatten
- Wenn Sie zu früh einstempeln möchten, sehen Sie eine Warnmeldung

### Pausen nehmen

**So starten Sie eine Pause:**

1. Klicken Sie auf **"Pause beginnen"**, während Sie eingestempelt sind
2. Der Timer pausiert
3. Ihre Pausenzeit wird separat erfasst

**So beenden Sie Ihre Pause:**

1. Klicken Sie auf **"Pause beenden"**
2. Der Timer setzt fort
3. Ihre Pausendauer wird erfasst

**Gesetzliche Anforderungen:**
- Nach **6 Stunden** Arbeit müssen Sie mindestens **30 Minuten** Pause nehmen
- Nach **9 Stunden** Arbeit müssen Sie mindestens **45 Minuten** Pause nehmen
- Das System erinnert Sie, wenn Ihnen eine erforderliche Pause fehlt

### Ausstempeln

**So beenden Sie die Zeiterfassung:**

1. Klicken Sie auf den **"Ausstempeln"**-Button
2. Das System erfasst Ihre Endzeit
3. Ihre Gesamtarbeitsstunden und Pausenzeit werden berechnet
4. Sie sehen eine Zusammenfassung Ihres Tages

**Was berechnet wird:**
- **Gesamtdauer**: Zeit vom Einstempeln bis zum Ausstempeln
- **Pausendauer**: Summe aller Pausenperioden
- **Arbeitsdauer**: Gesamtdauer minus Pausenzeit

---

## Zeiteinträge verwalten

### Zeiteinträge anzeigen

**So sehen Sie alle Ihre Zeiteinträge:**

1. Gehen Sie zu **"Zeiteinträge"** in der Navigation
2. Verwenden Sie Filter, um bestimmte Einträge zu finden:
   - **Datumsbereich**: Start- und Enddatum auswählen
   - **Status**: Nach Status filtern (aktiv, abgeschlossen, Genehmigung ausstehend, etc.)
3. Verwenden Sie Paginierung, um durch Einträge zu blättern

**Verfügbare Ansichten:**
- **Listenansicht**: Tabelle aller Einträge
- **Kalenderansicht**: Visueller Kalender mit Einträgen nach Datum
- **Zeitachsenansicht**: Chronologische Zeitachse der Arbeitsperioden

### Manueller Zeiteintrag

**Wenn Sie vergessen haben, ein- oder auszustempeln:**

1. Gehen Sie zu **"Zeiteinträge"** → **"Manuellen Eintrag hinzufügen"**
2. Geben Sie ein:
   - **Datum**: Das Datum, an dem Sie gearbeitet haben
   - **Stunden**: Anzahl der gearbeiteten Stunden
   - **Beschreibung**: **Pflichtangabe** Begründung (z.B. "Einstempeln vergessen")
   - **Projekt**: Optionale Projektzuordnung
3. Klicken Sie auf **"Speichern"**

**Wichtig:**
- Manuelle Einträge erfordern eine **Begründung** (dies ist für Audit-Zwecke verpflichtend)
- Manuelle Einträge können später bearbeitet oder gelöscht werden (im Gegensatz zu automatischen Ein-/Ausstempel-Einträgen)
- Ihr Vorgesetzter kann manuelle Einträge überprüfen

### Korrekturanfrage stellen

**Wenn Sie einen Fehler in einem abgeschlossenen Zeiteintrag bemerken:**

1. Finden Sie den Zeiteintrag in Ihrer Liste
2. Klicken Sie auf **"Korrektur anfordern"**
3. Füllen Sie aus:
   - **Begründung**: Warum die Korrektur erforderlich ist (Pflichtfeld)
   - **Korrigiertes Datum**: Falls das Datum falsch war
   - **Korrigierte Stunden**: Falls die Stunden falsch waren
   - **Korrigierte Beschreibung**: Falls die Beschreibung aktualisiert werden muss
4. Klicken Sie auf **"Anfrage senden"**

**Was passiert:**
- Ihre Anfrage wird an Ihren Vorgesetzten gesendet
- Der Eintragsstatus ändert sich auf **"Genehmigung ausstehend"**
- Sie erhalten eine Benachrichtigung, wenn er genehmigt oder abgelehnt wird
- Sie können den Status in Ihrer Zeiteinträge-Liste einsehen

**Hinweis:** Sie können nur Korrekturen für Einträge anfordern, die **abgeschlossen** sind und nicht bereits eine ausstehende Genehmigung haben.

### Zeiteinträge bearbeiten

**Sie können bearbeiten:**
- Manuelle Einträge (von Ihnen manuell erstellte Einträge)
- Einträge mit Status **"abgeschlossen"**, die noch nicht genehmigt wurden

**Sie können NICHT bearbeiten:**
- Automatische Ein-/Ausstempel-Einträge (diese sind manipulationssicher)
- Einträge mit Status **"Genehmigung ausstehend"** (warten Sie auf Vorgesetzten-Entscheidung)
- Einträge, die **"genehmigt"** sind (verwenden Sie stattdessen Korrekturanfrage)

**So bearbeiten Sie:**
1. Finden Sie den Eintrag in Ihrer Liste
2. Klicken Sie auf **"Bearbeiten"**
3. Nehmen Sie Ihre Änderungen vor
4. Klicken Sie auf **"Speichern"**

### Zeiteinträge löschen

**Sie können nur löschen:**
- Manuelle Einträge (von Ihnen manuell erstellte Einträge)

**Sie können NICHT löschen:**
- Automatische Ein-/Ausstempel-Einträge
- Einträge, die genehmigt wurden

**So löschen Sie:**
1. Finden Sie den Eintrag in Ihrer Liste
2. Klicken Sie auf **"Löschen"**
3. Bestätigen Sie die Löschung

---

## Abwesenheitsverwaltung

### Urlaub beantragen

**So beantragen Sie Urlaub:**

1. Gehen Sie zu **"Abwesenheiten"** → **"Abwesenheit beantragen"**
2. Wählen Sie **"Urlaub"** als Typ
3. Geben Sie ein:
   - **Startdatum**: Erster Urlaubstag
   - **Enddatum**: Letzter Urlaubstag
   - **Grund**: Optionale Beschreibung
4. Klicken Sie auf **"Antrag senden"**

**Was passiert:**
- Ihr Antrag wird an Ihren Vorgesetzten gesendet
- Status wird auf **"Ausstehend"** gesetzt
- Sie sehen Ihre verbleibenden Urlaubstage (falls konfiguriert)
- Sie erhalten eine Benachrichtigung bei Genehmigung/Ablehnung

**Wichtig:**
- Das System berechnet **Arbeitstage** automatisch (schließt Wochenenden und Feiertage aus)
- Sie können sehen, wie viele Urlaubstage Sie noch haben
- Überschneidende Anträge sind nicht zulässig

### Krankmeldung

**So melden Sie sich krank:**

1. Gehen Sie zu **"Abwesenheiten"** → **"Abwesenheit beantragen"**
2. Wählen Sie **"Krankmeldung"** als Typ
3. Geben Sie ein:
   - **Startdatum**: Erster Krankheitstag
   - **Enddatum**: Letzter Krankheitstag (oder erwartetes Rückkehrdatum)
   - **Grund**: Optional (keine medizinische Diagnose erforderlich)
4. Klicken Sie auf **"Antrag senden"**

**Hinweis:** Das System erfordert **keine** medizinischen Details. Melden Sie nur den Abwesenheitszeitraum.

### Andere Abwesenheitstypen

Sie können auch beantragen:
- **Sonderurlaub**: Für besondere Umstände
- **Unbezahlter Urlaub**: Urlaub ohne Lohnfortzahlung

### Abwesenheitsstatus anzeigen

**So prüfen Sie Ihre Abwesenheitsanträge:**

1. Gehen Sie zu **"Abwesenheiten"**
2. Sie sehen alle Ihre Anträge mit ihrem Status:
   - **Ausstehend**: Warten auf Vorgesetzten-Genehmigung
   - **Genehmigt**: Antrag genehmigt
   - **Abgelehnt**: Antrag abgelehnt (siehe Vorgesetzten-Kommentar)
   - **Gelöscht**: Antrag wurde storniert

### Abwesenheitsantrag stornieren

**So stornieren Sie einen ausstehenden Antrag:**

1. Finden Sie den Antrag in Ihrer Liste
2. Klicken Sie auf **"Löschen"**
3. Bestätigen Sie die Stornierung

**Hinweis:** Sie können nur Anträge mit Status **"Ausstehend"** stornieren.

---

## Dashboard und Übersicht

### Persönliches Dashboard

Ihr Dashboard bietet:

- **Tageszusammenfassung**: Gearbeitete Stunden, Pausenzeit, aktueller Status
- **Schnellaktionen**: Ein-/Ausstempeln-, Pause beginnen/beenden-Buttons
- **Aktuelle Einträge**: Ihre neuesten Zeiteinträge
- **Überstundenbilanz**: Ihre aktuelle Überstunden (positiv oder negativ)
- **Bevorstehende Abwesenheiten**: Genehmigte Abwesenheiten in naher Zukunft
- **Compliance-Status**: Aktuelle Verstöße oder Warnungen

### Kalenderansicht

**So sehen Sie Ihre Zeiteinträge im Kalenderformat:**

1. Gehen Sie zu **"Kalender"** in der Navigation
2. Navigieren Sie nach Monat
3. Klicken Sie auf ein Datum, um Details zu sehen
4. Farbcodierung zeigt:
   - **Grün**: Konforme Tage
   - **Gelb**: Warnungen (z.B. fehlende Pause)
   - **Rot**: Verstöße (z.B. überschrittene Stunden)

### Zeitachsenansicht

**So sehen Sie eine chronologische Zeitachse:**

1. Gehen Sie zu **"Zeitachse"** in der Navigation
2. Sehen Sie alle Ihre Arbeitsperioden in chronologischer Reihenfolge
3. Visuelle Darstellung zeigt:
   - Arbeitsperioden (blaue Balken)
   - Pausenperioden (graue Balken)
   - Lücken zwischen Schichten

### Berichte

**So sehen Sie Berichte:**

1. Gehen Sie zu **"Berichte"** in der Navigation
2. Wählen Sie Berichtstyp:
   - **Täglich**: Zusammenfassung von heute
   - **Wöchentlich**: Zusammenfassung dieser Woche
   - **Monatlich**: Zusammenfassung dieses Monats
   - **Überstunden**: Überstundenberechnung
   - **Abwesenheit**: Abwesenheitsstatistiken

---

## Compliance und Verstöße

### Verstöße verstehen

Das System prüft automatisch die Einhaltung des deutschen Arbeitszeitgesetzes:

- **Fehlende Pause**: Keine Pause nach 6 Stunden Arbeit
- **Unzureichende Pause**: Weniger als erforderliche Pausenzeit
- **Tägliche Stunden überschritten**: Mehr als 10 Stunden an einem Tag gearbeitet
- **Unzureichende Ruhezeit**: Weniger als 11 Stunden zwischen Schichten
- **Sonntags-/Feiertagsarbeit**: Arbeit an Sonntag oder Feiertag

### Ihre Verstöße anzeigen

**So sehen Sie Ihre Compliance-Verstöße:**

1. Gehen Sie zu **"Compliance"** → **"Verstöße"**
2. Filtern Sie nach:
   - **Typ**: Art des Verstoßes
   - **Schweregrad**: Info, Warnung oder Fehler
   - **Status**: Gelöst oder ungelöst
   - **Datumsbereich**: Bestimmter Zeitraum

### Verstöße lösen

**So markieren Sie einen Verstoß als gelöst:**

1. Finden Sie den Verstoß in Ihrer Liste
2. Klicken Sie auf **"Lösen"**
3. Optional fügen Sie einen Kommentar hinzu, der erklärt, wie er gelöst wurde

**Hinweis:** Einige Verstöße (z.B. fehlende Pause) können nicht rückwirkend gelöst werden, dienen aber als Dokumentation für zukünftige Compliance.

### Compliance-Status

**So sehen Sie Ihre Gesamt-Compliance:**

1. Gehen Sie zu **"Compliance"** → **"Dashboard"**
2. Sehen Sie:
   - **Compliance-Prozentsatz**: Wie konform Sie sind
   - **Verstoßanzahl**: Anzahl ungelöster Verstöße
   - **Aktuelle Verstöße**: Neueste Compliance-Probleme

---

## Daten exportieren

### Zeiteinträge exportieren

**So exportieren Sie Ihre Zeiteinträge:**

1. Gehen Sie zu **"Zeiteinträge"**
2. Klicken Sie auf **"Exportieren"**
3. Wählen Sie Format:
   - **CSV**: Für Excel oder Tabellenkalkulationssoftware
   - **JSON**: Für programmatischen Zugriff
   - **PDF**: Zum Drucken oder Archivieren
   - **DATEV**: Für Lohnabrechnung (falls konfiguriert)
4. Wählen Sie Datumsbereich
5. Klicken Sie auf **"Herunterladen"**

### Abwesenheiten exportieren

**So exportieren Sie Ihre Abwesenheiten:**

1. Gehen Sie zu **"Abwesenheiten"**
2. Klicken Sie auf **"Exportieren"**
3. Wählen Sie Format (CSV, JSON, PDF)
4. Wählen Sie Datumsbereich
5. Klicken Sie auf **"Herunterladen"**

### DSGVO-Datenexport

**So exportieren Sie alle Ihre personenbezogenen Daten (DSGVO-Auskunftsrecht):**

1. Gehen Sie zu **"Einstellungen"** → **"Persönlich"** → **"ArbeitszeitCheck"**
2. Klicken Sie auf **"Persönliche Daten exportieren"**
3. Eine JSON-Datei wird heruntergeladen, die enthält:
   - Alle Zeiteinträge
   - Alle Abwesenheiten
   - Ihre Einstellungen
   - Compliance-Verstöße
   - Protokolldaten

**Dieser Export ist umfassend und enthält alle Daten, die das System über Sie speichert.**

---

## Einstellungen

### Persönliche Einstellungen

**So greifen Sie auf Ihre persönlichen Einstellungen zu:**

1. Gehen Sie zu **"Einstellungen"** → **"Persönlich"** → **"ArbeitszeitCheck"**
2. Konfigurieren Sie:
   - **Urlaubsanspruch**: Tage pro Jahr
   - **Arbeitsstunden**: Ihre Vertragsstunden
   - **Benachrichtigungseinstellungen**: E-Mail- und In-App-Benachrichtigungen

### Benachrichtigungseinstellungen

Sie können Benachrichtigungen konfigurieren für:

- **Pausenerinnerungen**: Erinnerung nach 6 Stunden ohne Pause
- **Ausstempel-Erinnerungen**: Erinnerung, wenn Sie vergessen haben auszustempeln
- **Verstoß-Warnungen**: Benachrichtigungen über Compliance-Verstöße
- **Genehmigungsbenachrichtigungen**: Wenn Ihre Anträge genehmigt/abgelehnt werden

---

## Fehlerbehebung

### "Ich kann nicht einstempeln"

**Mögliche Gründe:**
- Sie haben bereits einen aktiven Zeiteintrag → Stempeln Sie zuerst aus
- Weniger als 11 Stunden seit letzter Schicht → Warten Sie, bis die Ruhezeit erfüllt ist
- Systemfehler → Kontaktieren Sie den IT-Support

### "Ich habe vergessen, ein- oder auszustempeln"

**Lösung:**
1. Erstellen Sie einen **manuellen Zeiteintrag** (siehe "Manueller Zeiteintrag" oben)
2. Geben Sie eine **Begründung** an, die erklärt, warum
3. Ihr Vorgesetzter kann ihn überprüfen

### "Mein Zeiteintrag ist falsch"

**Lösung:**
1. Wenn es ein **manueller Eintrag** ist: Bearbeiten Sie ihn direkt
2. Wenn es ein **automatischer Eintrag** ist: Stellen Sie eine Korrekturanfrage (siehe "Korrekturanfrage stellen" oben)

### "Ich kann die Genehmigung meines Vorgesetzten nicht sehen"

**Lösung:**
- Prüfen Sie Ihre **Benachrichtigungen** (Glocken-Symbol)
- Gehen Sie zu **"Abwesenheiten"** oder **"Zeiteinträge"** und prüfen Sie den Status
- Status zeigt: **"Ausstehend"**, **"Genehmigt"** oder **"Abgelehnt"**

### "Das System sagt, mir fehlt eine Pause"

**Was zu tun ist:**
- Dies ist eine **Warnung**, kein Fehler
- Sie sollten die erforderliche Pause nehmen (30 Min nach 6 Stunden, 45 Min nach 9 Stunden)
- Zukünftige Einträge werden auf Compliance geprüft
- Sie können Pausen nicht rückwirkend zu vergangenen Einträgen hinzufügen, aber der Verstoß dient als Dokumentation

### "Ich kann keinen Zeiteintrag löschen"

**Mögliche Gründe:**
- Es ist ein **automatischer Eintrag** (Ein-/Ausstempeln) → Diese können nicht gelöscht werden (manipulationssicher)
- Er ist **genehmigt** → Verwenden Sie stattdessen Korrekturanfrage
- Er ist **Genehmigung ausstehend** → Warten Sie auf Vorgesetzten-Entscheidung

---

## Tastenkürzel

Für Tastaturnavigation (WCAG 2.1 AAA-konform):

- **Tab**: Zwischen Elementen navigieren
- **Enter/Leertaste**: Buttons und Links aktivieren
- **Escape**: Modals und Dialoge schließen
- **Pfeiltasten**: In Listen und Tabellen navigieren
- **Strg/Cmd + F**: Suchen/Filtern (wo verfügbar)

**Alle Funktionen sind über die Tastatur zugänglich** - keine Maus erforderlich.

---

## Hilfe erhalten

### Support-Ressourcen

- **Benutzerhandbuch**: Dieses Handbuch
- **FAQ**: Siehe FAQ-Bereich
- **IT-Support**: Kontaktieren Sie die IT-Abteilung Ihrer Organisation
- **GitHub Issues**: https://github.com/nextcloud/arbeitszeitcheck/issues (für Fehlermeldungen)

### Probleme melden

**Wenn Sie auf ein Problem stoßen:**

1. Prüfen Sie den Abschnitt **"Fehlerbehebung"** oben
2. Prüfen Sie, ob es ein bekanntes Problem in GitHub ist
3. Melden Sie das Problem mit:
   - Was Sie versucht haben
   - Was stattdessen passiert ist
   - Fehlermeldungen (falls vorhanden)
   - Browser und Version

---

## Datenschutz und Ihre Rechte

### Ihre Datenrechte (DSGVO)

Sie haben das Recht auf:

- **Zugang zu Ihren Daten**: Exportieren Sie alle Ihre Daten (siehe "DSGVO-Datenexport" oben)
- **Berichtigung Ihrer Daten**: Korrekturen für Fehler anfordern
- **Löschung Ihrer Daten**: Löschung anfordern (unterliegt gesetzlichen Aufbewahrungsfristen)
- **Datenübertragbarkeit**: Export in maschinenlesbaren Formaten

### Datenaufbewahrung

- Zeiteinträge werden **mindestens 2 Jahre** aufbewahrt (deutsche Arbeitszeitgesetz-Anforderung)
- Einige Daten können länger für rechtliche Compliance aufbewahrt werden
- Sie werden informiert, wenn eine Löschung aufgrund von Aufbewahrungsanforderungen nicht möglich ist

### Transparenz

- Sie können **alle Ihre Daten** jederzeit einsehen
- Sie können sehen, **wer Zugriff** auf Ihre Daten hat (Vorgesetzte, HR)
- Sie können **Protokolldaten** von Änderungen an Ihren Daten einsehen

---

**Letzte Aktualisierung:** 2025-12-29  
**Version:** 1.0.0
