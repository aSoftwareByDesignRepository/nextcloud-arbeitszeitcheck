# Datenschutzerklärung – ArbeitszeitCheck / TimeGuard

**Stand:** 29. Dezember 2025  
**Gilt für:** Die Nutzung der App ArbeitszeitCheck / TimeGuard innerhalb Ihrer selbst gehosteten Nextcloud-Instanz.

> **Wichtig:** Dieses Dokument ist eine **Vorlage** und stellt **keine** Rechtsberatung dar.  
> Jede Organisation bleibt für ihre eigene DSGVO- und Arbeitsrechts-Compliance verantwortlich und sollte diese Richtlinie von qualifizierten Rechtsanwälten und gegebenenfalls dem Betriebsrat prüfen lassen.

---

## 1. Verantwortlicher und Kontaktdaten

**Verantwortlicher (Art. 4 Abs. 7 DSGVO)**  
Verantwortlicher für alle über ArbeitszeitCheck / TimeGuard verarbeiteten personenbezogenen Daten ist die Organisation, die die Nextcloud-Instanz betreibt (z. B. Ihr Unternehmen, eine öffentliche Behörde oder ein Verein).

Bitte tragen Sie hier Ihre Daten ein:

- **Organisationsname:** _[Ihr Firmenname]_  
- **Adresse:** _[Straße, Postleitzahl, Ort, Land]_  
- **Telefon:** _[Telefonnummer]_  
- **E-Mail:** _[Allgemeine Kontakt-E-Mail]_  

**Datenschutzbeauftragter (DSB), falls vorhanden (Art. 37 DSGVO)**  

- **Name des DSB:** _[Name oder „Datenschutzbeauftragter“]_  
- **Adresse:** _[Adresse, falls abweichend]_  
- **E-Mail:** _[DSB-E-Mail]_  
- **Telefon:** _[DSB-Telefon]_  

---

## 2. Geltungsbereich dieser Datenschutzerklärung

Diese Datenschutzerklärung erläutert, wie ArbeitszeitCheck / TimeGuard personenbezogene Daten verarbeitet, wenn die App in Ihrer Nextcloud-Instanz verwendet wird. Sie umfasst:

- Erfassung der Arbeitszeit (Kommen/Gehen, Pausen)  
- Verwaltung von Abwesenheiten (Urlaub, Krankheit, Sonderurlaub, unbezahlter Urlaub)  
- Compliance-Überwachung gemäß deutschem Arbeitszeitgesetz (ArbZG)  
- Berichte und Exporte für Personalabteilung/Lohnabrechnung und rechtliche Compliance  
- Optionale Integration mit der ProjectCheck-App (Projektzeiterfassung)

Die App ist darauf ausgelegt, **deutsches Arbeitsrecht** und **DSGVO**-Anforderungen zu unterstützen. Sie verarbeitet **Arbeitszeitdaten von Mitarbeitern**, keine Inhaltsdaten wie Dateien, E-Mails oder Nachrichten.

---

## 3. Zwecke der Verarbeitung (Art. 5 Abs. 1 lit. b, Art. 6 Abs. 1 lit. c, f DSGVO)

ArbeitszeitCheck verarbeitet personenbezogene Daten ausschließlich für folgende Zwecke:

1. **Erfüllung arbeitsrechtlicher Verpflichtungen (Rechtspflicht, Art. 6 Abs. 1 lit. c DSGVO)**  
   - Pflicht zur Erfassung der Arbeitszeit (EuGH C-55/18, BAG-Rechtsprechung)  
   - Durchsetzung der maximalen täglichen und wöchentlichen Arbeitszeiten  
   - Überwachung der erforderlichen Ruhezeiten und Pausen  
   - Dokumentation von Sonn-, Feiertags- und Nachtarbeit  
   - Bereitstellung von Nachweisen für Aufsichtsbehörden und Gerichte

