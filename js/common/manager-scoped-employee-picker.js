/**
 * Manager-scope employee combobox (teams, time entries, absences).
 * Uses GET /api/manager/scoped-employees (no cross-scope admin user list for managers).
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	function t(key, fallback) {
		const bundle = window.ArbeitszeitCheck?.l10n || {};
		const value = bundle[key];
		return value !== undefined && value !== '' ? value : (fallback || key);
	}

	function scopedEmployeesUrl() {
		const Utils = window.ArbeitszeitCheckUtils || {};
		if (Utils.buildAppUrl) {
			return Utils.buildAppUrl('/apps/arbeitszeitcheck/api/manager/scoped-employees');
		}
		if (typeof OC !== 'undefined' && OC.generateUrl) {
			return OC.generateUrl('/apps/arbeitszeitcheck/api/manager/scoped-employees');
		}
		return Utils.resolveUrl ? Utils.resolveUrl('/apps/arbeitszeitcheck/api/manager/scoped-employees') : '';
	}

	function defaultL10n(allowAll) {
		return {
			typeToSearch: t('pickerTypeToSearch', 'Type at least 2 characters to search.'),
			minSearchHint: t('pickerMinSearchHint', 'Type at least 2 characters to search.'),
			loading: t('pickerLoading', 'Loading…'),
			searchError: t('pickerSearchError', 'Employee search failed.'),
			noUsersFound: t('pickerNoUsersFound', 'No matching employees found.'),
			resultsCount: t('pickerResultsCount', '%s results'),
			employeeSelected: t('pickerEmployeeSelected', 'Selected: %s'),
			allEmployees: allowAll ? t('allInMyScope', 'All in my scope') : '',
		};
	}

	/**
	 * @param {object} options Passed through to initAdminUserPicker (selectors, idPrefix, onChange, …)
	 * @param {boolean} [options.allowAll=true] Optional filter: empty selection = all in scope
	 */
	function initManagerScopedEmployeePicker(options) {
		const initPicker = window.ArbeitszeitCheck?.initAdminUserPicker;
		if (!initPicker || !options) {
			return null;
		}

		const allowAll = options.allowAll !== false;
		const l10n = Object.assign(defaultL10n(allowAll), options.l10n || {});
		const picker = initPicker({
			hiddenSelector: options.hiddenSelector,
			searchSelector: options.searchSelector,
			listSelector: options.listSelector,
			wrapSelector: options.wrapSelector,
			statusSelector: options.statusSelector,
			searchUrl: options.searchUrl || scopedEmployeesUrl(),
			pickerMode: false,
			limit: options.limit || 25,
			minQueryLength: typeof options.minQueryLength === 'number' ? options.minQueryLength : 2,
			idPrefix: options.idPrefix,
			excludeUserIds: options.excludeUserIds,
			l10n: l10n,
			onChange: function (userId, meta) {
				if (options.clearButtonSelector) {
					const clearBtn = document.querySelector(options.clearButtonSelector);
					if (clearBtn) {
						clearBtn.hidden = !allowAll || userId === '';
					}
				}
				if (typeof options.onChange === 'function') {
					options.onChange(userId, meta || {});
				}
			},
		});

		if (!picker) {
			return null;
		}

		if (options.clearButtonSelector) {
			const clearBtn = document.querySelector(options.clearButtonSelector);
			if (clearBtn) {
				clearBtn.addEventListener('click', function () {
					picker.clear();
					clearBtn.hidden = true;
				});
				clearBtn.addEventListener('keydown', function (event) {
					if (event.key !== 'Enter' && event.key !== ' ') {
						return;
					}
					event.preventDefault();
					picker.clear();
					clearBtn.hidden = true;
				});
				clearBtn.hidden = true;
			}
		}

		return picker;
	}

	/**
	 * Clear a tampered employee filter after server-side scope denial (403).
	 *
	 * @param {object|null} error Utils.ajax error object
	 * @param {object} options
	 * @param {object|null} [options.picker]
	 * @param {string} [options.searchSelector]
	 * @param {string} [options.clearButtonSelector]
	 * @returns {string|null} Focus target element id for setFilterError
	 */
	/**
	 * Reject filter submit when the search box has text but no employee was chosen from the list.
	 * Prevents silently showing "all in scope" while the UI still shows a partial name.
	 *
	 * @param {object|null} picker initManagerScopedEmployeePicker instance
	 * @param {string} searchSelector
	 * @returns {{ valid: boolean, message?: string, focusId?: string }}
	 */
	function validateManagerFilterEmployeeSelection(picker, searchSelector, hiddenSelector) {
		const search = searchSelector ? document.querySelector(searchSelector) : null;
		const query = search ? String(search.value || '').trim() : '';
		let selectedId = picker && typeof picker.getUserId === 'function'
			? String(picker.getUserId() || '').trim()
			: '';
		if (!selectedId && hiddenSelector) {
			const hidden = document.querySelector(hiddenSelector);
			if (hidden) {
				selectedId = String(hidden.value || '').trim();
			}
		}
		if (query !== '' && selectedId === '') {
			const message = t(
				'pickerIncompleteSelection',
				'Select an employee from the list, or clear the search field to include everyone in your scope.'
			);
			return {
				valid: false,
				message: message,
				focusId: search && search.id ? search.id : undefined,
			};
		}
		return { valid: true };
	}

	function handleManagerListApiError(error, options) {
		if (!error || error.status !== 403) {
			return null;
		}
		const picker = options?.picker;
		if (picker && typeof picker.clear === 'function') {
			picker.clear();
		}
		if (options?.clearButtonSelector) {
			const clearBtn = document.querySelector(options.clearButtonSelector);
			if (clearBtn) {
				clearBtn.hidden = true;
			}
		}
		if (options?.searchSelector) {
			const search = document.querySelector(options.searchSelector);
			if (search) {
				search.focus();
			}
		}
		return options?.searchFocusId || null;
	}

	window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
	window.ArbeitszeitCheck.initManagerScopedEmployeePicker = initManagerScopedEmployeePicker;
	window.ArbeitszeitCheck.validateManagerFilterEmployeeSelection = validateManagerFilterEmployeeSelection;
	window.ArbeitszeitCheck.handleManagerListApiError = handleManagerListApiError;
})();
