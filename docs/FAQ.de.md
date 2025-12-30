# Häufig gestellte Fragen (FAQ) – ArbeitszeitCheck

**Letzte Aktualisierung:** 2025-12-29

## Allgemeine Fragen

### Was ist ArbeitszeitCheck?

**ArbeitszeitCheck** (auch bekannt als **TimeGuard** auf Englisch) ist ein rechtskonformes Zeiterfassungssystem, das speziell für deutsche Organisationen entwickelt wurde. Es hilft Unternehmen, die Anforderungen des deutschen Arbeitszeitgesetzes (ArbZG) zu erfüllen und gleichzeitig DSGVO-Compliance sicherzustellen.

### Wer sollte diese App verwenden?

- **Deutsche Unternehmen**, die ArbZG-konform sein müssen (obligatorische Zeiterfassung)
- **Organisationen**, die DSGVO-konforme Zeiterfassung wünschen
- **HR-Abteilungen**, die Arbeitszeiten von Mitarbeitern verwalten
- **Mitarbeiter**, die ihre Arbeitszeit erfassen müssen

### Ist diese App kostenlos?

Ja, ArbeitszeitCheck ist **Open-Source** und unter AGPL-3.0 lizenziert. Sie können es frei verwenden, müssen sich aber an die Lizenzbedingungen halten (wenn Sie es ändern, müssen Sie Ihre Änderungen teilen).

### Welche Nextcloud-Versionen werden unterstützt?

ArbeitszeitCheck unterstützt Nextcloud **27, 28 und 29** (und zukünftige LTS-Versionen). Die App erfordert PHP 8.1 oder höher.

---

## Installation & Einrichtung

### Wie installiere ich ArbeitszeitCheck?

**Aus dem App Store (Empfohlen):**
1. Gehen Sie zu **"Einstellungen"** → **"Apps"** in Ihrer Nextcloud
2. Suchen Sie nach "ArbeitszeitCheck"
3. Klicken Sie auf **"Installieren"** und aktivieren Sie die App

**Manuelle Installation:**
```bash
cd /pfad/zur/nextcloud/apps/
git clone https://github.com/nextcloud/arbeitszeitcheck.git
cd arbeitszeitcheck
npm install && npm run build
php occ app:enable arbeitszeitcheck
```

### Muss ich nach der Installation etwas konfigurieren?

Ja, Administratoren sollten:
1. **Globale Einstellungen** konfigurieren (Bundesland, Arbeitszeitgrenzen)
2. **Arbeitszeitmodelle** für verschiedene Mitarbeitergruppen einrichten
3. **Arbeitszeitmodelle** Benutzern zuweisen
4. **Urlaubsansprüche** pro Benutzer konfigurieren
5. **Vorgesetztenzuweisungen** für Genehmigungsworkflows einrichten

Siehe [Administrator-Handbuch](Administrator-Handbuch.de.md) für detaillierte Anweisungen.

### Kann ich dies in Docker verwenden?

Ja, ArbeitszeitCheck ist vollständig mit Docker kompatibel. Die App enthält ein Build-Skript (`build.sh`) für Docker-Umgebungen.

---

## Zeiterfassung

### Wie stempele ich ein/aus?

1. Gehen Sie zur **ArbeitszeitCheck**-App
2. Klicken Sie auf den **"Einstempeln"**-Button, um die Erfassung zu starten
3. Klicken Sie auf **"Ausstempeln"**, wenn Sie fertig sind
4. Das System erfasst automatisch Ihre Startzeit, Endzeit und berechnet Ihre Arbeitsstunden

### Was, wenn ich vergesse, ein- oder auszustempeln?

Sie können einen **manuellen Zeiteintrag** erstellen:
1. Gehen Sie zu **"Zeiteinträge"** → **"Manuellen Eintrag hinzufügen"**
2. Geben Sie Datum und gearbeitete Stunden ein
3. Geben Sie eine **Begründung** an (Pflicht für Audit-Zwecke)
4. Speichern Sie den Eintrag