2. **Personalverwaltung und Lohnabrechnung (Art. 6 Abs. 1 lit. b, c DSGVO)**  
   - Berechnung von Arbeitsstunden und Überstunden  
   - Verwaltung und Dokumentation von Abwesenheiten und Urlaubsansprüchen  
   - Bereitstellung von Berichten und Exporten (z. B. DATEV, CSV) für Lohn-/Buchhaltungssysteme  

3. **Organisatorische Planung und Transparenz (berechtigtes Interesse, Art. 6 Abs. 1 lit. f DSGVO)**  
   - Team- und Abteilungsübersichten für Führungskräfte  
   - Planung von Personalbesetzung und Abwesenheiten  
   - Sicherstellung der Betriebskontinuität  

4. **Optional: Projekt- und Kostenstellenerfassung (Art. 6 Abs. 1 lit. b, f DSGVO)**  
   - Zuordnung von Arbeitszeiten zu Projekten oder Kostenstellen  
   - Projektcontrolling und Kundenabrechnung  
   - Diese Funktion ist **logisch und technisch getrennt** von der gesetzlich erforderlichen Kern-Arbeitszeiterfassung.

ArbeitszeitCheck **darf nicht** für unverhältnismäßige Leistungsüberwachung oder Überwachung von Mitarbeitern verwendet werden. Jede zusätzliche Nutzung über die oben genannten Zwecke hinaus erfordert eine **separate Rechtsgrundlage** und gegebenenfalls eine Vereinbarung mit dem Betriebsrat.

---

## 4. Kategorien verarbeiteter personenbezogener Daten

ArbeitszeitCheck folgt dem **Grundsatz der Datenminimierung** (Art. 5 Abs. 1 lit. c DSGVO). Die App ist darauf ausgelegt, **nur die für die gesetzliche Zeiterfassung und Personalverwaltung erforderlichen Daten** zu speichern.

### 4.1 Kern-Arbeitszeitdaten

- **Benutzerkennung** (Nextcloud-Benutzer-ID)  
- **Arbeitstag-Aufzeichnungen**:
  - Start- und Endzeit jeder Arbeitsperiode  
  - Pausenstart- und -endzeiten  
  - Gesamte tägliche Arbeitszeit und Pausendauern (abgeleitet)  
  - Status des Eintrags (aktiv, abgeschlossen, Pause, Genehmigung ausstehend, abgelehnt)  
  - Kennzeichnung, ob ein Eintrag **manuell** erfasst wurde (manuelle Korrektur)  
  - Pflichttext zur Begründung für manuelle Korrekturen/verspätete Einträge  
- **Compliance-Attribute** (abgeleitet):
  - Verstöße gegen maximale tägliche Arbeitszeiten  
  - Wöchentliche Durchschnittsarbeitszeiten (für Compliance mit 48-Stunden-Regel)  
  - Unzureichende Pausen oder Ruhezeiten  
  - Kennzeichnungen für Sonn-, Feiertags- und Nachtarbeit  

### 4.2 Abwesenheits- und Urlaubsdaten

- Art der Abwesenheit (z. B. Urlaub, Krankheit, Sonderurlaub, unbezahlter Urlaub)  
- Start- und Enddatum der Abwesenheit  
- Anzahl betroffener Arbeitstage (berechnet)  
- Genehmigungsstatus (ausstehend, genehmigt, abgelehnt)  
- Begründungstext für Abwesenheit (falls angegeben)  
- Urlaubsanspruch und verbrauchte Urlaubstage pro Kalenderjahr  

### 4.3 Benutzer- und Konfigurationsdaten

- Dem Benutzer zugewiesenes Arbeitszeitmodell (z. B. Vollzeit, Teilzeit, Schichtmodell)  
- Vertragliche wöchentliche/tägliche Arbeitsstunden  
- Kernarbeitszeiten oder Gleitzeitregeln (falls konfiguriert)  
- Nacht-, Sonn- und Feiertagsregeln  
- Benachrichtigungseinstellungen (z. B. Erinnerungen zum Abmelden, Pausenerinnerungen, Fehlende-Eintrag-Warnungen)  
- Vorgesetztenzuweisung (für Genehmigungsworkflows)  

