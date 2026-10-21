/**
 * Manager people overview — vacation balances, approved upcoming vacation,
 * overtime and open issues for every employee in the actor's scope.
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const config = window.ArbeitszeitCheck?.peopleOverview || {};
	const state = {
		limit: 25,
		offset: 0,
		total: 0,
		loading: false,
		employeePicker: null,
		filter: 'all',
	};

	function t(key, fallback, vars) {
		const bundle = window.ArbeitszeitCheck?.l10n || {};
		const value = bundle[key];
		const msgid = (value !== undefined && value !== '') ? value : (fallback || key);
		if (vars && typeof vars === 'object') {
			return String(msgid).replace(/\{(\w+)\}/g, (_, name) => (
				Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : '{' + name + '}'
			));
		}
		return msgid;
	}

	function el(id) {
		return document.getElementById(id);
	}

	function escapeHtml(value) {
		if (value === null || value === undefined) {
			return '';
		}
		const div = document.createElement('div');
		div.textContent = String(value);
		return div.innerHTML;
	}

	function formatNumber(value, digits) {
		const num = Number(value);
		if (value === null || value === undefined || Number.isNaN(num)) {
			return null;
		}
		return num.toLocaleString(state.dateLocale || undefined, {
			minimumFractionDigits: 0,
			maximumFractionDigits: digits === undefined ? 1 : digits,
		});
	}

	function formatVacationDays(daysValue, vacation) {
		const unit = vacation && vacation.unit === 'hours' ? 'hours' : 'days';
		const num = formatNumber(daysValue, 1);
		if (num === null) {
			return '–';
		}
		if (unit === 'hours') {
			const hpd = Number(vacation && vacation.hoursPerDay) || Number(config.vacationHoursPerDay) || 8;
			return (Number(daysValue) * hpd).toLocaleString(state.dateLocale || undefined, { maximumFractionDigits: 1 }) + ' ' + t('h', 'h');
		}
		return num + ' ' + t('days', 'days');
	}

	function formatHours(value) {
		const num = formatNumber(value, 1);
		return num === null ? '–' : num + ' ' + t('h', 'h');
	}

	function setFilterError(message, focusId) {
		const errorEl = el('manager-people-filter-error');
		if (!errorEl) {
			return;
		}
		const inner = errorEl.querySelector('.azc-callout__text');
		if (inner) {
			inner.textContent = message || '';
		} else {
			errorEl.textContent = message || '';
		}
		errorEl.hidden = !message;
		if (message && focusId) {
			const target = document.getElementById(focusId);
			if (target && typeof target.focus === 'function') {
				target.focus();
			}
		}
	}

	function setLoading(loading) {
		state.loading = loading;
		const section = document.querySelector('.manager-scope-page__results');
		if (section) {
			section.setAttribute('aria-busy', loading ? 'true' : 'false');
		}
		const submit = el('manager-people-submit');
		if (submit) {
			submit.disabled = loading;
		}
	}

	function setEmpty(title, text) {
		const empty = el('manager-people-empty');
		const wrap = el('manager-people-table-wrap');
		if (empty) {
			empty.querySelector('.azc-empty-state__title').textContent = title;
			empty.querySelector('.azc-empty-state__text').textContent = text;
			empty.classList.remove('visually-hidden');
		}
		if (wrap) {
			wrap.classList.add('visually-hidden');
		}
		const nav = document.querySelector('.manager-scope-page__pagination');
		if (nav) {
			nav.classList.add('visually-hidden');
		}
	}

	function showTable() {
		const empty = el('manager-people-empty');
		const wrap = el('manager-people-table-wrap');
		if (empty) {
			empty.classList.add('visually-hidden');
		}
		if (wrap) {
			wrap.classList.remove('visually-hidden');
		}
		const nav = document.querySelector('.manager-scope-page__pagination');
		if (nav) {
			nav.classList.remove('visually-hidden');
		}
	}

	function updateCount() {
		const badge = el('manager-people-count');
		if (!badge) {
			return;
		}
		badge.textContent = state.total > 0
			? t('{count} employees', '{count} employees', { count: state.total })
			: '';
	}

	function updatePagination() {
		const pages = Math.max(1, Math.ceil(state.total / state.limit));
		const page = Math.min(pages, Math.floor(state.offset / state.limit) + 1);
		const indicator = el('manager-people-page-indicator');
		if (indicator) {
			indicator.textContent = state.total > 0
				? t('Page {page} of {pages}', 'Page {page} of {pages}', { page: page, pages: pages })
				: '';
		}
		const prev = el('manager-people-prev');
		const next = el('manager-people-next');
		if (prev) {
			prev.disabled = state.offset <= 0 || state.loading;
		}
		if (next) {
			next.disabled = (state.offset + state.limit) >= state.total || state.loading;
		}
	}

	function buildUrl(path, params) {
		const qs = new URLSearchParams(params).toString();
		return qs ? path + '?' + qs : path;
	}

	function employeeNameCell(employee) {
		const name = escapeHtml(employee.displayName || employee.userId);
		const uid = escapeHtml(employee.userId || '');
		const adminUrl = String(config.adminUserUrl || '');
		if (config.isAdminScope && adminUrl !== '') {
			const href = adminUrl.replace('__UID__', encodeURIComponent(employee.userId || ''));
			return '<a class="manager-people__name-link" href="' + escapeHtml(href) + '">' + name + '</a>'
				+ '<span class="manager-people__uid">' + uid + '</span>';
		}
		return '<span class="manager-people__name">' + name + '</span>'
			+ '<span class="manager-people__uid">' + uid + '</span>';
	}

	function vacationCell(employee) {
		const vacation = employee.vacation;
		if (!vacation) {
			return '<span class="manager-people__muted" title="'
				+ escapeHtml(t('Vacation balance unavailable for this employee.', 'Vacation balance unavailable for this employee.'))
				+ '">' + escapeHtml(t('Not available', 'Not available')) + '</span>';
		}
		if (vacation.error) {
			// Server flagged a configuration problem (e.g. missing hire
			// date) — show the spec's explicit "Not configured" badge
			// instead of a bare dash so HR sees it is actionable. For
			// admins the badge links straight to the employee profile
			// where the setting can be fixed.
			const badge = '<span class="azc-badge azc-badge--warning" title="'
				+ escapeHtml(String(vacation.error))
				+ '">' + escapeHtml(t('Not configured', 'Not configured')) + '</span>';
			const adminUrl = String(config.adminUserUrl || '');
			if (config.isAdminScope && adminUrl !== '') {
				const href = adminUrl.replace('__UID__', encodeURIComponent(employee.userId || ''));
				return '<a class="manager-people__cell-link" href="' + escapeHtml(href) + '" aria-label="'
					+ escapeHtml(t('Employee profile', 'Employee profile'))
					+ '">' + badge + '</a>';
			}
			return badge;
		}
		if (vacation.remaining === null || vacation.remaining === undefined) {
			return '<span class="manager-people__muted" title="'
				+ escapeHtml(t('Vacation balance unavailable for this employee.', 'Vacation balance unavailable for this employee.'))
				+ '">' + escapeHtml(t('Not available', 'Not available')) + '</span>';
		}
		const remaining = formatVacationDays(vacation.remaining, vacation);
		const total = vacation.totalAvailable !== null && vacation.totalAvailable !== undefined
			? ' <span class="manager-people__muted">/ ' + escapeHtml(formatVacationDays(vacation.totalAvailable, vacation)) + '</span>'
			: '';
		const cls = Number(vacation.remaining) < 0 ? 'manager-people__value manager-people__value--warn' : 'manager-people__value';
		return '<span class="' + cls + '">' + escapeHtml(remaining) + '</span>' + total;
	}

	function upcomingCell(employee) {
		const vacation = employee.vacation;
		if (!vacation) {
			return '<span class="manager-people__muted">–</span>';
		}
		const upcoming = vacation.approvedUpcoming;
		if (upcoming === null || upcoming === undefined) {
			return '<span class="manager-people__muted">–</span>';
		}
		return '<span class="manager-people__value">' + escapeHtml(formatVacationDays(upcoming, vacation)) + '</span>';
	}

	function overtimeCell(employee) {
		const overtime = employee.overtime;
		if (!overtime || overtime.balance === null || overtime.balance === undefined) {
			return '<span class="manager-people__muted" title="'
				+ escapeHtml(t('Overtime balance unavailable for this employee.', 'Overtime balance unavailable for this employee.'))
				+ '">' + escapeHtml(t('Not available', 'Not available')) + '</span>';
		}
		const badge = overtime.needsAttention
			? ' <span class="azc-badge azc-badge--warning">' + escapeHtml(t('needs attention', 'needs attention')) + '</span>'
			: '';
		return '<span class="manager-people__value">' + escapeHtml(formatHours(overtime.balance)) + '</span>' + badge;
	}

	function countBadgeCell(count, url, label) {
		const num = Number(count) || 0;
		const badge = num > 0
			? '<span class="azc-badge azc-badge--warning">' + num + '</span>'
			: '<span class="manager-people__muted">0</span>';
		return url && num > 0
			? '<a class="manager-people__cell-link" href="' + escapeHtml(url) + '" aria-label="' + escapeHtml(label) + '">' + badge + '</a>'
			: badge;
	}

	function violationsBadgeCell(openCount, criticalCount, url, label) {
		const num = Number(openCount) || 0;
		const critical = Number(criticalCount) || 0;
		if (num <= 0) {
			return '<span class="manager-people__muted">0</span>';
		}
		// Severity-colored: red when any unresolved violation is critical.
		const cls = critical > 0 ? 'azc-badge azc-badge--danger' : 'azc-badge azc-badge--warning';
		const text = critical > 0
			? num + ' · ' + critical + ' ' + t('critical', 'critical')
			: String(num);
		const badge = '<span class="' + cls + '">' + escapeHtml(text) + '</span>';
		return url
			? '<a class="manager-people__cell-link" href="' + escapeHtml(url) + '" aria-label="' + escapeHtml(label) + '">' + badge + '</a>'
			: badge;
	}

	function detailsCell(employee) {
		const links = [];
		const uid = employee.userId || '';
		if (config.employeeAbsencesUrl) {
			links.push('<a class="azc-btn azc-btn--secondary manager-people__action" href="'
				+ escapeHtml(buildUrl(config.employeeAbsencesUrl, { employee_id: uid })) + '">'
				+ escapeHtml(t('Absences', 'Absences')) + '</a>');
		}
		if (config.violationsUrl) {
			links.push('<a class="azc-btn azc-btn--secondary manager-people__action" href="'
				+ escapeHtml(buildUrl(config.violationsUrl, { userId: uid })) + '">'
				+ escapeHtml(t('Violations', 'Violations')) + '</a>');
		}
		return '<span class="azc-table-actions">' + links.join('') + '</span>';
	}

	function renderRows(employees) {
		const body = el('manager-people-body');
		if (!body) {
			return;
		}
		const violationsBase = String(config.violationsUrl || '');
		const absencesBase = String(config.employeeAbsencesUrl || '');
		const rows = employees.map((employee) => {
			const uid = employee.userId || '';
			const openViolations = Number(employee.openViolations) || 0;
			const criticalViolations = Number(employee.criticalViolations) || 0;
			const pendingAbsences = Number(employee.pendingAbsences) || 0;
			const violationsLink = openViolations > 0 && violationsBase
				? buildUrl(violationsBase, { userId: uid }) : '';
			const absencesLink = pendingAbsences > 0 && absencesBase
				? buildUrl(absencesBase, { employee_id: uid, status: 'pending' }) : '';
			return '<tr>'
				+ '<td data-cell="' + escapeHtml(t('Employee', 'Employee')) + '" class="manager-people__employee">' + employeeNameCell(employee) + '</td>'
				+ '<td data-cell="' + escapeHtml(t('Vacation remaining', 'Vacation remaining')) + '">' + vacationCell(employee) + '</td>'
				+ '<td data-cell="' + escapeHtml(t('Approved upcoming', 'Approved upcoming')) + '">' + upcomingCell(employee) + '</td>'
				+ '<td data-cell="' + escapeHtml(t('Overtime', 'Overtime')) + '">' + overtimeCell(employee) + '</td>'
				+ '<td data-cell="' + escapeHtml(t('Open issues', 'Open issues')) + '">'
					+ violationsBadgeCell(openViolations, criticalViolations, violationsLink, t('Violations', 'Violations')) + '</td>'
				+ '<td data-cell="' + escapeHtml(t('Pending requests', 'Pending requests')) + '">'
					+ countBadgeCell(pendingAbsences, absencesLink, t('Absences', 'Absences')) + '</td>'
				+ '<td data-cell="' + escapeHtml(t('Details', 'Details')) + '" class="actions-cell">' + detailsCell(employee) + '</td>'
				+ '</tr>';
		});
		body.innerHTML = rows.join('');
	}

	function readFilters() {
		const form = el('manager-people-filter-form');
		if (!form) {
			return null;
		}
		const formData = new FormData(form);
		const hiddenEl = el('manager-people-employee-filter-id');
		const sort = String(formData.get('sort') || 'violations').trim();
		return {
			employeeId: String(formData.get('employee_id') || hiddenEl?.value || '').trim(),
			year: String(formData.get('year') || '').trim(),
			search: String(formData.get('search') || '').trim(),
			sort: ['violations', 'name', 'vacation', 'overtime'].includes(sort) ? sort : 'violations',
			filter: ['all', 'needs_attention', 'pending_absences'].includes(state.filter) ? state.filter : 'all',
		};
	}

	function validateFilters(filters) {
		const pickerCheck = window.ArbeitszeitCheck?.validateManagerFilterEmployeeSelection?.(
			state.employeePicker,
			'#manager-people-employee-filter-search',
			'#manager-people-employee-filter-id'
		);
		if (pickerCheck && !pickerCheck.valid) {
			return { valid: false, message: pickerCheck.message, focusId: pickerCheck.focusId };
		}
		const year = Number(filters.year);
		if (!Number.isInteger(year) || year < 1970 || year > 2100) {
			return {
				valid: false,
				message: t('Invalid year. Please choose a year between 1970 and 2100.', 'Invalid year. Please choose a year between 1970 and 2100.'),
				focusId: 'manager-people-year',
			};
		}
		return { valid: true, year: year };
	}

	function buildQuery(filters, year) {
		const params = new URLSearchParams();
		params.set('year', String(year));
		params.set('limit', String(state.limit));
		params.set('offset', String(state.offset));
		params.set('filter', filters.filter);
		params.set('sort', filters.sort);
		if (filters.employeeId) {
			params.set('employeeId', filters.employeeId);
		}
		if (filters.search) {
			params.set('search', filters.search.slice(0, 200));
		}
		return params.toString();
	}

	function load() {
		if (typeof Utils.ajax !== 'function' || !config.apiUrl) {
			const message = t('Could not load the people overview.', 'Could not load the people overview.');
			setFilterError(message);
			Messaging?.showError?.(message);
			setEmpty(t('Could not load the people overview.', 'Could not load the people overview.'), '');
			return;
		}

		const filters = readFilters();
		if (!filters) {
			return;
		}
		const validation = validateFilters(filters);
		if (!validation.valid) {
			setFilterError(validation.message, validation.focusId);
			setEmpty(t('Check your filters', 'Check your filters'), validation.message || '');
			state.total = 0;
			updateCount();
			updatePagination();
			return;
		}
		setFilterError('');
		setLoading(true);

		const requestUrl = config.apiUrl + (config.apiUrl.indexOf('?') === -1 ? '?' : '&') + buildQuery(filters, validation.year);
		const ajaxPromise = Utils.ajax(requestUrl, {
			method: 'GET',
			onSuccess: (data) => {
				try {
					if (!data || data.success === false) {
						const message = data?.error || t('Could not load the people overview.', 'Could not load the people overview.');
						setFilterError(message);
						Messaging?.showError?.(message);
						setEmpty(t('Could not load the people overview.', 'Could not load the people overview.'), message);
						state.total = 0;
						updateCount();
						updatePagination();
						return;
					}
					state.total = Number(data.total || 0);
					const employees = Array.isArray(data.employees) ? data.employees : [];
					if (employees.length === 0) {
						setEmpty(
							t('No employees in your scope.', 'No employees in your scope.'),
							t('There is nobody in your scope to show yet.', 'There is nobody in your scope to show yet.')
						);
					} else {
						showTable();
						renderRows(employees);
					}
					if (Number(data.partialErrors) > 0) {
						Messaging?.showInfo?.(t('Some values could not be loaded. Try reloading the page.', 'Some values could not be loaded. Try reloading the page.'));
					}
					updateCount();
					updatePagination();
				} catch (err) {
					state.total = 0;
					const message = err?.message || t('Could not load the people overview.', 'Could not load the people overview.');
					setFilterError(message);
					Messaging?.showError?.(message);
					setEmpty(t('Could not load the people overview.', 'Could not load the people overview.'), message);
					updateCount();
					updatePagination();
				}
			},
			onError: (error) => {
				state.total = 0;
				const focusId = window.ArbeitszeitCheck?.handleManagerListApiError?.(error, {
					picker: state.employeePicker,
					searchSelector: '#manager-people-employee-filter-search',
					clearButtonSelector: '#manager-people-employee-filter-clear',
					searchFocusId: 'manager-people-employee-filter-search',
				});
				const message = error?.error || error?.message || t('Could not load the people overview.', 'Could not load the people overview.');
				setFilterError(message, focusId || undefined);
				Messaging?.showError?.(message);
				setEmpty(t('Could not load the people overview.', 'Could not load the people overview.'), message);
				updateCount();
				updatePagination();
			},
		});
		if (ajaxPromise && typeof ajaxPromise.finally === 'function') {
			ajaxPromise.finally(() => {
				setLoading(false);
				updatePagination();
			});
		} else {
			setLoading(false);
		}
	}

	function init() {
		state.dateLocale = window.ArbeitszeitCheck?.dateLocale || document.documentElement.lang || undefined;

		const initPicker = window.ArbeitszeitCheck?.initManagerScopedEmployeePicker;
		if (initPicker && el('manager-people-employee-filter-search')) {
			state.employeePicker = initPicker({
				hiddenSelector: '#manager-people-employee-filter-id',
				searchSelector: '#manager-people-employee-filter-search',
				listSelector: '#manager-people-employee-filter-listbox',
				wrapSelector: '#manager-people-employee-filter-wrap',
				statusSelector: '#manager-people-employee-filter-status',
				clearButtonSelector: '#manager-people-employee-filter-clear',
				idPrefix: 'manager-people-employee-filter',
				allowAll: true,
			});
		}

		// Quick-filter pills — mutual-exclusive radiogroup with roving
		// tabindex (design-system recipe B keyboard contract).
		const pillsWrap = el('manager-people-quick-filters');
		const pillButtons = pillsWrap
			? Array.prototype.slice.call(pillsWrap.querySelectorAll('.manager-people-pill'))
			: [];
		function activatePill(btn, focus) {
			pillButtons.forEach((b) => {
				b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
				b.tabIndex = b === btn ? 0 : -1;
			});
			if (focus) {
				btn.focus();
			}
			state.filter = btn.dataset.value || 'all';
			state.offset = 0;
			load();
		}
		if (pillsWrap) {
			pillsWrap.addEventListener('click', (event) => {
				const btn = event.target.closest('.manager-people-pill');
				if (btn && pillButtons.includes(btn)) {
					activatePill(btn, false);
				}
			});
			pillsWrap.addEventListener('keydown', (event) => {
				const cur = pillButtons.indexOf(document.activeElement);
				if (cur < 0) {
					return;
				}
				let next = null;
				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
					next = pillButtons[(cur + 1) % pillButtons.length];
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
					next = pillButtons[(cur - 1 + pillButtons.length) % pillButtons.length];
				} else if (event.key === 'Home') {
					next = pillButtons[0];
				} else if (event.key === 'End') {
					next = pillButtons[pillButtons.length - 1];
				}
				if (next) {
					event.preventDefault();
					activatePill(next, true);
				}
			});
		}

		const form = el('manager-people-filter-form');
		if (form) {
			form.addEventListener('submit', (event) => {
				event.preventDefault();
				state.offset = 0;
				load();
			});
		}
		const clear = el('manager-people-clear');
		if (clear) {
			clear.addEventListener('click', () => {
				if (state.employeePicker && typeof state.employeePicker.clear === 'function') {
					state.employeePicker.clear();
				}
				const search = el('manager-people-employee-filter-search');
				if (search) {
					search.value = '';
				}
				const nameSearch = el('manager-people-search');
				if (nameSearch) {
					nameSearch.value = '';
				}
				const sort = el('manager-people-sort');
				if (sort) {
					sort.value = 'violations';
				}
				const year = el('manager-people-year');
				if (year) {
					year.value = String(new Date().getFullYear());
				}
				state.filter = 'all';
				const allPill = pillButtons.filter((b) => b.dataset.value === 'all')[0] || pillButtons[0];
				if (allPill) {
					pillButtons.forEach((b) => {
						b.setAttribute('aria-pressed', b === allPill ? 'true' : 'false');
						b.tabIndex = b === allPill ? 0 : -1;
					});
				}
				state.offset = 0;
				setFilterError('');
				load();
			});
		}
		const prev = el('manager-people-prev');
		if (prev) {
			prev.addEventListener('click', () => {
				if (state.offset - state.limit >= 0) {
					state.offset -= state.limit;
					load();
				}
			});
		}
		const next = el('manager-people-next');
		if (next) {
			next.addEventListener('click', () => {
				if (state.offset + state.limit < state.total) {
					state.offset += state.limit;
					load();
				}
			});
		}

		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