**Hinweis:** Manuelle Einträge können von Ihrem Vorgesetzten überprüft werden.

### Kann ich meine Zeiteinträge bearbeiten?

- **Manuelle Einträge**: Ja, Sie können sie bearbeiten oder löschen
- **Automatische Einträge** (Ein-/Ausstempeln): Nein, diese sind manipulationssicher für rechtliche Compliance
- **Abgeschlossene Einträge**: Sie können eine Korrektur anfordern (erfordert Vorgesetzten-Genehmigung)

### Wie funktionieren Pausen?

- Klicken Sie auf **"Pause beginnen"**, während Sie eingestempelt sind
- Der Timer pausiert automatisch
- Klicken Sie auf **"Pause beenden"**, um fortzufahren
- Das System erfasst Ihre Gesamtpausenzeit

**Gesetzliche Anforderungen:**
- Nach **6 Stunden** Arbeit: Mindestens **30 Minuten** Pause erforderlich
- Nach **9 Stunden** Arbeit: Mindestens **45 Minuten** Pause erforderlich

---

## Compliance & Rechtliches

### Welche gesetzlichen Anforderungen erfüllt diese App?

ArbeitszeitCheck erfüllt:
- **Maximale tägliche Stunden**: 8 Stunden (erweiterbar auf 10 Stunden)
- **Maximale wöchentliche Stunden**: 48 Stunden Durchschnitt (über 6 Monate)
- **Ruhezeiten**: Mindestens 11 Stunden zwischen Schichten
- **Pausenanforderungen**: 30 Min nach 6 Stunden, 45 Min nach 9 Stunden
- **Nachtarbeitsverfolgung**: Arbeit zwischen 23 Uhr und 6 Uhr
- **Sonntags-/Feiertagsarbeit**: Erkennung und Dokumentation

### Was passiert, wenn ich Compliance-Regeln verletze?

Das System wird:
1. **Den Verstoß automatisch erkennen**
2. **Sie benachrichtigen** über Benachrichtigung
3. **HR benachrichtigen** zur Überprüfung
4. **Den Verstoß protokollieren** im Compliance-Log
5. **Bestimmte Aktionen verhindern** (z.B. Einstempeln, wenn Ruhezeit nicht erfüllt)

Verstöße können mit ordnungsgemäßer Dokumentation gelöst werden.

### Ist meine Daten DSGVO-konform?

Ja, ArbeitszeitCheck ist für DSGVO-Compliance entwickelt:
- **Datenminimierung**: Erfasst nur gesetzlich erforderliche Daten
- **Zweckbindung**: Daten werden nur für Arbeitszeitgesetz-Compliance verwendet
- **Mitarbeiterrechte**: Vollständige Zugriffs-, Berichtigungs- und Löschungsrechte
- **Datenaufbewahrung**: Automatische Löschung nach 2 Jahren (Minimum)
- **Protokolldaten**: Vollständige Protokollierung aller Operationen

### Kann ich meine persönlichen Daten exportieren?

Ja, Sie haben das Recht, alle Ihre Daten zu exportieren (DSGVO Art. 15):
1. Gehen Sie zu **"Einstellungen"** → **"Persönlich"** → **"ArbeitszeitCheck"**
2. Klicken Sie auf **"Persönliche Daten exportieren"**
3. Laden Sie Ihre vollständigen Daten im JSON-Format herunter

### Kann ich meine Daten löschen?

Ja, aber mit Einschränkungen:
- Daten älter als **2 Jahre** können gelöscht werden (DSGVO Art. 17)
- Aktuelle Daten müssen für **Arbeitszeitgesetz-Compliance** aufbewahrt werden (ArbZG-Anforderung)
- Protokolldaten werden für rechtliche Compliance aufbewahrt

---

## Abwesenheitsverwaltung

### Wie beantrage ich Urlaub?

1. Gehen Sie zu **"Abwesenheiten"** → **"Abwesenheit beantragen"**
2. Wählen Sie **"Urlaub"** als Typ
3. Geben Sie Start- und Enddatum ein
4. Senden Sie die Anfrage
5. Ihr Vorgesetzter wird benachrichtigt und kann genehmigen/ablehnen