### 4.4 Audit-Log-Daten

Zur Rechenschaftspflicht und Manipulationsschutz speichert ArbeitszeitCheck eine **Audit-Trail** relevanter Aktionen:

- Wer Zeit- oder Abwesenheitseinträge oder Einstellungen erstellt, geändert oder gelöscht hat  
- Wann die Aktion stattfand (Zeitstempel)  
- Was geändert wurde (alte vs. neue Werte, wo technisch möglich)  
- IP-Adresse und Browser-User-Agent (konfigurierbar, kann vom Verantwortlichen deaktiviert werden, falls nicht erforderlich)

### 4.5 Optionale Projekt-/Kostenstellendaten

Falls die optionale ProjectCheck-Integration aktiviert ist:

- Projekt- oder Kostenstellenkennungen  
- Zuordnung zwischen Zeiteinträgen und Projekten/Kostenstellen  
- Optionale Kunden-/Mandantenreferenzen  

Projektdaten werden **getrennt** von den gesetzlich erforderlichen Kern-Zeitaufzeichnungen gehalten; die gesetzliche Compliance hängt **nicht** davon ab, dass Einträge Projekten zugeordnet werden.

### 4.6 Standardmäßig nicht erfasste Daten

ArbeitszeitCheck **erfordert oder implementiert nicht**:

- Bildschirmaufzeichnungen, Tastenanschlag-Protokollierung oder Aktivitätsüberwachung  
- Inhalte von Dokumenten, E-Mails oder Chat-Nachrichten  
- Detaillierte Standortdaten oder GPS-Tracking  
- Biometrische Daten (z. B. Fingerabdrücke, Gesichtserkennung)  

Jede solche Verarbeitung würde **separate Tools, eine separate Rechtsgrundlage und explizite Transparenz** erfordern, die weit über diese App hinausgehen.

---

## 5. Rechtsgrundlagen der Verarbeitung (Art. 6 DSGVO)

Je nach nationalem Recht und konkretem Beschäftigungskontext gelten typischerweise folgende Rechtsgrundlagen:

1. **Art. 6 Abs. 1 lit. c DSGVO – Rechtspflicht**  
   - Erfüllung der ArbZG-Anforderungen für Erfassung und Überwachung der Arbeitszeit  
   - Bereitstellung von Nachweisen für Aufsichtsbehörden und Gerichte  

2. **Art. 6 Abs. 1 lit. b DSGVO – Erfüllung des Arbeitsvertrags**  
   - Berechnung und Dokumentation von Arbeitsstunden und Überstunden  
   - Urlaubs- und Abwesenheitsverwaltung  
   - Vorbereitung der Lohnabrechnung und verwandter HR-Prozesse  

3. **Art. 6 Abs. 1 lit. f DSGVO – Berechtigte Interessen**  
   - Transparente und effiziente Personalplanung  
   - Vermeidung von Burnout und Gesundheitsrisiken durch übermäßige Arbeitszeiten  
   - Dokumentation für interne Audits und Compliance-Management  

4. **Art. 6 Abs. 1 lit. a DSGVO – Einwilligung (nur für optionale Funktionen)**  
   - Optionale zusätzliche Erfassung, die über die strikten gesetzlichen Anforderungen hinausgeht (z. B. GPS-Standort, detaillierte Projektanalysen)  
   - Solche Funktionen müssen **explizit aktiviert** werden und in **separaten Informations- und Einwilligungstexten** erläutert werden.

ArbeitszeitCheck basiert ausdrücklich **nicht** auf Einwilligung für die Kern-Zeiterfassungspflichten: Der Arbeitgeber ist gesetzlich verpflichtet, die Arbeitszeit zu erfassen.

