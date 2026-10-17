/**
 * CSV cell safety for client-side exports — JS mirror of the server-side
 * ReportController::sanitizeCsvCellValue() guard (api-security-patterns §7).
 *
 * A cell whose first significant character is = + - @ (or that literally
 * begins with TAB, CR, LF or VT) is interpreted as a live formula by
 * Excel/LibreOffice, so user-controlled values (display names, user ids)
 * can inject formulas into exported files. Prefixing a literal apostrophe
 * marks the cell as text; RFC 4180 quoting alone does not.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

(function () {
	'use strict';

	if (typeof window !== 'undefined' && window.ArbeitszeitCheckCsv) {
		return;
	}

	// Formula leads: = + - @ — also after leading whitespace (PHP guard
	// parity) — plus TAB, CR, LF and VT as the first character.
	const FORMULA_LEAD = /^\s*[=+\-@]|^[\t\r\n\v]/;

	function neutralize(value) {
		const s = String(value ?? '');
		return FORMULA_LEAD.test(s) ? "'" + s : s;
	}

	function cell(value) {
		const s = neutralize(value);
		if (/[";,\n\r]/.test(s)) {
			return '"' + s.replace(/"/g, '""') + '"';
		}
		return s;
	}

	window.ArbeitszeitCheckCsv = { neutralize, cell };
})();