### Kann ich einen Urlaubsantrag stornieren?

Ja, wenn die Anfrage noch **Genehmigung ausstehend** ist:
1. Finden Sie die Anfrage in Ihrer Abwesenheitsliste
2. Klicken Sie auf **"Löschen"**
3. Bestätigen Sie die Stornierung

**Hinweis:** Sie können genehmigte Anfragen nicht stornieren (kontaktieren Sie Ihren Vorgesetzten).

### Wie werden Urlaubstage berechnet?

- Das System berechnet **Arbeitstage** automatisch (schließt Wochenenden und Feiertage aus)
- Ihr **Urlaubsanspruch** wird von Ihrem Administrator festgelegt
- Verwendete Tage werden automatisch verfolgt
- Verbleibende Tage werden auf Ihrem Dashboard angezeigt

### Welche Abwesenheitstypen kann ich beantragen?

- **Urlaub**: Reguläre Urlaubstage
- **Krankmeldung**: Krankheitsbedingte Abwesenheiten
- **Sonderurlaub**: Besondere Umstände
- **Unbezahlter Urlaub**: Urlaub ohne Lohnfortzahlung

---

## Vorgesetzten-Funktionen

### Was können Vorgesetzte tun?

Vorgesetzte können:
- **Teamübersicht anzeigen**: Sehen, wer eingestempelt ist, gearbeitete Stunden
- **Abwesenheiten genehmigen/ablehnen**: Abwesenheitsanträge prüfen
- **Zeiteintragskorrekturen genehmigen/ablehnen**: Korrekturanfragen prüfen
- **Compliance überwachen**: Team-Compliance-Status einsehen
- **Berichte generieren**: Team-Berichte erstellen

### Wie werde ich Vorgesetzter?

Vorgesetzte werden von Administratoren zugewiesen. Kontaktieren Sie Ihre HR-Abteilung oder Systemadministrator.

### Kann ich die Zeiteinträge meines Teams sehen?

Ja, Vorgesetzte können einsehen:
- Zeiteinträge von Teammitgliedern (mit entsprechenden Berechtigungen)
- Team-Arbeitsstunden-Zusammenfassungen
- Team-Compliance-Verstöße
- Team-Abwesenheitskalender

---

## Technische Fragen

### Funktioniert dies offline?

Nein, ArbeitszeitCheck erfordert eine aktive Nextcloud-Verbindung. Die Weboberfläche ist jedoch responsiv und funktioniert gut auf mobilen Geräten.

### Kann ich dies auf meinem Telefon verwenden?

Ja, die App ist **vollständig responsiv** und funktioniert auf:
- Mobilen Browsern (iOS Safari, Android Chrome)
- Tablet-Browsern
- Desktop-Browsern

**Hinweis:** Eine native Mobile-App ist nicht verfügbar, aber die Weboberfläche ist für mobile Nutzung optimiert.

### Integriert sich dies mit anderen Nextcloud-Apps?

Ja, ArbeitszeitCheck integriert sich mit:
- **ProjectCheck** (optional): Projektdaten und Zeiterfassung teilen
- **Nextcloud Kalender**: Abwesenheiten mit Kalender synchronisieren
- **Nextcloud Dateien**: Exportierte Berichte speichern

### Kann ich die API verwenden?

Ja, ArbeitszeitCheck bietet eine **RESTful API** für Integration. Siehe [API-Dokumentation](API-Documentation.en.md) für Details.

### Welche Datenbanken werden unterstützt?

- **MySQL/MariaDB** (empfohlen)
- **PostgreSQL**
- **SQLite** (für kleine Installationen)

---

## Fehlerbehebung

### Ich kann nicht einstempeln. Was ist falsch?

**Mögliche Gründe:**
- Sie haben bereits einen aktiven Zeiteintrag → Stempeln Sie zuerst aus
- Weniger als 11 Stunden seit Ihrer letzten Schicht → Warten Sie, bis die Ruhezeit erfüllt ist
- Systemfehler → Kontaktieren Sie den IT-Support

