# DSGVO-Compliance-Leitfaden – ArbeitszeitCheck (TimeGuard)

> **Wichtiger Hinweis:** Dieser Leitfaden beschreibt, wie ArbeitszeitCheck DSGVO-konform betrieben werden kann. Er ersetzt **keine Rechtsberatung**. Jede Organisation bleibt selbst für ihre Compliance verantwortlich und sollte den Datenschutzbeauftragten (DSB) und gegebenenfalls Rechtsberatung einbeziehen.

## 1. Rolle von ArbeitszeitCheck in Ihrer DSGVO-Compliance

ArbeitszeitCheck unterstützt insbesondere die Einhaltung von:

- **DSGVO** – vor allem Art. 5, 6, 13–22, 25, 30, 32, 35
- **Arbeitszeitgesetz (ArbZG)** – Pflicht zur Arbeitszeiterfassung und Einhaltung von Höchstarbeitszeiten, Pausen und Ruhezeiten

Die App setzt auf **Datenminimierung**, **Zweckbindung** und die **Rechte der Beschäftigten**, während sie arbeitszeitrechtliche Vorgaben technisch durchsetzt.

Sie als Verantwortlicher müssen:

- Zwecke und Rechtsgrundlagen definieren und dokumentieren
- Beschäftigte transparent informieren (Art. 13 DSGVO)
- Rollen, Aufbewahrungsfristen und Schnittstellen passend konfigurieren
- Verzeichnisse der Verarbeitungstätigkeiten führen (Art. 30)
- Gegebenenfalls eine **DSFA/DPIA** durchführen (Art. 35)

ArbeitszeitCheck stellt technische Funktionen und Dokumentationsvorlagen bereit, um diese Pflichten zu unterstützen.

## 2. Rechtsgrundlage und Zweckbindung (Art. 5, 6 DSGVO)

### 2.1 Empfohlene primäre Rechtsgrundlage

Für die verpflichtende Arbeitszeiterfassung nach deutschem Recht bietet sich an:

- **Art. 6 Abs. 1 lit. c DSGVO – Rechtliche Verpflichtung**
  - Erfüllung der Pflichten aus dem **ArbZG** und einschlägigen Vorschriften

Zusätzlich kommen in Betracht:

- **Art. 6 Abs. 1 lit. b DSGVO – Vertragserfüllung** (z.B. Entgeltabrechnung)
- **Art. 6 Abs. 1 lit. f DSGVO – Berechtigtes Interesse** (z.B. Abwehr von Rechtsansprüchen), mit dokumentierter Interessenabwägung

### 2.2 Zweckbindung

Sie sollten klar festlegen und dokumentieren, dass ArbeitszeitCheck genutzt wird für:

- Erfüllung gesetzlicher Pflichten zur Arbeitszeiterfassung (ArbZG)
- Personal- und Entgeltabrechnung (soweit erforderlich)
- Arbeits- und Gesundheitsschutz (Pausen, Höchstarbeitszeit, Ruhezeiten)
- Compliance und Audit (Nachweis gegenüber Behörden und Gerichten)

**Nicht zulässig** ohne gesonderte Rechtsgrundlage und ggf. Betriebsvereinbarung:

- verdeckte Leistungs- oder Verhaltenskontrolle
- Erstellung detaillierter Leistungsprofile einzelner Beschäftigter
- Nutzung von Daten zu disziplinarischen Zwecken über das rechtlich Zulässige hinaus

ArbeitszeitCheck trennt konzeptionell **zwingend erforderliche Compliance-Daten** von **optionalen Projekt-/Kostenstelleninformationen**, um die Zweckbindung zu unterstützen.

## 3. Datenminimierung und Konfiguration

### 3.1 Standard-Datensatz

Im Standardbetrieb benötigt ArbeitszeitCheck lediglich:

- Benutzerkennung (Nextcloud-Account-ID)
- Basisidentität (Name, Abteilung, Vorgesetzter)
- Arbeitszeitdaten (Datum, Beginn, Ende, Pausen, Dauer)
- Abwesenheitsarten (ohne medizinische Diagnose)
- Compliance-Verstöße (Art, Datum, Schweregrad)
- Protokolldaten (wer hat was wann geändert/eingesehen)

Besondere Kategorien personenbezogener Daten (Art. 9 DSGVO) sind **nicht erforderlich** und werden standardmäßig nicht verarbeitet.

### 3.2 Optionale Daten – worauf Sie achten sollten

ArbeitszeitCheck bietet optionale Funktionen, die Sie sorgfältig prüfen und konfigurieren sollten:

- **Projekt- / Kostenstellenzuordnung**
  - Nur verwenden, wenn für Abrechnung/Controlling erforderlich
  - Möglichst aggregierte Auswertungen statt individueller Leistungsprofile
- **Freitextfelder (Beschreibungen, Kommentare)**
  - Beschäftigte explizit anweisen, **keine sensiblen Inhalte** (Diagnosen, Religion, Politik etc.) einzutragen
