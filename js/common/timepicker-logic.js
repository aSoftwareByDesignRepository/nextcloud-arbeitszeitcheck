/**
 * Pure 24-hour time-picker rules. No DOM.
 *
 * Digit entry follows the Material UI time-field model: a first digit that
 * cannot start a second digit commits immediately; otherwise the field waits
 * for one more key. Arrow steps wrap. Minutes snap to the configured grid
 * when stepped, and any typed minute 0–59 stays valid.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
(function (root) {
	'use strict';

	var HOUR_MAX = 23;
	var MINUTE_MAX = 59;
	var HOUR_INSTANT_FROM = 3;
	var MINUTE_INSTANT_FROM = 6;

	function pad2(n) {
		var v = String(n);
		return v.length >= 2 ? v : ('0' + v);
	}

	function normalizeStep(step) {
		var n = parseInt(step, 10);
		if (!isFinite(n) || n < 1 || n > 30) {
			return 1;
		}
		return n;
	}

	function parseHm(value) {
		if (typeof value !== 'string') {
			return null;
		}
		var match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(value);
		if (!match) {
			return null;
		}
		return {
			hour: parseInt(match[1], 10),
			minute: parseInt(match[2], 10),
		};
	}

	function parseFlexible(value) {
		if (typeof value !== 'string') {
			return null;
		}
		var match = /^(\d{1,2}):(\d{1,2})$/.exec(value.trim().replace('.', ':'));
		if (!match) {
			return null;
		}
		var hour = parseInt(match[1], 10);
		var minute = parseInt(match[2], 10);
		if (hour > HOUR_MAX || minute > MINUTE_MAX) {
			return null;
		}
		return { hour: hour, minute: minute };
	}

	function formatHm(hour, minute) {
		if (typeof hour !== 'number' || typeof minute !== 'number') {
			return '';
		}
		if (!isFinite(hour) || !isFinite(minute)) {
			return '';
		}
		if (hour < 0 || hour > HOUR_MAX || minute < 0 || minute > MINUTE_MAX) {
			return '';
		}
		return pad2(hour) + ':' + pad2(minute);
	}

	function minuteChoices(selected, step) {
		var s = normalizeStep(step);
		var out = [];
		for (var m = 0; m < 60; m += s) {
			out.push(pad2(m));
		}
		if (selected === null || selected === undefined || selected === '') {
			return out;
		}
		if (typeof selected === 'string' && !/^\d{1,2}$/.test(selected)) {
			return out;
		}
		var n = typeof selected === 'number' ? selected : parseInt(selected, 10);
		if (!Number.isInteger(n) || n < 0 || n > MINUTE_MAX) {
			return out;
		}
		var padded = pad2(n);
		if (out.indexOf(padded) === -1) {
			out.push(padded);
			out.sort();
		}
		return out;
	}

	function hourChoices() {
		var out = [];
		for (var h = 0; h <= HOUR_MAX; h++) {
			out.push(pad2(h));
		}
		return out;
	}

	/**
	 * @param {'hour'|'minute'} section
	 * @param {string} buffer '' or one digit already typed in this focus
	 * @param {string} digit one character 0-9
	 */
	function applySectionDigit(section, buffer, digit) {
		if (!/^\d$/.test(digit)) {
			return null;
		}
		var d = parseInt(digit, 10);
		var max = section === 'hour' ? HOUR_MAX : MINUTE_MAX;
		var instantFrom = section === 'hour' ? HOUR_INSTANT_FROM : MINUTE_INSTANT_FROM;
		var pending = buffer === undefined || buffer === null ? '' : String(buffer);
		if (pending !== '' && !/^\d$/.test(pending)) {
			pending = '';
		}
		if (pending === '') {
			if (d >= instantFrom) {
				return { value: d, buffer: '', advance: true };
			}
			return { value: d, buffer: String(d), advance: false };
		}
		var combined = parseInt(pending + digit, 10);
		if (combined <= max) {
			return { value: combined, buffer: '', advance: true };
		}
		if (d >= instantFrom) {
			return { value: d, buffer: '', advance: true };
		}
		return { value: d, buffer: String(d), advance: false };
	}

	function stepSection(section, current, delta, step) {
		var dir = delta < 0 ? -1 : 1;
		if (section === 'hour') {
			if (current === null || current === undefined || !isFinite(current)) {
				return dir > 0 ? 0 : HOUR_MAX;
			}
			return (current + dir + HOUR_MAX + 1) % (HOUR_MAX + 1);
		}
		var s = normalizeStep(step);
		if (current === null || current === undefined || !isFinite(current)) {
			return dir > 0 ? 0 : Math.floor(MINUTE_MAX / s) * s;
		}
		if (dir > 0) {
			var next = Math.floor(current / s) * s + s;
			return next > MINUTE_MAX ? 0 : next;
		}
		var prev = Math.ceil(current / s) * s - s;
		if (prev < 0) {
			return Math.floor(MINUTE_MAX / s) * s;
		}
		return prev;
	}

	function endValue(section, step) {
		if (section === 'hour') {
			return HOUR_MAX;
		}
		var s = normalizeStep(step);
		return Math.floor(MINUTE_MAX / s) * s;
	}

	/**
	 * Interpret a native input's raw text against the last displayed digits.
	 * Returns a digit to feed into applySectionDigit, a full HH:MM, a clear, or a noop.
	 */
	function reconcileRaw(rawValue, previousDisplay) {
		var raw = String(rawValue == null ? '' : rawValue).trim();
		var flexible = parseFlexible(raw.replace('.', ':'));
		if (flexible) {
			return { type: 'full', hour: flexible.hour, minute: flexible.minute };
		}
		var digits = raw.replace(/\D/g, '');
		var prev = String(previousDisplay == null ? '' : previousDisplay).replace(/\D/g, '');
		if (digits === '' || digits === prev) {
			return { type: digits === '' && raw !== previousDisplay ? 'clear' : 'noop' };
		}
		if (prev !== '' && digits.length === prev.length + 1 && digits.indexOf(prev) === 0) {
			return { type: 'digit', digit: digits.charAt(digits.length - 1) };
		}
		return { type: 'digit', digit: digits.charAt(digits.length - 1) };
	}

	var api = {
		HOUR_MAX: HOUR_MAX,
		MINUTE_MAX: MINUTE_MAX,
		pad2: pad2,
		normalizeStep: normalizeStep,
		parseHm: parseHm,
		parseFlexible: parseFlexible,
		formatHm: formatHm,
		minuteChoices: minuteChoices,
		hourChoices: hourChoices,
		applySectionDigit: applySectionDigit,
		stepSection: stepSection,
		endValue: endValue,
		reconcileRaw: reconcileRaw,
	};

	root.ArbeitszeitCheckTimePickerLogic = api;
})(typeof window !== 'undefined' ? window : globalThis);
