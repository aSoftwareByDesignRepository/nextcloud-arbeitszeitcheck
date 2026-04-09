(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const state = {
		limit: 25,
		offset: 0,
		total: 0,
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
		const date = new Date(`${value}T00:00:00`);
		if (Number.isNaN(date.getTime())) {
			return '-';
		}
		return new Intl.DateTimeFormat(state.dateLocale, {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		}).format(date);
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

	function setEmpty(message) {
		const emptyEl = document.getElementById('employee-absences-empty');
		const tableWrap = document.getElementById('employee-absences-table-wrap');
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
		const body = document.getElementById('employee-absences-body');
		const emptyEl = document.getElementById('employee-absences-empty');
		const tableWrap = document.getElementById('employee-absences-table-wrap');
		if (!body || !emptyEl || !tableWrap) {
			return;
		}

		if (!entries.length) {
			setEmpty(t('No entries found for the selected filters.', 'No entries found for the selected filters.'));
			body.innerHTML = '';
			return;
		}

		body.innerHTML = entries.map((entry) => [
			'<tr>',
			`<td>${escapeHtml(entry.displayName || entry.userId || '-')}</td>`,
			`<td>${escapeHtml(entry.typeLabel || entry.type || '-')}</td>`,
			`<td>${escapeHtml(formatDate(entry.startDate))}</td>`,
			`<td>${escapeHtml(formatDate(entry.endDate))}</td>`,
			`<td>${escapeHtml(formatDays(entry.days))}</td>`,
			`<td><span class="badge badge--primary">${escapeHtml(entry.statusLabel || entry.status || '-')}</span></td>`,
			`<td>${escapeHtml(entry.reason || t('No reason', 'No reason'))}</td>`,
			'</tr>',
		].join('')).join('');

		emptyEl.classList.add('visually-hidden');
		tableWrap.classList.remove('visually-hidden');
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
		countEl.textContent = t('{count} entries', '{count} entries').replace('{count}', String(state.total));
	}

	function populateEmployees(employees) {
		const select = document.getElementById('employee-absences-employee-filter');
		if (!select) {
			return;
		}
		const current = select.value;
		select.innerHTML = '';
		const option = document.createElement('option');
		option.value = '';
		option.textContent = t('All in my scope', 'All in my scope');
		select.appendChild(option);

		employees.forEach((employee) => {
			const emp = document.createElement('option');
			emp.value = employee.userId;
			emp.textContent = employee.displayName || employee.userId;
			select.appendChild(emp);
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
		const params = new URLSearchParams();
		params.set('startDate', toISO(filters.startDate));
		params.set('endDate', toISO(filters.endDate));
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
		const form = document.getElementById('employee-absences-filter-form');
		if (!form) {
			return;
		}
		const formData = new FormData(form);
		const filters = {
			employeeId: String(formData.get('employee_id') || ''),
			startDate: String(formData.get('start_date') || ''),
			endDate: String(formData.get('end_date') || ''),
			status: String(formData.get('status') || ''),
			type: String(formData.get('type') || ''),
		};

		if (!filters.startDate || !filters.endDate) {
			setEmpty(t('Please select start and end date.', 'Please select start and end date.'));
			updatePagination();
			return;
		}

		Utils.ajax(`/apps/arbeitszeitcheck/api/manager/employee-absences?${buildQuery(filters)}`, {
			method: 'GET',
			onSuccess: (data) => {
				state.total = Number(data.total || 0);
				populateEmployees(Array.isArray(data.employees) ? data.employees : []);
				renderEntries(Array.isArray(data.entries) ? data.entries : []);
				updateCount();
				updatePagination();
			},
			onError: (error) => {
				const message = error?.error || t('Could not load employee absences.', 'Could not load employee absences.');
				Messaging.showError(message);
				setEmpty(message);
			},
		});
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
			form.reset();
			setDefaultDateRange(true);
			state.offset = 0;
			state.total = 0;
			setEmpty(t('Choose a date range to load absences.', 'Choose a date range to load absences.'));
			updateCount();
			updatePagination();
		});
	}

	function init() {
		setDefaultDateRange(false);
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