- **Schnittstellen (z.B. DATEV-Export)**
  - Sicherstellen, dass nur notwendige Felder exportiert werden

Nutzen Sie die Administrator-Einstellungen von ArbeitszeitCheck um:

- optionale Felder sinnvoll einzuschränken
- klare interne Regeln für die Nutzung von Freitextfeldern zu definieren

## 4. Transparenz und Information der Beschäftigten (Art. 13 DSGVO)

Sie müssen Beschäftigte in verständlicher Form informieren über:

- welche Daten in ArbeitszeitCheck verarbeitet werden,
- zu welchen Zwecken und auf welcher Rechtsgrundlage,
- wer Empfänger der Daten ist (intern/extern),
- wie lange Daten gespeichert werden,
- welche Rechte nach der DSGVO bestehen,
- wie der Datenschutzbeauftragte erreichbar ist.

Empfohlenes Vorgehen:

1. Erstellung eines **Datenschutz-Informationsblatts** für Beschäftigte
2. ausdrückliche Nennung von ArbeitszeitCheck als Zeiterfassungssystem
3. Verweise auf:
   - interne Richtlinien / Intranet-Seiten
   - (ggf. zusammengefasste) Ergebnisse der DSFA
   - diesen DSGVO-Leitfaden und weitere Unterlagen

ArbeitszeitCheck erzeugt diese Hinweise nicht automatisch, stellt aber folgende Vorlagen bereit:

- `docs/DPIA-Template.en.md` (DSFA-Vorlage)
- `docs/Processing-Activities-Record-Template.en.md` (Verzeichnis der Verarbeitungstätigkeiten)

Diese können als Grundlage für Ihre Dokumentation dienen.

## 5. Betroffenenrechte im Betrieb mit ArbeitszeitCheck

ArbeitszeitCheck unterstützt die Wahrnehmung von Betroffenenrechten technisch. Sie müssen passende **Prozesse** und **Fristen** definieren.

### 5.1 Auskunftsrecht (Art. 15 DSGVO)

- Beschäftigte können:
  - ihre eigenen Arbeitszeit- und Abwesenheitsdaten im Portal einsehen,
  - ihre Daten in maschinenlesbaren Formaten (CSV/JSON) exportieren.
- HR kann bei Bedarf zusätzliche Auszüge (z.B. PDF für bestimmte Zeiträume) bereitstellen.

Empfehlung:

- Standardprozess für Art.-15-Anfragen definieren,
- die Self-Service-Exporte als primäres Werkzeug nutzen.

### 5.2 Recht auf Berichtigung (Art. 16 DSGVO)

- Beschäftigte ändern vergangene Einträge **nicht direkt**,
- stattdessen stellen sie **Korrekturanträge** mit Begründung,
- Vorgesetzte/HR prüfen und genehmigen/lehnen ab,
- alle Änderungen werden mit Vorher-/Nachher-Werten protokolliert.

So werden **Datenrichtigkeit** und **Unverfälschbarkeit** mit einem vollständigen Audit-Trail kombiniert.

### 5.3 Recht auf Löschung und Aufbewahrungspflichten (Art. 17 DSGVO vs. ArbZG)

- ArbeitszeitCheck implementiert mindestens **2 Jahre Aufbewahrung** für Zeitnachweise (ArbZG)
- Das Löschrecht ist daher durch gesetzliche Aufbewahrungspflichten eingeschränkt
- Die im System vorhandene DSGVO-Löschfunktion:
  - entfernt Daten, die älter sind als die konfigurierte Aufbewahrungsdauer,
  - bereinigt benutzerspezifische Einstellungen, soweit nicht mehr erforderlich.

Empfohlen:

- Aufbewahrungsfristen für Arbeitszeit-, Abwesenheits- und Protokolldaten dokumentieren,
- Beschäftigte klar darüber informieren, dass bestimmte Daten **nicht** vor Ablauf der gesetzlichen Fristen gelöscht werden können.

### 5.4 Datenübertragbarkeit (Art. 20 DSGVO)

- Beschäftigte können Daten exportieren (CSV/JSON) und damit in andere Systeme übernehmen,
- HR kann ergänzende Exporte (z.B. DATEV- oder PDF-Berichte) erstellen.

Definieren Sie intern:

- welche Daten in welchen Formaten bereitgestellt werden,
- in welchen Fristen Anfragen nach Art. 20 beantwortet werden.

## 6. Sicherheit und TOMs (Art. 32 DSGVO)

ArbeitszeitCheck nutzt das Sicherheitskonzept von Nextcloud:

- Authentifizierung und ggf. Zwei-Faktor-Authentifizierung über Nextcloud
- TLS-verschlüsselte Verbindungen (HTTPS)
- rollenbasierte Zugriffskontrolle (Mitarbeiter, Vorgesetzte, HR, Admin)
- Protokollierung sensibler Aktionen

Ihre Aufgaben:

