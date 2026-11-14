/*
 * Check family — canonical license page (canonical source in _shared/license-page).
 *
 * SYNCED FILE — copied VERBATIM into js/common/license-page.js. Never edit the
 * in-app copy; edit the source and re-run sync_license_page.py.
 *
 * Drives every Check app's unified license page: save/remove license key,
 * mobile-seat combobox with multi-select + batch assign, seat table, device
 * card (link / register modes), accessible confirm + token modals.
 *
 * Wire contract (ArbeitszeitCheck canonical; tolerant normalizer below):
 *   POST {apiLicense}        {licenseKey}            -> {ok|success, ...}
 *   POST|DELETE {apiClearLicense}                    -> {ok|success}
 *   GET  {apiSearchUsers}?q=                         -> {users[]|data[] {userId|uid|id, displayName, hasSeat?}}
 *   POST {apiSeatsBatch}     {userIds[]}             -> {ok, summary?{assigned,skipped,failed}, seats?, ...}
 *   POST {apiSeats}          {userId}                -> {ok, seats?, ...}   (fallback when no batch endpoint)
 *   POST {apiRemoveSeat}     {userId}  | DELETE {apiRemoveSeat}/{userId}
 *   GET  {apiSeats}                                  -> {seats[]|{data[],total,limit}}   (re-fetch after mutation)
 *   POST {apiRegisterDevice} {label, ...deviceFields}-> {ok, device?, token?|tokenText?|credentials?}
 *   POST {apiRevokeDevice}   {deviceId} | DELETE {apiRevokeDevice}/{deviceId}
 *   GET  {apiDevices}                                -> {terminals[]|devices[]|data[]}
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	function initLicensePage(page) {
		const p = page.dataset.prefix || '';
		const id = (suffix) => document.getElementById(p + suffix);

		const apiLicense = page.dataset.apiLicense || '';
		const apiClearLicense = page.dataset.apiClearLicense || '';
		const apiSeats = page.dataset.apiSeats || '';
		const apiSeatsBatch = page.dataset.apiSeatsBatch || '';
		const apiRemoveSeat = page.dataset.apiRemoveSeat || '';
		const apiSearchUsers = page.dataset.apiSearchUsers || '';
		const apiRegisterDevice = page.dataset.apiRegisterDevice || '';
		const apiRevokeDevice = page.dataset.apiRevokeDevice || '';
		const apiRegenDevice = page.dataset.apiRegenDevice || '';
		const requestToken = page.dataset.requesttoken || '';
		const FLASH_KEY = p + '-license-flash';
		let i18n = {};
		try {
			i18n = JSON.parse(page.dataset.i18n || '{}');
		} catch (e) {
			i18n = {};
		}

		function t(msgid) {
			return i18n[msgid] || msgid;
		}


		const liveRegion = id('-license-live');
		const alertRegion = id('-license-alert');
		const feedback = id('-license-feedback');
		const keyInput = id('-license-key-input');
		const saveBtn = id('-license-save');
		const clearBtn = id('-license-clear');
		const seatListBody = id('-seat-list-body');
		const seatEmpty = id('-seat-empty');
		const seatCount = id('-seat-count');
		const seatsFullHint = id('-seats-full-hint');
		const userSearch = id('-seat-user-search');
		const searchResults = id('-seat-search-results');
		const assignSelectedBtn = id('-seat-assign-selected');
		const batchResultEl = id('-seat-batch-result');
		const clearModal = id('-license-clear-modal');
		const clearCancel = id('-license-clear-cancel');
		const clearConfirm = id('-license-clear-confirm');
		const confirmModal = id('-license-confirm-modal');
		const confirmTitle = id('-license-confirm-title');
		const confirmBody = id('-license-confirm-body');
		const confirmCancelBtn = id('-license-confirm-cancel');
		const confirmOkBtn = id('-license-confirm-ok');
		const tokenModal = id('-license-token-modal');
		const tokenTitle = id('-license-token-title');
		const tokenHint = id('-license-token-hint');
		const tokenValue = id('-license-token-value');
		const tokenCopy = id('-license-token-copy');
		const tokenClose = id('-license-token-close');
		const terminalLabel = id('-terminal-label');
		const terminalRegister = id('-terminal-register');
		const terminalListBody = id('-terminal-list-body');
		const terminalEmpty = id('-terminal-empty');
		const terminalCount = id('-terminal-count');
		const terminalsFullHint = id('-terminals-full-hint');
		const terminalUsedEl = id('-license-terminal-used');
		const terminalLimitEl = id('-license-terminal-limit');
		const terminalMeterEl = id('-license-terminal-meter');

		let searchTimer = null;
		let searchAbort = null;
		let searchActiveIndex = -1;
		let modalReturnFocus = null;
		let confirmResolver = null;
		let confirmReturnFocus = null;
		let tokenReturnFocus = null;
		let lastSeatUsed = 0;
		let lastSeatLimit = 0;
		let lastTerminalUsed = 0;
		let lastTerminalLimit = 0;
		const assignedUids = new Set();
		/** @type {Map<string, {id: string, displayName: string}>} */
		const pendingSeatSelection = new Map();

		const TRASH_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

		/* ── Response normalizers (per-app endpoints differ slightly) ── */

		function isOk(data) {
			return !!data && (data.ok === true || data.success === true || data.saved === true);
		}

		function seatUid(seat) {
			return String(seat.userId || seat.uid || seat.id || '');
		}

		function normalizeSeats(data) {
			if (!data || typeof data !== 'object') {
				return null;
			}
			let seats = null;
			if (Array.isArray(data.seats)) {
				seats = data.seats;
			} else if (Array.isArray(data.data)) {
				seats = data.data;
			} else if (data.data && Array.isArray(data.data.data)) {
				seats = data.data.data;
			} else if (Array.isArray(data.results)) {
				seats = data.results;
			}
			if (seats === null) {
				return null;
			}
			const usedRaw = data.mobileSeatsUsed ?? data.total ?? (data.data && data.data.total);
			const limitRaw = data.mobileSeatsLimit ?? data.limit ?? (data.data && data.data.limit);
			return {
				seats: seats,
				used: Number.isFinite(Number(usedRaw)) ? Number(usedRaw) : seats.length,
				limit: Number.isFinite(Number(limitRaw)) ? Number(limitRaw) : lastSeatLimit,
			};
		}

		function normalizeUsers(data) {
			if (!data || typeof data !== 'object') {
				return [];
			}
			const users = data.users
				|| data.items
				|| (data.data && (Array.isArray(data.data) ? data.data : data.data.users))
				|| data.results
				|| [];
			return Array.isArray(users) ? users : [];
		}

		function normalizeDevices(data) {
			if (!data || typeof data !== 'object') {
				return null;
			}
			const devices = data.terminals
				|| data.devices
				|| (Array.isArray(data.data) ? data.data : (data.data && data.data.data))
				|| null;
			if (!Array.isArray(devices)) {
				return null;
			}
			const usedRaw = data.terminalDevicesUsed ?? data.used ?? data.total ?? (data.data && data.data.total);
			const limitRaw = data.terminalDevicesLimit ?? data.limit ?? (data.data && data.data.limit);
			return {
				devices: devices,
				used: Number.isFinite(Number(usedRaw)) ? Number(usedRaw) : devices.length,
				limit: Number.isFinite(Number(limitRaw)) ? Number(limitRaw) : lastTerminalLimit,
			};
		}

		/* ── Feedback / announcements ────────────────────────────── */

		function announce(el, message) {
			if (el) {
				el.textContent = message;
			}
		}

		function showFeedback(message, type) {
			if (!feedback) {
				return;
			}
			feedback.hidden = false;
			feedback.textContent = message;
			feedback.className = p + '-license-feedback ' + p + '-license-feedback--' + (type || 'success');
			if (type === 'error') {
				announce(alertRegion, message);
			} else {
				announce(liveRegion, message);
			}
		}

		function hideFeedback() {
			if (feedback) {
				feedback.hidden = true;
				feedback.textContent = '';
			}
		}

		// Show the outcome of a save/clear that triggered a page reload.
		try {
			const flash = window.sessionStorage.getItem(FLASH_KEY);
			if (flash) {
				window.sessionStorage.removeItem(FLASH_KEY);
				showFeedback(flash, 'success');
			}
		} catch (e) {
			// Session storage unavailable — reload without flash.
		}

		function setFlashAndReload(message) {
			try {
				window.sessionStorage.setItem(FLASH_KEY, message);
			} catch (e) {
				// Best effort only.
			}
			window.location.reload();
		}

		function headers() {
			return {
				'Content-Type': 'application/json',
				requesttoken: requestToken,
				'X-Requested-With': 'XMLHttpRequest',
			};
		}

		async function apiFetch(url, options) {
			const res = await fetch(url, options || {});
			const data = await res.json().catch(() => ({}));
			return { res, data };
		}

		/**
		 * Mutating call tolerant of per-app verbs: POSTs a JSON body, and when
		 * the route only allows DELETE retries as DELETE (body preserved).
		 */
		async function apiMutate(url, body) {
			let result = await apiFetch(url, {
				method: 'POST',
				headers: headers(),
				body: JSON.stringify(body || {}),
			});
			if (result.res.status === 405) {
				result = await apiFetch(url, {
					method: 'PUT',
					headers: headers(),
					body: JSON.stringify(body || {}),
				});
			}
			if (result.res.status === 405) {
				result = await apiFetch(url, {
					method: 'DELETE',
					headers: headers(),
					body: JSON.stringify(body || {}),
				});
			}
			return result;
		}

		// Navigation/teardown aborts are not connectivity failures — suppress
		// the network-error flash when the fetch was cancelled by page unload
		// or an in-flight abort (mirrors common/api.js REQUEST_ABORTED).
		function isNavOrAbort(e) {
			return !!(e && (e.name === 'AbortError' || e.code === 'REQUEST_ABORTED'
				|| (e.cause && (e.cause.name === 'AbortError' || e.cause.code === 20))));
		}
		function showNetworkErrorUnlessAbort(e) {
			if (!isNavOrAbort(e)) {
				showFeedback(t('Network error. Please try again.'), 'error');
			}
		}

		// Focus lost to <body> on dialog close (trigger detached by a re-render)
		// strands keyboard users — only restore when the trigger is still
		// connected, else land on the app's main landmark.
		function restoreFocus(el) {
			if (el && el.isConnected) {
				el.focus();
				return;
			}
			const main = document.getElementById(p + '-main-content');
			if (main && main.focus) {
				if (main.tabIndex < 0) {
					main.tabIndex = -1;
				}
				main.focus();
			}
		}

		function escapeHtml(s) {
			const d = document.createElement('div');
			d.textContent = s;
			return d.innerHTML;
		}

		function escapeAttr(s) {
			return escapeHtml(String(s));
		}

		function formatAssignedAt(iso) {
			if (!iso) {
				return '—';
			}
			try {
				const d = typeof iso === 'number' ? new Date(iso * 1000) : new Date(iso);
				if (Number.isNaN(d.getTime())) {
					return String(iso);
				}
				const pad = (n) => String(n).padStart(2, '0');
				return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear()
					+ ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
			} catch (e) {
				return String(iso);
			}
		}

		function fmt2(template, a, b) {
			return String(template).replace('%1$d', String(a)).replace('%2$d', String(b));
		}

		function updateMeter(usedEl, limitEl, meterEl, used, limit) {
			if (usedEl) {
				usedEl.textContent = String(used);
			}
			if (limitEl) {
				limitEl.textContent = String(limit);
			}
			if (meterEl && limit > 0) {
				const pct = Math.min(100, Math.round((used / limit) * 100));
				meterEl.style.width = pct + '%';
				meterEl.classList.toggle(p + '-license-meter__fill--full', used >= limit);
				const meter = meterEl.closest('[role="meter"]');
				if (meter) {
					meter.setAttribute('aria-valuenow', String(used));
					meter.setAttribute('aria-valuemax', String(Math.max(1, limit)));
				}
			}
		}

		function updateSeatCounts(used, limit) {
			if (!Number.isFinite(used) || !Number.isFinite(limit)) {
				return;
			}
			lastSeatUsed = used;
			lastSeatLimit = limit;
			updateMeter(
				id('-license-mobile-used'),
				id('-license-mobile-limit'),
				id('-license-mobile-meter'),
				used,
				limit,
			);
			if (seatCount && limit > 0) {
				seatCount.textContent = fmt2(t('%1$d of %2$d seats assigned'), used, limit);
			}
			const full = limit > 0 && used >= limit;
			if (userSearch) {
				userSearch.disabled = full;
				userSearch.setAttribute('aria-disabled', full ? 'true' : 'false');
				if (full) {
					closeSearchResults();
				}
			}
			if (seatsFullHint) {
				seatsFullHint.hidden = !full;
			}
		}

		function updateTerminalCounts(used, limit) {
			if (!Number.isFinite(used) || !Number.isFinite(limit)) {
				return;
			}
			lastTerminalUsed = used;
			lastTerminalLimit = limit;
			updateMeter(terminalUsedEl, terminalLimitEl, terminalMeterEl, used, limit);
			if (terminalCount && limit > 0) {
				terminalCount.textContent = fmt2(t('%1$d of %2$d device slots in use.'), used, limit);
			}
			const full = limit > 0 && used >= limit;
			[terminalLabel, terminalRegister].forEach((el) => {
				if (el) {
					el.disabled = full;
					el.setAttribute('aria-disabled', full ? 'true' : 'false');
				}
			});
			if (terminalsFullHint) {
				terminalsFullHint.hidden = !full;
			}
		}

		/* ── Generic accessible confirm dialog ───────────────────── */

		function openConfirm(opts) {
			return new Promise((resolve) => {
				if (!confirmModal) {
					resolve({ confirmed: false });
					return;
				}
				confirmResolver = resolve;
				confirmReturnFocus = document.activeElement;
				if (confirmTitle) {
					confirmTitle.textContent = opts.title || t('Confirm');
				}
				if (confirmBody) {
					confirmBody.textContent = opts.message || '';
				}
				if (confirmCancelBtn) {
					confirmCancelBtn.textContent = opts.cancelLabel || t('Cancel');
				}
				if (confirmOkBtn) {
					confirmOkBtn.textContent = opts.confirmLabel || t('Confirm');
					confirmOkBtn.className = p + '-license-btn ' + p + '-license-btn--' + (opts.variant === 'danger' ? 'danger' : 'primary');
				}
				if (!confirmModal.open) {
					confirmModal.showModal();
				}
				document.body.classList.add(p + '-license-modal-open');
				(confirmCancelBtn || confirmOkBtn || confirmModal).focus();
			});
		}

		function closeConfirm(result) {
			if (confirmModal && confirmModal.open) {
				confirmModal.close();
			}
			document.body.classList.remove(p + '-license-modal-open');
			restoreFocus(confirmReturnFocus);
			confirmReturnFocus = null;
			if (confirmResolver) {
				const resolve = confirmResolver;
				confirmResolver = null;
				resolve(result);
			}
		}

		if (confirmCancelBtn) {
			confirmCancelBtn.addEventListener('click', () => closeConfirm({ confirmed: false }));
		}
		if (confirmOkBtn) {
			confirmOkBtn.addEventListener('click', () => closeConfirm({ confirmed: true }));
		}

		/* ── Token modal ─────────────────────────────────────────── */

		function openTokenModal(title, hint, value) {
			if (!tokenModal) {
				return;
			}
			tokenReturnFocus = document.activeElement;
			if (tokenTitle) {
				tokenTitle.textContent = title;
			}
			if (tokenHint) {
				tokenHint.textContent = hint || t('This token is shown only once. Paste it into the companion app on the device.');
			}
			if (tokenValue) {
				tokenValue.textContent = value;
			}
			if (!tokenModal.open) {
				tokenModal.showModal();
			}
			document.body.classList.add(p + '-license-modal-open');
			(tokenCopy || tokenValue || tokenModal).focus();
		}

		function closeTokenModal() {
			if (tokenModal && tokenModal.open) {
				tokenModal.close();
			}
			document.body.classList.remove(p + '-license-modal-open');
			restoreFocus(tokenReturnFocus);
			tokenReturnFocus = null;
		}

		if (tokenClose) {
			tokenClose.addEventListener('click', () => closeTokenModal());
		}
		if (tokenCopy && tokenValue) {
			tokenCopy.textContent = tokenCopy.textContent || t('Copy token');
			tokenCopy.addEventListener('click', async () => {
				const text = tokenValue.textContent || '';
				try {
					await navigator.clipboard.writeText(text);
					tokenCopy.textContent = t('Copied.');
					window.setTimeout(() => {
						tokenCopy.textContent = t('Copy token');
					}, 1600);
				} catch (e) {
					const range = document.createRange();
					range.selectNodeContents(tokenValue);
					const sel = window.getSelection();
					if (sel) {
						sel.removeAllRanges();
						sel.addRange(range);
					}
				}
			});
		}
		if (tokenClose) {
			tokenClose.textContent = tokenClose.textContent || t('Done');
		}

		// Native <dialog> gives Esc→cancel, ::backdrop, and focus containment for
		// free — route cancel/outside-click through the same close paths.
		const onOutsideClick = (dlg, closeFn) => {
			dlg.addEventListener('click', (e) => {
				const r = dlg.getBoundingClientRect();
				if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) {
					closeFn();
				}
			});
		};
		// NC's notifications app preventDefault()s the global Escape keydown,
		// which suppresses the native <dialog> `cancel` event — wire an
		// explicit Escape keydown close for each modal.
		const onEscapeClose = (dlg, closeFn) => {
			dlg.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') {
					e.stopPropagation();
					closeFn();
				}
			});
		};
		// Chrome lets Tab/Shift+Tab leave a modal <dialog> at its boundaries —
		// focus lands on <body>/browser chrome, and once outside, Escape never
		// reaches the dialog's keydown handler (and the suppressed `cancel`
		// event cannot close it either) leaving the modal stuck open. Trap Tab
		// at both boundaries — same fix shape as dc-swap-dialog.
		const FOCUSABLE_SEL = 'a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])';
		const onFocusTrap = (dlg) => {
			dlg.addEventListener('keydown', (e) => {
				if (e.key !== 'Tab') return;
				const list = Array.from(dlg.querySelectorAll(FOCUSABLE_SEL))
					.filter((node) => node.offsetParent !== null || node === document.activeElement);
				if (list.length === 0) {
					e.preventDefault();
					return;
				}
				const first = list[0];
				const last = list[list.length - 1];
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			});
		};
		if (clearModal) {
			clearModal.addEventListener('cancel', (e) => { e.preventDefault(); closeClearModal(); });
			onOutsideClick(clearModal, () => closeClearModal());
			onEscapeClose(clearModal, () => closeClearModal());
			onFocusTrap(clearModal);
		}
		if (confirmModal) {
			confirmModal.addEventListener('cancel', (e) => { e.preventDefault(); closeConfirm({ confirmed: false }); });
			onOutsideClick(confirmModal, () => closeConfirm({ confirmed: false }));
			onEscapeClose(confirmModal, () => closeConfirm({ confirmed: false }));
			onFocusTrap(confirmModal);
		}
		if (tokenModal) {
			tokenModal.addEventListener('cancel', (e) => { e.preventDefault(); closeTokenModal(); });
			onOutsideClick(tokenModal, () => closeTokenModal());
			onEscapeClose(tokenModal, () => closeTokenModal());
			onFocusTrap(tokenModal);
		}

		/* ── Save / clear license ────────────────────────────────── */

		if (saveBtn && keyInput) {
			saveBtn.addEventListener('click', async () => {
				// Keys copied from e-mails often contain line wraps — remove all
				// whitespace before validating/sending (server does the same).
				const key = keyInput.value.replace(/\s+/g, '');
				if (!key) {
					showFeedback(t('Please paste a license key.'), 'error');
					keyInput.focus();
					return;
				}
				hideFeedback();
				saveBtn.disabled = true;
				const originalLabel = saveBtn.textContent;
				saveBtn.textContent = t('Saving…');
				try {
					const { data } = await apiFetch(apiLicense, {
						method: 'POST',
						headers: headers(),
						body: JSON.stringify({ licenseKey: key, key: key, license_key: key }),
					});
					if (isOk(data)) {
						// Keep disabled: reload follows, re-enabling opens a double-submit window.
						setFlashAndReload(t('License saved successfully.'));
						return;
					}
					showFeedback(data.message || data.error || t('Could not save license.'), 'error');
				} catch (e) {
					showNetworkErrorUnlessAbort(e);
				}
				saveBtn.disabled = false;
				saveBtn.textContent = originalLabel;
			});
		}

		function openClearModal() {
			if (!clearModal) {
				return;
			}
			modalReturnFocus = clearBtn || null;
			if (!clearModal.open) {
				clearModal.showModal();
			}
			document.body.classList.add(p + '-license-modal-open');
			const focusTarget = clearCancel || clearConfirm;
			if (focusTarget) {
				focusTarget.focus();
			}
		}

		function closeClearModal() {
			if (clearModal && clearModal.open) {
				clearModal.close();
			}
			document.body.classList.remove(p + '-license-modal-open');
			restoreFocus(modalReturnFocus);
		}

		if (clearBtn) {
			clearBtn.addEventListener('click', () => openClearModal());
		}
		if (clearCancel) {
			clearCancel.addEventListener('click', () => closeClearModal());
		}
		if (clearConfirm) {
			clearConfirm.addEventListener('click', async () => {
				closeClearModal();
				if (clearBtn) {
					clearBtn.disabled = true;
				}
				try {
					// Canonical verb is DELETE (AZC + panel family); fall back to
					// POST for apps whose route accepts only POST.
					let result = await apiFetch(apiClearLicense, { method: 'DELETE', headers: headers() });
					if (result.res.status === 405) {
						result = await apiFetch(apiClearLicense, { method: 'POST', headers: headers() });
					}
					if (result.res.status === 405) {
						result = await apiFetch(apiClearLicense, { method: 'PUT', headers: headers() });
					}
					const data = result.data;
					if (isOk(data)) {
						setFlashAndReload(t('License removed.'));
						return;
					}
					showFeedback(data.message || data.error || t('Could not remove license.'), 'error');
				} catch (e) {
					showNetworkErrorUnlessAbort(e);
				}
				if (clearBtn) {
					clearBtn.disabled = false;
				}
			});
		}

		/* ── Seats: refetch helper ───────────────────────────────── */

		async function refreshSeats() {
			if (!apiSeats) {
				return;
			}
			try {
				const { data } = await apiFetch(apiSeats, {
					method: 'GET',
					headers: { requesttoken: requestToken, 'X-Requested-With': 'XMLHttpRequest' },
				});
				const norm = normalizeSeats(data);
				if (norm) {
					renderSeatRows(norm.seats);
					updateSeatCounts(norm.used, norm.limit);
				}
			} catch (e) {
				// Refetch is best-effort; the mutation itself already succeeded.
			}
		}

		function applySeatMutationResponse(data) {
			const norm = normalizeSeats(data);
			if (norm) {
				renderSeatRows(norm.seats);
				updateSeatCounts(norm.used, norm.limit);
			} else {
				refreshSeats();
			}
		}

		/* ── Seat rows ───────────────────────────────────────────── */

		function renderSeatRows(seats) {
			if (!seatListBody) {
				return;
			}
			assignedUids.clear();
			seatListBody.innerHTML = '';
			if (!seats || seats.length === 0) {
				if (seatEmpty) {
					seatEmpty.hidden = false;
				}
				return;
			}
			if (seatEmpty) {
				seatEmpty.hidden = true;
			}
			const removeLabel = t('Remove');
			const colPerson = escapeAttr(t('Person'));
			const colUserId = escapeAttr(t('User ID'));
			const colAssigned = escapeAttr(t('Assigned'));
			const colActions = escapeAttr(t('Actions'));
			seats.forEach((seat) => {
				const uid = seatUid(seat);
				if (uid) {
					assignedUids.add(uid);
				}
				const tr = document.createElement('tr');
				tr.dataset.userId = uid;
				tr.innerHTML =
					'<td data-label="' + colPerson + '">' + escapeHtml(String(seat.displayName || uid)) + '</td>' +
					'<td data-label="' + colUserId + '"><code class="' + p + '-license-user-id">' + escapeHtml(uid) + '</code></td>' +
					'<td data-label="' + colAssigned + '">' + escapeHtml(formatAssignedAt(seat.assignedAt || seat.assigned_at || '')) + '</td>' +
					'<td data-label="' + colActions + '" class="actions-cell">' +
					'<div class="' + p + '-table-actions ' + p + '-license-table-actions" role="group" aria-label="' + colActions + '">' +
					'<button type="button" class="' + p + '-license-btn ' + p + '-license-btn--danger ' + p + '-license-btn--icon ' + p + '-seat-remove" data-user-id="' + escapeAttr(uid) + '" aria-label="' + escapeAttr(removeLabel + ': ' + (seat.displayName || uid)) + '" title="' + escapeAttr(removeLabel) + '">' +
					TRASH_SVG + '</button></div></td>';
				seatListBody.appendChild(tr);
			});
		}

		/* ── Seat removal ────────────────────────────────────────── */

		if (seatListBody) {
			seatListBody.addEventListener('click', async (ev) => {
				const btn = ev.target.closest('.' + p + '-seat-remove');
				if (!btn) {
					return;
				}
				const userId = btn.dataset.userId;
				if (!userId) {
					return;
				}
				const result = await openConfirm({
					title: t('Remove mobile seat'),
					message: t('Remove mobile seat for this person?'),
					confirmLabel: t('Remove'),
					cancelLabel: t('Cancel'),
					variant: 'danger',
				});
				if (!result || result.confirmed !== true) {
					return;
				}
				btn.disabled = true;
				hideFeedback();
				try {
					let { res, data } = await apiMutate(apiRemoveSeat, { userId: userId, uid: userId });
					if (res.status === 404 || res.status === 405) {
						// Panel-family style: DELETE /seats/{uid}
						({ res, data } = await apiFetch(apiRemoveSeat.replace(/\/$/, '') + '/' + encodeURIComponent(userId), {
							method: 'DELETE',
							headers: headers(),
						}));
					}
					if (isOk(data)) {
						applySeatMutationResponse(data);
						showFeedback(t('Seat removed.'), 'success');
					} else {
						showFeedback(data.message || data.error || t('Could not remove seat.'), 'error');
					}
				} catch (e) {
					showNetworkErrorUnlessAbort(e);
				} finally {
					btn.disabled = false;
				}
			});
		}

		/* ── Seat assignment (combobox + multi-select) ───────────── */

		function updateAssignSelectedButton() {
			if (!assignSelectedBtn) {
				return;
			}
			const n = pendingSeatSelection.size;
			if (n === 0) {
				assignSelectedBtn.hidden = true;
				assignSelectedBtn.disabled = true;
				assignSelectedBtn.textContent = t('Assign selected');
				return;
			}
			assignSelectedBtn.hidden = false;
			assignSelectedBtn.disabled = false;
			assignSelectedBtn.textContent = t('Assign selected (%n)').replace(/%n/g, String(n));
		}

		function togglePendingSeat(user, checked) {
			const uid = String(user.userId || user.uid || user.id || '').trim();
			if (!uid) {
				return;
			}
			if (checked) {
				pendingSeatSelection.set(uid, { id: uid, displayName: user.displayName || uid });
			} else {
				pendingSeatSelection.delete(uid);
			}
			updateAssignSelectedButton();
		}

		function closeSearchResults() {
			if (!searchResults || !userSearch) {
				return;
			}
			searchResults.hidden = true;
			searchResults.innerHTML = '';
			userSearch.setAttribute('aria-expanded', 'false');
			userSearch.removeAttribute('aria-activedescendant');
			searchActiveIndex = -1;
		}

		function getSearchOptions() {
			return searchResults ? searchResults.querySelectorAll('li[role="option"]') : [];
		}

		function highlightSearchOption(index) {
			const options = getSearchOptions();
			options.forEach((opt, i) => {
				opt.setAttribute('aria-selected', i === index ? 'true' : 'false');
				opt.classList.toggle('is-active', i === index);
			});
			const active = options[index];
			if (active && userSearch) {
				userSearch.setAttribute('aria-activedescendant', active.id);
				active.scrollIntoView({ block: 'nearest' });
			} else if (userSearch) {
				userSearch.removeAttribute('aria-activedescendant');
			}
		}

		function renderSearchResults(users) {
			if (!searchResults || !userSearch) {
				return;
			}
			searchResults.innerHTML = '';
			searchActiveIndex = -1;
			userSearch.removeAttribute('aria-activedescendant');
			if (users.length === 0) {
				const li = document.createElement('li');
				li.className = p + '-seat-search-results__empty';
				li.textContent = t('No matching people found.');
				li.setAttribute('role', 'presentation');
				searchResults.appendChild(li);
			} else {
				users.forEach((u, index) => {
					const uid = String(u.userId || u.uid || u.id || '').trim();
					if (!uid) {
						return;
					}
					const display = String(u.displayName || u.name || uid);
					const li = document.createElement('li');
					li.setAttribute('role', 'option');
					li.setAttribute('aria-selected', pendingSeatSelection.has(uid) ? 'true' : 'false');
					li.id = p + '-seat-option-' + index;
					li.dataset.userId = uid;
					li.className = p + '-seat-search-results__option';

					const label = document.createElement('label');
					label.className = p + '-seat-search-results__label';
					const cb = document.createElement('input');
					cb.type = 'checkbox';
					cb.className = p + '-seat-search-results__check';
					cb.checked = pendingSeatSelection.has(uid);
					cb.setAttribute('aria-label', display + ' (' + uid + ')');
					cb.addEventListener('change', () => {
						togglePendingSeat({ id: uid, userId: uid, displayName: display }, cb.checked);
						li.setAttribute('aria-selected', cb.checked ? 'true' : 'false');
					});
					const text = document.createElement('span');
					text.textContent = display + ' (' + uid + ')';
					label.appendChild(cb);
					label.appendChild(text);
					li.appendChild(label);
					li.addEventListener('mousedown', (e) => {
						if (e.target !== cb) {
							e.preventDefault();
						}
					});
					searchResults.appendChild(li);
				});
			}
			searchResults.hidden = false;
			userSearch.setAttribute('aria-expanded', 'true');
			updateAssignSelectedButton();
		}

		async function assignOneSeat(userId) {
			const { data } = await apiMutate(apiSeats, { userId: userId, uid: userId, userIds: [userId] });
			return data;
		}

		async function assignSelectedSeats() {
			const userIds = Array.from(pendingSeatSelection.keys());
			if (userIds.length === 0) {
				showFeedback(t('Select at least one person.'), 'error');
				return;
			}
			const remaining = Math.max(0, lastSeatLimit - lastSeatUsed);
			let confirmMsg = fmt2(t('Assign mobile seats to %1$d people? %2$d seats remaining.'), userIds.length, remaining);
			if (userIds.length > remaining) {
				confirmMsg = fmt2(t('You selected %1$d people but only %2$d seats remain. Only free seats will be assigned.'), userIds.length, remaining);
			}
			const confirmResult = await openConfirm({
				title: t('Assign mobile seats?'),
				message: confirmMsg,
				confirmLabel: t('Assign seats'),
				cancelLabel: t('Cancel'),
				variant: 'primary',
			});
			if (!confirmResult || confirmResult.confirmed !== true) {
				return;
			}
			hideFeedback();
			if (assignSelectedBtn) {
				assignSelectedBtn.disabled = true;
			}
			try {
				let data;
				if (apiSeatsBatch) {
					({ data } = await apiMutate(apiSeatsBatch, { userIds: userIds }));
				} else {
					// Sequential fallback: assign one by one, synthesise the summary.
					const summary = { assigned: 0, skipped: 0, failed: 0 };
					let last = null;
					for (const uid of userIds) {
						last = await assignOneSeat(uid);
						if (isOk(last)) {
							summary.assigned += 1;
						} else {
							summary.failed += 1;
						}
					}
					data = { ok: summary.failed === 0 || summary.assigned > 0, summary: summary };
					if (last) {
						const norm = normalizeSeats(last);
						if (norm) {
							data.seats = norm.seats;
							data.mobileSeatsUsed = norm.used;
							data.mobileSeatsLimit = norm.limit;
						}
					}
				}
				if (isOk(data)) {
					applySeatMutationResponse(data);
					const summary = data.summary || {};
					const msg = t('Assigned %1$d, skipped %2$d, failed %3$d.')
						.replace('%1$d', String(summary.assigned ?? 1))
						.replace('%2$d', String(summary.skipped ?? 0))
						.replace('%3$d', String(summary.failed ?? 0));
					showFeedback(msg, 'success');
					if (batchResultEl) {
						batchResultEl.hidden = false;
						batchResultEl.textContent = msg;
					}
					pendingSeatSelection.clear();
					closeSearchResults();
					if (userSearch) {
						userSearch.value = '';
						userSearch.focus();
					}
					updateAssignSelectedButton();
				} else {
					showFeedback(data.message || data.error || t('Could not assign seat.'), 'error');
				}
			} catch (e) {
				showNetworkErrorUnlessAbort(e);
			} finally {
				updateAssignSelectedButton();
			}
		}

		if (assignSelectedBtn) {
			assignSelectedBtn.addEventListener('click', () => {
				assignSelectedSeats();
			});
		}

		if (userSearch && searchResults) {
			userSearch.addEventListener('input', () => {
				clearTimeout(searchTimer);
				const q = userSearch.value.trim();
				if (q.length < 2) {
					closeSearchResults();
					return;
				}
				searchTimer = setTimeout(async () => {
					if (searchAbort) {
						searchAbort.abort();
					}
					searchAbort = new AbortController();
					userSearch.setAttribute('aria-busy', 'true');
					searchResults.innerHTML = '';
					const loadingLi = document.createElement('li');
					loadingLi.className = p + '-seat-search-results__empty';
					loadingLi.textContent = t('Searching…');
					loadingLi.setAttribute('role', 'option');
					loadingLi.setAttribute('aria-disabled', 'true');
					searchResults.appendChild(loadingLi);
					searchResults.hidden = false;
					userSearch.setAttribute('aria-expanded', 'true');
					try {
						const url = apiSearchUsers + (apiSearchUsers.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q);
						const res = await fetch(url, {
							headers: { requesttoken: requestToken, 'X-Requested-With': 'XMLHttpRequest' },
							signal: searchAbort.signal,
						});
						const data = await res.json();
						const users = normalizeUsers(data).filter((u) => {
							const uid = String(u.userId || u.uid || u.id || '');
							const has = u.hasSeat === true || assignedUids.has(uid);
							return uid && !has;
						});
						renderSearchResults(users);
					} catch (e) {
						if (e && e.name === 'AbortError') {
							return;
						}
						closeSearchResults();
						showFeedback(t('Search failed. Please try again.'), 'error');
					} finally {
						userSearch.removeAttribute('aria-busy');
					}
				}, 250);
			});

			userSearch.addEventListener('keydown', (e) => {
				if (!searchResults || searchResults.hidden) {
					return;
				}
				const options = getSearchOptions();
				if (options.length === 0) {
					if (e.key === 'Escape') {
						closeSearchResults();
					}
					return;
				}
				if (e.key === 'ArrowDown') {
					e.preventDefault();
					searchActiveIndex = Math.min(searchActiveIndex + 1, options.length - 1);
					highlightSearchOption(searchActiveIndex);
				} else if (e.key === 'ArrowUp') {
					e.preventDefault();
					searchActiveIndex = Math.max(searchActiveIndex - 1, 0);
					highlightSearchOption(searchActiveIndex);
				} else if (e.key === 'Home') {
					e.preventDefault();
					searchActiveIndex = 0;
					highlightSearchOption(searchActiveIndex);
				} else if (e.key === 'End') {
					e.preventDefault();
					searchActiveIndex = options.length - 1;
					highlightSearchOption(searchActiveIndex);
				} else if (e.key === 'Enter' && searchActiveIndex >= 0) {
					e.preventDefault();
					const opt = options[searchActiveIndex];
					const cb = opt && opt.querySelector('input[type="checkbox"]');
					if (cb) {
						cb.checked = !cb.checked;
						cb.dispatchEvent(new Event('change', { bubbles: true }));
					}
				} else if (e.key === 'Escape') {
					e.preventDefault();
					closeSearchResults();
				}
			});

			userSearch.addEventListener('blur', () => {
				window.setTimeout(() => {
					if (document.activeElement !== userSearch) {
						closeSearchResults();
					}
				}, 150);
			});

			document.addEventListener('click', (e) => {
				if (!userSearch.contains(e.target) && !searchResults.contains(e.target)) {
					closeSearchResults();
				}
			});
		}

		const usedEl = id('-license-mobile-used');
		const limitEl = id('-license-mobile-limit');
		if (usedEl && limitEl) {
			lastSeatUsed = parseInt(usedEl.textContent, 10) || 0;
			lastSeatLimit = parseInt(limitEl.textContent, 10) || 0;
		}
		if (terminalUsedEl && terminalLimitEl) {
			lastTerminalUsed = parseInt(terminalUsedEl.textContent, 10) || 0;
			lastTerminalLimit = parseInt(terminalLimitEl.textContent, 10) || 0;
		}
		if (seatListBody) {
			seatListBody.querySelectorAll('tr[data-user-id]').forEach((tr) => {
				if (tr.dataset.userId) {
					assignedUids.add(tr.dataset.userId);
				}
			});
		}

		/* ── Devices: generic register form + revoke + token modal ─ */

		function collectDeviceFields() {
			const fields = { label: terminalLabel ? terminalLabel.value.trim() : '' };
			page.querySelectorAll('[data-device-field]').forEach((input) => {
				fields[input.dataset.deviceField] = input.value;
			});
			return fields;
		}

		function deviceFieldsValid() {
			let valid = true;
			page.querySelectorAll('[data-device-field][required]').forEach((input) => {
				if (!String(input.value).trim()) {
					valid = false;
				}
			});
			if (terminalLabel && !terminalLabel.value.trim()) {
				valid = false;
			}
			return valid;
		}

		function renderTerminalRows(devices) {
			if (!terminalListBody) {
				return;
			}
			terminalListBody.innerHTML = '';
			if (!devices || devices.length === 0) {
				if (terminalEmpty) {
					terminalEmpty.hidden = false;
				}
				return;
			}
			if (terminalEmpty) {
				terminalEmpty.hidden = true;
			}
			devices.forEach((dev) => {
				const devId = String(dev.id || dev.deviceId || '');
				const tr = document.createElement('tr');
				tr.dataset.deviceId = devId;
				const label = String(dev.label || dev.name || devId);
				const meta = String(dev.meta || dev.detail || dev.assetName || dev.siteName || '—');
				tr.innerHTML =
					'<td>' + escapeHtml(label) + '</td>' +
					'<td>' + escapeHtml(meta) + '</td>' +
					'<td>' + escapeHtml(formatAssignedAt(dev.registeredAt || dev.assignedAt || dev.createdAt || '')) + '</td>' +
					'<td class="actions-cell">' +
					'<div class="' + p + '-table-actions ' + p + '-license-table-actions" role="group" aria-label="' + colActions + '">' +
					'<button type="button" class="' + p + '-license-btn ' + p + '-license-btn--secondary ' + p + '-license-btn--sm ' + p + '-terminal-revoke" data-device-id="' + escapeAttr(devId) + '">' +
					escapeHtml(t('Revoke')) + '</button></div></td>';
				terminalListBody.appendChild(tr);
			});
		}

		function applyDeviceMutationResponse(data) {
			const norm = normalizeDevices(data);
			if (norm) {
				renderTerminalRows(norm.devices);
				updateTerminalCounts(norm.used, norm.limit);
			} else {
				const used = data.terminalDevicesUsed ?? data.used;
				const limit = data.terminalDevicesLimit ?? data.limit;
				if (Number.isFinite(Number(used)) && Number.isFinite(Number(limit))) {
					updateTerminalCounts(Number(used), Number(limit));
				}
			}
		}

		if (terminalRegister && apiRegisterDevice) {
			terminalRegister.addEventListener('click', async () => {
				if (!deviceFieldsValid()) {
					showFeedback(t('Please fill in all required fields.'), 'error');
					return;
				}
				hideFeedback();
				terminalRegister.disabled = true;
				try {
					const { data } = await apiMutate(apiRegisterDevice, collectDeviceFields());
					if (isOk(data)) {
						applyDeviceMutationResponse(data);
						showFeedback(t('Device registered.'), 'success');
						if (terminalLabel) {
							terminalLabel.value = '';
						}
						const payload = (data.data && typeof data.data === 'object' && !Array.isArray(data.data))
							? data.data : data;
						const tokenText = payload.token || payload.tokenText || payload.pairCode
							|| (payload.credentials ? JSON.stringify(payload.credentials, null, 2) : '')
							|| (payload.device && (payload.device.token || payload.device.secret || ''));
						if (tokenText) {
							openTokenModal(t('Device registered.'), t('This token is shown only once. Paste it into the companion app on the device.'), String(tokenText));
						}
					} else {
						showFeedback(data.message || data.error || t('Could not register device.'), 'error');
					}
				} catch (e) {
					showNetworkErrorUnlessAbort(e);
				} finally {
					terminalRegister.disabled = false;
				}
			});
		}

		if (terminalListBody) {
			terminalListBody.addEventListener('click', async (ev) => {
				const regenBtn = ev.target.closest('.' + p + '-terminal-regen');
				if (regenBtn && apiRegenDevice) {
					const regenId = regenBtn.dataset.deviceId;
					if (!regenId) {
						return;
					}
					const regenConfirm = await openConfirm({
						title: t('New pairing code'),
						message: t('Generate a new pairing code for this device? The old code stops working immediately.'),
						confirmLabel: t('Generate code'),
						cancelLabel: t('Cancel'),
						variant: 'primary',
					});
					if (!regenConfirm || regenConfirm.confirmed !== true) {
						return;
					}
					regenBtn.disabled = true;
					hideFeedback();
					try {
						const regenUrl = apiRegenDevice.indexOf('{id}') !== -1
							? apiRegenDevice.split('{id}').join(encodeURIComponent(regenId))
							: apiRegenDevice.replace(/\/$/, '') + '/' + encodeURIComponent(regenId);
						let regen = await apiMutate(regenUrl, {});
						if (regen.res.status === 404 || regen.res.status === 405) {
							regen = await apiMutate(apiRegenDevice, { deviceId: regenId, id: regenId });
						}
						if (isOk(regen.data)) {
							const rp = (regen.data.data && typeof regen.data.data === 'object' && !Array.isArray(regen.data.data))
								? regen.data.data : regen.data;
							const code = rp.pairCode || rp.token || rp.tokenText || '';
							showFeedback(t('New pairing code generated.'), 'success');
							if (code) {
								openTokenModal(t('New pairing code'), t('This token is shown only once. Paste it into the companion app on the device.'), String(code));
							}
						} else {
							showFeedback(regen.data.message || regen.data.error || t('Could not generate a pairing code.'), 'error');
						}
					} catch (e) {
						showNetworkErrorUnlessAbort(e);
					}
					regenBtn.disabled = false;
					return;
				}
				const btn = ev.target.closest('.' + p + '-terminal-revoke');
				if (!btn) {
					return;
				}
				const deviceId = btn.dataset.deviceId;
				if (!deviceId) {
					return;
				}
				const result = await openConfirm({
					title: t('Revoke device'),
					message: t('Revoke this device? The device loses access immediately.'),
					confirmLabel: t('Revoke'),
					cancelLabel: t('Cancel'),
					variant: 'danger',
				});
				if (!result || result.confirmed !== true) {
					return;
				}
				btn.disabled = true;
				hideFeedback();
				try {
					let { res, data } = await apiMutate(apiRevokeDevice, { deviceId: deviceId, id: deviceId });
					if (res.status === 404 || res.status === 405) {
						({ res, data } = await apiFetch(apiRevokeDevice.replace(/\/$/, '') + '/' + encodeURIComponent(deviceId), {
							method: 'DELETE',
							headers: headers(),
						}));
					}
					if (isOk(data)) {
						applyDeviceMutationResponse(data);
						const row = terminalListBody.querySelector('tr[data-device-id="' + deviceId.replace(/"/g, '') + '"]');
						if (row) {
							row.remove();
						}
						if (terminalListBody.children.length === 0 && terminalEmpty) {
							terminalEmpty.hidden = false;
						}
						showFeedback(t('Device revoked.'), 'success');
					} else {
						showFeedback(data.message || data.error || t('Could not revoke device.'), 'error');
					}
				} catch (e) {
					showNetworkErrorUnlessAbort(e);
				} finally {
					btn.disabled = false;
				}
			});
		}
	}

	function boot() {
		document.querySelectorAll('[data-license-root]').forEach(initLicensePage);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