---

## 6. Empfänger und Empfängerkategorien (Art. 13 Abs. 1 lit. e, Abs. 3 DSGVO)

Innerhalb Ihrer Organisation ist der Zugriff auf Daten aus ArbeitszeitCheck typischerweise wie folgt eingeschränkt:

- **Mitarbeiter (Betroffene)**  
  - Können ihre eigenen Arbeitszeitaufzeichnungen, Abwesenheiten, Urlaubsguthaben und Compliance-Benachrichtigungen einsehen.

- **Direkte Vorgesetzte / Teamleiter**  
  - Sehen Zeit- und Abwesenheitsdaten ihrer Teammitglieder, wie für Genehmigungsworkflows, Planung und Compliance erforderlich.

- **Personalabteilung / Personalverwaltung**  
  - Haben erweiterten Zugriff für globale Administration, Berichterstellung und Compliance-Prüfungen.

- **Lohnabrechnung / Buchhaltung**  
  - Erhalten Exporte (z. B. DATEV, CSV) für Gehaltsberechnung und Lohnabrechnungsprozesse.

- **Systemadministratoren (Nextcloud/IT)**  
  - Technischer Zugriff auf die Anwendung und Datenbank für Wartungs- und Backup-Zwecke; sie müssen durch Vertraulichkeit und interne Richtlinien gebunden sein.

Keine Daten werden an den Entwickler der App übertragen (es sei denn, Sie senden aktiv Logs oder Daten zu Supportzwecken), und standardmäßig werden keine Daten an Dritte gesendet.

Wenn Daten an externe Auftragsverarbeiter übertragen werden (z. B. Hosting-Anbieter, IT-Dienstleister), müssen **Auftragsverarbeitungsverträge (Art. 28 DSGVO)** vom Verantwortlichen abgeschlossen werden.

---

## 7. Datenspeicherung und Löschung (Art. 5 Abs. 1 lit. e DSGVO)

ArbeitszeitCheck unterstützt konfigurierbare Aufbewahrungsfristen. Standardmäßig ist das System darauf ausgelegt, mindestens die **zweijährige Aufbewahrung** zu erfüllen, die typischerweise für Arbeitszeitaufzeichnungen in Deutschland erforderlich ist.

Typische Konfiguration (kann vom Verantwortlichen angepasst werden):

- **Zeitaufzeichnungen (Arbeitszeit, Pausen, Verstöße):**  
  - Aufbewahrung: mindestens **2 Jahre** nach Ende des Kalenderjahres, in dem die Aufzeichnung erstellt wurde.  
  - Nach Ablauf: automatische, sichere Löschung oder Anonymisierung.

- **Abwesenheitsdaten und Urlaubsaufzeichnungen:**  
  - Aufbewahrung entsprechend den Anforderungen für Beschäftigungs- und Lohnabrechnungsdokumentation (oft bis zu 3–10 Jahren, je nach nationalem Recht).  

- **Audit-Logs:**  
  - Aufbewahrung so lange wie erforderlich, um Nachvollziehbarkeit, Compliance und Verteidigung rechtlicher Ansprüche sicherzustellen; dann Löschung oder Anonymisierung.

Konkrete Aufbewahrungsfristen müssen von Ihrer Organisation **konfiguriert und dokumentiert** werden, entsprechend lokalem Recht und internen Richtlinien. ArbeitszeitCheck bietet technische Mittel zur Durchsetzung dieser Richtlinien, ersetzt aber nicht Ihre rechtliche Bewertung.

---

## 8. Betroffenenrechte (Art. 12–22 DSGVO)

Mitarbeiter (Betroffene) haben folgende Rechte bezüglich ihrer Daten in ArbeitszeitCheck:

