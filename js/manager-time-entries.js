(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const state = {
		limit: 25,
		offset: 0,
		total: 0,
		lastFilters: null,
		loading: false,
		countBeforeLoad: '',
		dateLocale: window.ArbeitszeitCheck?.dateLocale || document.documentElement.lang || undefined,
	};

	function t(key, fallback, vars) {
		const bundle = window.ArbeitszeitCheck?.l10n || {};
		const value = bundle[key];
		if (value !== undefined && value !== '') {
			if (vars && typeof vars === 'object') {
				return String(value).replace(/\{(\w+)\}/g, (_, name) => (
					Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : '{' + name + '}'
				));
			}
			return value;
		}
		const msgid = fallback || key;
		if (typeof window.t === 'function') {
			return window.t('arbeitszeitcheck', msgid, vars || {});
		}
		if (vars && typeof vars === 'object') {
			return String(msgid).replace(/\{(\w+)\}/g, (_, name) => (
				Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : '{' + name + '}'
			));
		}
		return msgid;
	}

	function formatHours(value) {
		if (value === null || value === undefined || value === '') {
			return '-';
		}
		const num = Number(value);
		if (Number.isNaN(num)) {
			return '-';
		}
		if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.formatHours === 'function') {
			return window.ArbeitszeitCheckUtils.formatHours(num);
		}
		return String(Math.round(num * 100) / 100);
	}

	function formatBreaks(entry) {
		if (Array.isArray(entry.breaks) && entry.breaks.length > 0) {
			return entry.breaks.map((b) => {
				const s = formatDateTime(b.start || b.start_time, 'time');
				const e = formatDateTime(b.end || b.end_time, 'time');
				return s + '–' + e;
			}).join(', ');
		}
		if (entry.breakStartTime && entry.breakEndTime) {
			return formatDateTime(entry.breakStartTime, 'time') + '–' + formatDateTime(entry.breakEndTime, 'time');
		}
		return formatHours(entry.breakDurationHours) + ' h';
	}

	function formatDateTime(iso, mode) {
		if (!iso) {
			return '-';
		}
		const api = window.ArbeitszeitCheckTime;
		if (api) {
			if (mode === 'date') {
				const ymd = String(iso).slice(0, 10);
				if (/^\d{4}-\d{2}-\d{2}$/.test(ymd)) {
					const parsed = api.parseYmd(ymd);
					return parsed ? api.formatDate(parsed) : '-';
				}
				return api.formatDate(iso) || '-';
			}
			return api.formatTime(iso) || '-';
		}
		if (Utils.formatTime && Utils.formatDate) {
			return mode === 'date'
				? (Utils.formatDate(iso, 'DD.MM.YYYY') || '-')
				: (Utils.formatTime(iso) || '-');
		}
		return '-';
	}

	function escapeHtml(value) {
		if (value === null || value === undefined) {
			return '';
		}
		const div = document.createElement('div');
		div.textContent = String(value);
		return div.innerHTML;
	}

	function setLoading(isLoading) {
		state.loading = isLoading;
		const results = document.querySelector('.manager-scope-page__results');
		if (results) {
			results.setAttribute('aria-busy', isLoading ? 'true' : 'false');
		}
		const submitBtn = document.getElementById('employee-time-entries-submit');
		const clearBtn = document.getElementById('employee-time-entries-clear');
		const prevBtn = document.getElementById('employee-time-entries-prev');
		const nextBtn = document.getElementById('employee-time-entries-next');
		if (submitBtn) {
			submitBtn.disabled = isLoading;
			submitBtn.setAttribute('aria-disabled', isLoading ? 'true' : 'false');
		}
		if (clearBtn) {
			clearBtn.disabled = isLoading;
		}
		if (prevBtn) {
			prevBtn.disabled = isLoading || state.offset <= 0;
		}
		if (nextBtn) {
			nextBtn.disabled = isLoading || state.offset + state.limit >= state.total;
		}
		const countEl = document.getElementById('employee-time-entries-count');
		if (!countEl) {
			return;
		}
		if (isLoading) {
			state.countBeforeLoad = countEl.textContent;
			countEl.textContent = t('Loading...', 'Loading...');
		}
	}

	function clearFilterFieldErrors() {
		const form = document.getElementById('employee-time-entries-filter-form');
		if (!form) {
			return;
		}
		form.querySelectorAll('[aria-invalid="true"]').forEach((el) => {
			el.removeAttribute('aria-invalid');
		});
		const errorEl = document.getElementById('employee-time-entries-filter-error');
		setFilterErrorText(errorEl, '');
	}

	function setFilterErrorText(errorEl, message) {
		if (!errorEl) {
			return;
		}
		const inner = errorEl.querySelector('.azc-callout__text');
		if (inner) {
			inner.textContent = message;
		} else {
			errorEl.textContent = message;
		}
		errorEl.hidden = !message;
	}

	function setFilterError(message, focusId) {
		const errorEl = document.getElementById('employee-time-entries-filter-error');
		setFilterErrorText(errorEl, message);
		if (message && focusId) {
			const focusEl = document.getElementById(focusId);
			if (focusEl) {
				focusEl.setAttribute('aria-invalid', 'true');
				focusEl.focus();
			}
		}
	}

	function resolveLoadErrorFocus(error) {
		const fromHandler = window.ArbeitszeitCheck?.handleManagerListApiError?.(error, {
			picker: employeeFilterPicker,
			searchSelector: '#employee-filter-search',
			clearButtonSelector: '#employee-filter-clear',
			searchFocusId: 'employee-filter-search',
		});
		if (fromHandler) {
			return fromHandler;
		}
		if (error?.status === 400) {
			return 'start-date-filter';
		}
		return null;
	}

	function europeanToYmd(value) {
		if (!value || !/^\d{2}\.\d{2}\.\d{4}$/.test(value)) {
			return '';
		}
		const parts = String(value).split('.');
		return parts[2] + '-' + parts[1] + '-' + parts[0];
	}

	function parseEuropeanDate(value) {
		if (!value || !/^\d{2}\.\d{2}\.\d{4}$/.test(value)) {
			return null;
		}
		const parts = String(value).split('.');
		const date = new Date(
			parseInt(parts[2], 10),
			parseInt(parts[1], 10) - 1,
			parseInt(parts[0], 10)
		);
		if (
			date.getFullYear() !== parseInt(parts[2], 10)
			|| date.getMonth() !== parseInt(parts[1], 10) - 1
			|| date.getDate() !== parseInt(parts[0], 10)
		) {
			return null;
		}
		return date;
	}

	function validateFilters(filters) {
		clearFilterFieldErrors();
		const employeeCheck = window.ArbeitszeitCheck?.validateManagerFilterEmployeeSelection?.(
			employeeFilterPicker,
			'#employee-filter-search',
			'#employee-filter-id'
		);
		if (employeeCheck && !employeeCheck.valid) {
			setFilterError(employeeCheck.message, employeeCheck.focusId);
			return { valid: false };
		}
		if (!filters.startDate || !filters.endDate) {
			const message = t('Please select start and end date.', 'Please select start and end date.');
			setFilterError(message, !filters.startDate ? 'start-date-filter' : 'end-date-filter');
			return { valid: false };
		}
		const startParsed = parseEuropeanDate(filters.startDate);
		const endParsed = parseEuropeanDate(filters.endDate);
		if (!startParsed || !endParsed) {
			const message = t(
				'Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).',
				'Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).'
			);
			setFilterError(message, !startParsed ? 'start-date-filter' : 'end-date-filter');
			return { valid: false };
		}
		if (startParsed > endParsed) {
			const message = t(
				'Invalid date range. The start date must be before the end date.',
				'Invalid date range. The start date must be before the end date.'
			);
			setFilterError(message, 'start-date-filter');
			return { valid: false };
		}
		const dp = window.ArbeitszeitCheckDatepicker;
		const toISO = dp && typeof dp.convertEuropeanToISO === 'function'
			? dp.convertEuropeanToISO
			: europeanToYmd;
		const startISO = toISO(filters.startDate);
		const endISO = toISO(filters.endDate);
		if (!/^\d{4}-\d{2}-\d{2}$/.test(startISO) || !/^\d{4}-\d{2}-\d{2}$/.test(endISO)) {
			const message = t(
				'Invalid date range. Please use valid dates in YYYY-MM-DD format.',
				'Invalid date range. Please use valid dates in YYYY-MM-DD format.'
			);
			setFilterError(message, 'start-date-filter');
			return { valid: false };
		}
		const maxDays = Number(window.ArbeitszeitCheck?.maxManagerListDateRangeDays) || 365;
		const spanDays = Math.round((endParsed.getTime() - startParsed.getTime()) / 86400000) + 1;
		if (spanDays > maxDays) {
			const message = t('dateRangeTooLong', 'Date range must not exceed %d days. Please narrow the range.')
				.replace('%d', String(maxDays));
			setFilterError(message, 'start-date-filter');
			return { valid: false };
		}
		return { valid: true, startISO, endISO };
	}

	function setResultsPanel(options) {
		const emptyEl = document.getElementById('employee-time-entries-empty');
		const tableWrap = document.getElementById('employee-time-entries-table-wrap');
		const body = document.getElementById('employee-time-entries-body');
		if (!emptyEl || !tableWrap) {
			return;
		}
		const title = options?.title || '';
		const text = options?.text || '';
		const showTable = options?.showTable === true;

		if (showTable) {
			emptyEl.classList.add('visually-hidden');
			tableWrap.classList.remove('visually-hidden');
			return;
		}

		emptyEl.classList.remove('visually-hidden');
		tableWrap.classList.add('visually-hidden');
		if (body) {
			body.innerHTML = '';
		}
		const titleEl = emptyEl.querySelector('.azc-empty-state__title')
			|| emptyEl.querySelector('.empty-state__title');
		const desc = emptyEl.querySelector('.azc-empty-state__text')
			|| emptyEl.querySelector('.empty-state__description');
		if (titleEl && title) {
			titleEl.textContent = title;
		}
		if (desc) {
			desc.textContent = text;
		}
	}

	function setEmpty(title, text) {
		setResultsPanel({
			title: title || t('Select filters first', 'Select filters first'),
			text: text || t('Choose a date range to load entries.', 'Choose a date range to load entries.'),
		});
	}

	function setLoadingResultsPanel() {
		setResultsPanel({
			title: t('Loading results…', 'Loading results…'),
			text: t('Loading...', 'Loading...'),
		});
	}

	function statusBadgeHtml(status) {
		const label = status || '-';
		const variant = Utils.badgeVariantForTimeEntryStatus
			? Utils.badgeVariantForTimeEntryStatus(status)
			: 'secondary';
		if (Utils.renderBadgeHtml) {
			return Utils.renderBadgeHtml(label, variant);
		}
		return `<span class="badge badge--${escapeHtml(variant)}">${escapeHtml(label)}</span>`;
	}

	function renderEntries(entries) {
		const body = document.getElementById('employee-time-entries-body');
		const emptyEl = document.getElementById('employee-time-entries-empty');
		const tableWrap = document.getElementById('employee-time-entries-table-wrap');
		if (!body || !emptyEl || !tableWrap) {
			return;
		}

		if (!entries.length) {
			setResultsPanel({
				title: t('No matching time entries', 'No matching time entries'),
				text: t('No entries found for the selected filters.', 'No entries found for the selected filters.'),
			});
			body.innerHTML = '';
			return;
		}

		body.innerHTML = entries.map((entry) => {
			const canCorrect = entry.status === 'completed';
			const summaryPayload = {
				userId: entry.userId || null,
				projectCheckProjectId: entry.projectCheckProjectId || null,
				startTime: entry.displayStartTime || entry.startTime || null,
				endTime: entry.displayEndTime || entry.endTime || null,
				breaks: entry.displayBreaks || entry.breaks || null,
				minBreakMinutes: entry.minBreakMinutes,
			};
			const summaryJson = Utils.encodeAttributeJson(summaryPayload);
			const actionCell = canCorrect
				? `<button type="button" class="azc-btn azc-btn--secondary azc-btn--sm btn-manager-correct" data-entry-id="${escapeHtml(String(entry.id))}" data-entry-updated="${escapeHtml(entry.updatedAt || '')}" data-entry-summary="${summaryJson}" aria-label="${escapeHtml(t('Correct time entry', 'Correct time entry'))}">${escapeHtml(t('Correct', 'Correct'))}</button>`
				: '<span class="text-muted">–</span>';
			const td = (label, html, cls = '') => Utils.responsiveTd
				? Utils.responsiveTd(label, html, cls)
				: `<td${cls ? ` class="${cls}"` : ''}>${html}</td>`;
			return [
				'<tr>',
				td(t('Name', 'Name'), escapeHtml(entry.displayName || entry.userId || '-')),
				td(t('Date', 'Date'), escapeHtml(formatDateTime(entry.startTime, 'date'))),
				td(t('Start', 'Start'), escapeHtml(formatDateTime(entry.startTime, 'time'))),
				td(t('End', 'End'), escapeHtml(formatDateTime(entry.endTime, 'time'))),
				td(t('Working Hours', 'Working Hours'), escapeHtml(formatHours(entry.workingDurationHours))),
				td(t('Break', 'Break'), escapeHtml(formatBreaks(entry))),
				td(t('Status', 'Status'), statusBadgeHtml(entry.status)),
				td(t('Description', 'Description'), escapeHtml(entry.description || t('No description', 'No description')), 'description-cell'),
				td(t('Actions', 'Actions'), `<div class="azc-table-actions" role="group" aria-label="${escapeHtml(t('Actions', 'Actions'))}">${actionCell}</div>`, 'actions-cell'),
				'</tr>',
			].join('');
		}).join('');

		setResultsPanel({ showTable: true });
	}

	function updatePagination() {
		const prevBtn = document.getElementById('employee-time-entries-prev');
		const nextBtn = document.getElementById('employee-time-entries-next');
		const indicator = document.getElementById('employee-time-entries-page-indicator');
		const currentPage = Math.floor(state.offset / state.limit) + 1;
		const totalPages = Math.max(1, Math.ceil(state.total / state.limit));

		if (indicator) {
			indicator.textContent = t('Page {page} of {pages}', 'Page {page} of {pages}')
				.replace('{page}', String(currentPage))
				.replace('{pages}', String(totalPages));
		}
		if (prevBtn) {
			prevBtn.disabled = state.offset <= 0;
		}
		if (nextBtn) {
			nextBtn.disabled = state.offset + state.limit >= state.total;
		}
	}

	function updateCount() {
		const countEl = document.getElementById('employee-time-entries-count');
		if (!countEl) {
			return;
		}
		if (state.total <= 0 && !state.lastFilters) {
			countEl.textContent = '';
			state.countBeforeLoad = '';
			return;
		}
		const text = t('{count} entries', '{count} entries', { count: state.total });
		countEl.textContent = text;
		state.countBeforeLoad = text;
	}

	let employeeFilterPicker = null;

	function syncEmployeeFilterPicker(employees) {
		if (!employeeFilterPicker || !Array.isArray(employees)) {
			return;
		}
		const selectedId = employeeFilterPicker.getUserId();
		if (!selectedId) {
			return;
		}
		const match = employees.find((employee) => employee.userId === selectedId);
		if (match) {
			employeeFilterPicker.setSelection(match.userId, match.displayName || match.userId);
		}
	}

	function initEmployeeFilterPicker() {
		const initPicker = window.ArbeitszeitCheck?.initManagerScopedEmployeePicker;
		if (!initPicker || !document.getElementById('employee-filter-search')) {
			return;
		}
		employeeFilterPicker = initPicker({
			hiddenSelector: '#employee-filter-id',
			searchSelector: '#employee-filter-search',
			listSelector: '#employee-filter-listbox',
			wrapSelector: '#employee-filter-wrap',
			statusSelector: '#employee-filter-status',
			clearButtonSelector: '#employee-filter-clear',
			idPrefix: 'employee-filter',
			allowAll: true,
		});
	}

	function readFiltersFromForm() {
		const form = document.getElementById('employee-time-entries-filter-form');
		if (!form) {
			return null;
		}
		const formData = new FormData(form);
		const startEl = document.getElementById('start-date-filter');
		const endEl = document.getElementById('end-date-filter');
		const hiddenEl = document.getElementById('employee-filter-id');
		return {
			employeeId: String(formData.get('employee_id') || hiddenEl?.value || '').trim(),
			startDate: String(formData.get('start_date') || startEl?.value || '').trim(),
			endDate: String(formData.get('end_date') || endEl?.value || '').trim(),
			status: String(formData.get('status') || '').trim(),
		};
	}

	function buildQuery(filters, isoDates) {
		const startISO = isoDates?.startISO || europeanToYmd(filters.startDate);
		const endISO = isoDates?.endISO || europeanToYmd(filters.endDate);
		const params = new URLSearchParams();
		params.set('startDate', startISO);
		params.set('endDate', endISO);
		params.set('limit', String(state.limit));
		params.set('offset', String(state.offset));
		if (filters.employeeId) {
			params.set('employeeId', filters.employeeId);
		}
		if (filters.status) {
			params.set('status', filters.status);
		}
		return params.toString();
	}

	function loadEntries() {
		if (typeof Utils.ajax !== 'function') {
			const message = t('Could not load employee time entries.', 'Could not load employee time entries.');
			setFilterError(message);
			Messaging?.showError?.(message);
			setEmpty(t('Check your filters', 'Check your filters'), message);
			return;
		}

		const filters = readFiltersFromForm();
		if (!filters) {
			return;
		}

		const validation = validateFilters(filters);
		if (!validation.valid) {
			state.lastFilters = null;
			const message = document.getElementById('employee-time-entries-filter-error')
				?.querySelector('.azc-callout__text')?.textContent
				|| t('Choose a date range to load entries.', 'Choose a date range to load entries.');
			setEmpty(t('Check your filters', 'Check your filters'), message);
			updateCount();
			updatePagination();
			return;
		}

		if (state.loading) {
			return;
		}

		state.lastFilters = filters;
		setLoading(true);
		setLoadingResultsPanel();
		const query = buildQuery(filters, validation);
		const ajaxPromise = Utils.ajax(`/apps/arbeitszeitcheck/api/manager/employee-time-entries?${query}`, {
			method: 'GET',
			onSuccess: (data) => {
				try {
					if (!data || data.success === false) {
						const message = data?.error || t('Could not load employee time entries.', 'Could not load employee time entries.');
						setFilterError(message);
						Messaging?.showError?.(message);
						setEmpty(t('Check your filters', 'Check your filters'), message);
						state.total = 0;
						updateCount();
						updatePagination();
						return;
					}

					if (data.requiresFilters) {
						const message = t('Please select start and end date.', 'Please select start and end date.');
						setFilterError(
							message,
							!filters.startDate ? 'start-date-filter' : 'end-date-filter'
						);
						state.lastFilters = null;
						state.total = 0;
						setEmpty(t('Check your filters', 'Check your filters'), message);
						updateCount();
						updatePagination();
						return;
					}

					clearFilterFieldErrors();
					state.total = Number(data.total || 0);
					syncEmployeeFilterPicker(Array.isArray(data.employees) ? data.employees : []);
					renderEntries(Array.isArray(data.entries) ? data.entries : []);
					updateCount();
					state.countBeforeLoad = document.getElementById('employee-time-entries-count')?.textContent || '';
					updatePagination();
				} catch (err) {
					state.total = 0;
					const message = err?.message || t('Could not load employee time entries.', 'Could not load employee time entries.');
					setFilterError(message);
					Messaging?.showError?.(message);
					setEmpty(t('Check your filters', 'Check your filters'), message);
					updateCount();
					updatePagination();
				}
			},
			onError: (error) => {
				state.total = 0;
				const message = error?.error || error?.message || t('Could not load employee time entries.', 'Could not load employee time entries.');
				const focusId = resolveLoadErrorFocus(error);
				setFilterError(message, focusId);
				Messaging?.showError?.(message);
				setEmpty(t('Check your filters', 'Check your filters'), message);
				updateCount();
				updatePagination();
			},
		});
		if (ajaxPromise && typeof ajaxPromise.finally === 'function') {
			ajaxPromise.finally(() => {
				setLoading(false);
			});
		} else {
			setLoading(false);
		}
	}

	function bindPagination() {
		const prevBtn = document.getElementById('employee-time-entries-prev');
		const nextBtn = document.getElementById('employee-time-entries-next');

		if (prevBtn) {
			prevBtn.addEventListener('click', () => {
				state.offset = Math.max(0, state.offset - state.limit);
				loadEntries();
			});
		}
		if (nextBtn) {
			nextBtn.addEventListener('click', () => {
				state.offset += state.limit;
				loadEntries();
			});
		}
	}

	function bindForm() {
		const form = document.getElementById('employee-time-entries-filter-form');
		const clearBtn = document.getElementById('employee-time-entries-clear');
		if (!form || !clearBtn) {
			return;
		}

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			state.offset = 0;
			loadEntries();
		});

		clearBtn.addEventListener('click', () => {
			if (state.loading) {
				return;
			}
			form.reset();
			clearFilterFieldErrors();
			state.offset = 0;
			state.total = 0;
			state.lastFilters = null;
			state.countBeforeLoad = '';
			setDefaultDateRange(true);
			setEmpty(
				t('Select filters first', 'Select filters first'),
				t('Choose a date range to load entries.', 'Choose a date range to load entries.')
			);
			const countEl = document.getElementById('employee-time-entries-count');
			if (countEl) {
				countEl.textContent = '';
			}
			updatePagination();
			if (employeeFilterPicker) {
				employeeFilterPicker.clear();
			}
			const searchEl = document.getElementById('employee-filter-search');
			if (searchEl) {
				searchEl.focus();
			}
			const tbody = document.getElementById('employee-time-entries-body');
			const tableWrap = document.getElementById('employee-time-entries-table-wrap');
			if (tbody) {
				tbody.innerHTML = '';
			}
			if (tableWrap) {
				tableWrap.classList.add('visually-hidden');
			}
		});

		form.querySelectorAll('#start-date-filter, #end-date-filter').forEach((input) => {
			input.addEventListener('change', clearFilterFieldErrors);
		});
	}

	function toEuropeanDateString(date) {
		const day = String(date.getDate()).padStart(2, '0');
		const month = String(date.getMonth() + 1).padStart(2, '0');
		const year = date.getFullYear();
		return `${day}.${month}.${year}`;
	}

	function setDefaultDateRange(force) {
		const startInput = document.getElementById('start-date-filter');
		const endInput = document.getElementById('end-date-filter');
		if (!startInput || !endInput) {
			return;
		}

		if (!force && (startInput.value || endInput.value)) {
			return;
		}

		const api = window.ArbeitszeitCheckTime;
		if (api) {
			const endYmd = api.todayYmd();
			const endParsed = api.parseYmd(endYmd);
			if (endParsed) {
				const startParsed = new Date(endParsed);
				startParsed.setMonth(startParsed.getMonth() - 1);
				startInput.value = Utils.formatDate
					? Utils.formatDate(startParsed, 'DD.MM.YYYY')
					: toEuropeanDateString(startParsed);
				endInput.value = Utils.formatDate
					? Utils.formatDate(endParsed, 'DD.MM.YYYY')
					: toEuropeanDateString(endParsed);
				return;
			}
		}
		const today = new Date();
		const oneMonthAgo = new Date(today);
		oneMonthAgo.setMonth(oneMonthAgo.getMonth() - 1);
		startInput.value = toEuropeanDateString(oneMonthAgo);
		endInput.value = toEuropeanDateString(today);
	}


	function initManagerCorrectDelegation() {
		const body = document.getElementById('employee-time-entries-body');
		if (!body || body.dataset.managerCorrectBound === '1') {
			return;
		}
		body.dataset.managerCorrectBound = '1';
		body.addEventListener('click', (event) => {
			const btn = event.target.closest('.btn-manager-correct');
			if (!btn || !body.contains(btn)) {
				return;
			}
			const MgrCorrection = window.ArbeitszeitCheckManagerCorrection;
			const id = btn.getAttribute('data-entry-id');
			const updatedAt = btn.getAttribute('data-entry-updated') || '';
			const summary = MgrCorrection
				? MgrCorrection.parseEntrySummary(btn.getAttribute('data-entry-summary'))
				: null;
			if (!id || !MgrCorrection) {
				return;
			}
			if (!summary) {
				const message = t(
					'correctionErrorLoadEntry',
					'Could not load the stored times for this entry. Please reload the page and try again.'
				);
				Messaging?.showError?.(message);
				return;
			}
			MgrCorrection.open(id, updatedAt, summary);
		});
	}


	function init() {
		initEmployeeFilterPicker();
		initManagerCorrectDelegation();
		setDefaultDateRange(false);
		bindForm();
		bindPagination();
		updatePagination();
		document.addEventListener('arbeitszeitcheck:manager-entry-corrected', loadEntries);
		document.addEventListener('arbeitszeitcheck:manager-entry-created', loadEntries);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