- Betrieb von Nextcloud und ArbeitszeitCheck auf **aktuell gehaltenen, gehärteten Systemen**,
- Erzwingen von **HTTPS-only** Zugängen,
- sorgfältige Rollenvergabe nach dem **Least-Privilege-Prinzip**,
- Einbindung von ArbeitszeitCheck in Ihr **Backup- und Recovery-Konzept**, 
- regelmäßige Überprüfung von Logs und Updates.

Diese Maßnahmen sollten in Ihrem TOM-Dokument (Art. 32) abgebildet werden, z.B. in den Bereichen:

- Zugriffs- und Berechtigungskonzept
- Verschlüsselung und Netzwerksicherheit
- Verfügbarkeit & Wiederherstellbarkeit
- Verfahren zum Umgang mit Datenschutzvorfällen

## 7. Dokumentationspflichten (Art. 30, 35 DSGVO)

Im Verzeichnis `apps/arbeitszeitcheck/docs/` stehen folgende Vorlagen bereit:

- **DSFA-/DPIA-Vorlage** (Art. 35 DSGVO)
  - `DPIA-Template.en.md`
- **Verzeichnis von Verarbeitungstätigkeiten** (Art. 30 DSGVO)
  - `Processing-Activities-Record-Template.en.md`
- **Betriebsvereinbarungs-Vorlage (DE)** (BetrVG §87)
  - `Works-Council-Agreement-Template.de.md`

Empfohlenes Vorgehen:

1. Verarbeitungstätigkeit „Zeiterfassung / Arbeitszeit-Compliance“ im Verzeichnis dokumentieren
2. Prüfen, ob eine DSFA notwendig ist (bei systematischer Überwachung von Beschäftigten in der Regel: ja)
3. DSFA auf Basis der Vorlage durchführen und dokumentieren
4. Bei bestehendem Betriebsrat die Mustervorlage zur Betriebsvereinbarung anpassen und verhandeln

## 8. Betriebsrat und Mitbestimmung (BetrVG §87)

Technische Systeme zur Überwachung von Verhalten oder Leistung von Beschäftigten sind mitbestimmungspflichtig. Auch wenn ArbeitszeitCheck **primär** der Erfüllung gesetzlicher Pflichten dient, sind:

- Umfang und Tiefe der Auswertungen,
- Zugriffsrechte von Vorgesetzten und HR,
- Einsatz von Reports bei Beurteilungen

für den Betriebsrat von besonderem Interesse.

Empfehlungen:

- Betriebsrat frühzeitig einbinden
- Zwecke und Grenzen der Nutzung schriftlich festhalten
- Die bereitgestellte **Betriebsvereinbarungs-Vorlage** als Basis verwenden
- Transparente Regeln für zulässige und unzulässige Auswertungen vereinbaren

## 9. Konfigurations-Checkliste vor dem Produktivbetrieb

Vor dem Go-Live sollten Sie mindestens prüfen, ob:

1. die **Rechtsgrundlage** (Art. 6 DSGVO) dokumentiert ist,
2. die **Zwecke** (Compliance, HR, Entgeltabrechnung) eindeutig beschrieben und kommuniziert sind,
3. die **Rollen** in ArbeitszeitCheck passend konfiguriert sind:
   - Mitarbeiter sehen nur eigene Daten,
   - Vorgesetzte sehen nur ihre Teams,
   - HR sieht die für ihre Aufgaben erforderlichen Daten,
   - Admins haben rein technische Zugriffsrechte,
4. die **Aufbewahrungsfristen** im Admin-Bereich konfiguriert und dokumentiert sind (mind. 2 Jahre),
5. die **Datenschutz-Informationen** für Beschäftigte aktualisiert und verteilt wurden,
6. eine **DSFA** (soweit erforderlich) durchgeführt und dokumentiert ist,
7. das **Verzeichnis von Verarbeitungstätigkeiten** aktualisiert wurde,
8. eine ggf. notwendige **Betriebsvereinbarung** abgeschlossen wurde,
9. **Backups und Wiederherstellung** getestet wurden,
10. die **Sicherheitsbasislinie** der Nextcloud-Instanz geprüft wurde (TLS, Updates, Berechtigungen).

## 10. Zusammenfassung

ArbeitszeitCheck (TimeGuard) bietet:

- ein auf Datenminimierung und Zweckbindung ausgerichtetes Datenmodell,
- technische Unterstützung für Betroffenenrechte (Auskunft, Berichtigung, Löschung im Rahmen der Aufbewahrungspflichten, Datenübertragbarkeit),
- rollenbasierte Zugriffssteuerung und umfassende Protokollierung,
- Vorlagen zur Erfüllung der Dokumentationspflichten (Art. 30, 35 DSGVO und Betriebsvereinbarung).

Gleichzeitig bleibt die Verantwortung bei Ihrem Unternehmen:

- richtige Konfiguration,
- klare interne Richtlinien,
- Einbindung von DSB und Betriebsrat,
- und eine regelmäßige Überprüfung der getroffenen Maßnahmen.

Passen Sie die Vorlagen im Verzeichnis `apps/arbeitszeitcheck/docs/` an Ihre konkrete Situation an und dokumentieren Sie sämtliche Entscheidungen nachvollziehbar.