1. **Auskunftsrecht (Art. 15 DSGVO)**  
   - Mitarbeiter können ihre Arbeitszeitaufzeichnungen und Abwesenheiten direkt in der App einsehen.  
   - Sie können ihre Daten in maschinenlesbaren Formaten exportieren (z. B. CSV, JSON) und, wo implementiert, als PDF.

2. **Recht auf Berichtigung (Art. 16 DSGVO)**  
   - Falsche Einträge können über einen **Zeitkorrektur-Anfrage-Workflow** korrigiert werden.  
   - Korrekturen werden im Audit-Log dokumentiert.

3. **Recht auf Löschung (Art. 17 DSGVO)**  
   - Innerhalb der Grenzen gesetzlicher Aufbewahrungspflichten können Mitarbeiter die Löschung von Daten beantragen, die nicht mehr erforderlich sind oder unrechtmäßig verarbeitet werden.  
   - Wo eine sofortige Löschung rechtlich nicht möglich ist, werden Daten gesperrt und nach Ablauf der Aufbewahrungsfrist gelöscht.

4. **Recht auf Einschränkung der Verarbeitung (Art. 18 DSGVO)**  
   - In bestimmten Fällen (z. B. strittige Richtigkeit) können Daten gekennzeichnet/eingeschränkt werden, bis das Problem gelöst ist.

5. **Recht auf Datenübertragbarkeit (Art. 20 DSGVO)**  
   - Mitarbeiter können ihre Zeitaufzeichnungen in strukturierten, gängigen, maschinenlesbaren Formaten erhalten.

6. **Widerspruchsrecht (Art. 21 DSGVO)**  
   - Für Verarbeitungen aufgrund berechtigter Interessen (z. B. Analysen) können Mitarbeiter widersprechen.  
   - **Hinweis:** Verarbeitungen, die zur Erfüllung rechtlicher Verpflichtungen erforderlich sind (Art. 6 Abs. 1 lit. c), können nicht einfach aufgrund eines Widerspruchs gestoppt werden.

Um diese Rechte auszuüben, sollten Mitarbeiter den **Verantwortlichen** oder **DSB** kontaktieren, der in Abschnitt 1 aufgeführt ist. Ihre Organisation sollte klare interne Verfahren und Kontaktwege definieren.

---

## 9. Automatisierte Entscheidungsfindung und Profiling (Art. 22 DSGVO)

ArbeitszeitCheck führt **keine** automatisierte Entscheidungsfindung im Sinne von Art. 22 DSGVO durch, die rechtliche Wirkung oder ähnlich erhebliche Auswirkungen auf Mitarbeiter hat.

- Compliance-Prüfungen und Verstoß-Kennzeichnungen sind **regelbasierte** Auswertungen von Arbeitszeitaufzeichnungen (z. B. „mehr als 10 Stunden gearbeitet“, „unzureichende Pause“).  
- Entscheidungen über Konsequenzen (z. B. Führungsmaßnahmen, HR-Maßnahmen) werden von **menschlichen Entscheidungsträgern** getroffen, nicht von der App.

---

## 10. Technische und organisatorische Maßnahmen (Art. 32 DSGVO)

ArbeitszeitCheck basiert auf der Nextcloud-Plattform und nutzt deren Sicherheitsarchitektur:

- **Authentifizierung und Zugriffskontrolle** über Nextcloud-Konten und -Gruppen  
- **Rollenbasierte Ansichten** für Mitarbeiter, Führungskräfte, HR und Administratoren  
- **Transportsicherheit:** HTTPS/TLS wird auf der Nextcloud-Ebene erzwungen  
- **Datenbanksicherheit:** Verwendet parametrisierte Abfragen und Nextclouds DB-Abstraktion (QBMapper)  
- **Eingabevalidierung und Ausgabe-Escaping** zur Minderung gängiger Web-Schwachstellen (z. B. XSS, SQL-Injection)  
- **Content Security Policy (CSP)**: Alle JS/CSS werden über Nextclouds Asset-Pipeline geladen, keine Inline-Skripte/-Styles  
- **Audit-Protokollierung** kritischer Aktionen  
- **Konfigurierbare Hintergrundjobs** für Compliance-Prüfungen und Benachrichtigungen  

