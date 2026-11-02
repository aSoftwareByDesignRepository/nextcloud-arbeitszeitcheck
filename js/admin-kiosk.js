/**
 * Admin kiosk — terminals, credentials, enrollment.
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const page = document.getElementById('azc-kiosk-page');
	if (!page) {
		return;
	}

	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const Utils = window.ArbeitszeitCheckUtils || {};
	const token = page.dataset.requesttoken || '';
	const live = document.getElementById('azc-kiosk-live');
	const alertEl = document.getElementById('azc-kiosk-alert');
	const feedback = document.getElementById('azc-kiosk-feedback');
	const FLASH_KEY = 'azc-kiosk-flash';

	let i18n = {};
	try {
		i18n = JSON.parse(page.dataset.i18n || '{}');
	} catch {
		i18n = {};
	}

	function t(key, fallback) {
		return i18n[key] || fallback || key;
	}

	function prefersReducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

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
		feedback.className = 'azc-kiosk-feedback azc-kiosk-feedback--' + (type || 'success');
		// Never scrollIntoView here. Pairing / PIN / enrollment controls sit
		// further down; yanking to the top banner after every action is friction.
		// Toast + aria-live already announce the outcome.
		if (type === 'error') {
			announce(alertEl, message);
			if (typeof Messaging.showError === 'function') {
				Messaging.showError(message);
			}
		} else {
			announce(live, message);
			if (typeof Messaging.showSuccess === 'function') {
				Messaging.showSuccess(message);
			}
		}
	}

	function setFlashAndReload(message) {
		try {
			window.sessionStorage.setItem(FLASH_KEY, message);
		} catch {
			// Best effort.
		}
		window.location.reload();
	}

	try {
		const flash = window.sessionStorage.getItem(FLASH_KEY);
		if (flash) {
			window.sessionStorage.removeItem(FLASH_KEY);
			showFeedback(flash, 'success');
		}
	} catch {
		// ignore
	}

	function headers() {
		return {
			'Content-Type': 'application/json',
			requesttoken: token,
			'X-Requested-With': 'XMLHttpRequest',
		};
	}

	function apiUrl(pattern, id) {
		return String(pattern || '').replace('__ID__', encodeURIComponent(id));
	}

	async function api(url, options) {
		const raw = options || {};
		const opts = (Utils.normalizeMutatingFetchInit
			? Utils.normalizeMutatingFetchInit(raw)
			: raw);
		let res;
		try {
			res = await fetch(url, opts);
		} catch {
			const err = new Error(t(
				'networkFailed',
				'No connection to the server. Check your network and try again.',
			));
			err.code = 'NETWORK';
			throw err;
		}
		const data = await res.json().catch(() => ({}));
		if (!res.ok) {
			const code = data.error || data.code || '';
			const mapped = mapKioskErrorCode(code);
			const msg = (data.message && String(data.message).trim())
				|| mapped
				|| (code ? String(code) : '')
				|| t(
					'requestFailedDetail',
					'The request failed (HTTP {status}). Refresh the page and try again.',
				).split('{status}').join(String(res.status));
			const err = new Error(msg);
			err.code = code || String(res.status);
			err.status = res.status;
			throw err;
		}
		return data;
	}

	function mapKioskErrorCode(code) {
		const messages = {
			KIOSK_USER_NOT_ALLOWED: t(
				'enableAccessFirst',
				'Allow kiosk access for this employee first',
			),
			KIOSK_TERMINAL_NOT_FOUND: t(
				'errTerminalNotFound',
				'Terminal not found. Refresh the page and select an active tablet.',
			),
			KIOSK_TERMINAL_NOT_ACTIVE: t(
				'errTerminalNotActive',
				'Only a paired (active) tablet can enroll badges. Finish pairing first.',
			),
			KIOSK_RFID_ALREADY_ASSIGNED: t(
				'errBadgeAssigned',
				'This badge is already assigned to another employee. Remove it there first, or use a different badge.',
			),
			KIOSK_RFID_INVALID: t(
				'errBadgeInvalid',
				'The badge could not be read. Hold it flat on the reader for 1–2 seconds and try again.',
			),
			ENROLLMENT_NOT_ACTIVE: t(
				'errEnrollmentInactive',
				'No badge scan is waiting on this tablet. Click “Scan badge at tablet” again, then hold the badge.',
			),
			KIOSK_BUSY: t(
				'errKioskBusy',
				'Another PIN or badge change is still finishing. Wait a few seconds, then try again. If a badge scan is stuck, click “Cancel scan” — that always clears it.',
			),
			KIOSK_SCAN_FAILED: t(
				'errScanFailed',
				'Badge could not be saved. Check tablet online status and kiosk access, then start the scan again.',
			),
			TERMINAL_LICENSE_REQUIRED: t(
				'errLicenseRequired',
				'A Terminal license is required. Open License administration to apply a key.',
			),
			TERMINAL_DEVICE_LIMIT_REACHED: t(
				'errDeviceLimit',
				'All terminal license slots are in use. Revoke an unused terminal or upgrade the license.',
			),
		};
		return messages[code] || '';
	}

	function escapeHtml(s) {
		const d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	/** Stable HTML id fragment from an arbitrary user id (no entity encoding). */
	function safeDomId(prefix, raw) {
		const base = String(raw || '');
		let encoded = '';
		try {
			encoded = btoa(unescape(encodeURIComponent(base))).replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
		} catch {
			encoded = encodeURIComponent(base).replace(/%/g, '_');
		}
		return String(prefix || 'azc') + '-' + encoded;
	}

	function setBusy(el, busy) {
		if (!el) {
			return;
		}
		el.disabled = !!busy;
		el.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function formatDateTime(iso) {
		if (!iso) {
			return t('neverSeen', 'Never');
		}
		try {
			const d = new Date(iso);
			if (Number.isNaN(d.getTime())) {
				return iso;
			}
			const pad = (n) => String(n).padStart(2, '0');
			return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear()
				+ ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
		} catch {
			return iso;
		}
	}

	// Format last-seen cells rendered by PHP.
	document.querySelectorAll('.azc-kiosk-last-seen[data-iso]').forEach((el) => {
		const iso = el.getAttribute('data-iso') || '';
		el.textContent = formatDateTime(iso);
	});

	let modalReturnFocus = null;
	let openModalEl = null;
	/** When PIN modal opened from a credentials row, restore focus by user id after table rebuild. */
	let pinReturnFocusUserId = '';

	function findRowPinButton(userId) {
		const uid = String(userId || '');
		if (!uid) {
			return null;
		}
		const rows = document.querySelectorAll('#azc-kiosk-creds-body tr[data-user-id]');
		for (let i = 0; i < rows.length; i++) {
			if (rows[i].getAttribute('data-user-id') === uid) {
				return rows[i].querySelector('.azc-kiosk-row-pin');
			}
		}
		return null;
	}

	function getFocusables(modal) {
		return Array.from(modal.querySelectorAll(
			'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		)).filter((el) => {
			if (el.hasAttribute('hidden') || el.getAttribute('aria-hidden') === 'true') {
				return false;
			}
			return el.offsetParent !== null || el === document.activeElement;
		});
	}

	function openModal(modal, backdrop, returnFocus) {
		if (!modal) {
			return;
		}
		modalReturnFocus = returnFocus || null;
		openModalEl = modal;
		modal.hidden = false;
		if (backdrop) {
			backdrop.hidden = false;
		}
		document.body.classList.add('azc-kiosk-modal-open');
		const focusables = getFocusables(modal);
		if (focusables[0] instanceof HTMLElement) {
			focusables[0].focus();
		}
	}

	function closeModal(modal, backdrop, reloadAfter) {
		if (!modal) {
			return;
		}
		const wasPin = modal.id === 'azc-kiosk-pin-modal';
		const focusUserId = wasPin ? pinReturnFocusUserId : '';
		modal.hidden = true;
		if (backdrop) {
			backdrop.hidden = true;
		}
		if (openModalEl === modal) {
			openModalEl = null;
		}
		document.body.classList.remove('azc-kiosk-modal-open');
		if (wasPin && typeof clearPinReveal === 'function') {
			clearPinReveal();
		}
		let restored = false;
		if (wasPin && focusUserId) {
			const rowBtn = findRowPinButton(focusUserId);
			if (rowBtn instanceof HTMLElement) {
				rowBtn.focus();
				restored = true;
			}
		}
		if (!restored && modalReturnFocus instanceof HTMLElement && document.body.contains(modalReturnFocus)) {
			modalReturnFocus.focus();
			restored = true;
		}
		if (!restored) {
			const fallback = document.getElementById('azc-kiosk-generate-pin')
				|| document.getElementById('azc-kiosk-creds-heading');
			if (fallback instanceof HTMLElement) {
				fallback.focus();
			}
		}
		modalReturnFocus = null;
		pinReturnFocusUserId = '';
		if (reloadAfter) {
			window.location.reload();
		}
	}

	function bindModal(modal, backdrop, closeBtn, reloadOnClose) {
		if (!modal) {
			return;
		}
		const close = () => closeModal(modal, backdrop, !!reloadOnClose);
		const closers = new Set();
		if (closeBtn) {
			closers.add(closeBtn);
		}
		modal.querySelectorAll('[data-azc-modal-close]').forEach((el) => closers.add(el));
		closers.forEach((btn) => {
			btn.addEventListener('click', close);
		});
		if (backdrop) {
			backdrop.addEventListener('click', close);
		}
	}

	document.addEventListener('keydown', (e) => {
		if (!openModalEl || openModalEl.hidden) {
			return;
		}
		if (e.key === 'Escape') {
			e.preventDefault();
			const modalId = openModalEl.id;
			let backdrop = null;
			let reloadAfter = false;
			if (modalId === 'azc-kiosk-pairing-modal') {
				backdrop = document.getElementById('azc-kiosk-pairing-backdrop');
				reloadAfter = true;
			} else if (modalId === 'azc-kiosk-pin-modal') {
				backdrop = document.getElementById('azc-kiosk-pin-backdrop');
			} else if (modalId === 'azc-kiosk-create-modal') {
				backdrop = document.getElementById('azc-kiosk-create-backdrop');
			}
			closeModal(openModalEl, backdrop, reloadAfter);
			return;
		}
		if (e.key === 'Tab') {
			const focusables = getFocusables(openModalEl);
			if (focusables.length === 0) {
				return;
			}
			const first = focusables[0];
			const last = focusables[focusables.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}
	});

	const pairingModal = document.getElementById('azc-kiosk-pairing-modal');
	const pairingBackdrop = document.getElementById('azc-kiosk-pairing-backdrop');
	const pairingClose = document.getElementById('azc-kiosk-pairing-close');
	bindModal(pairingModal, pairingBackdrop, pairingClose, true);

	const createModal = document.getElementById('azc-kiosk-create-modal');
	const createBackdrop = document.getElementById('azc-kiosk-create-backdrop');
	const createClose = document.getElementById('azc-kiosk-create-close');
	const openCreateBtn = document.getElementById('azc-kiosk-open-create');
	bindModal(createModal, createBackdrop, createClose, false);

	if (openCreateBtn) {
		openCreateBtn.addEventListener('click', () => {
			const labelEl = document.getElementById('azc-kiosk-terminal-label');
			if (labelEl) {
				labelEl.value = '';
			}
			openModal(createModal, createBackdrop, openCreateBtn);
			if (labelEl instanceof HTMLElement) {
				window.setTimeout(() => labelEl.focus(), 0);
			}
		});
	}

	const pinModal = document.getElementById('azc-kiosk-pin-modal');
	const pinBackdrop = document.getElementById('azc-kiosk-pin-backdrop');
	const pinClose = document.getElementById('azc-kiosk-pin-close');
	bindModal(pinModal, pinBackdrop, pinClose, false);

	const selectedUser = document.getElementById('azc-kiosk-selected-user');
	const selectedPanel = document.getElementById('azc-kiosk-selected-panel');
	const selectedName = document.getElementById('azc-kiosk-selected-name');
	const selectedAllowed = document.getElementById('azc-kiosk-selected-allowed');
	const userSearch = document.getElementById('azc-kiosk-user-search');
	const userResults = document.getElementById('azc-kiosk-user-results');

	let enrollmentPollToken = 0;
	let enrollmentTerminalId = '';
	let searchAbort = null;
	let searchTimer = null;
	let searchActiveIndex = -1;
	let credentialsLoadSeq = 0;
	let pinGenerateInFlight = false;
	let enrollmentInFlight = false;
	let enrollmentCountdownTimer = null;
	let lastPinEmployeeEmail = '';

	/** Filled once PIN modal helpers exist; used by table row buttons. */
	const pinWorkflow = {
		generateForUser: null,
	};

	function colLabels() {
		return {
			employee: t('employee', 'Employee'),
			credentials: t('credentials', 'Credentials'),
			allowed: t('kioskAllowedLabel', 'Allow kiosk access'),
			actions: t('actions', 'Actions'),
		};
	}

	function typeLabel(type) {
		if (type === 'pin') {
			return t('typePin', 'PIN');
		}
		if (type === 'rfid') {
			return t('typeRfid', 'Badge');
		}
		return type;
	}

	function groupCredentials(creds) {
		const map = new Map();
		creds.forEach((c) => {
			if (!map.has(c.userId)) {
				map.set(c.userId, {
					userId: c.userId,
					displayName: c.displayName,
					kioskAllowed: !!c.kioskAllowed,
					items: [],
				});
			}
			const row = map.get(c.userId);
			row.kioskAllowed = !!c.kioskAllowed;
			row.items.push(c);
		});
		return Array.from(map.values());
	}

	async function loadCredentials() {
		const tbody = document.getElementById('azc-kiosk-creds-body');
		if (!tbody) {
			return;
		}
		const seq = ++credentialsLoadSeq;
		const data = await api(page.dataset.apiCredentials || '', { headers: headers() });
		if (seq !== credentialsLoadSeq) {
			return;
		}
		const creds = (data.data && data.data.credentials) || [];
		const groups = groupCredentials(creds);
		const labels = colLabels();
		const allowedLabel = t('kioskAllowedLabel', 'Allow kiosk access');

		if (groups.length === 0) {
			tbody.innerHTML = '<tr class="azc-kiosk-empty-row"><td class="azc-kiosk-empty-cell" colspan="4">'
				+ escapeHtml(t('noCredentials', 'No badges or PINs yet. Select an employee above to get started.'))
				+ '</td></tr>';
			return;
		}

		tbody.innerHTML = groups.map((g) => {
			const toggleId = safeDomId('azc-kiosk-allowed-user', g.userId);
			const hasPin = g.items.some((c) => c.type === 'pin');
			const tags = g.items.map((c) =>
				'<span class="azc-badge azc-badge--neutral">' + escapeHtml(typeLabel(c.type)) + '</span>'
			).join('');
			const deleteActions = g.items.map((c) => {
				const btnLabel = c.type === 'pin'
					? t('deletePin', 'Delete PIN')
					: t('deleteBadge', 'Delete badge');
				const who = g.displayName || g.userId;
				const glyph = (window.AzcCatalog && window.AzcCatalog.render) ? window.AzcCatalog.render('trash') : '';
				return '<button type="button" class="azc-btn azc-btn--danger azc-icon-btn azc-kiosk-delete-cred" data-id="'
					+ escapeHtml(String(c.id)) + '" aria-label="' + escapeHtml(btnLabel + ': ' + who) + '" title="' + escapeHtml(btnLabel) + '">'
					+ glyph + '</button>';
			}).join('');
			const pinBtnLabel = hasPin
				? t('newPin', 'New PIN')
				: t('generatePin', 'Generate PIN');
			const pinDisabled = (g.kioskAllowed && !pinGenerateInFlight) ? '' : ' disabled aria-disabled="true"';
			const pinTitle = g.kioskAllowed
				? pinBtnLabel
				: t('enableAccessFirst', 'Allow kiosk access for this employee first');
			const pinGlyph = (window.AzcCatalog && window.AzcCatalog.render) ? window.AzcCatalog.render('key') : '';
			const pinAction = '<button type="button" class="azc-btn azc-btn--secondary azc-icon-btn azc-kiosk-row-pin"'
				+ ' data-user-id="' + escapeHtml(g.userId) + '"'
				+ ' data-display-name="' + escapeHtml(g.displayName || g.userId) + '"'
				+ ' data-has-pin="' + (hasPin ? '1' : '0') + '"'
				+ ' data-kiosk-allowed="' + (g.kioskAllowed ? '1' : '0') + '"'
				+ pinDisabled
				+ ' title="' + escapeHtml(pinTitle) + '"'
				+ ' aria-label="' + escapeHtml(pinBtnLabel + ': ' + (g.displayName || g.userId)) + '">'
				+ pinGlyph + '</button>';
			const actions = pinAction + deleteActions;
			return '<tr data-user-id="' + escapeHtml(g.userId) + '">'
				+ '<td data-label="' + escapeHtml(labels.employee) + '">'
				+ escapeHtml(g.displayName) + ' <small>(' + escapeHtml(g.userId) + ')</small></td>'
				+ '<td data-label="' + escapeHtml(labels.credentials) + '"><div class="azc-kiosk-cred-tags">' + tags + '</div></td>'
				+ '<td data-label="' + escapeHtml(labels.allowed) + '">'
				+ '<label class="azc-kiosk-allowed-toggle" for="' + toggleId + '">'
				+ '<input type="checkbox" class="azc-kiosk-allowed-input" id="' + toggleId + '"'
				+ ' data-user-id="' + escapeHtml(g.userId) + '"' + (g.kioskAllowed ? ' checked' : '') + '>'
				+ '<span class="azc-sr-only">' + escapeHtml(allowedLabel) + ' — ' + escapeHtml(g.displayName) + '</span>'
				+ '</label></td>'
				+ '<td class="actions-cell" data-label="' + escapeHtml(labels.actions) + '"><div class="azc-kiosk-cred-actions azc-table-actions" role="group" aria-label="' + escapeHtml(labels.actions) + '">' + actions + '</div></td>'
				+ '</tr>';
		}).join('');

		tbody.querySelectorAll('.azc-kiosk-row-pin').forEach((btn) => {
			btn.addEventListener('click', async () => {
				if (typeof pinWorkflow.generateForUser !== 'function') {
					return;
				}
				const userId = btn.getAttribute('data-user-id') || '';
				const displayName = btn.getAttribute('data-display-name') || userId;
				const hasPin = btn.getAttribute('data-has-pin') === '1';
				const kioskAllowed = btn.getAttribute('data-kiosk-allowed') === '1';
				await pinWorkflow.generateForUser(userId, displayName, {
					hasPin,
					kioskAllowed,
					triggerBtn: btn,
				});
			});
		});

		tbody.querySelectorAll('.azc-kiosk-delete-cred').forEach((btn) => {
			btn.addEventListener('click', async () => {
				const id = btn.getAttribute('data-id');
				const confirmed = Utils.confirmDestructiveAction
					? await Utils.confirmDestructiveAction({
						title: t('confirmDeleteCredTitle', 'Remove credential'),
						message: t('confirmDeleteCred', 'Remove this credential?'),
						confirmLabel: t('Remove', 'Remove'),
						cancelLabel: t('Cancel', 'Cancel'),
						variant: 'danger',
					})
					: null;
				if (!confirmed) {
					return;
				}
				setBusy(btn, true);
				try {
					await api((page.dataset.apiCredentials || '') + '/' + id, { method: 'DELETE', headers: headers() });
					showFeedback(t('credentialRemoved', 'Credential removed'), 'success');
					await loadCredentials();
				} catch (e) {
					showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
					setBusy(btn, false);
				}
			});
		});

		tbody.querySelectorAll('.azc-kiosk-allowed-input').forEach((input) => {
			input.addEventListener('change', async () => {
				const userId = input.getAttribute('data-user-id') || '';
				const allowed = input.checked;
				input.disabled = true;
				try {
					await api(apiUrl(page.dataset.apiUserAllowed || '', userId), {
						method: 'PUT',
						headers: headers(),
						body: JSON.stringify({ kioskAllowed: allowed }),
					});
					showFeedback(
						allowed ? t('kioskAllowedOn', 'Kiosk access enabled') : t('kioskAllowedOff', 'Kiosk access disabled'),
						'success',
					);
					if (selectedUser && selectedUser.value === userId && selectedAllowed) {
						selectedAllowed.checked = allowed;
					}
					const row = input.closest('tr');
					const rowPinBtn = row ? row.querySelector('.azc-kiosk-row-pin') : null;
					if (rowPinBtn) {
						rowPinBtn.setAttribute('data-kiosk-allowed', allowed ? '1' : '0');
						rowPinBtn.disabled = !allowed || pinGenerateInFlight;
						rowPinBtn.setAttribute('aria-disabled', (!allowed || pinGenerateInFlight) ? 'true' : 'false');
						if (allowed) {
							rowPinBtn.removeAttribute('title');
						} else {
							rowPinBtn.setAttribute(
								'title',
								t('enableAccessFirst', 'Allow kiosk access for this employee first'),
							);
						}
					}
				} catch (e) {
					input.checked = !allowed;
					showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
				} finally {
					input.disabled = false;
				}
			});
		});
	}

	function bindRevokeButtons(scope) {
		const root = scope || document;
		root.querySelectorAll('.azc-kiosk-revoke-terminal').forEach((btn) => {
			if (btn.dataset.bound === '1') {
				return;
			}
			btn.dataset.bound = '1';
			btn.addEventListener('click', async () => {
				const terminalId = btn.getAttribute('data-terminal-id') || '';
				if (!terminalId) {
					return;
				}
				const confirmed = Utils.confirmDestructiveAction
					? await Utils.confirmDestructiveAction({
						title: t('confirmRevokeTitle', 'Revoke terminal'),
						message: t('confirmRevoke', 'Revoke this terminal?'),
						confirmLabel: t('Revoke', 'Revoke'),
						cancelLabel: t('Cancel', 'Cancel'),
						variant: 'danger',
					})
					: null;
				if (!confirmed) {
					return;
				}
				setBusy(btn, true);
				try {
					await api(apiUrl(page.dataset.apiTerminalRevoke || '', terminalId), {
						method: 'POST',
						headers: headers(),
					});
					setFlashAndReload(t('terminalRevoked', 'Terminal revoked'));
				} catch (e) {
					showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
					setBusy(btn, false);
				}
			});
		});
	}

	function syncOverviewMode(enabled) {
		const modeEl = document.getElementById('azc-kiosk-overview-mode');
		const statEl = document.getElementById('azc-kiosk-stat-mode');
		if (modeEl) {
			modeEl.textContent = enabled ? t('overviewOn', 'On') : t('overviewOff', 'Off');
		}
		if (statEl) {
			statEl.classList.toggle('azc-kiosk-stat--on', enabled);
			statEl.classList.toggle('azc-kiosk-stat--off', !enabled);
		}
	}

	const enabledToggle = document.getElementById('azc-kiosk-enabled');
	if (enabledToggle) {
		enabledToggle.addEventListener('change', async () => {
			const wanted = enabledToggle.checked;
			enabledToggle.disabled = true;
			try {
				await api(page.dataset.apiEnabled || '', {
					method: 'POST',
					headers: headers(),
					body: JSON.stringify({ enabled: wanted }),
				});
				syncOverviewMode(wanted);
				showFeedback(
					wanted ? t('kioskEnabled', 'Kiosk enabled') : t('kioskDisabled', 'Kiosk disabled'),
					'success',
				);
			} catch (e) {
				enabledToggle.checked = !wanted;
				showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
			} finally {
				enabledToggle.disabled = false;
			}
		});
	}

	const createBtn = document.getElementById('azc-kiosk-create-terminal');
	if (createBtn) {
		const submitCreate = async () => {
			const labelEl = document.getElementById('azc-kiosk-terminal-label');
			const label = labelEl ? labelEl.value.trim() : '';
			if (!label) {
				showFeedback(t('labelRequired', 'Enter a terminal label'), 'error');
				if (labelEl) {
					labelEl.focus();
				}
				return;
			}
			setBusy(createBtn, true);
			try {
				const data = await api(page.dataset.apiTerminals || '', {
					method: 'POST',
					headers: headers(),
					body: JSON.stringify({ label }),
				});
				const codeEl = document.getElementById('azc-kiosk-pairing-code');
				const expiresEl = document.getElementById('azc-kiosk-pairing-expires');
				if (codeEl && data.data) {
					codeEl.textContent = data.data.pairingCode || '';
				}
				if (expiresEl && data.data && data.data.pairingExpiresAt) {
					expiresEl.hidden = false;
					expiresEl.textContent = t('pairingExpires', 'Valid until') + ': '
						+ formatDateTime(data.data.pairingExpiresAt);
				}
				if (labelEl) {
					labelEl.value = '';
				}
				closeModal(createModal, createBackdrop, false);
				openModal(pairingModal, pairingBackdrop, openCreateBtn || createBtn);
				showFeedback(t('terminalCreated', 'Terminal created — save the pairing code'), 'success');
			} catch (e) {
				showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
			} finally {
				setBusy(createBtn, false);
			}
		};

		createBtn.addEventListener('click', () => {
			void submitCreate();
		});

		const labelInput = document.getElementById('azc-kiosk-terminal-label');
		if (labelInput) {
			labelInput.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
					void submitCreate();
				}
			});
		}
	}

	function selectEmployee(userId, displayName, kioskAllowed, email) {
		if (selectedUser) {
			selectedUser.value = userId;
		}
		if (userSearch) {
			userSearch.value = displayName;
			userSearch.setAttribute('aria-expanded', 'false');
			userSearch.removeAttribute('aria-activedescendant');
		}
		if (userResults) {
			userResults.hidden = true;
			userResults.innerHTML = '';
		}
		searchActiveIndex = -1;
		if (selectedPanel) {
			selectedPanel.hidden = !userId;
		}
		if (selectedName) {
			selectedName.textContent = displayName + (userId ? ' (' + userId + ')' : '');
		}
		if (selectedAllowed) {
			selectedAllowed.checked = !!kioskAllowed;
			selectedAllowed.disabled = !userId;
		}
		lastPinEmployeeEmail = String(email || '').trim();
		updateWizardUi();
	}

	function setStepActive(stepEl, active) {
		if (!stepEl) {
			return;
		}
		stepEl.classList.toggle('azc-kiosk-flow__block--muted', !active);
		stepEl.classList.toggle('azc-kiosk-wizard__step--muted', !active); // legacy class if present
		stepEl.setAttribute('aria-disabled', active ? 'false' : 'true');
	}

	function updateWizardUi() {
		const userId = selectedUser ? String(selectedUser.value || '').trim() : '';
		const allowed = !!(selectedAllowed && selectedAllowed.checked);
		const stepAllow = document.getElementById('azc-kiosk-step-allow');
		const stepAssign = document.getElementById('azc-kiosk-step-assign');
		const assignGrid = document.getElementById('azc-kiosk-assign-grid');
		const allowHint = document.getElementById('azc-kiosk-step-allow-hint');
		const assignHint = document.getElementById('azc-kiosk-step-assign-hint');
		const pinBtnEl = document.getElementById('azc-kiosk-generate-pin');
		const enrollBtnEl = document.getElementById('azc-kiosk-start-enrollment');
		const terminalSelect = document.getElementById('azc-kiosk-enroll-terminal');

		setStepActive(stepAllow, !!userId);
		setStepActive(stepAssign, !!userId && allowed);

		if (allowHint) {
			if (userId) {
				allowHint.hidden = true;
				allowHint.textContent = '';
			} else {
				allowHint.hidden = false;
				allowHint.textContent = t('selectEmployee', 'Select an employee first');
			}
		}
		if (assignHint) {
			if (!userId) {
				assignHint.textContent = t('selectEmployee', 'Select an employee first');
			} else if (!allowed) {
				assignHint.textContent = t('stepAssignHintBlocked', 'Allow kiosk access for this employee first, then choose PIN or badge scan.');
			} else {
				assignHint.textContent = t('stepAssignHintReady', 'Create a PIN or start a badge scan. You only need one method — or both.');
			}
		}
		if (assignGrid) {
			assignGrid.hidden = !(userId && allowed);
		}

		const canAct = !!userId && allowed && !enrollmentInFlight && !pinGenerateInFlight;
		if (pinBtnEl) {
			pinBtnEl.disabled = !canAct;
			pinBtnEl.setAttribute('aria-disabled', canAct ? 'false' : 'true');
		}
		const hasTerminal = terminalSelect
			? String(terminalSelect.value || '').trim() !== ''
			: false;
		if (enrollBtnEl) {
			const canScan = canAct && hasTerminal && !enrollmentInFlight;
			enrollBtnEl.disabled = !canScan;
			enrollBtnEl.setAttribute('aria-disabled', canScan ? 'false' : 'true');
		}
		// Cancel must stay available whenever a tablet is selected — not only during
		// an in-page poll. Page reload / failed start must still clear a stuck server scan.
		const cancelBtnEl = document.getElementById('azc-kiosk-cancel-enrollment');
		if (cancelBtnEl) {
			cancelBtnEl.hidden = !hasTerminal;
		}
	}

	function renderSearchResults(users) {
		if (!userResults) {
			return;
		}
		searchActiveIndex = -1;
		if (users.length === 0) {
			userResults.hidden = true;
			userResults.innerHTML = '';
			if (userSearch) {
				userSearch.setAttribute('aria-expanded', 'false');
			}
			return;
		}
		userResults.innerHTML = users.map((u, idx) =>
			'<li role="option" id="azc-kiosk-user-opt-' + idx + '" data-id="' + escapeHtml(u.userId)
			+ '" data-allowed="' + (u.kioskAllowed ? '1' : '0') + '"'
			+ ' data-email="' + escapeHtml(u.email || '') + '"'
			+ ' data-name="' + escapeHtml(u.displayName || '') + '">'
			+ '<button type="button" class="azc-kiosk-search__option" tabindex="-1">'
			+ '<span class="azc-kiosk-search__option-name">' + escapeHtml(u.displayName) + '</span>'
			+ '<span class="azc-kiosk-search__option-meta">' + escapeHtml(u.userId) + '</span>'
			+ '</button></li>'
		).join('');
		userResults.hidden = false;
		if (userSearch) {
			userSearch.setAttribute('aria-expanded', 'true');
		}
		userResults.querySelectorAll('.azc-kiosk-search__option').forEach((btn) => {
			btn.addEventListener('mousedown', (e) => {
				e.preventDefault();
				const li = btn.closest('li');
				if (!li) {
					return;
				}
				selectEmployee(
					li.getAttribute('data-id') || '',
					li.getAttribute('data-name') || '',
					li.getAttribute('data-allowed') === '1',
					li.getAttribute('data-email') || '',
				);
			});
		});
	}

	function moveSearchHighlight(delta) {
		if (!userResults || userResults.hidden) {
			return;
		}
		const options = Array.from(userResults.querySelectorAll('[role="option"]'));
		if (options.length === 0) {
			return;
		}
		searchActiveIndex = (searchActiveIndex + delta + options.length) % options.length;
		options.forEach((opt, i) => {
			opt.setAttribute('aria-selected', i === searchActiveIndex ? 'true' : 'false');
		});
		const active = options[searchActiveIndex];
		if (userSearch && active) {
			userSearch.setAttribute('aria-activedescendant', active.id);
			active.scrollIntoView({ block: 'nearest' });
		}
	}

	if (userSearch && userResults) {
		userSearch.addEventListener('input', () => {
			if (selectedUser) {
				selectedUser.value = '';
			}
			if (selectedPanel) {
				selectedPanel.hidden = true;
			}
			updateWizardUi();
			clearTimeout(searchTimer);
			searchTimer = setTimeout(async () => {
				const q = userSearch.value.trim();
				if (q.length < 2) {
					userResults.hidden = true;
					userResults.innerHTML = '';
					userSearch.setAttribute('aria-expanded', 'false');
					return;
				}
				if (searchAbort) {
					searchAbort.abort();
				}
				searchAbort = new AbortController();
				try {
					const data = await api(
						(page.dataset.apiSearchUsers || '') + '?q=' + encodeURIComponent(q),
						{ headers: headers(), signal: searchAbort.signal },
					);
					renderSearchResults(data.users || []);
				} catch (e) {
					if (e && e.name === 'AbortError') {
						return;
					}
					showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
				}
			}, 250);
		});

		userSearch.addEventListener('keydown', (e) => {
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				moveSearchHighlight(1);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				moveSearchHighlight(-1);
			} else if (e.key === 'Enter') {
				if (searchActiveIndex >= 0 && userResults) {
					const opt = userResults.querySelectorAll('[role="option"]')[searchActiveIndex];
					if (opt) {
						e.preventDefault();
						selectEmployee(
							opt.getAttribute('data-id') || '',
							opt.getAttribute('data-name') || '',
							opt.getAttribute('data-allowed') === '1',
							opt.getAttribute('data-email') || '',
						);
					}
				}
			} else if (e.key === 'Escape') {
				userResults.hidden = true;
				userSearch.setAttribute('aria-expanded', 'false');
			}
		});
	}

	if (selectedAllowed) {
		selectedAllowed.addEventListener('change', async () => {
			const userId = selectedUser ? selectedUser.value : '';
			if (!userId) {
				selectedAllowed.checked = false;
				showFeedback(t('selectEmployee', 'Select an employee first'), 'error');
				updateWizardUi();
				return;
			}
			const allowed = selectedAllowed.checked;
			updateWizardUi();
			selectedAllowed.disabled = true;
			try {
				await api(apiUrl(page.dataset.apiUserAllowed || '', userId), {
					method: 'PUT',
					headers: headers(),
					body: JSON.stringify({ kioskAllowed: allowed }),
				});
				showFeedback(
					allowed ? t('kioskAllowedOn', 'Kiosk access enabled') : t('kioskAllowedOff', 'Kiosk access disabled'),
					'success',
				);
				await loadCredentials();
			} catch (e) {
				selectedAllowed.checked = !allowed;
				showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
			} finally {
				selectedAllowed.disabled = false;
				updateWizardUi();
			}
		});
	}

	function stopEnrollmentCountdown() {
		if (enrollmentCountdownTimer) {
			window.clearInterval(enrollmentCountdownTimer);
			enrollmentCountdownTimer = null;
		}
		const timerEl = document.getElementById('azc-kiosk-enrollment-timer');
		if (timerEl) {
			timerEl.hidden = true;
			timerEl.textContent = '';
		}
	}

	function formatRemaining(ms) {
		const totalSec = Math.max(0, Math.ceil(ms / 1000));
		const m = Math.floor(totalSec / 60);
		const s = totalSec % 60;
		return m + ':' + String(s).padStart(2, '0');
	}

	function startEnrollmentCountdown(expiresAtIso) {
		stopEnrollmentCountdown();
		const timerEl = document.getElementById('azc-kiosk-enrollment-timer');
		if (!timerEl || !expiresAtIso) {
			return;
		}
		const ends = Date.parse(expiresAtIso);
		if (Number.isNaN(ends)) {
			return;
		}
		const tick = () => {
			const left = ends - Date.now();
			timerEl.hidden = false;
			timerEl.textContent = String(t('enrollmentTimer', 'Time left: {time}'))
				.split('{time}').join(formatRemaining(left));
			if (left <= 0) {
				stopEnrollmentCountdown();
			}
		};
		tick();
		enrollmentCountdownTimer = window.setInterval(tick, 1000);
	}

	/**
	 * @param {'waiting'|'success'|'error'|'cancelled'|'idle'} kind
	 * @param {string} title
	 * @param {string} body
	 * @param {{ showSteps?: boolean, expiresAt?: string }} [opts]
	 */
	function setEnrollmentPanel(kind, title, body, opts) {
		const panel = document.getElementById('azc-kiosk-enrollment-panel');
		const titleEl = document.getElementById('azc-kiosk-enrollment-title');
		const statusEl = document.getElementById('azc-kiosk-enrollment-status');
		const stepsEl = document.getElementById('azc-kiosk-enrollment-steps');
		if (!panel) {
			return;
		}
		const options = opts || {};
		if (kind === 'idle') {
			panel.hidden = true;
			panel.className = 'azc-kiosk-enrollment-panel';
			stopEnrollmentCountdown();
			if (stepsEl) {
				stepsEl.hidden = true;
			}
			if (titleEl) {
				titleEl.textContent = '';
			}
			if (statusEl) {
				statusEl.textContent = '';
			}
			return;
		}
		panel.hidden = false;
		panel.className = 'azc-kiosk-enrollment-panel azc-kiosk-enrollment-panel--' + kind;
		if (titleEl) {
			titleEl.textContent = title || '';
		}
		if (statusEl) {
			statusEl.textContent = body || '';
		}
		if (stepsEl) {
			stepsEl.hidden = !options.showSteps;
		}
		if (kind === 'waiting' && options.expiresAt) {
			startEnrollmentCountdown(options.expiresAt);
		} else if (kind !== 'waiting') {
			stopEnrollmentCountdown();
		}
	}

	function stopEnrollmentPoll() {
		enrollmentPollToken += 1;
		enrollmentTerminalId = '';
		enrollmentInFlight = false;
		const enrollBtnEl = document.getElementById('azc-kiosk-start-enrollment');
		if (enrollBtnEl) {
			setBusy(enrollBtnEl, false);
		}
		updateWizardUi();
	}

	async function pollEnrollment(terminalId) {
		const myToken = ++enrollmentPollToken;
		enrollmentTerminalId = terminalId;
		enrollmentInFlight = true;
		updateWizardUi();

		// Server TTL is 300s. Poll slightly longer so Admin never expires early.
		const intervalMs = 2000;
		const deadline = Date.now() + 310000;
		let first = true;
		while (Date.now() < deadline) {
			if (myToken !== enrollmentPollToken) {
				return;
			}
			if (!first) {
				await new Promise((r) => setTimeout(r, intervalMs));
			} else {
				await new Promise((r) => setTimeout(r, 800));
			}
			first = false;
			if (myToken !== enrollmentPollToken) {
				return;
			}
			try {
				const data = await api(
					(page.dataset.apiEnrollmentStatus || '') + '?terminalId=' + encodeURIComponent(terminalId),
					{ headers: headers() },
				);
				const st = (data.data && data.data.status) || '';
				const lastErrMsg = (data.data && data.data.lastErrorMessage)
					? String(data.data.lastErrorMessage)
					: '';
				if (lastErrMsg && st === 'pending') {
					setEnrollmentPanel(
						'error',
						t('enrollmentScanProblem', 'Problem while scanning'),
						lastErrMsg + ' '
							+ t('enrollmentScanRetryHint', 'The scan is still open — hold the badge again, or cancel and restart.'),
						{ showSteps: true, expiresAt: data.data.expiresAt || '' },
					);
					// Keep polling; a successful retry on the tablet can still complete.
				}
				if (st === 'completed') {
					setEnrollmentPanel(
						'success',
						t('enrollmentDoneTitle', 'Badge saved'),
						t('enrollmentDone', 'Badge assigned successfully'),
					);
					showFeedback(t('enrollmentDone', 'Badge assigned successfully'), 'success');
					stopEnrollmentPoll();
					await loadCredentials();
					return;
				}
				if (st === 'expired') {
					setEnrollmentPanel(
						'error',
						t('enrollmentExpiredTitle', 'Scan expired'),
						t('enrollmentExpired', 'Scan timed out. Click “Scan badge at tablet” to try again.'),
					);
					showFeedback(
						t('enrollmentExpired', 'Scan timed out. Click “Scan badge at tablet” to try again.'),
						'error',
					);
					stopEnrollmentPoll();
					return;
				}
				if (st === 'pending' && data.data && data.data.expiresAt) {
					startEnrollmentCountdown(data.data.expiresAt);
				}
			} catch (e) {
				if (Date.now() + intervalMs >= deadline) {
					const msg = e instanceof Error ? e.message : t('requestFailed', 'Request failed');
					setEnrollmentPanel('error', t('requestFailed', 'Request failed'), msg);
					showFeedback(msg, 'error');
					stopEnrollmentPoll();
					return;
				}
			}
		}
		if (myToken === enrollmentPollToken) {
			setEnrollmentPanel(
				'error',
				t('enrollmentExpiredTitle', 'Scan expired'),
				t('enrollmentExpired', 'Scan timed out. Click “Scan badge at tablet” to try again.'),
			);
			stopEnrollmentPoll();
		}
	}

	const enrollBtn = document.getElementById('azc-kiosk-start-enrollment');
	if (enrollBtn) {
		enrollBtn.addEventListener('click', async () => {
			const userId = selectedUser ? selectedUser.value : '';
			const terminalSelect = document.getElementById('azc-kiosk-enroll-terminal');
			const terminalId = terminalSelect ? String(terminalSelect.value || '').trim() : '';
			const terminalLabel = terminalSelect && terminalSelect.selectedIndex >= 0
				? String(terminalSelect.options[terminalSelect.selectedIndex].text || '').trim()
				: '';

			if (enrollmentInFlight) {
				showFeedback(t('enrollmentBusy', 'A badge scan is already open for this tablet. Click “Cancel scan” first, then start again.'), 'error');
				return;
			}
			if (!userId) {
				showFeedback(t('selectEmployee', 'Select an employee first'), 'error');
				if (userSearch) {
					userSearch.focus();
				}
				return;
			}
			if (selectedAllowed && !selectedAllowed.checked) {
				showFeedback(t('enableAccessFirst', 'Allow kiosk access for this employee first'), 'error');
				if (selectedAllowed) {
					selectedAllowed.focus();
				}
				return;
			}
			if (!terminalSelect) {
				showFeedback(t('enrollmentNoTerminal', 'No active tablet yet. Pair a terminal first.'), 'error');
				return;
			}
			if (!terminalId) {
				showFeedback(t('selectTerminal', 'Select a tablet for the scan'), 'error');
				terminalSelect.focus();
				return;
			}

			setBusy(enrollBtn, true);
			enrollmentInFlight = true;
			try {
				const data = await api(page.dataset.apiEnrollmentStart || '', {
					method: 'POST',
					headers: headers(),
					body: JSON.stringify({ userId, terminalId }),
				});
				const expiresAt = data.data && data.data.expiresAt
					? String(data.data.expiresAt)
					: '';
				const bodyTpl = t(
					'enrollmentWaitingBody',
					'Go to “{terminal}” and hold the badge on the reader. This page updates by itself.',
				);
				const body = String(bodyTpl).split('{terminal}').join(terminalLabel || terminalId);
				setEnrollmentPanel(
					'waiting',
					t('enrollmentWaitingTitle', 'Scan in progress'),
					body,
					{ showSteps: true, expiresAt: expiresAt },
				);
				showFeedback(t('enrollmentWaiting', 'Waiting for badge scan on the tablet…'), 'success');
				pollEnrollment(terminalId);
			} catch (e) {
				enrollmentInFlight = false;
				setBusy(enrollBtn, false);
				const msg = e instanceof Error ? e.message : t('requestFailed', 'Request failed');
				setEnrollmentPanel('error', t('requestFailed', 'Request failed'), msg);
				showFeedback(msg, 'error');
				updateWizardUi();
			}
		});
	}

	const cancelEnrollBtn = document.getElementById('azc-kiosk-cancel-enrollment');
	if (cancelEnrollBtn) {
		cancelEnrollBtn.addEventListener('click', async () => {
			const terminalId = String(
				enrollmentTerminalId
				|| (document.getElementById('azc-kiosk-enroll-terminal') || {}).value
				|| '',
			).trim();
			if (!terminalId) {
				stopEnrollmentPoll();
				setEnrollmentPanel('idle', '', '');
				return;
			}

			// Stop polling first so a late "expired/completed" response cannot fight the cancel UX.
			const cancelToken = ++enrollmentPollToken;
			enrollmentInFlight = true;
			setBusy(cancelEnrollBtn, true);
			setEnrollmentPanel(
				'waiting',
				t('enrollmentCancelling', 'Stopping the scan…'),
				t('enrollmentCancelling', 'Stopping the scan…'),
			);

			const maxAttempts = 3;
			let lastError = null;
			try {
				for (let attempt = 1; attempt <= maxAttempts; attempt++) {
					if (cancelToken !== enrollmentPollToken) {
						return;
					}
					try {
						const data = await api(page.dataset.apiEnrollmentCancel || '', {
							method: 'POST',
							headers: headers(),
							body: JSON.stringify({ terminalId }),
						});
						const status = (data.data && data.data.status) || 'cancelled';
						const forced = !!(data.data && data.data.forced);
						if (status === 'already_completed') {
							setEnrollmentPanel(
								'success',
								t('enrollmentAlreadyDoneTitle', 'Badge already saved'),
								t(
									'enrollmentAlreadyDone',
									'The badge was already saved on the tablet before cancel finished.',
								),
							);
							showFeedback(
								t(
									'enrollmentAlreadyDone',
									'The badge was already saved on the tablet before cancel finished.',
								),
								'success',
							);
							stopEnrollmentPoll();
							await loadCredentials();
							return;
						}
						setEnrollmentPanel(
							'cancelled',
							t('enrollmentCancelledTitle', 'Cancelled'),
							t(
								'enrollmentCancelledBody',
								'The badge scan was stopped. Nothing was saved. You can start again whenever you are ready.',
							),
						);
						showFeedback(
							forced
								? t('enrollmentCancelForced', 'Cleared a stuck scan so you can start again.')
								: t('enrollmentCancelled', 'Scan cancelled'),
							'success',
						);
						stopEnrollmentPoll();
						return;
					} catch (e) {
						lastError = e;
						const code = e && e.code ? String(e.code) : '';
						const busy = code === 'KIOSK_BUSY' || (e && e.status === 409);
						if (busy && attempt < maxAttempts) {
							await new Promise((r) => setTimeout(r, 200 * attempt));
							continue;
						}
						throw e;
					}
				}
				if (lastError) {
					throw lastError;
				}
			} catch (e) {
				const msg = e instanceof Error ? e.message : t('requestFailed', 'Request failed');
				setEnrollmentPanel(
					'error',
					t('requestFailed', 'Request failed'),
					msg,
				);
				showFeedback(msg, 'error');
				// Keep Cancel available via updateWizardUi; do not re-enter a stuck poll loop.
				enrollmentInFlight = false;
				updateWizardUi();
			} finally {
				setBusy(cancelEnrollBtn, false);
			}
		});
	}

	const pinBtn = document.getElementById('azc-kiosk-generate-pin');
	const pinCopyBtn = document.getElementById('azc-kiosk-pin-copy');
	const pinShareBtn = document.getElementById('azc-kiosk-pin-share');
	const pinEmailLink = document.getElementById('azc-kiosk-pin-email');
	const pinShareStatus = document.getElementById('azc-kiosk-pin-share-status');
	let lastGeneratedPin = '';
	let lastPinEmployeeLabel = '';

	function clearPinReveal() {
		lastGeneratedPin = '';
		lastPinEmployeeLabel = '';
		const codeEl = document.getElementById('azc-kiosk-pin-code');
		if (codeEl) {
			codeEl.textContent = '';
		}
		setPinShareStatus('', '');
		if (pinEmailLink) {
			pinEmailLink.hidden = true;
			pinEmailLink.setAttribute('href', '#');
			pinEmailLink.removeAttribute('aria-disabled');
		}
		if (pinShareBtn) {
			pinShareBtn.hidden = true;
		}
		if (pinCopyBtn) {
			pinCopyBtn.textContent = t('copyPin', 'Copy PIN');
		}
	}

	function setPinShareStatus(message, kind) {
		if (!pinShareStatus) {
			return;
		}
		pinShareStatus.textContent = message || '';
		pinShareStatus.classList.toggle('is-success', kind === 'success');
		pinShareStatus.classList.toggle('is-error', kind === 'error');
	}

	function buildPinShareText(pin) {
		const rawName = String(lastPinEmployeeLabel || '').trim();
		// Strip trailing " (userId)" from the selected-panel label when present.
		const name = rawName.replace(/\s*\([^)]*\)\s*$/, '').trim();
		const nameSuffix = name ? (' ' + name) : '';
		const body = t(
			'sharePinBody',
			"Hello{nameSuffix},\n\nYour kiosk PIN for ArbeitszeitCheck is: {pin}\n\nKeep this PIN private. You can change it only by asking an administrator to generate a new one.\n",
		);
		return String(body)
			.split('{nameSuffix}').join(nameSuffix)
			.split('{pin}').join(String(pin || ''));
	}

	function buildPinMailtoHref(pin) {
		const subject = t('sharePinSubject', 'Your ArbeitszeitCheck kiosk PIN');
		const body = buildPinShareText(pin);
		const to = String(lastPinEmployeeEmail || '').trim();
		const addr = to ? encodeURIComponent(to) : '';
		return 'mailto:' + addr + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
	}

	async function copyPinToClipboard(pin) {
		const value = String(pin || '').trim();
		if (!value) {
			return false;
		}
		try {
			if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
				await navigator.clipboard.writeText(value);
				return true;
			}
		} catch {
			// fall through to legacy path
		}
		try {
			const ta = document.createElement('textarea');
			ta.value = value;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			document.body.appendChild(ta);
			ta.select();
			const ok = document.execCommand('copy');
			document.body.removeChild(ta);
			return !!ok;
		} catch {
			return false;
		}
	}

	function preparePinShareUi(pin, employeeLabel) {
		lastGeneratedPin = String(pin || '');
		lastPinEmployeeLabel = String(employeeLabel || '');
		setPinShareStatus('', '');
		if (pinEmailLink) {
			pinEmailLink.hidden = !lastGeneratedPin;
			pinEmailLink.setAttribute('href', lastGeneratedPin ? buildPinMailtoHref(lastGeneratedPin) : '#');
			if (lastGeneratedPin) {
				pinEmailLink.removeAttribute('aria-disabled');
			} else {
				pinEmailLink.setAttribute('aria-disabled', 'true');
			}
		}
		if (pinShareBtn) {
			const canShare = !!(navigator.share && typeof navigator.share === 'function' && lastGeneratedPin);
			pinShareBtn.hidden = !canShare;
		}
		if (pinCopyBtn) {
			pinCopyBtn.textContent = t('copyPin', 'Copy PIN');
		}
	}

	function setPinGenerateBusy(busy) {
		pinGenerateInFlight = !!busy;
		if (pinBtn) {
			setBusy(pinBtn, busy);
		}
		document.querySelectorAll('#azc-kiosk-creds-body .azc-kiosk-row-pin').forEach((btn) => {
			const allowed = btn.getAttribute('data-kiosk-allowed') === '1';
			btn.disabled = busy || !allowed;
			btn.setAttribute('aria-disabled', (busy || !allowed) ? 'true' : 'false');
		});
		updateWizardUi();
	}

	if (pinCopyBtn) {
		pinCopyBtn.addEventListener('click', async () => {
			const pin = lastGeneratedPin
				|| (document.getElementById('azc-kiosk-pin-code') || {}).textContent
				|| '';
			const ok = await copyPinToClipboard(pin);
			if (ok) {
				setPinShareStatus(t('pinCopied', 'PIN copied'), 'success');
				pinCopyBtn.textContent = t('pinCopied', 'PIN copied');
				window.setTimeout(() => {
					if (pinCopyBtn && lastGeneratedPin) {
						pinCopyBtn.textContent = t('copyPin', 'Copy PIN');
					}
				}, 2000);
			} else {
				setPinShareStatus(
					t('copyFailed', 'Could not copy. Please select the PIN and copy it manually.'),
					'error',
				);
			}
		});
	}

	if (pinShareBtn) {
		pinShareBtn.addEventListener('click', async () => {
			if (!lastGeneratedPin || !navigator.share) {
				return;
			}
			try {
				await navigator.share({
					title: t('sharePinSubject', 'Your ArbeitszeitCheck kiosk PIN'),
					text: buildPinShareText(lastGeneratedPin),
				});
			} catch (e) {
				// User cancelled share sheet — not an error.
				if (e && e.name === 'AbortError') {
					return;
				}
				setPinShareStatus(
					t('shareFailed', 'Could not share. Please copy the PIN instead.'),
					'error',
				);
			}
		});
	}

	if (pinEmailLink) {
		pinEmailLink.addEventListener('click', (e) => {
			if (!lastGeneratedPin) {
				e.preventDefault();
			}
		});
	}

	/**
	 * Create or replace a kiosk PIN, then open the one-time reveal modal.
	 *
	 * @param {string} userId
	 * @param {string} displayName
	 * @param {{ hasPin?: boolean, kioskAllowed?: boolean, triggerBtn?: HTMLElement|null }} [options]
	 */
	pinWorkflow.generateForUser = async function generatePinForUser(userId, displayName, options) {
		const opts = options || {};
		const uid = String(userId || '').trim();
		if (!uid) {
			showFeedback(t('selectEmployee', 'Select an employee first'), 'error');
			return;
		}
		if (pinGenerateInFlight) {
			showFeedback(t('pinBusy', 'A PIN is already being generated. Please wait a moment.'), 'error');
			return;
		}
		if (opts.kioskAllowed === false) {
			showFeedback(t('enableAccessFirst', 'Allow kiosk access for this employee first'), 'error');
			return;
		}
		if (opts.hasPin) {
			const confirmTpl = t(
				'confirmNewPin',
				'Generate a new PIN for {name}? The previous PIN will stop working immediately.',
			);
			const msg = String(confirmTpl).split('{name}').join(displayName || uid);
			const confirmed = Utils.confirmDestructiveAction
				? await Utils.confirmDestructiveAction({
					title: t('confirmNewPinTitle', 'Generate new PIN'),
					message: msg,
					confirmLabel: t('Generate PIN', 'Generate PIN'),
					cancelLabel: t('Cancel', 'Cancel'),
					variant: 'warning',
				})
				: null;
			if (!confirmed) {
				return;
			}
		}
		pinReturnFocusUserId = uid;
		setPinGenerateBusy(true);
		try {
			const data = await api(page.dataset.apiPin || '', {
				method: 'POST',
				headers: headers(),
				body: JSON.stringify({ userId: uid }),
			});
			const pin = data.data && data.data.pin;
			if (!pin) {
				throw new Error(t('requestFailed', 'Request failed'));
			}
			const codeEl = document.getElementById('azc-kiosk-pin-code');
			if (codeEl) {
				codeEl.textContent = String(pin);
			}
			preparePinShareUi(pin, displayName || uid);
			// Refresh table first so return-focus can target the new row button.
			await loadCredentials();
			const returnEl = findRowPinButton(uid)
				|| pinBtn
				|| document.getElementById('azc-kiosk-creds-heading');
			openModal(pinModal, pinBackdrop, returnEl);
			if (pinCopyBtn) {
				pinCopyBtn.focus();
			}
		} catch (e) {
			pinReturnFocusUserId = '';
			showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
		} finally {
			setPinGenerateBusy(false);
		}
	};

	if (pinBtn) {
		pinBtn.addEventListener('click', async () => {
			const userId = selectedUser ? selectedUser.value : '';
			if (!userId) {
				showFeedback(t('selectEmployee', 'Select an employee first'), 'error');
				if (userSearch) {
					userSearch.focus();
				}
				return;
			}
			let alreadyHasPin = false;
			document.querySelectorAll('#azc-kiosk-creds-body tr[data-user-id]').forEach((row) => {
				if (row.getAttribute('data-user-id') === userId) {
					const rowPinBtn = row.querySelector('.azc-kiosk-row-pin');
					alreadyHasPin = !!(rowPinBtn && rowPinBtn.getAttribute('data-has-pin') === '1');
				}
			});
			const employeeLabel = (selectedName && selectedName.textContent)
				|| (userSearch && userSearch.value)
				|| userId;
			await pinWorkflow.generateForUser(userId, employeeLabel, {
				hasPin: alreadyHasPin,
				kioskAllowed: !(selectedAllowed && !selectedAllowed.checked),
				triggerBtn: pinBtn,
			});
		});
	}

	bindRevokeButtons();

	const enrollTerminalSelect = document.getElementById('azc-kiosk-enroll-terminal');
	if (enrollTerminalSelect) {
		enrollTerminalSelect.addEventListener('change', updateWizardUi);
	}
	updateWizardUi();

	/**
	 * Deep-link from employee profile: /admin/kiosk?user=<uid>#azc-kiosk-creds-heading
	 * Pre-selects that employee in the Badges & PIN form.
	 */
	async function applyUserDeepLink() {
		let userId = '';
		try {
			userId = String(new URLSearchParams(window.location.search).get('user') || '').trim();
		} catch {
			userId = '';
		}
		if (!userId || !userSearch) {
			return;
		}
		try {
			const data = await api(
				(page.dataset.apiSearchUsers || '') + '?q=' + encodeURIComponent(userId),
				{ headers: headers() },
			);
			const users = Array.isArray(data.users) ? data.users : [];
			const match = users.find((u) => String(u.userId) === userId) || users[0];
			if (match && String(match.userId) === userId) {
				selectEmployee(
					match.userId,
					match.displayName || match.userId,
					!!match.kioskAllowed,
					match.email || '',
				);
			} else {
				// Should be rare after exact-UID server resolve; still prefill safely.
				selectEmployee(userId, userId, false, '');
			}
		} catch {
			selectEmployee(userId, userId, false, '');
		}
		const scrollBehavior = prefersReducedMotion() ? 'auto' : 'smooth';
		const target = document.getElementById('azc-kiosk-creds-heading');
		if (target && typeof target.scrollIntoView === 'function') {
			target.scrollIntoView({ behavior: scrollBehavior, block: 'start' });
		}
		const tbody = document.getElementById('azc-kiosk-creds-body');
		if (!tbody) {
			return;
		}
		const rows = tbody.querySelectorAll('tr[data-user-id]');
		for (let i = 0; i < rows.length; i++) {
			if (rows[i].getAttribute('data-user-id') === userId) {
				rows[i].classList.add('azc-kiosk-cred-row--focus');
				rows[i].scrollIntoView({ behavior: scrollBehavior, block: 'nearest' });
				break;
			}
		}
	}

	loadCredentials()
		.then(() => applyUserDeepLink())
		.catch((e) => {
			showFeedback(e instanceof Error ? e.message : t('requestFailed', 'Request failed'), 'error');
			applyUserDeepLink().catch(() => { /* non-fatal */ });
		});
})();
