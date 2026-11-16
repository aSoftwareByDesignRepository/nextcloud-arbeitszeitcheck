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
		pendingReload: false,
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

	/** Plural-aware translate (Nextcloud window.n replaces %n). */
	function n(singular, plural, count) {
		const c = Number.isFinite(Number(count)) ? Number(count) : 0;
		if (typeof window.n === 'function') {
			return window.n('arbeitszeitcheck', singular, plural, c);
		}
		return String(c === 1 ? singular : plural).replace('%n', String(c));
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
		// Clear stale rows: visually-hidden keeps them in the a11y tree,
		// so screen readers would announce outdated results alongside the
		// empty state.
		const body = el('manager-people-body');
		if (body) {
			body.innerHTML = '';
		}
		const nav = el('manager-people-pager');
		if (nav) {
			nav.hidden = true;
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
	}

	function updateCount() {
		const badge = el('manager-people-count');
		if (!badge) {
			return;
		}
		if (state.total > 0) {
			badge.textContent = n('1 employee', '%n employees', state.total);
			badge.classList.remove('visually-hidden');
		} else {
			badge.textContent = '';
			badge.classList.add('visually-hidden');
		}
	}

	function syncPager() {
		const nav = el('manager-people-pager');
		const pager = window.AzcTablePager;
		if (!nav || !pager || typeof pager.update !== 'function') {
			return;
		}
		pager.update(nav, {
			page: Math.floor(state.offset / state.limit) + 1,
			pages: Math.max(1, Math.ceil(state.total / state.limit)),
			total: state.total,
			perPage: state.limit,
		});
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
			? num + ' · ' + n('%n critical violation', '%n critical violations', critical)
			: String(num);
		const badge = '<span class="' + cls + '">' + escapeHtml(text) + '</span>';
		return url
			? '<a class="manager-people__cell-link" href="' + escapeHtml(url) + '" aria-label="' + escapeHtml(label) + '">' + badge + '</a>'
			: badge;
	}

	function detailsCell(employee) {
		const links = [];
		const uid = employee.userId || '';
		const name = employee.displayName || uid;
		const glyph = (icon) => (window.AzcCatalog && window.AzcCatalog.render) ? window.AzcCatalog.render(icon) : '';
		if (config.employeeAbsencesUrl) {
			const label = t('Absences', 'Absences');
			links.push('<a class="azc-btn azc-btn--secondary azc-icon-btn manager-people__action" href="'
				+ escapeHtml(buildUrl(config.employeeAbsencesUrl, { employee_id: uid })) + '"'
				+ ' aria-label="' + escapeHtml(label + ': ' + name) + '" title="' + escapeHtml(label) + '">'
				+ glyph('calendar') + '</a>');
		}
		if (config.violationsUrl) {
			const label = t('Violations', 'Violations');
			links.push('<a class="azc-btn azc-btn--secondary azc-icon-btn manager-people__action" href="'
				+ escapeHtml(buildUrl(config.violationsUrl, { userId: uid })) + '"'
				+ ' aria-label="' + escapeHtml(label + ': ' + name) + '" title="' + escapeHtml(label) + '">'
				+ glyph('alert-triangle') + '</a>');
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
				+ '<td data-label="' + escapeHtml(t('Employee', 'Employee')) + '" class="manager-people__employee">' + employeeNameCell(employee) + '</td>'
				+ '<td data-label="' + escapeHtml(t('Vacation remaining', 'Vacation remaining')) + '">' + vacationCell(employee) + '</td>'
				+ '<td data-label="' + escapeHtml(t('Approved upcoming', 'Approved upcoming')) + '">' + upcomingCell(employee) + '</td>'
				+ '<td data-label="' + escapeHtml(t('Overtime', 'Overtime')) + '">' + overtimeCell(employee) + '</td>'
				+ '<td data-label="' + escapeHtml(t('Open issues', 'Open issues')) + '">'
					+ violationsBadgeCell(openViolations, criticalViolations, violationsLink, t('Violations', 'Violations')) + '</td>'
				+ '<td data-label="' + escapeHtml(t('Pending requests', 'Pending requests')) + '">'
					+ countBadgeCell(pendingAbsences, absencesLink, t('Absences', 'Absences')) + '</td>'
				+ '<td data-label="' + escapeHtml(t('Details', 'Details')) + '" class="actions-cell">' + detailsCell(employee) + '</td>'
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
			syncPager();
			return;
		}
		setFilterError('');
		if (state.loading) {
			// Queue the newest intent instead of dropping it — an in-flight
			// load must not swallow a filter change or pager click.
			state.pendingReload = true;
			return;
		}
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
						syncPager();
						return;
					}
					state.total = Number(data.total || 0);
					const employees = Array.isArray(data.employees) ? data.employees : [];
					if (employees.length === 0 && state.total > 0 && state.offset >= state.total) {
						state.offset = Math.max(0, (Math.ceil(state.total / state.limit) - 1) * state.limit);
						load();
						return;
					}
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
					syncPager();
				} catch (err) {
					state.total = 0;
					const message = err?.message || t('Could not load the people overview.', 'Could not load the people overview.');
					setFilterError(message);
					Messaging?.showError?.(message);
					setEmpty(t('Could not load the people overview.', 'Could not load the people overview.'), message);
					updateCount();
					syncPager();
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
				syncPager();
			},
		});
		if (ajaxPromise && typeof ajaxPromise.finally === 'function') {
			ajaxPromise.finally(() => {
				setLoading(false);
				syncPager();
				if (state.pendingReload) {
					state.pendingReload = false;
					load();
				}
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

		// Quick-filter pills — design-system recipe B via the synced
		// view-components pack (radiogroup + roving tabindex + arrow keys).
		const pillsWrap = el('manager-people-quick-filters');
		let pillInitPending = true;
		const pillGroup = window.CheckViewComponents
			? window.CheckViewComponents.pillGroup(pillsWrap, (value) => {
				state.filter = value || 'all';
				state.offset = 0;
				if (pillInitPending) {
					pillInitPending = false;
					return;
				}
				load();
			})
			: null;

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
				const sort = el('manager-people-sort');
				if (sort) {
					sort.value = 'violations';
				}
				const year = el('manager-people-year');
				if (year) {
					year.value = String(new Date().getFullYear());
				}
				setFilterError('');
				if (pillGroup && pillsWrap) {
					pillGroup.select('all');
				} else {
					state.filter = 'all';
					state.offset = 0;
					load();
				}
			});
		}
		// Design-system table pager (remote mode): the host owns the
		// limit/offset fetch, the pack paints total, size select and pages.
		const pagerSizes = (window.AzcTablePager && Array.isArray(window.AzcTablePager.SIZES))
			? window.AzcTablePager.SIZES
			: [10, 25, 50, 100];
		const urlParams = new URLSearchParams(window.location.search);
		const urlSize = Number(urlParams.get('pp_per_page'));
		if (pagerSizes.includes(urlSize)) {
			state.limit = urlSize;
		}
		const urlPage = Number(urlParams.get('pp_page'));
		if (Number.isInteger(urlPage) && urlPage > 1) {
			state.offset = (urlPage - 1) * state.limit;
		}
		const pagerNav = el('manager-people-pager');
		if (pagerNav) {
			pagerNav.__goTo = (page, size) => {
				if (pagerSizes.includes(Number(size))) {
					state.limit = Number(size);
				}
				state.offset = Math.max(0, (Math.max(1, Number(page) || 1) - 1) * state.limit);
				load();
			};
		}

		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