Ihre Organisation bleibt verantwortlich für:

- Sichere Konfiguration des Nextcloud-Servers (Patches, Firewall, Backups)  
- Ordnungsgemäße Zuweisung von Rollen und Berechtigungen  
- Sichere Speicherung von Verschlüsselungsschlüsseln und Datenbankzugangsdaten  
- Regelmäßige Überprüfung von Logs und Verstoßberichten  

---

## 11. Betriebsrat und Mitbestimmung (§87 BetrVG)

Wo ein **Betriebsrat** existiert, müssen dessen Mitbestimmungsrechte nach §87 BetrVG respektiert werden.

ArbeitszeitCheck ist als **Compliance- und Dokumentationstool** konzipiert, nicht als Überwachungssystem:

- Fokus auf gesetzliche Arbeitszeit-Compliance, nicht auf Leistungsranking  
- Aggregierte und anonymisierte Statistiken können für Berichterstellung und Planung verwendet werden  
- Individuelle Leistungsüberwachung über das gesetzlich Erforderliche hinaus muss vermieden oder separat mit dem Betriebsrat vereinbart werden.

Wir empfehlen:

- Abschluss einer **Betriebsvereinbarung** speziell für ArbeitszeitCheck  
- Definition klarer Regeln darüber, wer welche Daten zu welchen Zwecken einsehen kann  
- Dokumentation zusätzlicher Auswertungen (z. B. Projektanalysen)

---

## 12. Internationale Datenübertragungen

ArbeitszeitCheck selbst initiiert keine Datenübertragungen außerhalb Ihrer Infrastruktur.  
Wenn Ihre Nextcloud-Instanz oder Datenbank von einem Anbieter außerhalb der EU/des EWR gehostet wird oder Support-Partner aus Drittländern Zugriff auf das System haben, müssen angemessene Garantien vorhanden sein:

- Angemessenheitsbeschluss (Art. 45 DSGVO), oder  
- Standardvertragsklauseln (Art. 46 DSGVO), und  
- Zusätzliche technische und organisatorische Garantien, wo erforderlich.

Diese Aspekte liegen in der Verantwortung Ihrer Organisation als Verantwortlicher.

---

## 13. Änderungen dieser Datenschutzerklärung

Diese Datenschutzerklärung muss möglicherweise aktualisiert werden, wenn:

- Rechtliche Anforderungen sich ändern (z. B. neue Rechtsprechung, Änderungen am ArbZG oder der DSGVO),  
- Neue Funktionen eingeführt werden, die die Datenverarbeitung beeinflussen (z. B. neue Arten von Analysen, Integrationen), oder  
- Ihre internen Prozesse oder Systemlandschaft sich ändern.

Der Verantwortliche ist verantwortlich für:

- Aktualisierung dieses Dokuments,  
- Transparente Information der Mitarbeiter über wesentliche Änderungen, und  
- Sicherstellung, dass die tatsächliche Nutzung von ArbeitszeitCheck dieser Richtlinie entspricht.

---

## 14. Fragen und Beschwerden

Mitarbeiter können sich wenden an:

- **Verantwortlicher / Personalabteilung** für Fragen bezüglich Arbeitszeitdaten und HR-Nutzung des Systems.  
- **Datenschutzbeauftragter** für Datenschutzfragen oder zur Ausübung ihrer Rechte.  
- **Aufsichtsbehörde** (Art. 77 DSGVO), um eine Beschwerde einzulegen, wenn sie glauben, dass die Datenverarbeitung rechtswidrig ist.

Bitte tragen Sie die konkreten Kontaktdaten und Aufsichtsbehörden-Informationen ein, die für Ihre Organisation gelten.
