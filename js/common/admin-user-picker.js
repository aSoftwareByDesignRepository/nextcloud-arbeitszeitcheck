/**
 * Searchable admin employee picker (WAI-ARIA combobox).
 * Uses GET /api/admin/users?picker=1 (lightweight, enabled users only).
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const DEBOUNCE_MS = 280;

	function queryOne(selector) {
		if (Utils.$) {
			return Utils.$(selector);
		}
		return document.querySelector(selector);
	}

	function queryAll(selector, root) {
		const scope = root || document;
		if (Utils.$$) {
			return Utils.$$(selector, scope);
		}
		return scope.querySelectorAll(selector);
	}

	function bind(el, event, handler) {
		if (!el) {
			return;
		}
		if (Utils.on) {
			Utils.on(el, event, handler);
			return;
		}
		el.addEventListener(event, handler);
	}

	function escapeText(text) {
		if (Utils.escapeHtml) {
			return Utils.escapeHtml(text);
		}
		const d = document.createElement('div');
		d.textContent = String(text);
		return d.innerHTML;
	}

	function deriveIdPrefix(hidden, search, options) {
		if (options.idPrefix && String(options.idPrefix).trim() !== '') {
			return String(options.idPrefix).trim();
		}
		if (hidden && hidden.id) {
			return hidden.id.replace(/[^a-zA-Z0-9_-]/g, '-');
		}
		if (search && search.id) {
			return search.id.replace(/[^a-zA-Z0-9_-]/g, '-');
		}
		return 'user-picker-' + String(Math.random()).slice(2, 9);
	}

	/**
	 * @param {object} options
	 * @param {string} options.hiddenSelector Hidden input storing userId
	 * @param {string} options.searchSelector Visible combobox input
	 * @param {string} options.listSelector Listbox container element
	 * @param {string} options.wrapSelector Container for outside-click close
	 * @param {string} [options.searchUrl] Base URL for GET ?picker=1&search=&limit=
	 * @param {string} [options.statusSelector] Live region for screen reader updates
	 * @param {string} [options.idPrefix] Unique prefix for option element ids
	 * @param {number} [options.limit=15]
	 * @param {boolean} [options.pickerMode=true] Use lightweight GET ?picker=1
	 * @param {number} [options.minQueryLength=2] Minimum typed characters before searching
	 * @param {string[]} [options.excludeUserIds] User IDs to hide from suggestions
	 * @param {object} [options.l10n]
	 * @param {function(string, object=): void} [options.onChange] Called when selection cleared or set
	 */
	function initAdminUserPicker(options) {
		const hidden = queryOne(options.hiddenSelector);
		const search = queryOne(options.searchSelector);
		const list = queryOne(options.listSelector);
		const wrap = queryOne(options.wrapSelector);
		const status = options.statusSelector ? queryOne(options.statusSelector) : null;
		const baseUrl = options.searchUrl || '';
		const l10n = options.l10n || {};
		const limit = options.limit || 15;
		const pickerMode = options.pickerMode !== false;
		const minQueryLength = typeof options.minQueryLength === 'number'
			? Math.max(0, options.minQueryLength)
			: 2;
		const idPrefix = deriveIdPrefix(hidden, search, options);
		const excludeLookup = {};
		if (Array.isArray(options.excludeUserIds)) {
			options.excludeUserIds.forEach(function (id) {
				const key = String(id || '').trim();
				if (key !== '') {
					excludeLookup[key] = true;
				}
			});
		}
		const onChange = typeof options.onChange === 'function' ? options.onChange : function () {};
		let selectedMeta = null;

		if (!hidden || !search || !list || !baseUrl) {
			return null;
		}

		search.setAttribute('aria-haspopup', 'listbox');
		if (!list.getAttribute('role')) {
			list.setAttribute('role', 'listbox');
		}

		let debounceTimer = null;
		let selectedLabel = '';
		let activeIndex = -1;
		let requestGeneration = 0;

		function setStatus(message) {
			if (status) {
				status.textContent = message || '';
			}
		}

		function closeList() {
			list.hidden = true;
			list.innerHTML = '';
			search.setAttribute('aria-expanded', 'false');
			search.removeAttribute('aria-activedescendant');
			activeIndex = -1;
		}

		function openList() {
			list.hidden = false;
			search.setAttribute('aria-expanded', 'true');
		}

		function showMessage(msg, muted) {
			const cls = 'user-picker__item user-picker__item--muted';
			list.innerHTML = '<div class="' + cls + '" role="presentation">' + escapeText(msg) + '</div>';
			openList();
			if (muted) {
				/* keep pointer events off informational rows */
			}
		}

		function minSearchHint() {
			return l10n.typeToSearch || l10n.minSearchHint
				|| ('Type at least ' + minQueryLength + ' characters to search.');
		}

		function fetchUsers(query) {
			const q = typeof query === 'string' ? query.trim() : '';
			if (q.length < minQueryLength) {
				const hint = minSearchHint();
				showMessage(hint, true);
				setStatus(hint);
				return;
			}
			const params = new URLSearchParams({ limit: String(limit) });
			if (pickerMode) {
				params.set('picker', '1');
			}
			params.set('search', q);
			// Exclude already-assigned people server-side so a heavily-staffed
			// unit cannot fill the capped page and hide everyone still
			// available (issue #14). Client-side filtering below stays as a
			// defensive second pass.
			Object.keys(excludeLookup).forEach(function (id) {
				params.append('exclude[]', id);
			});
			const sep = baseUrl.indexOf('?') >= 0 ? '&' : '?';
			const url = baseUrl + sep + params.toString();
			const generation = ++requestGeneration;

			showMessage(l10n.loading || l10n.loadingEllipsis || 'Loading…', true);
			setStatus(l10n.loading || l10n.loadingEllipsis || 'Loading…');

			const onDone = function (data) {
				if (generation !== requestGeneration) {
					return;
				}
				if (!data || !data.success || !Array.isArray(data.users)) {
					const err = l10n.searchError || l10n.error || 'User search failed';
					showMessage(err, true);
					setStatus(err);
					return;
				}
				const raw = data.users;
				const filtered = raw.filter(function (u) {
					const uid = String(u.userId || u.uid || u.id || '').trim();
					return uid !== '' && !excludeLookup[uid];
				});
				if (filtered.length === 0 && raw.length > 0) {
					const excludedMsg = l10n.allExcluded || l10n.noUsersFound
						|| 'No matching employees found.';
					showMessage(excludedMsg, true);
					setStatus(excludedMsg);
					return;
				}
				renderUsers(filtered, data.truncated === true);
			};

			const onFail = function () {
				if (generation !== requestGeneration) {
					return;
				}
				const err = l10n.searchError || l10n.error || 'User search failed';
				showMessage(err, true);
				setStatus(err);
			};

			if (Utils.ajax) {
				Utils.ajax(url, {
					method: 'GET',
					onSuccess: onDone,
					onError: onFail,
				});
				return;
			}

			const token = (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : '';
			fetch(url, {
				headers: { requesttoken: token, Accept: 'application/json' },
				credentials: 'same-origin',
			})
				.then(function (res) { return res.json(); })
				.then(onDone)
				.catch(onFail);
		}

		function selectUser(uid, displayName, meta) {
			const id = String(uid || '').trim();
			if (id === '') {
				return;
			}
			const name = (displayName && String(displayName).trim()) ? String(displayName).trim() : id;
			hidden.value = id;
			selectedLabel = name + ' (' + id + ')';
			search.value = selectedLabel;
			selectedMeta = meta && typeof meta === 'object' ? meta : null;
			closeList();
			const tpl = l10n.employeeSelected || 'Selected: %s';
			const selectedMsg = tpl.indexOf('%s') >= 0
				? tpl.replace('%s', selectedLabel)
				: (tpl + ' ' + selectedLabel);
			setStatus(selectedMsg);
			onChange(id, selectedMeta || {});
		}

		function clearSelection() {
			hidden.value = '';
			selectedLabel = '';
			search.value = '';
			selectedMeta = null;
			closeList();
			setStatus(l10n.allEmployees || '');
			onChange('', {});
		}

		function setSelection(uid, displayName) {
			const id = String(uid || '').trim();
			if (id === '') {
				clearSelection();
				return;
			}
			const name = (displayName && String(displayName).trim()) ? String(displayName).trim() : id;
			selectUser(id, name);
		}

		function truncationHint(count) {
			const tpl = l10n.moreResults
				|| 'Showing the first %n matches. Keep typing to narrow it down.';
			return tpl.replace('%n', String(count));
		}

		function renderUsers(users, truncated) {
			const emptyMsg = l10n.noUsersFound || 'No matching employees found.';
			if (users.length === 0) {
				list.innerHTML = '';
				showMessage(emptyMsg, true);
				setStatus(emptyMsg);
				return;
			}

			let html = users.map(function (u, index) {
				const uid = u.userId || u.uid || u.id || '';
				const name = (u.displayName && String(u.displayName).trim()) ? String(u.displayName) : uid;
				const optId = idPrefix + '-opt-' + index;
				const minBreak = Number(u.minBreakMinutes);
				const minBreakAttr = Number.isFinite(minBreak) && minBreak > 0
					? ' data-min-break-minutes="' + escapeText(String(Math.round(minBreak))) + '"'
					: '';
				return '<div role="option" id="' + escapeText(optId) + '" tabindex="-1" class="user-picker__item" data-user-id="'
					+ escapeText(uid) + '"' + minBreakAttr + ' aria-selected="false">'
					+ '<span class="user-picker__name">' + escapeText(name) + '</span>'
					+ '<span class="user-picker__meta">' + escapeText(uid) + '</span></div>';
			}).join('');
			if (truncated) {
				html += '<div class="user-picker__item user-picker__item--muted user-picker__hint" role="presentation">'
					+ escapeText(truncationHint(users.length)) + '</div>';
			}
			list.innerHTML = html;
			openList();
			activeIndex = -1;

			const countTpl = l10n.resultsCount || '%n results';
			let countMsg = countTpl.replace('%n', String(users.length));
			if (truncated) {
				countMsg += '. ' + truncationHint(users.length);
			}
			setStatus(countMsg);

			queryAll('.user-picker__item[data-user-id]', list).forEach(function (item) {
				bind(item, 'mousedown', function (e) {
					e.preventDefault();
				});
				bind(item, 'click', function () {
					const uid = item.getAttribute('data-user-id') || '';
					const nameEl = item.querySelector('.user-picker__name');
					const rawMin = Number(item.getAttribute('data-min-break-minutes'));
					const meta = Number.isFinite(rawMin) && rawMin > 0
						? { minBreakMinutes: Math.round(rawMin) }
						: {};
					selectUser(uid, nameEl ? nameEl.textContent : uid, meta);
				});
			});
		}

		function getSelectableItems() {
			return list.querySelectorAll('.user-picker__item[data-user-id]');
		}

		function highlightOption(index) {
			const items = getSelectableItems();
			if (!items.length) {
				return;
			}
			items.forEach(function (item, i) {
				const active = i === index;
				item.setAttribute('aria-selected', active ? 'true' : 'false');
				if (active && item.id) {
					search.setAttribute('aria-activedescendant', item.id);
				}
			});
			activeIndex = index;
		}

		function commitHighlighted() {
			const items = getSelectableItems();
			if (activeIndex >= 0 && items[activeIndex]) {
				const item = items[activeIndex];
				const uid = item.getAttribute('data-user-id') || '';
				const nameEl = item.querySelector('.user-picker__name');
				const rawMin = Number(item.getAttribute('data-min-break-minutes'));
				const meta = Number.isFinite(rawMin) && rawMin > 0
					? { minBreakMinutes: Math.round(rawMin) }
					: {};
				selectUser(uid, nameEl ? nameEl.textContent : uid, meta);
				return true;
			}
			if (items.length === 1) {
				const item = items[0];
				const uid = item.getAttribute('data-user-id') || '';
				const nameEl = item.querySelector('.user-picker__name');
				const rawMin = Number(item.getAttribute('data-min-break-minutes'));
				const meta = Number.isFinite(rawMin) && rawMin > 0
					? { minBreakMinutes: Math.round(rawMin) }
					: {};
				selectUser(uid, nameEl ? nameEl.textContent : uid, meta);
				return true;
			}
			return false;
		}

		bind(search, 'input', function () {
			if (search.value !== selectedLabel) {
				hidden.value = '';
				selectedLabel = '';
				selectedMeta = null;
				onChange('', {});
			}
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(function () {
				fetchUsers(search.value);
			}, DEBOUNCE_MS);
		});

		bind(search, 'focus', function () {
			const q = (hidden.value && search.value === selectedLabel) ? hidden.value : search.value.trim();
			if (q.length >= minQueryLength) {
				fetchUsers(q);
			} else if (q.length > 0) {
				showMessage(minSearchHint(), true);
				setStatus(minSearchHint());
			}
		});

		bind(search, 'keydown', function (e) {
			const items = getSelectableItems();
			if (e.key === 'Escape') {
				closeList();
				return;
			}
			if (e.key === 'Enter') {
				e.preventDefault();
				if (!list.hidden && items.length > 0) {
					commitHighlighted();
				}
				return;
			}
			if (list.hidden || items.length === 0) {
				return;
			}
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				const next = activeIndex < items.length - 1 ? activeIndex + 1 : 0;
				highlightOption(next);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				const prev = activeIndex > 0 ? activeIndex - 1 : items.length - 1;
				highlightOption(prev);
			} else if (e.key === 'Home') {
				e.preventDefault();
				highlightOption(0);
			} else if (e.key === 'End') {
				e.preventDefault();
				highlightOption(items.length - 1);
			}
		});

		function onDocumentClick(ev) {
			if (!wrap || !wrap.isConnected) {
				document.removeEventListener('click', onDocumentClick);
				return;
			}
			if (wrap.contains(ev.target)) {
				return;
			}
			closeList();
		}
		document.addEventListener('click', onDocumentClick);

		return {
			clear: clearSelection,
			setSelection: setSelection,
			getUserId: function () {
				return String(hidden.value || '').trim();
			},
			getSelectedMeta: function () {
				return selectedMeta && typeof selectedMeta === 'object' ? Object.assign({}, selectedMeta) : {};
			},
			destroy: function () {
				clearTimeout(debounceTimer);
				document.removeEventListener('click', onDocumentClick);
				closeList();
			},
		};
	}

	window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
	window.ArbeitszeitCheck.initAdminUserPicker = initAdminUserPicker;
})();
