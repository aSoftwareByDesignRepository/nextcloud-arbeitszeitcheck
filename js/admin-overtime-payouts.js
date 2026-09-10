/**
 * Admin overtime payout (Auszahlung) UI — audit-focused payroll workflow
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const cfg = window.ARBEITSZEITCHECK_OT_PAYOUT || {};
	const i18n = cfg.i18n || {};
	const Utils = window.ArbeitszeitCheckUtils || window.ArbeitszeitCheck?.Utils || {};

	function $(sel) {
		return document.querySelector(sel);
	}

	function escapeHtml(text) {
		if (Utils && typeof Utils.escapeHtml === 'function') {
			return Utils.escapeHtml(text);
		}
		const d = document.createElement('div');
		d.textContent = String(text);
		return d.innerHTML;
	}

	function setLive(msg, type) {
		const el = $('#ot-payout-live');
		if (!el) {
			return;
		}
		el.textContent = msg || '';
		el.classList.remove('admin-ot-payouts__live--error', 'admin-ot-payouts__live--success');
		if (type === 'error') {
			el.classList.add('admin-ot-payouts__live--error');
		} else if (type === 'success') {
			el.classList.add('admin-ot-payouts__live--success');
		}
	}

	function getYearMonth() {
		return {
			year: parseInt($('#ot-payout-year')?.value || '0', 10),
			month: parseInt($('#ot-payout-month')?.value || '0', 10),
		};
	}

	function validateYearMonth() {
		const { year, month } = getYearMonth();
		if (!Number.isFinite(year) || year < 2000 || year > 2100) {
			setLive(i18n.invalidYear || 'Enter a valid year (2000–2100).', 'error');
			$('#ot-payout-year')?.focus();
			return false;
		}
		if (!Number.isFinite(month) || month < 1 || month > 12) {
			setLive(i18n.invalidMonth || 'Choose a valid month.', 'error');
			$('#ot-payout-month')?.focus();
			return false;
		}
		return true;
	}

	function setToolbarBusy(busy) {
		['#ot-payout-refresh', '#ot-payout-export', '#ot-payout-bulk'].forEach((sel) => {
			const btn = $(sel);
			if (!btn) {
				return;
			}
			btn.disabled = busy || (sel !== '#ot-payout-refresh' && !cfg.bankEnabled);
			btn.setAttribute('aria-busy', busy ? 'true' : 'false');
		});
	}

	async function apiGet(url, params) {
		const qs = new URLSearchParams(params).toString();
		const res = await fetch(url + (qs ? '?' + qs : ''), {
			headers: { requesttoken: OC.requestToken },
			credentials: 'same-origin',
		});
		return res.json();
	}

	async function apiPost(url, body) {
		const res = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			credentials: 'same-origin',
			body: JSON.stringify(body),
		});
		return res.json();
	}

	function renderSummary(summary, meta) {
		const el = $('#ot-payout-summary');
		if (!el || !summary) {
			return;
		}
		let text = (i18n.summary || '%1$s pending (%2$s h), %3$s already paid.')
			.replace('%1$s', String(summary.pending_count ?? 0))
			.replace('%2$s', String(summary.pending_hours ?? 0))
			.replace('%3$s', String(summary.paid_count ?? 0));
		if (meta && meta.truncated) {
			text += ' ' + (i18n.truncatedWarning || 'Warning: employee list was truncated; contact support.');
		} else if (meta && meta.total_users_in_scope != null) {
			const scanned = meta.users_scanned ?? meta.total_users_in_scope;
			text += ' ' + (i18n.scopeCount || '(%1$s employees in scope)')
				.replace('%1$s', String(scanned));
		}
		el.textContent = text;
		el.classList.toggle('azc-callout--warning', !!(meta && meta.truncated));
		el.classList.toggle('azc-callout--info', !(meta && meta.truncated));
		el.hidden = false;
	}

	function statusBadge(status) {
		const variant = Utils.badgeVariantForOvertimePayoutStatus
			? Utils.badgeVariantForOvertimePayoutStatus(status)
			: 'secondary';
		const label = status === 'paid'
			? (i18n.paid || 'Paid')
			: status === 'pending'
				? (i18n.pending || 'Pending')
				: (i18n.none || '—');
		return Utils.renderBadgeHtml
			? Utils.renderBadgeHtml(label, variant)
			: `<span class="badge badge--${variant}">${escapeHtml(label)}</span>`;
	}

	function renderTable(items) {
		const tbody = $('#ot-payout-tbody');
		if (!tbody) {
			return;
		}
		const pending = (items || []).filter((r) => r.status === 'pending');
		const paid = (items || []).filter((r) => r.status === 'paid');
		const ordered = pending.concat(paid).concat((items || []).filter((r) => r.status === 'none'));

		if (!ordered.length) {
			tbody.innerHTML = '<tr><td colspan="5">' + escapeHtml(i18n.empty || '') + '</td></tr>';
			return;
		}

		tbody.innerHTML = ordered.map((row) => {
			const status = row.status || 'none';
			let eligible = '—';
			let paidH = '—';
			let action = '';

			if (status === 'pending') {
				eligible = Number(row.payout_eligible_hours || 0).toFixed(2);
				const name = escapeHtml(row.display_name || row.user_id);
				const hours = eligible;
				action = '<button type="button" class="azc-btn azc-btn--primary azc-btn--sm ot-payout-one" data-user-id="'
					+ escapeHtml(row.user_id) + '" data-name="' + name + '" data-hours="' + escapeHtml(hours) + '">'
					+ escapeHtml(i18n.confirmBtn || 'Confirm') + '</button>';
			} else if (status === 'paid') {
				paidH = Number(row.hours_paid || 0).toFixed(2);
			}

			const Utils = window.ArbeitszeitCheckUtils || {};
			const td = (label, html, cls) => Utils.responsiveTd
				? Utils.responsiveTd(label, html, cls)
				: '<td' + (cls ? ' class="' + cls + '"' : '') + '>' + html + '</td>';
			return '<tr>'
				+ td(i18n.colEmployee || 'Employee', escapeHtml(row.display_name || row.user_id))
				+ td(i18n.colStatus || 'Status', statusBadge(status))
				+ td(i18n.colEligible || 'Eligible (h)', escapeHtml(eligible))
				+ td(i18n.colPaid || 'Paid (h)', escapeHtml(paidH))
				+ td(i18n.colActions || 'Actions', action, 'actions-cell')
				+ '</tr>';
		}).join('');

		tbody.querySelectorAll('.ot-payout-one').forEach((btn) => {
			btn.addEventListener('click', async () => {
				const name = btn.getAttribute('data-name') || '';
				const hours = btn.getAttribute('data-hours') || '';
				const msg = (i18n.confirmOne || 'Record payout for %s?')
					.replace('%s', name + ' (' + hours + ' h)');
				const confirmed = await (window.ArbeitszeitCheckUtils?.confirmDestructiveAction?.({
					title: i18n.confirmTitle || 'Confirm payout',
					message: msg,
					variant: 'danger',
					confirmLabel: i18n.confirmBtn || 'Confirm',
				}) ?? Promise.resolve(null));
				if (confirmed) {
					processOne(btn.getAttribute('data-user-id'));
				}
			});
		});
	}

	async function loadList() {
		if (!cfg.bankEnabled) {
			setLive(i18n.bankDisabled || 'Enable the overtime bank before loading payouts.', 'error');
			return;
		}
		if (!validateYearMonth()) {
			return;
		}
		const { year, month } = getYearMonth();
		setLive(i18n.loading || 'Loading…', false);
		setToolbarBusy(true);
		try {
			const json = await apiGet(cfg.apiList, { year, month });
			if (!json.success) {
				setLive(json.error || i18n.error, 'error');
				return;
			}
			renderSummary(json.data?.summary, json.data?.meta);
			renderTable(json.data?.items);
			setLive('', false);
		} catch (e) {
			setLive(i18n.error, 'error');
		} finally {
			setToolbarBusy(false);
		}
	}

	async function processOne(userId) {
		if (!cfg.bankEnabled || !validateYearMonth()) {
			return;
		}
		const { year, month } = getYearMonth();
		if (!userId) {
			return;
		}
		setLive(i18n.loading || 'Loading…', false);
		setToolbarBusy(true);
		try {
			const json = await apiPost(cfg.apiProcess, { userId, year, month });
			if (!json.success) {
				setLive(json.error || i18n.error, 'error');
				return;
			}
			await loadList();
			setLive(i18n.paid || 'Paid', 'success');
		} catch (e) {
			setLive(i18n.error, 'error');
		} finally {
			setToolbarBusy(false);
		}
	}

	async function processBulk() {
		if (!cfg.bankEnabled || !validateYearMonth()) {
			return;
		}
		const confirmed = await (window.ArbeitszeitCheckUtils?.confirmDestructiveAction?.({
			title: i18n.confirmBulkTitle || 'Pay out all pending',
			message: i18n.confirmBulk || 'Confirm bulk payout?',
			variant: 'danger',
			confirmLabel: i18n.confirmBtn || 'Confirm',
		}) ?? Promise.resolve(null));
		if (!confirmed) {
			return;
		}
		const { year, month } = getYearMonth();
		setLive(i18n.loading || 'Loading…', false);
		setToolbarBusy(true);
		try {
			const json = await apiPost(cfg.apiBulk, { year, month });
			if (!json.success) {
				setLive(json.error || i18n.error, 'error');
				return;
			}
			const r = json.result || {};
			const text = (i18n.done || 'Done.')
				.replace('%1$s', String(r.processed ?? 0))
				.replace('%2$s', String(r.skipped ?? 0));
			setLive(text, 'success');
			await loadList();
		} catch (e) {
			setLive(i18n.error, 'error');
		} finally {
			setToolbarBusy(false);
		}
	}

	function exportCsv() {
		if (!cfg.bankEnabled) {
			setLive(i18n.bankDisabled || 'Enable the overtime bank before exporting.', 'error');
			return;
		}
		if (!validateYearMonth()) {
			return;
		}
		const { year, month } = getYearMonth();
		const params = new URLSearchParams({
			year: String(year),
			month: String(month),
		});
		if (typeof OC !== 'undefined' && OC.requestToken) {
			params.set('requesttoken', OC.requestToken);
		}
		const query = params.toString();
		let url;
		if (cfg.apiExport) {
			const separator = cfg.apiExport.indexOf('?') >= 0 ? '&' : '?';
			url = (Utils.buildAppUrl ? Utils.buildAppUrl(cfg.apiExport) : cfg.apiExport) + separator + query;
		} else if (Utils.buildAppUrl) {
			url = Utils.buildAppUrl('/apps/arbeitszeitcheck/api/admin/overtime-payouts/export?' + query);
		} else {
			setLive(i18n.error || 'Export failed.', 'error');
			return;
		}
		if (Utils.triggerDownload && !Utils.triggerDownload(url)) {
			setLive(i18n.error || 'Export failed.', 'error');
			return;
		}
		if (!Utils.triggerDownload) {
			window.location.href = url;
		}
	}

	function applyQueryParams() {
		try {
			const params = new URLSearchParams(window.location.search);
			const year = parseInt(params.get('year') || '', 10);
			const month = parseInt(params.get('month') || '', 10);
			const yearEl = $('#ot-payout-year');
			const monthEl = $('#ot-payout-month');
			if (yearEl && Number.isFinite(year) && year >= 2000 && year <= 2100) {
				yearEl.value = String(year);
			}
			if (monthEl && Number.isFinite(month) && month >= 1 && month <= 12) {
				monthEl.value = String(month);
			}
		} catch (e) {
			// ignore malformed query string
		}
	}

	function init() {
		applyQueryParams();
		$('#ot-payout-refresh')?.addEventListener('click', loadList);
		$('#ot-payout-bulk')?.addEventListener('click', processBulk);
		$('#ot-payout-export')?.addEventListener('click', exportCsv);
		if (cfg.bankEnabled) {
			loadList();
		}
	}

	if (typeof window !== 'undefined') {
		window.__ArbeitszeitCheckOvertimePayoutsTestables = {
			processBulk: processBulk,
		};
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
