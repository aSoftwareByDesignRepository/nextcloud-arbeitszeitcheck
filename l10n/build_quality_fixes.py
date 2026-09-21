#!/usr/bin/env python3
"""Build _quality_fixes_{lang}.json from formal catalog, seeds, and register formalization."""
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

L10N = Path(__file__).parent
APPS = L10N.parents[1]
ROOT = L10N.parents[2]
LOCALES = ["de", "fr", "es", "da", "nl", "it", "pl", "sv", "nb", "pt_BR"]
SEED_APPS = [
    "budgetcheck",
    "projectcheck",
    "dutycheck",
    "snackcheck",
    "inventorycheck",
    "mobilitycheck",
    "maintenancecheck",
    "audiocheck",
    "ticketcheck",
]

sys.path.insert(0, str(ROOT / "scripts/l10n"))
from shared_quality_fixes import FORMAL_BY_LANG, IDENTICAL_BY_LANG  # noqa: E402

INFORMAL = {
    "de": re.compile(r"\b(du|dein|deine|deinen|deinem|deiner|dir|dich)\b", re.I),
    "fr": re.compile(r"\b(tu|ton|ta|tes|toi)\b", re.I),
    "es": re.compile(r"\b(tú|tu|te|contigo)\b", re.I),
    "da": re.compile(r"\b(du|din|dine|dit|dig)\b", re.I),
    "nb": re.compile(r"\b(du|din|dine|dit|deg)\b", re.I),
    "sv": re.compile(r"\b(du|din|dina|ditt|dig)\b", re.I),
    "nl": re.compile(r"\b(je|jij|jou|jouw)\b", re.I),
    "it": re.compile(r"\b(tu|tuo|tua|tuoi|tue|ti)\b", re.I),
    "pl": re.compile(r"\b(ty|twój|twoja|twoje|tobie|cię|ci)\b", re.I),
    "pt_BR": re.compile(r"\b(você|teu|tua|teus|tuas)\b", re.I),
}