### Das System sagt, mir fehlt eine Pause, aber ich habe eine genommen.

**Mögliche Gründe:**
- Pause war kürzer als erforderlich (30 Min nach 6 Stunden, 45 Min nach 9 Stunden)
- Pause wurde nicht ordnungsgemäß erfasst → Prüfen Sie Ihre Zeiteintragsdetails
- Systemberechnungsfehler → Kontaktieren Sie den Support

**Hinweis:** Sie können Pausen nicht rückwirkend zu vergangenen Einträgen hinzufügen, aber der Verstoß dient als Dokumentation.

### Mein Zeiteintrag ist falsch. Wie behebe ich das?

**Wenn es ein manueller Eintrag ist:**
- Bearbeiten Sie ihn direkt aus der Zeiteinträge-Liste

**Wenn es ein automatischer Eintrag ist:**
- Korrekturanfrage stellen (erfordert Vorgesetzten-Genehmigung)
- Gehen Sie zum Eintrag → **"Korrektur anfordern"** → Füllen Sie Begründung und korrigierte Daten aus

### Ich kann die Genehmigung meines Vorgesetzten nicht sehen.

**Prüfen Sie:**
- Ihre **Benachrichtigungen** (Glocken-Symbol)
- Den **Status** in Ihrer Abwesenheiten/Zeiteinträge-Liste
- Kontaktieren Sie Ihren Vorgesetzten, wenn der Status unklar ist

### Die App ist langsam oder lädt nicht.

**Versuchen Sie:**
1. Seite aktualisieren (Strg+F5 oder Cmd+Shift+R)
2. Browser-Cache leeren
3. Nextcloud-Serverstatus prüfen
4. IT-Support kontaktieren, wenn Problem weiterhin besteht

---

## Datenschutz & Sicherheit

### Wer kann meine Zeitdaten sehen?

- **Sie**: Vollzugriff auf Ihre eigenen Daten
- **Ihr Vorgesetzter**: Kann Ihre Zeiteinträge und Abwesenheiten sehen (für Genehmigung)
- **HR/Administratoren**: Können alle Daten sehen (für Compliance und Berichterstattung)
- **Niemand sonst**: Daten sind durch Nextclouds Berechtigungssystem geschützt

### Sind meine Daten verschlüsselt?

Ja:
- **In Übertragung**: HTTPS/TLS-Verschlüsselung
- **Im Ruhezustand**: Datenbankverschlüsselung (wenn in Nextcloud konfiguriert)
- **Zugriffskontrolle**: Rollenbasierte Berechtigungen

### Kann ich mich von der Zeiterfassung abmelden?

**Nein**, wenn Ihre Organisation Zeiterfassung für rechtliche Compliance (ArbZG) erfordert, können Sie sich nicht abmelden. Sie haben jedoch vollständige Transparenz und Kontrolle über Ihre Daten.

### Welche Daten werden erfasst?

**Nur gesetzlich erforderliche Daten:**
- Startzeit, Endzeit, Pausenzeiten
- Arbeitsdauer
- Abwesenheitsanträge
- Compliance-Verstöße (für rechtliche Dokumentation)

**NICHT erfasst:**
- Detaillierte Standortdaten (es sei denn, explizit mit Einwilligung aktiviert)
- Aktivitätsüberwachung oder Screenshots
- Leistungsbewertungsdaten

---

## Integration mit ProjectCheck

### Was ist ProjectCheck-Integration?

Wenn sowohl **ArbeitszeitCheck** als auch **ProjectCheck**-Apps installiert sind, integrieren sie sich nahtlos:
- Projekte aus ProjectCheck erscheinen in der Zeiterfassung
- Zeiteinträge können Projekten zugewiesen werden
- Projektbudgets werden automatisch aktualisiert
- Einheitliche Projektberichterstattung

### Brauche ich ProjectCheck, um ArbeitszeitCheck zu verwenden?

**Nein**, ProjectCheck ist **optional**. ArbeitszeitCheck funktioniert perfekt ohne es. Die Integration ist nur aktiv, wenn beide Apps installiert sind.

