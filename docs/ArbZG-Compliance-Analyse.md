# ArbZG-Compliance-Analyse – ArbeitszeitCheck (TimeGuard)

**Ziel dieses Dokuments:**  
Kurzer, aktueller Überblick darüber, wie ArbeitszeitCheck die zentralen Anforderungen des deutschen Arbeitszeitgesetzes (ArbZG) technisch abdeckt.  
Details zur Implementierung finden sich in `Compliance-Implementation.de.md` / `.en.md` sowie in den einzelnen technischen Notizen (`Maximale-Arbeitszeit-10-Stunden.md`, `Automatische-Pausenberechnung.md` etc.).

---

## 1. §3 ArbZG – Maximale tägliche und wöchentliche Arbeitszeit

**Gesetzliche Kernpunkte (vereinfachte Darstellung):**

- Grundsatz: max. **8 Stunden Arbeitszeit pro Werktag**
- Verlängerung auf **bis zu 10 Stunden** zulässig, wenn innerhalb von **6 Monaten / 24 Wochen** im Durchschnitt 8 Stunden nicht überschritten werden
- Wöchentliche Arbeitszeit im Schnitt max. **48 Stunden**

**Implementierung in ArbeitszeitCheck:**

- **Tägliche 10‑Stunden‑Grenze (reine Arbeitszeit, ohne Pausen)**
  - Frontend‑Validierung in Formularen
  - Backend‑Validierung über `TimeEntry::getWorkingDurationHours()`  
  - Optionaler **Strict‑Mode** (Blockierung) vs. **Warning‑Mode** (Zulassen mit Verstoß‑Eintrag)
  - Dashboard‑Timer mit Warnstufen (ab 8h gelb, ab 10h rot, Auto‑Clock‑Out)
- **6‑Monats‑Durchschnitt / 24‑Wochen‑Prüfung**
  - Rolling‑Average‑Berechnung über Zeiträume
  - Manager‑Benachrichtigung, wenn der Durchschnitt von 8h überschritten wird  
  - Keine harte Blockierung (bewusste Entscheidung, um Ausnahmesituationen nicht hart zu verhindern; rechtliche Beurteilung bleibt beim Arbeitgeber)
- **Wöchentliche 48‑Stunden‑Grenze**
  - Auswertung der Wochenarbeitszeit über gleitende Zeitfenster
  - Kennzeichnung/Bericht in Compliance‑Auswertungen
  - Manager‑Warnungen bei systematischer Überschreitung

**Bewertung:**  
Die tägliche 10‑Stunden‑Grenze (ohne Pausen) wird technisch streng überwacht; die langfristigen Durchschnittsprüfungen erfolgen mit Manager‑Warnungen und Berichten. Damit unterstützt die App eine ArbZG‑konforme Organisation der Arbeitszeit, ersetzt aber keine arbeitsrechtliche Beratung.

---

## 2. §4 ArbZG – Ruhepausen

**Gesetzliche Kernpunkte (vereinfacht):**

- Ab **> 6 Stunden** Arbeitszeit: mind. **30 Minuten** Pause
- Ab **> 9 Stunden** Arbeitszeit: mind. **45 Minuten** Pause
- Pausen können aufgeteilt werden, **jede Teilpause mind. 15 Minuten**

**Implementierung in ArbeitszeitCheck:**

- **Manuelle Pausen**:
  - Benutzer können eine oder mehrere Pausen mit Start/Ende erfassen
  - Validierung stellt sicher, dass nur Pausen ≥ 15 Minuten auf die Pausenpflicht angerechnet werden
- **Automatische Pausenberechnung** (wenn keine Pause erfasst wurde):
  - Wird bei abgeschlossenen Einträgen ohne Pause angewendet
  - Berechnet die erforderliche Pausenzeit gemäß §4 (30/45 Minuten)
  - Platziert die Pause automatisch in der Mitte der Arbeitszeit
  - Kennzeichnung im Datensatz (`automatic: true`, dokumentierter Grund)
- **Compliance‑Prüfungen:**
  - Prüfung der tatsächlich vorhandenen Pausen (inkl. 15‑Min‑Mindestdauer)
  - Anlegen von Compliance‑Verstößen bei fehlenden/zu kurzen Pausen

**Bewertung:**  
Pausenanforderungen werden sowohl bei manuellen als auch automatisch ergänzten Pausen berücksichtigt; nur Pausen ≥ 15 Minuten erfüllen die Pausenpflicht. Damit ist §4 technisch vollständig abgedeckt.

---

## 3. §5 ArbZG – Ruhezeit

**Gesetzlicher Kernpunkt:**  
Mindestens **11 Stunden** ununterbrochene Ruhezeit zwischen zwei Arbeitstagen.

**Implementierung in ArbeitszeitCheck:**

- Prüfung der **Ruhezeit vor jedem Clock‑In**:
  - Wird die 11‑Stunden‑Grenze unterschritten, wird das Einstempeln blockiert
  - Fehlermeldung mit letztem Schichtende und frühestmöglichem Startzeitpunkt