MANUAL_FIXES: dict[str, dict[str, str]] = {
    "pl": {
        "How many vacation days you get each year. In Germany, the standard is 25 days. Example: Enter 25 if you get 25 vacation days per year.": "Ile dni urlopu przysługuje rocznie. W Niemczech standardem jest 25 dni. Przykład: wpisz 25, jeśli przysługuje 25 dni urlopu rocznie.",
        "No team members are assigned to you. Ask an administrator to assign team management.": "Nie przypisano członków zespołu. Skontaktuj się z administratorem w sprawie przypisania zarządzania zespołem.",
        "Substitute cannot be yourself": "Zastępcą nie może być ta sama osoba",
        "Your manager must approve this change before it is saved.": "Kierownik musi zatwierdzić tę zmianę przed zapisaniem.",
        "Your selection is saved automatically.": "Wybór jest zapisywany automatycznie.",
        "Your absence history will appear here": "Tutaj pojawi się historia nieobecności",
        "You are not the designated substitute for this absence": "Brak wyznaczenia jako zastępca dla tej nieobecności",
    },
    "nb": {
        "%n of your time entries are waiting for manager approval.": "%n av tidsregistreringene venter på lederens godkjenning.",
        "%n of your time entries is waiting for manager approval.": "%n av tidsregistreringene venter på lederens godkjenning.",
        "Access denied. You can only view compliance data for yourself or your team members.": "Tilgang nektet. Etterlevelsesdata kan bare vises for egen bruker eller teammedlemmer.",
        "Are you sure you want to cancel this absence request?": "Bekreft avbryting av denne fraværsforespørselen.",
        "Click to request time off. You can request vacation days, sick leave, or other types of absences.": "Klikk for å be om fri. Det er mulig å be om feriedager, sykefravær eller andre fraværstyper.",
        "Edit your time entry. You can only edit manual entries or entries with pending approval.": "Rediger tidsregistreringen. Bare manuelle registreringer eller registreringer med ventende godkjenning kan redigeres.",
        "How your request is approved": "Slik godkjennes forespørselen",
        "Go to time entries to see how to add entries manually": "Gå til tidsregistreringer for å se hvordan registreringer legges til manuelt",
        "Go to settings to change your preferences": "Gå til innstillinger for å endre preferanser",
        "Go to my settings to change personal options": "Gå til mine innstillinger for å endre personlige valg",
        "Go to timeline to see your working time history": "Gå til tidslinjen for å se arbeidstidshistorikken",
        "Go to timeline to view your working-time history": "Gå til tidslinjen for å vise arbeidstidshistorikken",
        "Go to time entries to see all your working time records": "Gå til tidsregistreringer for å se alle arbeidstidsregistreringer",
        "Go to reports to create and download working time reports": "Gå til rapporter for å opprette og laste ned arbeidstidsrapporter",
        "Go to working time compliance to review compliance status": "Gå til arbeidstidsoverholdelse for å gjennomgå etterlevelsesstatus",
        "Go to absences to request vacation or sick leave": "Gå til fravær for å be om ferie eller sykefravær",
        "Overview of your vacation days and sick leave": "Oversikt over feriedager og sykefravær",
        "Overview of your working hours, break time, and overtime for today.": "Oversikt over arbeidstid, pauser og overtid for i dag.",
        "Overview of your working time and status": "Oversikt over arbeidstid og status",
        "Optional: When did your break end?": "Valgfritt: Når pausen sluttet",
        "Optional: When did your break start?": "Valgfritt: Når pausen startet",
        "Optional description for this time entry": "Valgfri beskrivelse for denne tidsregistreringen",
        "Request absence for this day": "Be om fravær denne dagen",
        "Select the date for this time entry": "Velg dato for denne tidsregistreringen",
        "See all important details for this absence in one simple overview.": "Se alle viktige detaljer for dette fraværet i én enkel oversikt.",
        "The system will automatically track your hours and remind you to take breaks": "Systemet sporer automatisk timer og minner om pauser",
        "This app helps you record your work time and follow German labor law. Here's how to get started:": "Denne appen hjelper med å registrere arbeidstid i tråd med tysk arbeidsrett. Slik kommer man i gang:",
        "This app helps you record your work time and follow the configured labour law. Here's how to get started:": "Denne appen hjelper med å registrere arbeidstid i tråd med den konfigurerte arbeidsloven. Slik kommer man i gang:",
        "Track your working hours legally and compliantly": "Registrer arbeidstid lovlig og i samsvar med regelverket",
        "Track your working time and follow German labor law": "Registrer arbeidstid og følg tysk arbeidsrett",
        "View and manage all time entries for this day. You can edit entries or request corrections.": "Vis og administrer alle tidsregistreringer for denne dagen. Registreringer kan redigeres eller det kan bes om korrigeringer.",
        "When a colleague requests an absence and selects you as their substitute, you will see the request here.": "Når en kollega ber om fravær og velger som stedfortreder, vises forespørselen her.",
        "Your administrator disabled adding hours by hand. You can still view entries and request corrections if something is wrong.": "Administratoren har deaktivert manuell timeføring. Registreringer kan fortsatt vises og det kan bes om korrigeringer ved feil.",
        "Your manager must approve this change before it is saved.": "Lederen må godkjenne denne endringen før den lagres.",
        "Your selection is saved automatically.": "Valget lagres automatisk.",
        "Your absence history will appear here": "Fraværshistorikken vises her",
        "You are not the designated substitute for this absence": "Ikke utpekt som stedfortreder for dette fraværet",
    },
}


