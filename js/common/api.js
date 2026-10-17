/**
 * Fetch wrapper with CSRF, JSON error mapping, and session-expiry handling.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

(function () {
	'use strict';

	if (typeof window !== 'undefined' && window.AzcApi) {
		return;
	}

	function canUseAppTranslations() {
		if (typeof window === 'undefined' || typeof window.t !== 'function') {
			return false;
		}
		if (window.OC?.L10N) {
			return true;
		}
		return typeof OC !== 'undefined' && !!OC?.L10N;
	}

	function translate(msgid) {
		return canUseAppTranslations() ? window.t('arbeitszeitcheck', msgid) : msgid;
	}

	const AzcApi = {
		getRequestToken() {
			if (window.OC?.requestToken) {
				return window.OC.requestToken;
			}
			const head = document.querySelector('head');
			const fromHead = head ? head.getAttribute('data-requesttoken') : '';
			if (fromHead) {
				return fromHead;
			}
			const meta = document.querySelector('head meta[name="requesttoken"]');
			return meta ? meta.getAttribute('content') : '';
		},

		/**
		 * @param {string} url
		 * @param {RequestInit & {json?: object, silent?: boolean}} options
		 * @returns {Promise<{ok: boolean, status: number, data: object|null, error: string|null}>}
		 */
		async fetch(url, options = {}) {
			const silent = options.silent === true;
			const method = (options.method || 'GET').toUpperCase();
			const headers = new Headers(options.headers || {});
			const token = this.getRequestToken();

			if (token) {
				headers.set('requesttoken', token);
			}

			const isMutating = method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE';
			let body = options.body;
			if (options.json !== undefined) {
				headers.set('Content-Type', 'application/json');
				headers.set('Accept', 'application/json');
				body = JSON.stringify(options.json);
			} else if (isMutating && (body === undefined || body === null || body === '')) {
				// Bare mutating requests must still send JSON — see mobile clock-in 400.
				headers.set('Content-Type', 'application/json');
				const payload = token ? { requesttoken: token } : {};
				body = JSON.stringify(payload);
			}
			if (!headers.has('Accept')) {
				headers.set('Accept', 'application/json');
			}

			const init = {
				...options,
				method,
				headers,
				credentials: options.credentials || 'same-origin',
				body,
			};
			delete init.json;
			delete init.silent;

			let response;
			try {
				response = await fetch(url, init);
			} catch (e) {
				const msg = translate('Network error. Check your connection and try again.');
				if (!silent) {
					window.ArbeitszeitCheckMessaging?.showError?.(msg);
				}
				return { ok: false, status: 0, data: null, error: msg };
			}

			if (response.status === 412 || response.status === 419) {
				const expired = translate('Your session expired — please refresh the page and try again.');
				if (!silent) {
					window.ArbeitszeitCheckMessaging?.showError?.(expired);
				}
				return { ok: false, status: response.status, data: null, error: expired };
			}

			let data = null;
			const contentType = response.headers.get('content-type') || '';
			if (contentType.includes('application/json')) {
				try {
					data = await response.json();
				} catch (e) {
					data = null;
				}
			}

			if (!response.ok) {
				const error = this.mapApiError(data, response.status);
				return { ok: false, status: response.status, data, error };
			}

			return { ok: true, status: response.status, data, error: null };
		},

		/**
		 * True when HTTP succeeded and JSON body indicates success (if present).
		 *
		 * @param {{ok?: boolean, data?: object|null}} result
		 * @returns {boolean}
		 */
		isApiSuccess(result) {
			if (!result || result.ok !== true) {
				return false;
			}
			const body = result.data;
			if (body && typeof body === 'object') {
				if (body.ok === false) {
					return false;
				}
				if (typeof body.success === 'boolean') {
					return body.success;
				}
			}
			return true;
		},

		/**
		 * True when a string looks like an API/machine code rather than user-facing text.
		 * @param {string} value
		 * @returns {boolean}
		 */
		isMachineErrorCode(value) {
			if (typeof value !== 'string' || value === '') {
				return false;
			}
			if (/\s/.test(value)) {
				return false;
			}
			return /^[A-Z][A-Z0-9_]*$/.test(value) || /^[a-z][a-z0-9_]+$/.test(value);
		},

		/**
		 * @param {string} msgid
		 * @returns {string}
		 */
		translate(msgid) {
			return translate(msgid);
		},

		/**
		 * True when a string looks like HTML, a bare HTTP status, or other
		 * non-user-facing proxy/gateway noise that must never be shown as-is.
		 *
		 * @param {unknown} value
		 * @returns {boolean}
		 */
		isUnsafeApiErrorText(value) {
			if (typeof value !== 'string') {
				return true;
			}
			const t = value.trim();
			if (t === '') {
				return true;
			}
			if (t.charAt(0) === '<' || /^<!DOCTYPE/i.test(t)) {
				return true;
			}
			if (/^\d{3}$/.test(t) || /^HTTP\s*\d{3}\b/i.test(t)) {
				return true;
			}
			if (/empty-content|guest-box|body-login|<\/?(html|body|head|script|style)\b/i.test(t)) {
				return true;
			}
			// Cap absurd payloads (full HTML error documents).
			if (t.length > 500) {
				return true;
			}
			return false;
		},

		/**
		 * Localized fallback when the transport failed without a usable JSON message.
		 *
		 * @param {number} [status]
		 * @returns {string}
		 */
		httpTransportFallbackMessage(status) {
			const code = Number.isFinite(status) && status > 0 ? String(status) : '';
			if (code !== '') {
				return translate(
					'The server rejected this request (HTTP %s). Reload the page and try again. If it continues, contact your administrator.',
				).replace('%s', code);
			}
			return translate(
				'An unexpected error occurred. Please try again. If the problem continues, contact your administrator.',
			);
		},

		mapApiError(data, status) {
			if (data && typeof data === 'object') {
				if (typeof data.error === 'string' && data.error !== '' && !this.isMachineErrorCode(data.error)
					&& !this.isUnsafeApiErrorText(data.error)) {
					return data.error;
				}
				if (data.error && typeof data.error.message === 'string'
					&& !this.isUnsafeApiErrorText(data.error.message)) {
					return data.error.message;
				}
				if (typeof data.message === 'string' && data.message !== '' && !this.isMachineErrorCode(data.message)
					&& !this.isUnsafeApiErrorText(data.message)) {
					return data.message;
				}
				const code = (typeof data.code === 'string' && data.code)
					|| (typeof data.error === 'string' && data.error)
					|| (typeof data.message === 'string' && data.message);
				if (code === 'app_access_denied' || code === 'access_denied') {
					return translate(
						'You do not have access to ArbeitszeitCheck. Your account is not among the users or groups allowed to use this app.',
					);
				}
				if (code === 'LICENSE_REQUIRED') {
					return translate('ArbeitszeitCheck Mobile is not licensed for this user.');
				}
				if (code === 'TERMINAL_LICENSE_REQUIRED') {
					return translate('ArbeitszeitCheck Terminal is not licensed for this organisation.');
				}
				if (code === 'KIOSK_DISABLED') {
					return translate('Kiosk mode is not enabled on this server.');
				}
				if (code === 'KIOSK_TERMINAL_UNAUTHORIZED') {
					return translate('Terminal token invalid or revoked.');
				}
			}
			if (typeof data === 'string' && !this.isUnsafeApiErrorText(data) && !this.isMachineErrorCode(data)) {
				return data;
			}
			if (status === 403 || status === 404) {
				return translate('Not found or no access.');
			}
			if (status >= 400 && status < 600) {
				return this.httpTransportFallbackMessage(status);
			}
			return translate(
				'An unexpected error occurred. Please try again. If the problem continues, contact your administrator.',
			);
		},
	};

	if (typeof window !== 'undefined') {
		window.AzcApi = AzcApi;
	}
})();
