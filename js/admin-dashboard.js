/**
 * Admin Dashboard JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const Components = window.ArbeitszeitCheckComponents || {};

	let refreshInterval = null;
	let drilldownModalId = 'admin-dashboard-employee-drilldown';

	function t(key, fallback) {
		const bundle = window.ArbeitszeitCheck?.l10n || {};
		const value = bundle[key];
		return value !== undefined && value !== '' ? value : (fallback || key);
	}

	/** Plural-aware translate (Nextcloud window.n replaces %n). */
	function n(singular, plural, count) {
		const c = Number.isFinite(Number(count)) ? Number(count) : 0;
		if (typeof window.n === 'function') {
			return window.n('arbeitszeitcheck', singular, plural, c);
		}
		return String(c === 1 ? singular : plural).replace('%n', String(c));
	}

	function buildApiUrl(path) {
		if (Utils && typeof Utils.buildAppUrl === 'function') {
			return Utils.buildAppUrl(path);
		}
		if (Utils && typeof Utils.resolveUrl === 'function') {
			return Utils.resolveUrl(path);
		}
		return path;
	}

	function buildEmployeeExportUrl(filter) {
		const params = new URLSearchParams({
			filter,
			format: 'csv',
		});
		return buildApiUrl('/apps/arbeitszeitcheck/api/admin/dashboard-employees?' + params.toString());
	}

	function escapeHtml(value) {
		if (value === null || value === undefined) {
			return '';
		}
		const div = document.createElement('div');
		div.textContent = String(value);
		return div.innerHTML;
	}

	function init() {
		bindEvents();
		setupAutoRefresh();
		bindStatDrilldowns();
		bindOvertimeBanner();
	}

	function bindEvents() {
		const refreshBtn = Utils.$('#refresh-statistics');
		if (refreshBtn) {
			Utils.on(refreshBtn, 'click', refreshStatistics);
		}
	}

	function setupAutoRefresh() {
		if (refreshInterval) {
			clearInterval(refreshInterval);
		}
		refreshInterval = setInterval(refreshStatistics, 5 * 60 * 1000);
	}

	function cleanup() {
		if (refreshInterval) {
			clearInterval(refreshInterval);
			refreshInterval = null;
		}
	}

	function refreshStatistics() {
		Utils.ajax('/apps/arbeitszeitcheck/api/admin/statistics', {
			method: 'GET',
			onSuccess(data) {
				if (data.success && data.statistics) {
					updateStatisticsDisplay(data.statistics);
					updateOvertimeBanner(data.statistics.overtime_tracking);
				}
			},
			onError() {
				if (Messaging && Messaging.showError) {
					Messaging.showError(t('statisticsRefreshError', 'Could not refresh statistics.'));
				}
			},
		});
	}

	function updateStatisticsDisplay(stats) {
		const totalUsersEl = document.querySelector('[data-stat="total_users"] .stat-number');
		const activeTodayEl = document.querySelector('[data-stat="active_users_today"] .stat-number');
		const violationsEl = document.querySelector('[data-stat="unresolved_violations"] .stat-number');

		if (totalUsersEl) {
			totalUsersEl.textContent = stats.total_users || 0;
		}
		if (activeTodayEl) {
			activeTodayEl.textContent = stats.active_users_today || 0;
		}
		if (violationsEl) {
			violationsEl.textContent = stats.unresolved_violations || 0;
			const violationsTile = violationsEl.closest('[data-stat="unresolved_violations"]');
			if (violationsTile) {
				const hasOpen = (stats.unresolved_violations || 0) > 0;
				violationsTile.classList.toggle('azc-stat-tile--danger', hasOpen);
				violationsTile.classList.toggle('azc-stat-tile--success', !hasOpen);
			}
		}
	}

	function updateOvertimeBanner(tracking) {
		const banner = document.getElementById('admin-overtime-onboarding-banner');
		if (!banner || !tracking) {
			return;
		}
		banner.hidden = !tracking.show_onboarding_hint;
	}

	function bindOvertimeBanner() {
		// Banner link is a plain anchor; native navigation is sufficient.
		// Kept as a no-op so callers can add behavior later without re-wiring init().
	}

	function bindStatDrilldowns() {
		document.querySelectorAll('[data-drilldown-filter]').forEach((card) => {
			card.addEventListener('click', (e) => {
				if (e.target.closest('a[href]') && card.tagName !== 'BUTTON') {
					return;
				}
				e.preventDefault();
				const filter = card.getAttribute('data-drilldown-filter');
				if (filter === 'violations') {
					const href = card.getAttribute('data-href');
					if (href) {
						window.location.href = href;
					}
					return;
				}
				openEmployeeDrilldown(filter, card);
			});
			card.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					card.click();
				}
			});
		});
	}

	function openEmployeeDrilldown(filter, triggerCard) {
		if (!Components.createModal) {
			return;
		}
		const existing = document.getElementById(drilldownModalId);
		if (existing) {
			existing.remove();
		}

		const title = filter === 'active_today'
			? t('activeTodayDrilldownTitle', 'Active today')
			: t('totalEmployeesDrilldownTitle', 'All employees');

		const searchId = 'admin-drilldown-search';
		const exportUrl = buildEmployeeExportUrl(filter);
		const exportLabel = t('Export CSV', 'Export CSV');
		const content = [
			'<p class="form-help">' + escapeHtml(t('drilldownHelp', 'Search by name or login. Export downloads the full filtered list.')) + '</p>',
			'<div class="admin-drilldown-toolbar">',
			'<label class="sr-only" for="' + searchId + '">' + escapeHtml(t('Search employees', 'Search employees')) + '</label>',
			'<input type="search" id="' + searchId + '" class="form-input admin-drilldown-search" placeholder="' + escapeHtml(t('Search employees…', 'Search employees…')) + '" autocomplete="off">',
			'<a class="btn btn--secondary btn--sm admin-drilldown-export" href="' + escapeHtml(exportUrl) + '" aria-label="' + escapeHtml(exportLabel) + '">' + escapeHtml(exportLabel) + '</a>',
			'</div>',
			'<div class="admin-drilldown-status" role="status" aria-live="polite">' + escapeHtml(t('Loading…', 'Loading…')) + '</div>',
			'<div class="table-container admin-drilldown-table-wrap" hidden>',
			'<table class="table table--hover azc-table--responsive admin-drilldown-table">',
			'<thead><tr>',
			'<th scope="col">' + escapeHtml(t('Name', 'Name')) + '</th>',
			'<th scope="col">' + escapeHtml(t('User ID', 'User ID')) + '</th>',
			'<th scope="col">' + escapeHtml(t('Active today', 'Active today')) + '</th>',
			'<th scope="col">' + escapeHtml(t('Overtime tracking set', 'Overtime tracking set')) + '</th>',
			'</tr></thead>',
			'<tbody id="admin-drilldown-tbody"></tbody>',
			'</table>',
			'</div>',
		].join('');

		const modal = Components.createModal({
			id: drilldownModalId,
			title,
			content,
			size: 'lg',
		});

		const state = { filter, search: '', employees: [] };
		const statusEl = modal.querySelector('.admin-drilldown-status');
		const tbody = modal.querySelector('#admin-drilldown-tbody');
		const tableWrap = modal.querySelector('.admin-drilldown-table-wrap');
		const searchInput = modal.querySelector('#' + searchId);

		function renderRows() {
			if (!tbody) {
				return;
			}
			const q = state.search.trim().toLowerCase();
			const filtered = state.employees.filter((row) => {
				if (!q) {
					return true;
				}
				return (row.displayName || '').toLowerCase().includes(q)
					|| (row.userId || '').toLowerCase().includes(q);
			});
			if (!filtered.length) {
				tbody.innerHTML = '<tr><td colspan="4">' + escapeHtml(t('No employees found.', 'No employees found.')) + '</td></tr>';
			} else {
				const nameHdr = t('Name', 'Name');
				const userIdHdr = t('User ID', 'User ID');
				const activeTodayHdr = t('Active today', 'Active today');
				const overtimeHdr = t('Overtime tracking set', 'Overtime tracking set');
				const td = (label, html) => Utils.responsiveTd
					? Utils.responsiveTd(label, html)
					: '<td>' + html + '</td>';
				tbody.innerHTML = filtered.map((row) => {
					const yes = t('Yes', 'Yes');
					const no = t('No', 'No');
					return '<tr>'
						+ td(nameHdr, escapeHtml(row.displayName || row.userId))
						+ td(userIdHdr, escapeHtml(row.userId))
						+ td(activeTodayHdr, escapeHtml(row.hasTimeEntriesToday ? yes : no))
						+ td(overtimeHdr, escapeHtml(row.hasOvertimeTrackingFrom ? yes : no))
						+ '</tr>';
				}).join('');
			}
			if (statusEl) {
				const countLabel = n('1 employee', '%n employees', filtered.length);
				const truncatedNotice = state.truncated
					? ' · ' + t('drilldownTruncatedNotice', 'Showing the first results. Use search to narrow down the list.')
					: '';
				statusEl.textContent = countLabel + truncatedNotice;
			}
		}

		function loadEmployees() {
			if (statusEl) {
				statusEl.textContent = t('Loading…', 'Loading…');
			}
			if (tableWrap) {
				tableWrap.hidden = true;
			}
			const params = new URLSearchParams({
				filter: state.filter,
				limit: '500',
			});
			Utils.ajax('/apps/arbeitszeitcheck/api/admin/dashboard-employees?' + params.toString(), {
				method: 'GET',
				onSuccess(data) {
					if (data.success && Array.isArray(data.employees)) {
						state.employees = data.employees;
						state.truncated = !!data.truncated;
						if (tableWrap) {
							tableWrap.hidden = false;
						}
						renderRows();
					} else if (statusEl) {
						statusEl.textContent = t('drilldownLoadError', 'Could not load employee list.');
					}
				},
				onError() {
					if (statusEl) {
						statusEl.textContent = t('drilldownLoadError', 'Could not load employee list.');
					}
				},
			});
		}

		if (searchInput) {
			let debounceTimer = null;
			searchInput.addEventListener('input', () => {
				clearTimeout(debounceTimer);
				debounceTimer = setTimeout(() => {
					state.search = searchInput.value;
					renderRows();
				}, 200);
			});
		}

		loadEmployees();
		Components.openModal(drilldownModalId);

		if (triggerCard) {
			triggerCard.setAttribute('aria-expanded', 'true');
			const onModalClose = (event) => {
				const detail = event && event.detail;
				if (!detail || detail.modalId === drilldownModalId) {
					triggerCard.setAttribute('aria-expanded', 'false');
					window.removeEventListener('modal-close', onModalClose);
				}
			};
			window.addEventListener('modal-close', onModalClose);
		}
	}

	window.addEventListener('beforeunload', cleanup);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
