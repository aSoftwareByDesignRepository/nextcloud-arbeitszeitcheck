/**
 * Audit Log Viewer JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const config = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.auditLogViewerConfig) || {};

	const state = {
		limit: Number(config.limit) > 0 ? Number(config.limit) : 50,
		offset: Number(config.offset) >= 0 ? Number(config.offset) : 0,
		total: Number(config.total) >= 0 ? Number(config.total) : 0,
		maxDateRangeDays: Number(config.maxDateRangeDays) > 0 ? Number(config.maxDateRangeDays) : 365,
		defaultStartDate: config.defaultStartDate || '',
		defaultEndDate: config.defaultEndDate || '',
		loading: false,
		loadSeq: 0,
	};

	function alT(msg, params) {
		const map = window.ArbeitszeitCheck && window.ArbeitszeitCheck.auditLogViewerL10n;
		let text = msg;
		if (map && Object.prototype.hasOwnProperty.call(map, msg) && map[msg] !== undefined && map[msg] !== '') {
			text = map[msg];
		} else if (typeof window.t === 'function') {
			text = window.t('arbeitszeitcheck', msg);
		}
		if (Array.isArray(params) && params.length) {
			return text.replace(/%(\d+\$)?[ds]/g, function (_match, index) {
				const idx = index ? parseInt(index, 10) - 1 : 0;
				return params[idx] !== undefined ? String(params[idx]) : '';
			});
		}
		return text;
	}

	function $(selector) {
		return Utils.$ ? Utils.$(selector) : document.querySelector(selector);
	}

	function on(el, event, handler) {
		if (Utils.on) {
			Utils.on(el, event, handler);
			return;
		}
		if (el) {
			el.addEventListener(event, handler);
		}
	}

	function buildApiUrl(path) {
		if (Utils.buildAppUrl) {
			return Utils.buildAppUrl(path);
		}
		if (Utils.resolveUrl) {
			return Utils.resolveUrl(path);
		}
		return path;
	}

	function buildAuditExportUrl(filters) {
		const params = buildQueryParams(filters, false);
		params.append('format', 'csv');
		if (config.exportUrl) {
			const separator = config.exportUrl.indexOf('?') >= 0 ? '&' : '?';
			return buildApiUrl(config.exportUrl + separator + params.toString());
		}
		return buildApiUrl('/apps/arbeitszeitcheck/api/admin/audit-logs/export?' + params.toString());
	}

	function escapeHtml(value) {
		if (Utils.escapeHtml) {
			return Utils.escapeHtml(value);
		}
		const div = document.createElement('div');
		div.textContent = value === null || value === undefined ? '' : String(value);
		return div.innerHTML;
	}

	function toIsoDate(value) {
		const dp = window.ArbeitszeitCheckDatepicker;
		if (dp && typeof dp.convertEuropeanToISO === 'function') {
			return dp.convertEuropeanToISO(value || '');
		}
		return value || '';
	}

	function isValidEuropeanDate(value) {
		if (!/^\d{2}\.\d{2}\.\d{4}$/.test(String(value || ''))) {
			return false;
		}
		const dp = window.ArbeitszeitCheckDatepicker;
		if (dp && typeof dp.convertEuropeanToISO === 'function') {
			return !!dp.convertEuropeanToISO(value);
		}
		return true;
	}

	function daySpan(startIso, endIso) {
		const start = new Date(startIso + 'T00:00:00');
		const end = new Date(endIso + 'T00:00:00');
		if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
			return null;
		}
		return Math.round((end.getTime() - start.getTime()) / 86400000);
	}

	function clearFilterFieldErrors() {
		const form = $('#audit-log-filter-form');
		if (form) {
			form.querySelectorAll('[aria-invalid="true"]').forEach(function (el) {
				el.removeAttribute('aria-invalid');
			});
		}
	}

	function setErrorText(errorEl, message) {
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

	function setError(message, focusId) {
		const errorEl = $('#audit-log-filter-error');
		clearFilterFieldErrors();
		setErrorText(errorEl, message || '');
		if (message && focusId) {
			const focusEl = document.getElementById(focusId);
			if (focusEl) {
				focusEl.setAttribute('aria-invalid', 'true');
				focusEl.focus();
			}
		}
	}

	function setLoading(loading) {
		state.loading = loading;
		const results = document.querySelector('.audit-log-page__results');
		const applyBtn = $('#apply-filters');
		const exportBtn = $('#export-logs');
		const resetBtn = $('#reset-filters');
		const prevBtn = $('#audit-log-prev');
		const nextBtn = $('#audit-log-next');

		if (results) {
			results.setAttribute('aria-busy', loading ? 'true' : 'false');
		}
		[applyBtn, exportBtn, resetBtn, prevBtn, nextBtn].forEach(function (btn) {
			if (!btn) {
				return;
			}
			btn.disabled = loading;
			btn.setAttribute('aria-disabled', loading ? 'true' : 'false');
		});
	}

	function readFilters() {
		return {
			startDate: ($('#start-date') && $('#start-date').value) || '',
			endDate: ($('#end-date') && $('#end-date').value) || '',
			userId: ($('#user-filter') && $('#user-filter').value.trim()) || '',
			actionCategory: ($('#action-category-filter') && $('#action-category-filter').value) || '',
			entityType: ($('#entity-type-filter') && $('#entity-type-filter').value) || '',
			offlineSync: !!( $('#offline-sync-filter') && $('#offline-sync-filter').checked ),
		};
	}

	function validateFilters(filters) {
		if (filters.userId.length > 200) {
			return { message: alT('User filter is too long.'), focusId: 'audit-user-search' };
		}
		if (!isValidEuropeanDate(filters.startDate) || !isValidEuropeanDate(filters.endDate)) {
			return { message: alT('Please enter valid dates in dd.mm.yyyy format.'), focusId: 'start-date' };
		}
		const startIso = toIsoDate(filters.startDate);
		const endIso = toIsoDate(filters.endDate);
		if (!startIso || !endIso) {
			return { message: alT('Please enter valid dates in dd.mm.yyyy format.'), focusId: 'start-date' };
		}
		if (startIso > endIso) {
			return { message: alT('Start date must be before or equal to end date'), focusId: 'start-date' };
		}
		const days = daySpan(startIso, endIso);
		if (days !== null && days > state.maxDateRangeDays) {
			return {
				message: alT('Date range must not exceed %d days. Please narrow the range.', [state.maxDateRangeDays]),
				focusId: 'start-date',
			};
		}
		return { message: '', focusId: '' };
	}

	function buildQueryParams(filters, includePaging) {
		const params = new URLSearchParams();
		const startIso = toIsoDate(filters.startDate);
		const endIso = toIsoDate(filters.endDate);
		if (startIso) {
			params.append('start_date', startIso);
		}
		if (endIso) {
			params.append('end_date', endIso);
		}
		if (filters.userId) {
			params.append('user_id', filters.userId);
		}
		if (filters.actionCategory) {
			params.append('action_category', filters.actionCategory);
		}
		if (filters.entityType) {
			params.append('entity_type', filters.entityType);
		}
		if (filters.offlineSync) {
			params.append('offlineSync', '1');
		}
		if (includePaging) {
			params.append('limit', String(state.limit));
			params.append('offset', String(state.offset));
		}
		return params;
	}

	function updatePaginationUi() {
		const countEl = $('#audit-log-count');
		const nav = $('#audit-log-pagination');
		const textEl = $('#audit-log-pagination-text');
		const prevBtn = $('#audit-log-prev');
		const nextBtn = $('#audit-log-next');
		const shown = document.querySelectorAll('#audit-log-tbody tr:not(.audit-log-empty-row)').length;
		const hasDataRows = state.total > 0 && shown > 0;
		const rangeStart = hasDataRows ? state.offset + 1 : 0;
		const rangeEnd = hasDataRows ? Math.min(state.total, state.offset + shown) : 0;
		const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
		const currentPage = Math.min(totalPages, Math.floor(state.offset / state.limit) + 1);

		if (countEl) {
			countEl.textContent = state.total > 0
				? alT('%1$d–%2$d of %3$d entries', [rangeStart, rangeEnd, state.total])
				: alT('0 entries');
		}
		if (nav) {
			nav.hidden = state.total <= state.limit;
		}
		if (textEl) {
			textEl.textContent = alT('Page %1$d of %2$d', [currentPage, totalPages]);
		}
		if (prevBtn) {
			const disabled = state.offset <= 0 || state.loading;
			prevBtn.disabled = disabled;
			prevBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
		}
		if (nextBtn) {
			const disabled = state.offset + state.limit >= state.total || state.loading;
			nextBtn.disabled = disabled;
			nextBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
		}
	}

	function renderAuditLogs(logs) {
		const tbody = $('#audit-log-tbody');
		if (!tbody) {
			return;
		}

		if (!logs.length) {
			tbody.innerHTML = '<tr class="audit-log-empty-row"><td colspan="5" class="text-center audit-log-empty">' +
				'<div class="empty-state">' +
				'<h3 class="empty-state__title">' + escapeHtml(alT('No audit log entries found')) + '</h3>' +
				'</div></td></tr>';
			updatePaginationUi();
			return;
		}

		tbody.innerHTML = logs.map(function (log) {
			const created = log.created_at_display || log.created_at || log.createdAt || '-';
			const user = log.user_display_name || log.userDisplayName || log.user_id || log.userId || '-';
			const performed = log.performed_by_display_name || log.performedByDisplayName || log.performed_by || log.performedBy || '-';
			const entity = log.entity_type || log.entityType || '-';
			const action = log.action || '-';
			const isOffline = !!(log.is_offline_sync || log.isOfflineSync);
			const occurred = log.client_occurred_at || log.clientOccurredAtIso || '';
			const td = Utils.responsiveTd
				? function (label, html) { return Utils.responsiveTd(label, html); }
				: function (_label, html) { return '<td>' + html + '</td>'; };

			let whenHtml = escapeHtml(String(created));
			if (isOffline) {
				whenHtml += ' <span class="azc-badge azc-badge--info audit-log-offline-badge">' + escapeHtml(alT('Offline sync')) + '</span>';
				if (occurred) {
					whenHtml += ' <span class="audit-log-offline-occurred">' + escapeHtml(alT('Occurred: %s', [occurred])) + '</span>';
				}
			}

			return '<tr>' +
				td(alT('Date and time'), whenHtml) +
				td(alT('Employee'), escapeHtml(String(user))) +
				td(alT('Action'), escapeHtml(String(action))) +
				td(alT('What was changed'), escapeHtml(String(entity))) +
				td(alT('Who did it'), escapeHtml(String(performed))) +
				'</tr>';
		}).join('');

		updatePaginationUi();
	}

	function loadAuditLogs() {
		const filters = readFilters();
		const validation = validateFilters(filters);
		if (validation.message) {
			setError(validation.message, validation.focusId);
			return;
		}
		setError('');

		const tbody = $('#audit-log-tbody');
		if (tbody) {
			tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + escapeHtml(alT('Loading…')) + '</td></tr>';
		}
		setLoading(true);
		const seq = ++state.loadSeq;

		const params = buildQueryParams(filters, true);
		Utils.ajax('/apps/arbeitszeitcheck/api/admin/audit-logs?' + params.toString(), {
			method: 'GET',
			onSuccess: function (data) {
				if (seq !== state.loadSeq) {
					return;
				}
				setLoading(false);
				if (data.success && Array.isArray(data.logs)) {
					state.total = Number(data.total) >= 0 ? Number(data.total) : data.logs.length;
					state.limit = Number(data.limit) > 0 ? Number(data.limit) : state.limit;
					state.offset = Number(data.offset) >= 0 ? Number(data.offset) : state.offset;
					renderAuditLogs(data.logs);
					return;
				}
				const apiMessage = (data && (data.error || data.message)) ? String(data.error || data.message) : '';
				if (apiMessage) {
					const focusId = apiMessage.toLowerCase().indexOf('user') !== -1 ? 'audit-user-search' : 'start-date';
					setError(apiMessage, focusId);
				}
				state.total = 0;
				if (tbody) {
					tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + escapeHtml(alT('Error loading audit logs')) + '</td></tr>';
				}
				updatePaginationUi();
			},
			onError: function () {
				if (seq !== state.loadSeq) {
					return;
				}
				setLoading(false);
				state.total = 0;
				if (tbody) {
					tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + escapeHtml(alT('Error loading audit logs')) + '</td></tr>';
				}
				updatePaginationUi();
				if (Messaging && Messaging.showError) {
					Messaging.showError(alT('Failed to load audit logs. Please try again.'));
				}
			},
		});
	}

	function exportLogs() {
		const filters = readFilters();
		const validation = validateFilters(filters);
		if (validation.message) {
			setError(validation.message, validation.focusId);
			return;
		}
		setError('');
		const url = buildAuditExportUrl(filters);
		if (Utils.triggerDownload && !Utils.triggerDownload(url)) {
			if (Messaging && Messaging.showError) {
				Messaging.showError(alT('Failed to start export. Please try again.'));
			}
			return;
		}
		if (!Utils.triggerDownload) {
			window.location.href = url;
		}
	}

	let userPicker = null;

	function createUserPicker() {
		if (typeof window.ArbeitszeitCheck?.initAdminUserPicker !== 'function') {
			return null;
		}
		return window.ArbeitszeitCheck.initAdminUserPicker({
			hiddenSelector: '#user-filter',
			searchSelector: '#audit-user-search',
			listSelector: '#audit-user-listbox',
			wrapSelector: '#audit-user-picker',
			statusSelector: '#audit-user-status',
			searchUrl: config.adminUserSearchUrl || '',
			limit: 20,
			minQueryLength: 2,
			idPrefix: 'audit-user',
			l10n: (window.ArbeitszeitCheck && window.ArbeitszeitCheck.auditLogViewerL10n) || {},
			onChange: function (userId) {
				const clearBtn = $('#audit-user-clear');
				if (clearBtn) {
					clearBtn.hidden = userId === '';
				}
			},
		});
	}

	function resetFilters() {
		const startEl = $('#start-date');
		const endEl = $('#end-date');
		const actionEl = $('#action-category-filter');
		const entityEl = $('#entity-type-filter');
		if (startEl) {
			startEl.value = state.defaultStartDate;
		}
		if (endEl) {
			endEl.value = state.defaultEndDate;
		}
		if (userPicker && typeof userPicker.clearSelection === 'function') {
			userPicker.clearSelection();
		} else if (userPicker && typeof userPicker.clear === 'function') {
			userPicker.clear();
		} else {
			const userEl = $('#user-filter');
			const searchEl = $('#audit-user-search');
			if (userEl) {
				userEl.value = '';
			}
			if (searchEl) {
				searchEl.value = '';
			}
		}
		const clearBtn = $('#audit-user-clear');
		if (clearBtn) {
			clearBtn.hidden = true;
		}
		if (actionEl) {
			actionEl.value = '';
		}
		if (entityEl) {
			entityEl.value = '';
		}
		const offlineEl = $('#offline-sync-filter');
		if (offlineEl) {
			offlineEl.checked = false;
		}
		state.offset = 0;
		setError('');
		if (startEl) {
			startEl.focus();
		}
		loadAuditLogs();
	}

	function applyUrlParams() {
		const params = new URLSearchParams(window.location.search);
		const userId = params.get('user_id') || params.get('userId') || '';
		const actionCategory = params.get('action_category') || params.get('action_type') || '';
		const entityType = params.get('entity_type') || '';
		const actionEl = $('#action-category-filter');
		const entityEl = $('#entity-type-filter');
		if (userId) {
			if (userPicker && typeof userPicker.setSelection === 'function') {
				userPicker.setSelection(userId, userId);
			} else {
				const userEl = $('#user-filter');
				const searchEl = $('#audit-user-search');
				if (userEl) {
					userEl.value = userId;
				}
				if (searchEl) {
					searchEl.value = userId;
				}
			}
			const clearBtn = $('#audit-user-clear');
			if (clearBtn) {
				clearBtn.hidden = false;
			}
		}
		if (actionCategory && actionEl) {
			actionEl.value = actionCategory;
		}
		if (entityType && entityEl) {
			entityEl.value = entityType;
		}
		if (userId || actionCategory || entityType) {
			state.offset = 0;
			loadAuditLogs();
		}
	}

	function bindEvents() {
		const form = $('#audit-log-filter-form');
		if (form) {
			on(form, 'submit', function (event) {
				event.preventDefault();
				state.offset = 0;
				loadAuditLogs();
			});
		}

		on($('#export-logs'), 'click', exportLogs);
		on($('#reset-filters'), 'click', resetFilters);
		on($('#audit-user-clear'), 'click', function () {
			if (userPicker && typeof userPicker.clear === 'function') {
				userPicker.clear();
			} else {
				const userEl = $('#user-filter');
				const searchEl = $('#audit-user-search');
				if (userEl) {
					userEl.value = '';
				}
				if (searchEl) {
					searchEl.value = '';
				}
			}
			const clearBtn = $('#audit-user-clear');
			if (clearBtn) {
				clearBtn.hidden = true;
			}
		});
		on($('#audit-log-prev'), 'click', function () {
			state.offset = Math.max(0, state.offset - state.limit);
			loadAuditLogs();
		});
		on($('#audit-log-next'), 'click', function () {
			if (state.offset + state.limit < state.total) {
				state.offset += state.limit;
				loadAuditLogs();
			}
		});
	}

	function init() {
		userPicker = createUserPicker();
		bindEvents();
		updatePaginationUi();
		applyUrlParams();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