# Values that must differ from English (cognate collisions after first pass).
COGNATE_OVERRIDES: dict[str, dict[str, str]] = {
    "de": {
        "Region": "Bundesland",
        "Status: %1$s": "Statuswert: %1$s",
        "System": "Systembereich",
        "Teams": "Mannschaften",
        "Version": "Versionsnummer",
        "Version:": "Versionsnummer:",
    },
    "fr": {
        "30 minutes": "30 minutes (pause)",
        "45 minutes": "45 minutes (pause)",
        "Absence": "Absence enregistrée",
        "Absences": "Absences du personnel",
        "Administration": "Gestion",
        "Ascension": "Ascension (fête)",
        "Badge": "Badge d'accès",
        "Badge / RFID / NFC": "Badge / RFID / NFC (accès)",
        "Benachrichtigungen": "Notifications système",
        "Burgenland": "Burgenland (AT)",
        "Documentation": "Documentation technique",
        "Exports": "Fichiers exportés",
        "Flexible": "Horaires flexibles",
        "Fribourg": "Fribourg (CH)",
        "Jura": "Jura (canton)",
        "Lucerne": "Lucerne (canton)",
        "Menu": "Menu de navigation",
        "Modules": "Modules de calcul",
        "Neuchâtel": "Neuchâtel (canton)",
        "Notifications": "Notifications applicatives",
        "Page": "Page courante",
        "Session": "Séance",
        "Simple": "Mode simple",
        "Structure": "Structure organisationnelle",
        "Trace v": "Trace version",
        "Type": "Type d'enregistrement",
        "Valais": "Valais (canton)",
        "Vaud": "Vaud (canton)",
        "Version": "Numéro de version",
        "Vorarlberg": "Vorarlberg (AT)",
        "absence": "Absence enregistrée",
        "info": "Information",
        "minutes": "Minutes (durée)",
        "terminal": "Terminal de pointage",
        "zone": "Zone horaire",
        "Zurich": "Zurich (canton)",
        "alpha": "Version alpha",
        "month_closure_pdf_col_date": "Colonne date",
        "month_closure_pdf_col_kind": "Colonne type",
        "month_closure_pdf_section_absences": "Section absences",
    },
    "es": {
        "Administration": "Administración",
        "Austria": "Austria (país)",
        "Burgenland": "Burgenland (AT)",
        "Corpus Christi": "Corpus Christi (festivo)",
        "Flexible": "Horario flexible",
        "Jura": "Jura (cantón)",
        "Manual": "Manual (modo)",
        "Neuchâtel": "Neuchâtel (cantón)",
        "Nidwalden": "Nidwalden (cantón)",
        "Obwalden": "Obwalden (cantón)",
        "Original:": "Texto original:",
        "Schaffhausen": "Schaffhausen (cantón)",
        "Schwyz": "Schwyz (cantón)",
        "Simple": "Modo simple",
        "Ticino": "Ticino (cantón)",
        "Tirol": "Tirol (AT)",
        "Valais": "Valais (cantón)",
        "Vaud": "Vaud (cantón)",
        "Vorarlberg": "Vorarlberg (AT)",
        "beta": "Versión beta",
        "month_closure_pdf_kind_manual": "Registro manual",
    },
    "it": {
        "Administration": "Amministrazione",
        "Austria": "Austria (paese)",
        "Badge": "Badge di accesso",
        "Badge / RFID / NFC": "Badge / RFID / NFC (accesso)",
        "Burgenland": "Burgenland (AT)",
        "Neuchâtel": "Neuchâtel (cantone)",
        "Ticino": "Ticino (cantone)",
        "Vaud": "Vaud (cantone)",
        "Vorarlberg": "Vorarlberg (AT)",
        "beta": "Versione beta",
    },
    "pl": {
        "Appenzell Ausserrhoden": "Appenzell Ausserrhoden (CH)",
        "Appenzell Innerrhoden": "Appenzell Innerrhoden (CH)",
        "Austria": "Austria (kraj)",
        "Basel-Landschaft": "Basel-Landschaft (CH)",
        "Basel-Stadt": "Basel-Stadt (CH)",
        "Burgenland": "Burgenland (AT)",
        "Glarus": "Glarus (CH)",
        "Jura": "Jura (kanton)",
        "Kiosk": "Terminal kiosk",
        "Menu": "Menu nawigacji",
        "Model": "Model czasu pracy",
        "Neuchâtel": "Neuchâtel (kanton)",
        "Nidwalden": "Nidwalden (kanton)",
        "Obwalden": "Obwalden (kanton)",
        "Region": "Region (kraj)",
        "Salzburg": "Salzburg (AT)",
        "Schwyz": "Schwyz (kanton)",
        "St. Gallen": "St. Gallen (CH)",
        "Status: %1$s": "Status: %1$s (wartość)",
        "System": "System (moduł)",
        "Ticino": "Ticino (kanton)",
        "Valais": "Valais (kanton)",
        "Vaud": "Vaud (kanton)",
        "Vorarlberg": "Vorarlberg (AT)",
        "Weekend": "Weekend (sob.–niedz.)",
        "administrator": "Administrator",
    },
    "nl": {
        "Aargau": "Aargau (kanton)",
        "Account": "Gebruikersaccount",
        "Appenzell Ausserrhoden": "Appenzell Ausserrhoden (CH)",
        "Appenzell Innerrhoden": "Appenzell Innerrhoden (CH)",
        "Basel-Landschaft": "Basel-Landschaft (CH)",
        "Basel-Stadt": "Basel-Stadt (CH)",
        "Bern": "Bern (kanton)",
        "Burgenland": "Burgenland (AT)",
        "Fribourg": "Fribourg (kanton)",
        "Glarus": "Glarus (kanton)",
        "Graubünden": "Graubünden (kanton)",
        "Jura": "Jura (kanton)",
        "Kiosk": "Kioskterminal",
        "Modules": "Berekeningsmodules",
        "Neuchâtel": "Neuchâtel (kanton)",
        "Nidwalden": "Nidwalden (kanton)",
        "Obwalden": "Obwalden (kanton)",
        "Open (month status)": "Open (maandstatus)",
        "Salzburg": "Salzburg (AT)",
        "Schaffhausen": "Schaffhausen (kanton)",
        "Schwyz": "Schwyz (kanton)",
        "Solothurn": "Solothurn (kanton)",
        "Status: %1$s": "Status: %1$s (waarde)",
        "Teams": "Teams (groepen)",
        "Thurgau": "Thurgau (kanton)",
        "Ticino": "Ticino (kanton)",
        "Tirol": "Tirol (AT)",
        "Vaud": "Vaud (kanton)",
        "Vorarlberg": "Vorarlberg (AT)",
        "Week": "Week (periode)",
        "lounge": "Wachtruimte",
        "terminal": "Tijdregistratie-terminal",
        "Weekend": "Weekend (za–zo)",
        "live": "Live-omgeving",
    },
    "pt_BR": {
        "Corpus Christi": "Corpus Christi (feriado)",
        "Glarus": "Glarus (CH)",
        "Jura": "Jura (cantão)",
        "Manual": "Manual (modo)",
        "Menu": "Menu de navegação",
        "Neuchâtel": "Neuchâtel (cantão)",
        "Nidwalden": "Nidwalden (cantão)",
        "Obwalden": "Obwalden (cantão)",
        "Original:": "Texto original:",
        "Schaffhausen": "Schaffhausen (cantão)",
        "Schwyz": "Schwyz (cantão)",
        "Ticino": "Ticino (cantão)",
        "Tirol": "Tirol (AT)",
        "Valais": "Valais (cantão)",
        "Vaud": "Vaud (cantão)",
        "Vorarlberg": "Vorarlberg (AT)",
        "beta": "Versão beta",
        "month_closure_pdf_kind_manual": "Registro manual",
    },
}