### Wie aktiviere ich ProjectCheck-Integration?

1. Installieren Sie beide Apps (ArbeitszeitCheck und ProjectCheck)
2. Die Integration ist **automatisch** - keine Konfiguration erforderlich
3. Projekte erscheinen in Zeiteintragsformularen

---

## Support & Hilfe

### Wo kann ich Hilfe erhalten?

- **Benutzerhandbuch**: Siehe `docs/Benutzerhandbuch.de.md`
- **Administrator-Handbuch**: Siehe `docs/Administrator-Handbuch.de.md`
- **API-Dokumentation**: Siehe `docs/API-Documentation.en.md`
- **GitHub Issues**: https://github.com/nextcloud/arbeitszeitcheck/issues
- **IT-Support**: Kontaktieren Sie die IT-Abteilung Ihrer Organisation

### Wie melde ich einen Fehler?

1. Prüfen Sie, ob es ein bekanntes Problem ist: https://github.com/nextcloud/arbeitszeitcheck/issues
2. Erstellen Sie ein neues Issue mit:
   - Was Sie versucht haben
   - Was stattdessen passiert ist
   - Fehlermeldungen (falls vorhanden)
   - Browser und Version
   - Nextcloud-Version

### Kann ich zum Projekt beitragen?

Ja! ArbeitszeitCheck ist Open-Source. Siehe `CONTRIBUTING.md` für Richtlinien.

---

## Rechtliches & Compliance

### Ist diese App rechtlich konform?

ArbeitszeitCheck ist entwickelt, um Organisationen bei der Einhaltung zu helfen:
- **Deutsches Arbeitszeitgesetz (ArbZG)**: Erfüllt alle obligatorischen Anforderungen
- **DSGVO**: Implementiert alle Datenschutzanforderungen

**Jedoch** bleiben Organisationen für ihre eigene rechtliche Compliance verantwortlich. Konsultieren Sie immer Rechtsanwälte für Ihre spezifische Situation.

### Brauche ich eine Betriebsvereinbarung?

**Möglicherweise**, wenn Ihre Organisation einen Betriebsrat hat. Die App bietet eine [Betriebsvereinbarungs-Vorlage](Works-Council-Agreement-Template.de.md) zur Hilfe.

### Was ist mit Datenschutz-Folgenabschätzung (DSFA)?

Organisationen sollten eine DSFA durchführen. Die App bietet eine [DSFA-Vorlage](DPIA-Template.en.md) zur Anleitung des Prozesses.

---

## Verschiedenes

### Kann ich die App anpassen?

**Begrenzte Anpassung:**
- Administratoren können Arbeitszeitmodelle, Grenzen und Einstellungen konfigurieren
- Die App folgt Nextclouds Design-System (konsistent mit anderen Apps)
- Benutzerdefinierte Themes werden über Nextclouds Theming-System unterstützt

**Keine Anpassung:**
- Kern-Compliance-Regeln (können nicht deaktiviert werden)
- Datenaufbewahrungsanforderungen (gesetzliche Anforderung)
- DSGVO-Rechte (gesetzliche Anforderung)

### Kann ich dies für Leistungsbewertung verwenden?

**Nein**, ArbeitszeitCheck ist nur für **rechtliche Compliance** entwickelt, nicht für Leistungsbewertung. Die Verwendung von Zeitdaten für Leistungsbewertung erfordert separate Rechtsgrundlage und Betriebsvereinbarung.

### Erfasst dies meinen Standort?

**Nein**, standardmäßig erfasst ArbeitszeitCheck **keinen** Standort. Optionale GPS-Erfassung kann mit expliziter Einwilligung und klarer Dokumentation aktiviert werden, ist aber nicht für rechtliche Compliance erforderlich.

---

**Haben Sie noch Fragen?** Prüfen Sie das [Benutzerhandbuch](Benutzerhandbuch.de.md) oder [Administrator-Handbuch](Administrator-Handbuch.de.md), oder öffnen Sie ein Issue auf GitHub.
