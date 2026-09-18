/**
 * Pending time-entry approval card renderer (manager dashboard).
 *
 * Reads nested `item.summary` from GET /api/manager/pending-approvals
 * and distinguishes manual_create vs correction requests.
 *
 * @copyright Copyright (c) 2024-2026, Alexander Mäule / Software by Design
 * @license AGPL-3.0-or-later
 */
(function(global) {
	'use strict';

	/**
	 * @param {*} summary
	 * @returns {Record<string, *>}
	 */
	function parseSummary(summary) {
		if (summary == null) {
			return {};
		}
		if (typeof summary === 'object' && !Array.isArray(summary)) {
			return summary;
		}
		if (typeof summary === 'string') {
			try {
				const o = JSON.parse(summary);
				return (typeof o === 'object' && o !== null && !Array.isArray(o)) ? o : {};
			} catch (e) {
				return {};
			}
		}
		return {};
	}

	/**
	 * @param {*} value
	 * @returns {Record<string, *>|null}
	 */
	function asRecord(value) {
		if (!value || typeof value !== 'object' || Array.isArray(value)) {
			return null;
		}
		return value;
	}

	/**
	 * True when this pending row is a new manual entry awaiting first approval
	 * (no existing Ist entry to compare).
	 *
	 * @param {Record<string, *>} summary
	 * @returns {boolean}
	 */
	function isManualCreateApproval(summary) {
		const requestType = summary.requestType != null ? summary.requestType : summary.type;
		if (requestType === 'manual_create') {
			return true;
		}
		if (requestType === 'correction') {
			return false;
		}
		const original = asRecord(summary.original);
		return !original || (original.startTime == null && original.date == null);
	}

	/**
	 * @param {*} hours
	 * @returns {string}
	 */
	function formatDurationHours(hours) {
		if (hours == null || hours === '') {
			return '';
		}
		const n = Number(hours);
		if (!Number.isFinite(n)) {
			return String(hours);
		}
		return Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100);
	}

	/**
	 * Display a clock that may already be HH:mm or an ISO instant.
	 *
	 * @param {*} raw
	 * @param {{ formatCorrectionTime: function(*): string }} deps
	 * @returns {string}
	 */
	function formatClockDisplay(raw, deps) {
		if (raw == null || raw === '') {
			return '–';
		}
		const s = String(raw).trim();
		if (/^\d{1,2}:\d{2}$/.test(s)) {
			const parts = s.split(':');
			return parts[0].padStart(2, '0') + ':' + parts[1];
		}
		return deps.formatCorrectionTime(s);
	}

	/**
	 * @param {{
	 *   id: *,
	 *   displayName?: string,
	 *   userId?: string,
	 *   summary?: *,
	 *   startTime?: string,
	 *   justification?: string,
	 *   original?: *,
	 *   proposed?: *
	 * }} item
	 * @param {{
	 *   escapeHtml: function(*): string,
	 *   t: function(string, string=): string,
	 *   formatDateForDisplay: function(string): string,
	 *   formatCorrectionTime: function(*): string,
	 *   formatCorrectionBreaks: function(*): string,
	 *   buildCorrectionDiffRow: function(string, string, string): string
	 * }} deps
	 * @returns {string}
	 */
	function renderTimeEntryApprovalCardHtml(item, deps) {
		const escapeHtml = deps.escapeHtml;
		const t = deps.t;
		const summary = parseSummary(item.summary);

		// Prefer nested summary (API contract). Tolerate legacy flat payloads.
		const dateRaw = summary.date
			|| String(summary.startTime || item.startTime || '').slice(0, 10);
		const date = deps.formatDateForDisplay(dateRaw);
		const justificationText = summary.justification != null && summary.justification !== ''
			? summary.justification
			: (item.justification || '');
		const justification = justificationText
			? escapeHtml(String(justificationText).substring(0, 300))
			: '';
		const manualCreate = isManualCreateApproval(summary);
		const id = item.id;
		const displayName = escapeHtml(item.displayName || item.userId || '');
		const kindLabel = manualCreate
			? t('New manual time entry', 'New manual time entry')
			: t('Time entry correction', 'Time entry correction');

		let bodyHtml;
		if (manualCreate) {
			const start = formatClockDisplay(summary.startTime, deps);
			const end = formatClockDisplay(summary.endTime, deps);
			const duration = formatDurationHours(summary.durationHours);
			const facts = [
				'<dl class="pending-approval-card__facts">',
				'<div class="pending-approval-card__fact"><dt>' + escapeHtml(t('Start', 'Start')) + '</dt><dd>' + escapeHtml(start) + '</dd></div>',
				'<div class="pending-approval-card__fact"><dt>' + escapeHtml(t('End', 'End')) + '</dt><dd>' + escapeHtml(end) + '</dd></div>',
			];
			if (duration !== '') {
				facts.push(
					'<div class="pending-approval-card__fact"><dt>' + escapeHtml(t('Duration', 'Duration')) + '</dt><dd>'
					+ escapeHtml(duration) + ' ' + escapeHtml(t('hours', 'hours')) + '</dd></div>'
				);
			}
			facts.push('</dl>');
			bodyHtml = facts.join('');
		} else {
			const orig = asRecord(summary.original) || asRecord(item.original) || {};
			const prop = asRecord(summary.proposed) || asRecord(item.proposed) || {};
			bodyHtml = [
				'<div class="manager-correction-diff" role="group" aria-label="' + escapeHtml(t('Correction comparison', 'Correction comparison')) + '">',
				'<div class="manager-correction-diff__header">',
				'<span class="manager-correction-diff__field"></span>',
				'<span class="manager-correction-diff__label">' + escapeHtml(t('Current (Ist)', 'Current (Ist)')) + '</span>',
				'<span class="manager-correction-diff__label">' + escapeHtml(t('Proposed (Soll)', 'Proposed (Soll)')) + '</span>',
				'</div>',
				deps.buildCorrectionDiffRow(t('Start', 'Start'), deps.formatCorrectionTime(orig.startTime), deps.formatCorrectionTime(prop.startTime)),
				deps.buildCorrectionDiffRow(t('End', 'End'), deps.formatCorrectionTime(orig.endTime), deps.formatCorrectionTime(prop.endTime)),
				deps.buildCorrectionDiffRow(t('Breaks', 'Breaks'), deps.formatCorrectionBreaks(orig.breaks), deps.formatCorrectionBreaks(prop.breaks)),
				'</div>',
			].join('');
		}

		return (
			'<div class="pending-approval-card pending-approval-card--time-entry'
			+ (manualCreate ? ' pending-approval-card--manual-create' : ' pending-approval-card--correction')
			+ '" data-time-entry-id="' + escapeHtml(String(id))
			+ '" data-request-type="' + escapeHtml(manualCreate ? 'manual_create' : 'correction')
			+ '" role="article">'
			+ '  <div class="pending-approval-card__body">'
			+ '    <p class="pending-approval-card__title"><strong>' + displayName + '</strong> – ' + escapeHtml(kindLabel) + '</p>'
			+ '    <p class="pending-approval-card__meta">' + escapeHtml(date) + '</p>'
			+ bodyHtml
			+ (justification
				? '<p class="pending-approval-card__reason"><strong>' + escapeHtml(t('Reason:', 'Reason:')) + '</strong> <em>'
					+ justification + (String(justificationText).length > 300 ? '…' : '') + '</em></p>'
				: '')
			+ '    <div class="pending-approval-card__actions">'
			+ '      <button type="button" class="azc-btn azc-btn--primary btn-approve-time-entry" data-time-entry-id="'
				+ escapeHtml(String(id)) + '" aria-label="'
				+ escapeHtml(t('Approve', 'Approve') + ' ' + (item.displayName || '')) + '">'
				+ t('Approve', 'Approve') + '</button>'
			+ '      <button type="button" class="azc-btn azc-btn--secondary btn-reject-time-entry" data-time-entry-id="'
				+ escapeHtml(String(id)) + '" aria-label="'
				+ escapeHtml(t('Reject', 'Reject') + ' ' + (item.displayName || '')) + '">'
				+ t('Reject', 'Reject') + '</button>'
			+ '    </div>'
			+ '  </div>'
			+ '</div>'
		);
	}

	const api = {
		parseSummary: parseSummary,
		asRecord: asRecord,
		isManualCreateApproval: isManualCreateApproval,
		formatDurationHours: formatDurationHours,
		formatClockDisplay: formatClockDisplay,
		renderTimeEntryApprovalCardHtml: renderTimeEntryApprovalCardHtml,
	};

	global.ArbeitszeitCheckManagerPendingTimeEntry = api;
})(typeof window !== 'undefined' ? window : globalThis);