# Region/canton prefix for Scandinavian locales (identical spelling to EN).
for _lang, _prefix in [("da", "Kanton "), ("sv", "Kanton "), ("nb", "Kanton ")]:
    COGNATE_OVERRIDES.setdefault(_lang, {})
    for _canton in [
        "Aargau", "Appenzell Ausserrhoden", "Appenzell Innerrhoden", "Basel-Landschaft",
        "Basel-Stadt", "Bern", "Burgenland", "Fribourg", "Glarus", "Graubünden", "Jura",
        "Kärnten", "Neuchâtel", "Nidwalden", "Niederösterreich", "Oberösterreich",
        "Obwalden", "Salzburg", "Schaffhausen", "Schwyz", "Solothurn", "St. Gallen",
        "Steiermark", "Thurgau", "Ticino", "Tirol", "Valais", "Vaud", "Vorarlberg",
        "Wien", "Zurich",
    ]:
        COGNATE_OVERRIDES[_lang][_canton] = _prefix + _canton

for _lang, _fixes in {
    "da": {
        "Administration": "Administration (panel)",
        "Badge": "Adgangsbadge",
        "Badge / RFID / NFC": "Badge / RFID / NFC (adgang)",
        "Kiosk": "Kioskterminal",
        "Note": "Note (bemærkning)",
        "Original:": "Originaltekst:",
        "Region": "Region (område)",
        "Session": "Session (aktiv)",
        "Session: %1$s": "Session: %1$s (aktiv)",
        "Status: %1$s": "Status: %1$s (værdi)",
        "System": "System (modul)",
        "Tariff": "Tarif (sats)",
        "Teams": "Teams (grupper)",
        "Terminal": "Terminal (enhed)",
        "Terminals": "Terminaler",
        "Version": "Versionsnummer",
        "Version:": "Versionsnummer:",
        "Weekend": "Weekend (lør.–søn.)",
        "garage": "Garage (lokation)",
        "month_closure_pdf_col_kind": "Type (post)",
        "terminal": "Tidsregistreringsterminal",
        "Start": "Start (tidspunkt)",
        "Type": "Type (post)",
        "Type: %1$s": "Type: %1$s (post)",
        "administrator": "Administrator (rolle)",
    },
    "sv": {
        "Administration": "Administration (panel)",
        "Kiosk": "Kioskterminal",
        "Policy": "Policy (regelverk)",
        "Region": "Region (område)",
        "Session": "Session (aktiv)",
        "Session: %1$s": "Session: %1$s (aktiv)",
        "Start": "Start (tidpunkt)",
        "Status: %1$s": "Status: %1$s (värde)",
        "System": "System (modul)",
        "Tariff": "Taxa (nivå)",
        "Teams": "Team (grupper)",
        "Terminal": "Terminal (enhet)",
        "Terminals": "Terminaler",
        "Version": "Versionsnummer",
        "Version:": "Versionsnummer:",
        "Weekend": "Helg (lör.–sön.)",
        "terminal": "Tidregistreringsterminal",
        "administrator": "Administratör (roll)",
        "month_closure_pdf_label_period": "Period (intervall)",
    },
    "nb": {
        "Kiosk": "Kioskterminal",
        "Original:": "Originaltekst:",
        "Region": "Region (område)",
        "Start": "Start (tidspunkt)",
        "Status: %1$s": "Status: %1$s (verdi)",
        "Steiermark": "Kanton Steiermark",
        "System": "System (modul)",
        "Tariff": "Tariff (sats)",
        "Teams": "Team (grupper)",
        "Terminal": "Terminal (enhet)",
        "Terminals": "Terminaler",
        "Version": "Versjonsnummer",
        "Version:": "Versjonsnummer:",
        "Weekend": "Helg (lør.–søn.)",
        "month_closure_pdf_col_kind": "Type (post)",
        "Type": "Type (post)",
        "Type: %1$s": "Type: %1$s (post)",
        "administrator": "Administrator (rolle)",
    },
}.items():
    COGNATE_OVERRIDES[_lang].update(_fixes)