- Prüfung der Ruhezeit bei **manuellen Zeiteinträgen / Updates**:
  - Validierung in den Controllern und im `ComplianceService`
  - Fehlversuche werden mit aussagekräftiger Meldung zurückgegeben (HTTP 400)
- Unterscheidung **Unterbrechung vs. neuer Arbeitstag**:
  - Unterbrechungen am selben Kalendertag (Resume) werden nicht als neue Schicht mit 11‑Stunden‑Pflicht behandelt

**Bewertung:**  
§5 wird technisch streng durchgesetzt (Blockierung bei Verstößen), sowohl für das Live‑Einstempeln als auch für nachträgliche Eingaben.

---

## 4. §6 ArbZG – Nachtarbeit

**Kurzfassung:**  
Regelt u. a. maximale Arbeitszeiten und besondere Schutzrechte bei Nachtarbeit.

**Status in ArbeitszeitCheck:**

- Erkennung und Auswertung von **Nachtarbeit** (typisch 23:00–06:00)
- Markierung von Einträgen mit Nachtanteil und Einbezug in Reports
- Vorbereitung für weitergehende Regeln (z. B. spezielle Höchstgrenzen, Gesundheitschecks) vorhanden, aber bewusst nicht vollautomatisiert umgesetzt, da dies stark vom konkreten Tarif‑/Betriebskontext abhängt.

**Bewertung:**  
Nachtarbeit wird sauber erkannt und dokumentiert; Detailregelungen (z. B. spezielle Tarifausnahmen, medizinische Untersuchungen) müssen organisatorisch/vertraglich abgebildet werden.

---

## 5. §§9, 10 ArbZG – Sonn‑ und Feiertagsarbeit

**Kurzfassung:**  
Regeln Ausnahmen und Ausgleich bei Arbeit an Sonn‑ und Feiertagen.

**Status in ArbeitszeitCheck:**

- Nutzung des konfigurierten **Bundeslands** zur Ermittlung gesetzlicher Feiertage
- Kennzeichnung von Arbeitszeit an Sonn‑ und Feiertagen und Einbezug in:
  - Berichte
  - Compliance‑Übersichten
- Keine harte Blockierung, da die Zulässigkeit stark vom Einzelfall (Branche, Tarif, Ausnahmetatbestände) abhängt; stattdessen Dokumentation und Transparenz.

**Bewertung:**  
Relevante Arbeitstage werden korrekt erkannt und ausgewiesen; ob eine konkrete Sonn-/Feiertagsarbeit zulässig ist, bleibt eine rechtliche/organisatorische Frage des Arbeitgebers.

---

## 6. §16 ArbZG – Aufzeichnungspflicht

**Gesetzlicher Kernpunkt:**  
Verpflichtung des Arbeitgebers, die Arbeitszeiten aufzuzeichnen und mindestens 2 Jahre aufzubewahren.

**Implementierung in ArbeitszeitCheck:**

- Vollständige Aufzeichnung aller Arbeitszeit‑ und Abwesenheitsdaten in eigenen Tabellen
- **Audit‑Log** für alle relevanten Änderungen (Wer? Was? Wann?)
- Konfigurierbare **Aufbewahrungsdauer** (Standard ≥ 2 Jahre), technische Lösch‑/Anonymisierungsfunktionen im Rahmen der DSGVO
- Exporte (CSV, JSON, PDF, DATEV) zur Archivierung und für externe Systeme

**Bewertung:**  
§16 ist technisch vollständig abgedeckt; die konkrete Konfiguration der Aufbewahrungsfristen liegt in der Verantwortung des Betreibers.

---

## 7. Zusammenfassung und Verantwortlichkeit

- Die **Kernanforderungen des ArbZG (§3, §4, §5, §16)** werden in ArbeitszeitCheck technisch umfassend unterstützt (Grenzwerte, Validierungen, automatische Berechnungen, Audit‑Log, Berichte).  
- **Nachtarbeit (§6)** sowie **Sonn‑/Feiertagsarbeit (§§9, 10)** werden verlässlich erkannt und ausgewiesen; Detailregelungen und Ausnahmetatbestände sind stark kontextabhängig und müssen arbeitsvertraglich / tariflich / organisatorisch geregelt werden.
- Die App stellt damit eine **robuste technische Grundlage** für ArbZG‑Compliance bereit, ersetzt aber **keine** individuelle Rechtsberatung. Arbeitgeber bleiben für die rechtskonforme Ausgestaltung von Arbeitszeitmodellen, Betriebsvereinbarungen, Tarifumsetzungen und organisatorischen Prozessen verantwortlich.

**Stand:** 2025‑01‑06 (Inhalt konsolidiert und auf aktuellen Implementierungsstand gebracht)  
Weiterführende technische Details: siehe `Compliance-Implementation.de.md` / `.en.md`.

