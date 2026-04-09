(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const state = {
		limit: 25,
		offset: 0,
		total: 0,
		lastFilters: null,
		dateLocale: window.ArbeitszeitCheck?.dateLocale || document.documentElement.lang || undefined,
	};

	function t(key, fallback) {
		const bundle = window.ArbeitszeitCheck?.l10n || {};
		const value = bundle[key];
		if (value !== undefined && value !== '') {
			return value;
		}
		return fallback || key;
	}

	function formatHours(value) {
		if (value === null || value === undefined || value === '') {
			return '-';
		}
		const num = Number(value);
		if (Number.isNaN(num)) {
			return '-';
		}
		return num.toFixed(2);
	}

	function formatDateTime(iso, mode) {
		if (!iso) {
			return '-';
		}
		const date = new Date(iso);
		if (Number.isNaN(date.getTime())) {
			return '-';
		}
		if (mode === 'date') {
			return new Intl.DateTimeFormat(state.dateLocale, {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
			}).format(date);
		}
		return new Intl.DateTimeFormat(state.dateLocale, {
			hour: '2-digit',
			minute: '2-digit',
		}).format(date);
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
		const countEl = document.getElementById('employee-time-entries-count');
		if (countEl) {
			countEl.textContent = isLoading ? t('Loading...', 'Loading...') : countEl.textContent;
		}
	}

	function setEmpty(message) {
		const emptyEl = document.getElementById('employee-time-entries-empty');
		const tableWrap = document.getElementById('employee-time-entries-table-wrap');
		if (!emptyEl || !tableWrap) {
			return;
		}
		emptyEl.classList.remove('visually-hidden');
		tableWrap.classList.add('visually-hidden');
		const desc = emptyEl.querySelector('.empty-state__description');
		if (desc) {
			desc.textContent = message;
		}
	}

	function renderEntries(entries) {
		const body = document.getElementById('employee-time-entries-body');
		const emptyEl = document.getElementById('employee-time-entries-empty');
		const tableWrap = document.getElementById('employee-time-entries-table-wrap');
		if (!body || !emptyEl || !tableWrap) {
			return;
		}

		if (!entries.length) {
			setEmpty(t('No entries found for the selected filters.', 'No entries found for the selected filters.'));
			body.innerHTML = '';
			return;
		}

		body.innerHTML = entries.map((entry) => {
			return [
				'<tr>',
				`<td>${escapeHtml(entry.displayName || entry.userId || '-')}</td>`,
				`<td>${escapeHtml(formatDateTime(entry.startTime, 'date'))}</td>`,
				`<td>${escapeHtml(formatDateTime(entry.startTime, 'time'))}</td>`,
				`<td>${escapeHtml(formatDateTime(entry.endTime, 'time'))}</td>`,
				`<td>${escapeHtml(formatHours(entry.workingDurationHours))}</td>`,
				`<td>${escapeHtml(formatHours(entry.breakDurationHours))}</td>`,
				`<td><span class="badge badge--primary">${escapeHtml(entry.status || '-')}</span></td>`,
				`<td>${escapeHtml(entry.description || t('No description', 'No description'))}</td>`,
				'</tr>',
			].join('');
		}).join('');

		emptyEl.classList.add('visually-hidden');
		tableWrap.classList.remove('visually-hidden');
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
		countEl.textContent = t('{count} entries', '{count} entries').replace('{count}', String(state.total));
	}

	function populateEmployees(employees) {
		const select = document.getElementById('employee-filter');
		if (!select) {
			return;
		}
		const current = select.value;
		const defaultOption = select.querySelector('option[value=""]');
		select.innerHTML = '';
		if (defaultOption) {
			select.appendChild(defaultOption);
		} else {
			const option = document.createElement('option');
			option.value = '';
			option.textContent = t('All in my scope', 'All in my scope');
			select.appendChild(option);
		}

		employees.forEach((employee) => {
			const option = document.createElement('option');
			option.value = employee.userId;
			option.textContent = employee.displayName || employee.userId;
			select.appendChild(option);
		});

		if (current) {
			select.value = current;
		}
	}

	function buildQuery(filters) {
		const dp = window.ArbeitszeitCheckDatepicker;
		const toISO = dp && typeof dp.convertEuropeanToISO === 'function'
			? dp.convertEuropeanToISO
			: (value) => value;
		const startISO = toISO(filters.startDate);
		const endISO = toISO(filters.endDate);
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
		const form = document.getElementById('employee-time-entries-filter-form');
		if (!form) {
			return;
		}

		const formData = new FormData(form);
		const filters = {
			employeeId: String(formData.get('employee_id') || ''),
			startDate: String(formData.get('start_date') || ''),
			endDate: String(formData.get('end_date') || ''),
			status: String(formData.get('status') || ''),
		};
		state.lastFilters = filters;

		if (!filters.startDate || !filters.endDate) {
			setEmpty(t('Please select start and end date.', 'Please select start and end date.'));
			updatePagination();
			return;
		}

		setLoading(true);
		const query = buildQuery(filters);
		Utils.ajax(`/apps/arbeitszeitcheck/api/manager/employee-time-entries?${query}`, {
			method: 'GET',
			onSuccess: (data) => {
				state.total = Number(data.total || 0);
				populateEmployees(Array.isArray(data.employees) ? data.employees : []);
				renderEntries(Array.isArray(data.entries) ? data.entries : []);
				updateCount();
				updatePagination();
			},
			onError: (error) => {
				Messaging.showError(error?.error || t('Could not load employee time entries.', 'Could not load employee time entries.'));
				setEmpty(t('Could not load employee time entries.', 'Could not load employee time entries.'));
			},
		});
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
			form.reset();
			state.offset = 0;
			state.total = 0;
			setDefaultDateRange();
			setEmpty(t('Choose a date range to load entries.', 'Choose a date range to load entries.'));
			updateCount();
			updatePagination();
		});
	}

	function toEuropeanDateString(date) {
		const day = String(date.getDate()).padStart(2, '0');
		const month = String(date.getMonth() + 1).padStart(2, '0');
		const year = date.getFullYear();
		return `${day}.${month}.${year}`;
	}

	function setDefaultDateRange() {
		const startInput = document.getElementById('start-date-filter');
		const endInput = document.getElementById('end-date-filter');
		if (!startInput || !endInput) {
			return;
		}

		// Only apply defaults when fields are empty to avoid overwriting user input.
		if (startInput.value || endInput.value) {
			return;
		}

		const today = new Date();
		const oneMonthAgo = new Date(today);
		oneMonthAgo.setMonth(oneMonthAgo.getMonth() - 1);

		startInput.value = toEuropeanDateString(oneMonthAgo);
		endInput.value = toEuropeanDateString(today);
	}

	function init() {
		setDefaultDateRange();
		bindForm();
		bindPagination();
		updatePagination();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