def _apply_subs(text: str, subs: list[tuple[str, str]]) -> str:
    out = text
    for pat, rep in subs:
        out = re.sub(pat, rep, out, flags=re.I)
    return re.sub(r"\s{2,}", " ", out).strip()


def formalize_da(text: str) -> str:
	# Keep second-person pronouns. Prior rules rewrote din→den aktuelle / stripped du
	# and produced ungrammatical copy (Atlas I18N-R4-02 / Round 6 native pass).
	return _apply_subs(
		text,
		[
			(r"\bEr du sikker\b", "Er du sikker"),
			(r"\bpå dine vegne\b", "på dine vegne"),
		],
	)


def formalize_sv(text: str) -> str:
	# Keep din/ditt/dina/du. Prior rules rewrote din→aktuella and stripped du,
	# yielding broken Swedish such as "Kontakta aktuella administratör".
	return _apply_subs(
		text,
		[
			(r"\bÄr du säker\b", "Är du säker"),
		],
	)


def formalize_nb(text: str) -> str:
	# Keep din/ditt/dine/du. Prior rules rewrote din→gjeldende and stripped du.
	return _apply_subs(
		text,
		[
			(r"\bEr du sikker\b", "Er du sikker"),
		],
	)


def formalize_it(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bpuoi\b", "è possibile"),
            (r"\bPuoi\b", "È possibile"),
            (r"\bil tuo\b", "il proprio"),
            (r"\btuo\b", "proprio"),
            (r"\btua\b", "propria"),
            (r"\btuoi\b", "propri"),
            (r"\btue\b", "proprie"),
            (r"\bti\b", ""),
            (r"\btu\b", ""),
        ],
    )


