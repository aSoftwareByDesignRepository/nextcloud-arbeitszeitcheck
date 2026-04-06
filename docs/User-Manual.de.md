# ArbeitszeitCheck — Kurzanleitung (Deutsch)

Diese Kurzanleitung richtet sich an **Endnutzerinnen und Endnutzer** sowie an **Führungskräfte** und ergänzt die in der App sichtbare Oberfläche. Sie liegt im Ordner `docs/` und kann intern weitergegeben werden.

**Rechtliches / DSGVO:** [GDPR-Compliance-Guide.en.md](GDPR-Compliance-Guide.en.md) (englisch, inhaltlich für Betrieb relevant). **Technische ArbZG-Umsetzung:** [Compliance-Implementation.de.md](Compliance-Implementation.de.md).

---

## 1. Was die App leistet

ArbeitszeitCheck erfasst **Arbeitszeiten**, prüft konfigurierbare **ArbZG-Regeln** (Pausen, Ruhezeiten, Höchstzeiten), verwaltet **Abwesenheiten** (Urlaub, Krankheit, …) inkl. Genehmigungen nach betrieblicher Ausgestaltung und bietet **Berichte und Exporte**.

Alle Daten verbleiben in Ihrer **Nextcloud-Instanz**.

---

## 2. Kalender: Ansicht in der App vs. Nextcloud-Kalender

| Thema | Was Sie erwarten können |
|--------|---------------------------|
| **Kalenderansicht in ArbeitszeitCheck** | Die App hat eine eigene **Monatskalender-Ansicht** (Arbeitszeiten und Abwesenheiten). Das ist **nicht** die separate App „Kalender“. |
| **Nextcloud-Kalender-App** | ArbeitszeitCheck **synchronisiert keine** Abwesenheiten in die Nextcloud-**Kalender**-App (kein CalDAV-Abgleich durch diese App). |
| **Alte Kalender** | Falls früher Kalender in der Kalender-App angelegt wurden, **bleiben** diese dort, bis Sie sie dort **manuell löschen**. Die App entfernt sie nicht automatisch. |
| **E-Mail mit `.ics`-Anhang** | In manchen Abläufen kann die App **E-Mails** mit einer iCalendar-Datei senden, damit Empfänger einen Termin **manuell** importieren können. Das ist optionale E-Mail, **keine** dauerhafte Zwei-Wege-Synchronisation. |

---

## 3. Rollen (typisch)

- **Mitarbeitende**: Zeiten erfassen, Abwesenheiten beantragen, eigene Daten einsehen.
- **Vertretung** (falls genutzt): Eindeckung für Abwesenheiten bestätigen oder ablehnen.
- **Führungskraft / Team**: Abwesenheiten genehmigen, Teamübersichten je nach Rechten.
- **Administratorin / Administrator**: Globale Einstellungen, Nutzer, Feiertage, Compliance-Optionen, Exporte.

Die genauen Rechte hängen von Nextcloud-Gruppen und der App-Konfiguration ab.

---

## 4. Alltägliche Aufgaben

- **Kommen/Gehen und Pausen** über die Zeiterfassung; Korrekturen und Begründungen nach internen Regeln.
- **Abwesenheiten** beantragen und ggf. auf Freigabe warten. **Resturlaub** und Überträge werden angezeigt, wenn die Administration das gepflegt hat.
- **Berichte** für Zeiträume erstellen und erlaubte Exporte nutzen (CSV, DATEV, …).
- **Compliance-Hinweise** (z. B. fehlende Pausen) nach Vorgabe des Arbeitgebers bearbeiten.

---

## 5. Feiertage (Deutschland)

Gesetzliche und betriebliche Feiertage hängen vom **Bundesland** und den **Administrator-Einstellungen** ab. Die App nutzt diese Daten für Arbeitstags- und Prüflogik—**nicht** zum automatischen Befüllen der Nextcloud-Kalender-App.

---

## 6. Datenschutz

Personenbezogene Daten werden für **Zeiterfassung und damit verbundene HR-Prozesse** verarbeitet, wie von Ihrer Organisation festgelegt. **DSGVO-Export und -Löschung** nur im Rahmen von Vorgaben und Aufbewahrungsfristen nutzen (siehe verlinkte Dokumente).

---

## 7. Hilfe

- **Interne Fragen**: IT oder Nextcloud-Administration Ihrer Organisation.
- **Fehler in der App**: Meldung über die im App-Store genannte Projektvorgabe möglich.

---

*Stand: zur App-Version 1.1.x passend; exakte Versionsnummer: `appinfo/info.xml`.*
