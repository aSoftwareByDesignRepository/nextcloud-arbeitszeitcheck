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

	/** Plural-aware translate (Nextcloud window.n replaces %n). */
	function n(singular, plural, count) {
		const c = Number.isFinite(Number(count)) ? Number(count) : 0;
		if (typeof window.n === 'function') {
			return window.n('arbeitszeitcheck', singular, plural, c);
		}
		return String(c === 1 ? singular : plural).replace('%n', String(c));
	}

	function escapeHtml(value) {
		if (value === null || value === undefined) {
			return '';
		}
		const div = document.createElement('div');
		div.textContent = String(value);
		return div.innerHTML;
	}

	function formatDate(value) {
		if (!value) {
			return '-';
		}
		const ymd = String(value).slice(0, 10);
		const api = window.ArbeitszeitCheckTime;
		if (api && /^\d{4}-\d{2}-\d{2}$/.test(ymd)) {
			const parsed = api.parseYmd(ymd);
			return parsed ? api.formatDate(parsed) : '-';
		}
		if (Utils.formatDate) {
			return Utils.formatDate(ymd, 'DD.MM.YYYY') || '-';
		}
		return ymd;
	}

	function formatDays(value) {
		if (value === null || value === undefined || value === '') {
			return '-';
		}
		const num = Number(value);
		if (Number.isNaN(num)) {
			return '-';
		}
		return num.toFixed(1);
	}

	function formatDuration(entry) {
		const hoursRaw = entry && (entry.durationHours != null ? entry.durationHours : entry.duration_hours);
		const hours = hoursRaw === null || hoursRaw === undefined || hoursRaw === '' ? null : Number(hoursRaw);
		if (hours !== null && !Number.isNaN(hours) && hours > 0) {
			return hours.toFixed(1) + ' ' + t('h', 'h');
		}
		const type = entry && (entry.type || '');
		const unit = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.vacationUnit) || 'days';
		const hpd = Number((window.ArbeitszeitCheck && window.ArbeitszeitCheck.vacationHoursPerDay) || 8) || 8;
		if (unit === 'hours' && type === 'vacation') {
			const days = Number(entry.days);
			if (!Number.isNaN(days) && days > 0) {
				return (days * hpd).toFixed(1) + ' ' + t('h', 'h');
			}
		}
		return formatDays(entry && entry.days);
	}

	function isPastRecord(entry) {
		const rawEnd = entry && (entry.endDate || entry.end_date || entry.startDate || entry.start_date);
		if (!rawEnd) {
			return false;
		}
		const api = window.ArbeitszeitCheckTime;
		const endYmd = String(rawEnd).slice(0, 10);
		const todayYmd = api ? api.todayYmd() : '';
		if (api && /^\d{4}-\d{2}-\d{2}$/.test(endYmd) && todayYmd) {
			return endYmd < todayYmd;
		}
		return false;
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

	function setResultsPanel(options) {
		const emptyEl = document.getElementById('employee-absences-empty');
		const tableWrap = document.getElementById('employee-absences-table-wrap');
		const body = document.getElementById('employee-absences-body');
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
			text: text || t('Choose a date range to load absences.', 'Choose a date range to load absences.'),
		});
	}

	function setLoadingResultsPanel() {
		setResultsPanel({
			title: t('Loading results…', 'Loading results…'),
			text: t('Loading...', 'Loading...'),
		});
	}

	function renderEntries(entries) {
		const body = document.getElementById('employee-absences-body');
		const emptyEl = document.getElementById('employee-absences-empty');
		const tableWrap = document.getElementById('employee-absences-table-wrap');
		if (!body || !emptyEl || !tableWrap) {
			return;
		}

		if (!entries.length) {
			setResultsPanel({
				title: t('No matching absences', 'No matching absences'),
				text: t('No entries found for the selected filters.', 'No entries found for the selected filters.'),
			});
			body.innerHTML = '';
			return;
		}

		body.innerHTML = entries.map((entry) => {
			const pastBadge = isPastRecord(entry)
				? `<span class="badge badge--past-record">${escapeHtml(t('Past record', 'Past record'))}</span>`
				: '';
			const statusLabel = entry.statusLabel || entry.status || '-';
			const statusVariant = Utils.badgeVariantForAbsenceStatus
				? Utils.badgeVariantForAbsenceStatus(entry.status)
				: 'secondary';
			const statusBadge = Utils.renderBadgeHtml
				? Utils.renderBadgeHtml(statusLabel, statusVariant)
				: `<span class="badge badge--${escapeHtml(statusVariant)}">${escapeHtml(statusLabel)}</span>`;
			const statusCell = `<span class="absence-type-badges">${statusBadge}${pastBadge}</span>`;
			const td = (label, html, cls = '') => Utils.responsiveTd
				? Utils.responsiveTd(label, html, cls)
				: `<td${cls ? ` class="${cls}"` : ''}>${html}</td>`;
			const convertLabel = t('Convert to sick', 'Convert to sick');
			const actionCell = (entry.type === 'vacation' && entry.status === 'approved')
				? `<button type="button" class="azc-btn azc-btn--secondary azc-icon-btn" data-sick-convert
						data-sick-convert-flow="manager"
						data-sick-convert-url="${escapeHtml(Utils.buildAppUrl(`/apps/arbeitszeitcheck/api/manager/employee-absences/${encodeURIComponent(String(entry.id))}/convert-to-sick`))}"
						data-vac-start="${escapeHtml(entry.startDate || '')}"
						data-vac-end="${escapeHtml(entry.endDate || '')}"
						data-updated-at="${escapeHtml(entry.updatedAt || '')}"
						aria-label="${escapeHtml(convertLabel)}"
						title="${escapeHtml(convertLabel)}">${(window.AzcCatalog && window.AzcCatalog.render) ? window.AzcCatalog.render('activity') : ''}</button>`
				: '<span class="text-muted">–</span>';
			return [
			'<tr>',
			td(t('Name', 'Name'), escapeHtml(entry.displayName || entry.userId || '-')),
			td(t('Type', 'Type'), escapeHtml(entry.typeLabel || entry.type || '-')),
			td(t('Start date', 'Start date'), escapeHtml(formatDate(entry.startDate))),
			td(t('End date', 'End date'), escapeHtml(formatDate(entry.endDate))),
			td(t('Duration', 'Duration'), escapeHtml(formatDuration(entry))),
			td(t('Status', 'Status'), statusCell),
			td(t('Reason', 'Reason'), escapeHtml(entry.reason || t('No reason', 'No reason')), 'reason-cell'),
			td(t('Actions', 'Actions'), `<div class="azc-table-actions" role="group" aria-label="${escapeHtml(t('Actions', 'Actions'))}">${actionCell}</div>`, 'actions-cell'),
			'</tr>',
			].join('');
		}).join('');

		setResultsPanel({ showTable: true });
	}

	function updatePagination() {
		const prevBtn = document.getElementById('employee-absences-prev');
		const nextBtn = document.getElementById('employee-absences-next');
		const indicator = document.getElementById('employee-absences-page-indicator');
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
		const countEl = document.getElementById('employee-absences-count');
		if (!countEl) {
			return;
		}
		if (state.total <= 0 && !state.lastFilters) {
			countEl.textContent = '';
			state.countBeforeLoad = '';
			return;
		}
		const text = n('1 entry', '%n entries', state.total);
		countEl.textContent = text;
		state.countBeforeLoad = text;
	}

	function setLoading(isLoading) {
		state.loading = isLoading;
		const results = document.querySelector('.manager-scope-page__results');
		if (results) {
			results.setAttribute('aria-busy', isLoading ? 'true' : 'false');
		}
		const submitBtn = document.getElementById('employee-absences-submit');
		const clearBtn = document.getElementById('employee-absences-clear');
		const prevBtn = document.getElementById('employee-absences-prev');
		const nextBtn = document.getElementById('employee-absences-next');
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
		const countEl = document.getElementById('employee-absences-count');
		if (!countEl) {
			return;
		}
		if (isLoading) {
			state.countBeforeLoad = countEl.textContent;
			countEl.textContent = t('Loading...', 'Loading...');
		}
	}

	function clearFilterFieldErrors() {
		const form = document.getElementById('employee-absences-filter-form');
		if (!form) {
			return;
		}
		form.querySelectorAll('[aria-invalid="true"]').forEach((el) => {
			el.removeAttribute('aria-invalid');
		});
		const errorEl = document.getElementById('employee-absences-filter-error');
		setFilterErrorText(errorEl, '');
	}

	function setFilterError(message, focusId) {
		const errorEl = document.getElementById('employee-absences-filter-error');
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
			searchSelector: '#employee-absences-employee-filter-search',
			clearButtonSelector: '#employee-absences-employee-filter-clear',
			searchFocusId: 'employee-absences-employee-filter-search',
		});
		if (fromHandler) {
			return fromHandler;
		}
		if (error?.status === 400) {
			return 'employee-absences-start-date-filter';
		}
		return null;
	}

	let employeeFilterPicker = null;
	let recordEmployeePicker = null;

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

	function syncRecordEmployeePicker(employees) {
		if (!recordEmployeePicker || !Array.isArray(employees)) {
			return;
		}
		const selectedId = recordEmployeePicker.getUserId();
		if (!selectedId) {
			return;
		}
		const match = employees.find((employee) => employee.userId === selectedId);
		if (match) {
			recordEmployeePicker.setSelection(match.userId, match.displayName || match.userId);
		}
	}

	function initEmployeePickers() {
		const initPicker = window.ArbeitszeitCheck?.initManagerScopedEmployeePicker;
		if (!initPicker) {
			return;
		}
		if (document.getElementById('employee-absences-employee-filter-search')) {
			employeeFilterPicker = initPicker({
				hiddenSelector: '#employee-absences-employee-filter-id',
				searchSelector: '#employee-absences-employee-filter-search',
				listSelector: '#employee-absences-employee-filter-listbox',
				wrapSelector: '#employee-absences-employee-filter-wrap',
				statusSelector: '#employee-absences-employee-filter-status',
				clearButtonSelector: '#employee-absences-employee-filter-clear',
				idPrefix: 'employee-absences-employee-filter',
				allowAll: true,
			});
		}
		if (document.getElementById('manager-absence-record-employee-search')) {
			recordEmployeePicker = initPicker({
				hiddenSelector: '#manager-absence-record-employee-id',
				searchSelector: '#manager-absence-record-employee-search',
				listSelector: '#manager-absence-record-employee-listbox',
				wrapSelector: '#manager-absence-record-employee-wrap',
				statusSelector: '#manager-absence-record-employee-status',
				idPrefix: 'manager-absence-record-employee',
				allowAll: false,
			});
		}
	}

	function readFiltersFromForm() {
		const form = document.getElementById('employee-absences-filter-form');
		if (!form) {
			return null;
		}
		const formData = new FormData(form);
		const startEl = document.getElementById('employee-absences-start-date-filter');
		const endEl = document.getElementById('employee-absences-end-date-filter');
		const hiddenEl = document.getElementById('employee-absences-employee-filter-id');
		return {
			employeeId: String(formData.get('employee_id') || hiddenEl?.value || '').trim(),
			startDate: String(formData.get('start_date') || startEl?.value || '').trim(),
			endDate: String(formData.get('end_date') || endEl?.value || '').trim(),
			status: String(formData.get('status') || '').trim(),
			type: String(formData.get('type') || '').trim(),
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
		if (filters.type) {
			params.set('type', filters.type);
		}
		return params.toString();
	}

	function loadEntries() {
		if (typeof Utils.ajax !== 'function') {
			const message = t('Could not load employee absences.', 'Could not load employee absences.');
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
			const message = document.getElementById('employee-absences-filter-error')
				?.querySelector('.azc-callout__text')?.textContent
				|| t('Choose a date range to load absences.', 'Choose a date range to load absences.');
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
		const ajaxPromise = Utils.ajax(`/apps/arbeitszeitcheck/api/manager/employee-absences?${buildQuery(filters, validation)}`, {
			method: 'GET',
			onSuccess: (data) => {
				try {
					if (!data || data.success === false) {
						const message = data?.error || t('Could not load employee absences.', 'Could not load employee absences.');
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
							!filters.startDate ? 'employee-absences-start-date-filter' : 'employee-absences-end-date-filter'
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
					const list = Array.isArray(data.employees) ? data.employees : [];
					syncEmployeeFilterPicker(list);
					syncRecordEmployeePicker(list);
					renderEntries(Array.isArray(data.entries) ? data.entries : []);
					updateCount();
					state.countBeforeLoad = document.getElementById('employee-absences-count')?.textContent || '';
					updatePagination();
					syncRecordDatesFromFilter();
				} catch (err) {
					state.total = 0;
					const message = err?.message || t('Could not load employee absences.', 'Could not load employee absences.');
					setFilterError(message);
					Messaging?.showError?.(message);
					setEmpty(t('Check your filters', 'Check your filters'), message);
					updateCount();
					updatePagination();
				}
			},
			onError: (error) => {
				state.total = 0;
				const message = error?.error || error?.message || t('Could not load employee absences.', 'Could not load employee absences.');
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

	function syncRecordDatesFromFilter() {
		const fs = document.getElementById('employee-absences-start-date-filter');
		const fe = document.getElementById('employee-absences-end-date-filter');
		const rs = document.getElementById('manager-absence-record-start');
		const re = document.getElementById('manager-absence-record-end');
		if (fs && rs && fs.value) {
			rs.value = fs.value;
		}
		if (fe && re && fe.value) {
			re.value = fe.value;
		}
	}

	/* Past-date awareness for the manager record form.
	 *
	 * Mirrors the behaviour of the employee absence form: when both dates
	 * have been entered and the end date is strictly before today (local
	 * time, matching the visible datepicker), reveal an aria-live hint so
	 * the manager has clear feedback that they are recording a historical
	 * entry. Submission semantics are unchanged - the manager API always
	 * persists as APPROVED - but the visible cue helps prevent typos like
	 * 2024 vs 2025 going unnoticed.
	 */
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
			Number.isNaN(date.getTime())
			|| date.getFullYear() !== parseInt(parts[2], 10)
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
			'#employee-absences-employee-filter-search',
			'#employee-absences-employee-filter-id'
		);
		if (employeeCheck && !employeeCheck.valid) {
			setFilterError(employeeCheck.message, employeeCheck.focusId);
			return { valid: false };
		}
		if (!filters.startDate || !filters.endDate) {
			const message = t('Please select start and end date.', 'Please select start and end date.');
			setFilterError(
				message,
				!filters.startDate ? 'employee-absences-start-date-filter' : 'employee-absences-end-date-filter'
			);
			return { valid: false };
		}
		const startParsed = parseEuropeanDate(filters.startDate);
		const endParsed = parseEuropeanDate(filters.endDate);
		if (!startParsed || !endParsed) {
			const message = t(
				'Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).',
				'Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).'
			);
			setFilterError(
				message,
				!startParsed ? 'employee-absences-start-date-filter' : 'employee-absences-end-date-filter'
			);
			return { valid: false };
		}
		if (startParsed > endParsed) {
			const message = t(
				'Invalid date range. The start date must be before the end date.',
				'Invalid date range. The start date must be before the end date.'
			);
			setFilterError(message, 'employee-absences-start-date-filter');
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
			setFilterError(message, 'employee-absences-start-date-filter');
			return { valid: false };
		}
		const maxDays = Number(window.ArbeitszeitCheck?.maxManagerListDateRangeDays) || 365;
		const spanDays = Math.round((endParsed.getTime() - startParsed.getTime()) / 86400000) + 1;
		if (spanDays > maxDays) {
			const message = t('dateRangeTooLong', 'Date range must not exceed %d days. Please narrow the range.')
				.replace('%d', String(maxDays));
			setFilterError(message, 'employee-absences-start-date-filter');
			return { valid: false };
		}
		return { valid: true, startISO, endISO };
	}

	function updateManagerRecordHistoricalHint() {
		const hint = document.getElementById('manager-absence-record-historical-hint');
		const endEl = document.getElementById('manager-absence-record-end');
		if (!hint || !endEl) {
			return;
		}
		const end = parseEuropeanDate(endEl.value);
		if (!end) {
			hint.hidden = true;
			return;
		}
		const endYmd = europeanToYmd(endEl.value);
		const todayYmd = window.ArbeitszeitCheckTime
			? window.ArbeitszeitCheckTime.todayYmd()
			: '';
		hint.hidden = !(endYmd && todayYmd && endYmd < todayYmd);
	}

	function bindRecordHistoricalHint() {
		const startEl = document.getElementById('manager-absence-record-start');
		const endEl = document.getElementById('manager-absence-record-end');
		if (startEl) {
			startEl.addEventListener('change', updateManagerRecordHistoricalHint);
			startEl.addEventListener('input', updateManagerRecordHistoricalHint);
		}
		if (endEl) {
			endEl.addEventListener('change', updateManagerRecordHistoricalHint);
			endEl.addEventListener('input', updateManagerRecordHistoricalHint);
		}
		updateManagerRecordHistoricalHint();
	}

		function bindRecordForm() {
		const form = document.getElementById('manager-absence-record-form');
		const submitBtn = document.getElementById('manager-absence-record-submit');
		const hoursField = document.getElementById('manager-absence-record-hours-field');
		const hoursInput = document.getElementById('manager-absence-record-hours');
		const hoursPreview = document.getElementById('manager-absence-record-hours-preview');
		const dayFractionField = document.getElementById('manager-absence-record-day-fraction-field');
		const dayFractionFull = document.getElementById('manager-absence-day-fraction-full');
		const dayFractionHalf = document.getElementById('manager-absence-day-fraction-half');
		const dayFractionPreview = document.getElementById('manager-absence-day-fraction-preview');
		const dayFractionLive = document.getElementById('manager-absence-day-fraction-live');
		const typeSel = document.getElementById('manager-absence-record-type');
		const startEl = document.getElementById('manager-absence-record-start');
		const endEl = document.getElementById('manager-absence-record-end');
		const hoursMode = ((window.ArbeitszeitCheck && window.ArbeitszeitCheck.vacationUnit) || 'days') === 'hours';
		const daysMode = !hoursMode;
		const previewHalfMsg = t('This request uses 0.5 vacation day.', 'This request uses 0.5 vacation day.');
		const previewFullMsg = t('This request uses 1 vacation day.', 'This request uses 1 vacation day.');
		const rangeAnnounceMsg = t(
			'Half day is only for a single day. This request will use full working days.',
			'Half day is only for a single day. This request will use full working days.'
		);
		let lastDayFractionAnnounce = '';
		const orgHoursPerDay = Number((window.ArbeitszeitCheck && window.ArbeitszeitCheck.vacationHoursPerDay) || 8) || 8;
		let oneDayHours = orgHoursPerDay;
		let averageDaily = orgHoursPerDay;
		let weekdayNets = null;
		let hoursTouched = false;
		let lastAutoFill = null;
		let estimateTimer = null;
		let estimateSeq = 0;
		let estimateAbort = null;
		if (!form || !submitBtn) {
			return;
		}

		function parseDDMMYYYY(s) {
			if (!s || !/^\d{2}\.\d{2}\.\d{4}$/.test(s)) {
				return null;
			}
			const p = s.split('.');
			return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
		}
		function toYmd(d) {
			if (!d) {
				return '';
			}
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return y + '-' + m + '-' + day;
		}
		const DOW_TO_NET = { 1: 'mon', 2: 'tue', 3: 'wed', 4: 'thu', 5: 'fri', 6: 'sat', 0: 'sun' };
		function countWeekdays(start, end) {
			if (!start || !end || end < start) {
				return 0;
			}
			let n = 0;
			const cur = new Date(start.getFullYear(), start.getMonth(), start.getDate());
			const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
			while (cur <= last) {
				const dow = cur.getDay();
				if (dow !== 0 && dow !== 6) {
					n += 1;
				}
				cur.setDate(cur.getDate() + 1);
			}
			return n;
		}
		function selectedEmployeeId() {
			const hidden = document.getElementById('manager-absence-record-employee');
			return hidden && hidden.value ? String(hidden.value) : '';
		}
		function netForDate(d) {
			if (!weekdayNets || typeof weekdayNets !== 'object') {
				return averageDaily;
			}
			const key = DOW_TO_NET[d.getDay()];
			const v = Number(weekdayNets[key]);
			return Number.isFinite(v) && v > 0 ? v : 0;
		}
		function rangeHoursEstimate() {
			const start = parseDDMMYYYY(startEl && startEl.value);
			const end = parseDDMMYYYY(endEl && endEl.value);
			if (!start || !end || end < start) {
				return 0;
			}
			if (weekdayNets && typeof weekdayNets === 'object') {
				let sum = 0;
				const cur = new Date(start.getFullYear(), start.getMonth(), start.getDate());
				const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
				while (cur <= last) {
					sum += netForDate(cur);
					cur.setDate(cur.getDate() + 1);
				}
				if (sum > 0.009) {
					return Math.round(sum * 100) / 100;
				}
				return 0;
			}
			const days = countWeekdays(start, end);
			if (days < 1) {
				return 0;
			}
			return Math.round(days * averageDaily * 100) / 100;
		}
		function updateHoursPreview() {
			if (!hoursPreview || !hoursInput) {
				return;
			}
			const show = hoursMode && typeSel && typeSel.value === 'vacation';
			const raw = parseFloat(String(hoursInput.value || '').replace(',', '.'));
			if (!show || !Number.isFinite(raw) || raw <= 0) {
				hoursPreview.hidden = true;
				hoursPreview.textContent = '';
				return;
			}
			const weekdays = countWeekdays(parseDDMMYYYY(startEl && startEl.value), parseDDMMYYYY(endEl && endEl.value)) || 1;
			const tpl = t(
				'This request: %s hours across about %s weekdays (work model). Public holidays reduce the final debit.',
				'This request: %s hours across about %s weekdays (work model). Public holidays reduce the final debit.'
			);
			hoursPreview.textContent = tpl
				.replace('%s', String(raw))
				.replace('%s', String(weekdays));
			hoursPreview.hidden = false;
		}
		function applyEstimate(estimate) {
			if (!hoursInput) {
				return;
			}
			const current = String(hoursInput.value || '').trim();
			if (!hoursTouched || current === '' || current === String(lastAutoFill) || current === String(orgHoursPerDay) || current === String(oneDayHours)) {
				hoursInput.value = String(estimate);
				lastAutoFill = estimate;
				hoursTouched = false;
			}
			updateHoursPreview();
		}
		function applyAutoRangeIfNeeded() {
			if (!hoursInput || !typeSel || !(hoursMode && typeSel.value === 'vacation')) {
				return;
			}
			applyEstimate(rangeHoursEstimate());
			const userId = selectedEmployeeId();
			const start = parseDDMMYYYY(startEl && startEl.value);
			const end = parseDDMMYYYY(endEl && endEl.value);
			const url = window.ArbeitszeitCheck && window.ArbeitszeitCheck.estimateEmployeeVacationHoursUrl;
			if (!userId || !start || !end || !url) {
				return;
			}
			if (estimateTimer) {
				clearTimeout(estimateTimer);
			}
			estimateTimer = setTimeout(function () {
				if (estimateAbort) {
					try { estimateAbort.abort(); } catch (e) { /* ignore */ }
				}
				const seq = ++estimateSeq;
				const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
				estimateAbort = controller;
				const qs = 'userId=' + encodeURIComponent(userId)
					+ '&startDate=' + encodeURIComponent(toYmd(start))
					+ '&endDate=' + encodeURIComponent(toYmd(end));
				const token = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.getRequestToken && window.ArbeitszeitCheck.getRequestToken())
					|| (typeof OC !== 'undefined' && OC.requestToken) || '';
				fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + qs, {
					credentials: 'same-origin',
					headers: token ? { requesttoken: token } : {},
					signal: controller ? controller.signal : undefined
				}).then(function (r) { return r.json(); }).then(function (j) {
					if (seq !== estimateSeq) {
						return;
					}
					if (!j || !j.success || !Number.isFinite(j.hours) || j.hours < 0) {
						return;
					}
					if (j.one_day_hours && Number.isFinite(j.one_day_hours)) {
						oneDayHours = Math.max(0.25, Number(j.one_day_hours));
					}
					if (j.average_daily && Number.isFinite(j.average_daily)) {
						averageDaily = Math.max(0.25, Number(j.average_daily));
					}
					if (j.weekday_nets && typeof j.weekday_nets === 'object') {
						weekdayNets = j.weekday_nets;
					}
					applyEstimate(Math.round(Number(j.hours) * 100) / 100);
				}).catch(function () { /* keep local estimate / abort */ });
			}, 200);
		}

		function syncDayFractionField() {
			if (!dayFractionField || !typeSel) {
				return;
			}
			const start = parseDDMMYYYY(startEl && startEl.value);
			const end = parseDDMMYYYY(endEl && endEl.value);
			const singleDay = !!(start && end && toYmd(start) === toYmd(end));
			const show = daysMode && typeSel.value === 'vacation' && singleDay;
			const wasVisible = !dayFractionField.hidden;
			dayFractionField.hidden = !show;
			if (!show) {
				if (dayFractionFull) {
					dayFractionFull.checked = true;
				}
				if (dayFractionHalf) {
					dayFractionHalf.checked = false;
				}
				if (wasVisible && dayFractionLive && lastDayFractionAnnounce !== rangeAnnounceMsg) {
					dayFractionLive.textContent = rangeAnnounceMsg;
					lastDayFractionAnnounce = rangeAnnounceMsg;
				}
				if (dayFractionPreview) {
					dayFractionPreview.textContent = '';
				}
			} else {
				lastDayFractionAnnounce = '';
				if (dayFractionLive) {
					dayFractionLive.textContent = '';
				}
				if (dayFractionPreview) {
					dayFractionPreview.textContent = (dayFractionHalf && dayFractionHalf.checked)
						? previewHalfMsg
						: previewFullMsg;
				}
			}
		}

		function syncHoursField() {
			if (!hoursField || !hoursInput || !typeSel) {
				return;
			}
			const show = hoursMode && typeSel.value === 'vacation';
			hoursField.hidden = !show;
			hoursInput.required = show;
			hoursInput.setAttribute('aria-required', show ? 'true' : 'false');
			if (!show) {
				hoursInput.value = '';
				hoursTouched = false;
				lastAutoFill = null;
				if (hoursPreview) {
					hoursPreview.hidden = true;
					hoursPreview.textContent = '';
				}
			} else {
				applyAutoRangeIfNeeded();
			}
			syncDayFractionField();
		}

		if (typeSel) {
			typeSel.addEventListener('change', syncHoursField);
		}
		[dayFractionFull, dayFractionHalf].forEach((el) => {
			if (!el) {
				return;
			}
			el.addEventListener('change', () => {
				if (dayFractionPreview) {
					dayFractionPreview.textContent = (dayFractionHalf && dayFractionHalf.checked)
						? previewHalfMsg
						: previewFullMsg;
				}
			});
		});
		if (hoursInput) {
			hoursInput.addEventListener('input', () => {
				hoursTouched = true;
				updateHoursPreview();
			});
		}
		[startEl, endEl].forEach((el) => {
			if (!el) {
				return;
			}
			el.addEventListener('change', () => {
				applyAutoRangeIfNeeded();
				syncDayFractionField();
			});
			el.addEventListener('input', () => {
				applyAutoRangeIfNeeded();
				syncDayFractionField();
			});
		});
		document.querySelectorAll('.manager-absence-hours-preset').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				if (!hoursInput) {
					return;
				}
				let h = parseFloat(btn.getAttribute('data-hours') || '');
				if (btn.hasAttribute('data-hours-half')) {
					h = Math.round((oneDayHours / 2) * 100) / 100;
					hoursTouched = true;
				} else if (btn.hasAttribute('data-hours-full')) {
					h = oneDayHours;
					hoursTouched = true;
				} else if (btn.hasAttribute('data-hours-range')) {
					h = rangeHoursEstimate();
					hoursTouched = false;
					lastAutoFill = h;
					applyAutoRangeIfNeeded();
				} else {
					hoursTouched = true;
				}
				if (!Number.isFinite(h) || h <= 0) {
					return;
				}
				hoursInput.value = String(h);
				updateHoursPreview();
				hoursInput.focus();
			});
		});
		syncHoursField();
		syncDayFractionField();

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			const rs = document.getElementById('manager-absence-record-start');
			const re = document.getElementById('manager-absence-record-end');
			const reasonEl = document.getElementById('manager-absence-record-reason');
			const userId = recordEmployeePicker
				? recordEmployeePicker.getUserId()
				: String(document.getElementById('manager-absence-record-employee-id')?.value || '');
			const type = typeSel ? String(typeSel.value || '') : '';
			const dp = window.ArbeitszeitCheckDatepicker;
			const toISO = dp && typeof dp.convertEuropeanToISO === 'function'
				? dp.convertEuropeanToISO
				: (value) => value;
			const startDate = rs ? toISO(String(rs.value || '')) : '';
			const endDate = re ? toISO(String(re.value || '')) : '';
			const reason = reasonEl ? String(reasonEl.value || '') : '';
			if (!userId) {
				Messaging?.showError?.(t('Select an employee', 'Select an employee'));
				const searchEl = document.getElementById('manager-absence-record-employee-search');
				if (searchEl) {
					searchEl.focus();
				}
				return;
			}
			if (!startDate || !endDate) {
				Messaging?.showError?.(t('Please select start and end date.', 'Please select start and end date.'));
				return;
			}
			const needHours = hoursMode && type === 'vacation';
			const durationHours = hoursInput ? Number(String(hoursInput.value || '').replace(',', '.')) : NaN;
			if (needHours && (!Number.isFinite(durationHours) || durationHours <= 0)) {
				Messaging?.showError?.(t('Please enter vacation hours.', 'Please enter vacation hours.'));
				if (hoursInput) {
					hoursInput.focus();
				}
				return;
			}
			const original = submitBtn.textContent;
			submitBtn.disabled = true;
			const payload = {
				userId,
				type,
				startDate,
				endDate,
				reason,
			};
			if (needHours) {
				payload.durationHours = durationHours;
				payload.requireDurationHours = true;
				payload.serverMayFillHours = true;
			}
			if (daysMode && type === 'vacation' && dayFractionField && !dayFractionField.hidden) {
				payload.dayFraction = (dayFractionHalf && dayFractionHalf.checked) ? '0.5' : '1';
			}
			form.querySelector('.azc-self-target-notice')?.remove();
			Utils.ajax('/apps/arbeitszeitcheck/api/manager/employee-absences', {
				method: 'POST',
				data: payload,
				onSuccess: () => {
					submitBtn.disabled = false;
					submitBtn.textContent = original;
					if (window.OC && window.OC.Notification && window.OC.Notification.showTemporary) {
						window.OC.Notification.showTemporary(
							t('Absence recorded and approved.', 'Absence recorded and approved.'),
							{ type: 'success' }
						);
					}
					if (reasonEl) {
						reasonEl.value = '';
					}
					if (hoursInput) {
						hoursInput.value = '';
					}
					state.offset = 0;
					loadEntries();
				},
				onError: (err) => {
					submitBtn.disabled = false;
					submitBtn.textContent = original;
					// B1-MH-04 / BX-02: self-target renders a hint with a link into
					// the self-service flow instead of a dead-end toast.
					if (err?.data?.selfTarget === true) {
						renderSelfTargetNotice(form, err.data);
						return;
					}
					const code = err?.error_code || err?.code || err?.data?.error_code || err?.data?.code || '';
					let message = err?.error || t('Could not save absence.', 'Could not save absence.');
					if (code === 'ABSENCE_HOURS_CLIENT_REQUIRED') {
						message = t('Please enter vacation hours.', 'Please enter vacation hours.');
					}
					Messaging?.showError?.(message);
				},
			});
		});
	}

	/**
	 * B1-MH-04 / BX-02: self-target 403 renders an inline hint with a link into
	 * the self-service flow — not a dead-end toast.
	 */
	function renderSelfTargetNotice(host, err) {
		if (!host) {
			Messaging?.showError?.(err?.error || t('Could not save absence.', 'Could not save absence.'));
			return;
		}
		let notice = host.querySelector('.azc-self-target-notice');
		if (!notice) {
			notice = document.createElement('p');
			notice.className = 'field-error azc-self-target-notice';
			notice.setAttribute('role', 'alert');
			host.prepend(notice);
		}
		const area = err.selfServiceArea === 'absences' ? 'absences' : 'time-entries';
		const href = Utils.buildAppUrl('/apps/arbeitszeitcheck/' + area);
		const linkLabel = area === 'absences'
			? t('Open Absences', 'Open Absences')
			: t('Open Time Entries', 'Open Time Entries');
		notice.textContent = (err.error
			|| t('Please record your own entries via Time Entries / Absences.', 'Please record your own entries via Time Entries / Absences.')) + ' ';
		const link = document.createElement('a');
		link.href = href;
		link.textContent = linkLabel;
		notice.appendChild(link);
		notice.hidden = false;
		if (typeof notice.scrollIntoView === 'function') {
			notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	}

	function toEuropeanDateString(date) {
		const day = String(date.getDate()).padStart(2, '0');
		const month = String(date.getMonth() + 1).padStart(2, '0');
		const year = date.getFullYear();
		return `${day}.${month}.${year}`;
	}

	function setDefaultDateRange(force) {
		const startInput = document.getElementById('employee-absences-start-date-filter');
		const endInput = document.getElementById('employee-absences-end-date-filter');
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

	function bindPagination() {
		const prevBtn = document.getElementById('employee-absences-prev');
		const nextBtn = document.getElementById('employee-absences-next');
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
		const form = document.getElementById('employee-absences-filter-form');
		const clearBtn = document.getElementById('employee-absences-clear');
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
			setDefaultDateRange(true);
			const searchEl = document.getElementById('employee-absences-employee-filter-search');
			if (searchEl) {
				searchEl.focus();
			}
			state.offset = 0;
			state.total = 0;
			state.lastFilters = null;
			state.countBeforeLoad = '';
			setEmpty(
				t('Select filters first', 'Select filters first'),
				t('Choose a date range to load absences.', 'Choose a date range to load absences.')
			);
			const countEl = document.getElementById('employee-absences-count');
			if (countEl) {
				countEl.textContent = '';
			}
			updatePagination();
			syncRecordDatesFromFilter();
			if (employeeFilterPicker) {
				employeeFilterPicker.clear();
			}
			const tbody = document.getElementById('employee-absences-body');
			const tableWrap = document.getElementById('employee-absences-table-wrap');
			if (tbody) {
				tbody.innerHTML = '';
			}
			if (tableWrap) {
				tableWrap.classList.add('visually-hidden');
			}
		});

		['employee-absences-start-date-filter', 'employee-absences-end-date-filter'].forEach((id) => {
			const input = document.getElementById(id);
			if (input) {
				input.addEventListener('change', () => {
					clearFilterFieldErrors();
					syncRecordDatesFromFilter();
				});
			}
		});
	}

	function init() {
		initEmployeePickers();
		setDefaultDateRange(false);
		syncRecordDatesFromFilter();
		bindForm();
		bindRecordForm();
		bindRecordHistoricalHint();
		bindPagination();
		updatePagination();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