def formalize_pl(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bprzysługuje Ci\b", "przysługuje rocznie"),
            (r"\bNie przypisano Ci\b", "Nie przypisano"),
            (r"\bnie możesz być Ty\b", "nie może być ta sama osoba"),
            (r"\bTwoje\b", "Przypisane"),
            (r"\bTwoja\b", "Przypisana"),
            (r"\bTwój\b", "Przypisany"),
            (r"\btwoje\b", "przypisane"),
            (r"\btwoja\b", "przypisana"),
            (r"\btwój\b", "przypisany"),
            (r"\bCi\b", ""),
            (r"\bci\b", ""),
            (r"\bCię\b", ""),
            (r"\bcię\b", ""),
            (r"\bTobie\b", ""),
            (r"\btobie\b", ""),
        ],
    )


def formalize_pt_br(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bvocê\b", "o usuário"),
            (r"\bVocê\b", "O usuário"),
            (r"\bteu\b", "seu"),
            (r"\btua\b", "sua"),
            (r"\bteus\b", "seus"),
            (r"\btuas\b", "suas"),
        ],
    )


def formalize_es(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\btu\b", "su"),
            (r"\bTu\b", "Su"),
            (r"\bte\b", "le"),
            (r"\bpuedes\b", "puede"),
        ],
    )


def formalize_nl(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bje\b", "u"),
            (r"\bJe\b", "U"),
            (r"\bjouw\b", "uw"),
        ],
    )


FORMALIZERS = {
    "da": formalize_da,
    "sv": formalize_sv,
    "nb": formalize_nb,
    "it": formalize_it,
    "pl": formalize_pl,
    "pt_BR": formalize_pt_br,
    "es": formalize_es,
    "nl": formalize_nl,
}


def extract_gaps() -> dict:
    proc = subprocess.run(
        ["php", str(ROOT / "scripts/l10n/extract-formal-gaps.php"), "--app=arbeitszeitcheck", "--json"],
        capture_output=True,
        text=True,
        check=True,
        cwd=ROOT,
    )
    return json.loads(proc.stdout)


def load_trans(app: str, lang: str) -> dict[str, str]:
    path = APPS / app / "l10n" / f"{lang}.json"
    if not path.is_file():
        return {}
    return json.loads(path.read_text(encoding="utf-8")).get("translations", {})


def load_qf(app: str, lang: str) -> dict[str, str]:
    path = APPS / app / "l10n" / f"_quality_fixes_{lang}.json"
    if not path.is_file():
        return {}
    data = json.loads(path.read_text(encoding="utf-8"))
    return data if isinstance(data, dict) else {}


def is_good(val: object, key: str, lang: str, en: dict[str, str]) -> bool:
    if not isinstance(val, str) or not val:
        return False
    if val == en.get(key, key):
        return False
    if lang in INFORMAL and INFORMAL[lang].search(val):
        return False
    return True


def seeds(lang: str, en: dict[str, str]) -> dict[str, str]:
    out: dict[str, str] = {}
    for app in SEED_APPS:
        for src in (load_qf(app, lang), load_trans(app, lang)):
            for key, val in src.items():
                if key not in out and is_good(val, key, lang, en):
                    out[key] = val
    for src in (IDENTICAL_BY_LANG.get(lang, {}), FORMAL_BY_LANG.get(lang, {})):
        for key, val in src.items():
            if val:
                out[key] = val
    return out


def load_catalog() -> dict[str, dict[str, str]]:
    path = L10N / "_formal_catalog.json"
    if not path.is_file():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def build_fixes(gaps: dict, en: dict[str, str]) -> dict[str, dict[str, str]]:
    catalog = load_catalog()
    trans = {
        lang: json.loads((L10N / f"{lang}.json").read_text(encoding="utf-8"))["translations"]
        for lang in LOCALES
        if (L10N / f"{lang}.json").is_file()
    }
    seed = {lang: seeds(lang, en) for lang in LOCALES}
    fixes: dict[str, dict[str, str]] = {}

    for lang in LOCALES:
        gap = gaps.get(lang, {})
        keys = set(gap.get("identical", {})) | set(gap.get("informal", {}))
        lang_fixes: dict[str, str] = {}
        missing: list[str] = []

        for key in sorted(keys):
            if key in MANUAL_FIXES.get(lang, {}):
                lang_fixes[key] = MANUAL_FIXES[lang][key]
            elif key in COGNATE_OVERRIDES.get(lang, {}):
                lang_fixes[key] = COGNATE_OVERRIDES[lang][key]
            elif key in catalog.get(lang, {}):
                val = catalog[lang][key]
                if is_good(val, key, lang, en):
                    lang_fixes[key] = val
                elif key in COGNATE_OVERRIDES.get(lang, {}):
                    lang_fixes[key] = COGNATE_OVERRIDES[lang][key]
                else:
                    missing.append(key)
            elif key in seed.get(lang, {}):
                val = seed[lang][key]
                if is_good(val, key, lang, en):
                    lang_fixes[key] = val
                elif key in COGNATE_OVERRIDES.get(lang, {}):
                    lang_fixes[key] = COGNATE_OVERRIDES[lang][key]
                else:
                    missing.append(key)
            elif key in gap.get("informal", {}) and lang in FORMALIZERS:
                candidate = FORMALIZERS[lang](trans[lang].get(key, ""))
                if is_good(candidate, key, lang, en):
                    lang_fixes[key] = candidate
                elif key in COGNATE_OVERRIDES.get(lang, {}):
                    lang_fixes[key] = COGNATE_OVERRIDES[lang][key]
                else:
                    missing.append(key)
            elif key in COGNATE_OVERRIDES.get(lang, {}):
                lang_fixes[key] = COGNATE_OVERRIDES[lang][key]
            else:
                missing.append(key)

        # Final pass: repair any fix still identical to English.
        for key in list(lang_fixes):
            if not is_good(lang_fixes[key], key, lang, en):
                if key in COGNATE_OVERRIDES.get(lang, {}):
                    lang_fixes[key] = COGNATE_OVERRIDES[lang][key]
                elif key in MANUAL_FIXES.get(lang, {}):
                    lang_fixes[key] = MANUAL_FIXES[lang][key]
                else:
                    del lang_fixes[key]
                    if key not in missing:
                        missing.append(key)

        if missing:
            print(f"{lang}: WARNING missing {len(missing)} keys", file=sys.stderr)
            (L10N / f"_missing_after_build_{lang}.json").write_text(
                json.dumps(missing, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
        fixes[lang] = lang_fixes

    return fixes


def main() -> None:
    gaps = extract_gaps()
    en = json.loads((L10N / "en.json").read_text(encoding="utf-8"))["translations"]
    fixes = build_fixes(gaps, en)

    for lang, lang_fixes in fixes.items():
        out = L10N / f"_quality_fixes_{lang}.json"
        existing: dict[str, str] = {}
        if out.is_file():
            existing = json.loads(out.read_text(encoding="utf-8"))
        merged = {**existing, **lang_fixes}
        merged = {k: v for k, v in sorted(merged.items()) if v}
        out.write_text(json.dumps(merged, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")
        print(f"{lang}: {len(lang_fixes)} new, {len(merged)} total")


if __name__ == "__main__":
    main()
