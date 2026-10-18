/**
 * F16/F19b: pending suite draft stamps on the worker dashboard.
 *
 * The card renders server-side; this module only handles the two decisions
 * (confirm / dismiss) against /api/ext-stamps/{id}/… and removes handled
 * rows. Decisions are idempotent server-side — a double-click or retry can
 * never mint a second time entry.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	function t(msgid) {
		return (typeof window !== 'undefined' && typeof window.t === 'function')
			? window.t('arbeitszeitcheck', msgid)
			: msgid;
	}

	function apiFetch(url, method) {
		if (window.AzcApi && typeof window.AzcApi.fetch === 'function') {
			return window.AzcApi.fetch(url, { method: method, silent: true });
		}
		return fetch(url, {
			method: method,
			headers: { 'requesttoken': (window.OC && window.OC.requestToken) || '' },
			credentials: 'same-origin',
		}).then(async function (res) {
			let data = null;
			try { data = await res.json(); } catch (_e) { /* non-JSON */ }
			return { ok: res.ok && (!data || data.success !== false), status: res.status, data: data, error: null };
		}).catch(function () {
			return { ok: false, status: 0, data: null, error: null };
		});
	}

	function setStatus(text, isError) {
		const status = document.getElementById('azc-ext-stamps-status');
		if (!status) {
			return;
		}
		status.textContent = text;
		status.classList.toggle('azc-ext-stamps__status--error', isError === true);
	}

	function maybeHideCard() {
		const card = document.getElementById('azc-ext-stamps');
		const list = document.getElementById('azc-ext-stamps-list');
		if (card && list && list.querySelectorAll('.azc-ext-stamps__item').length === 0) {
			card.setAttribute('hidden', 'hidden');
		}
	}

	function decide(item, action) {
		const stampId = item.getAttribute('data-stamp-id') || '';
		if (!/^\d+$/.test(stampId)) {
			return;
		}
		const buttons = item.querySelectorAll('.azc-ext-stamps__btn');
		buttons.forEach(function (btn) { btn.disabled = true; });

		const base = (window.OC && window.OC.webroot ? window.OC.webroot : '') + '/apps/arbeitszeitcheck';
		apiFetch(base + '/api/ext-stamps/' + encodeURIComponent(stampId) + '/' + action, 'POST')
			.then(function (res) {
				if (res.ok) {
					item.remove();
					setStatus(action === 'confirm'
						? t('Stamp added to your working time.')
						: t('Suggestion dismissed.'), false);
					maybeHideCard();
					return;
				}
				// 404 = a foreign/already-handled id — treat like a handled row so
				// a stale tab can never trap the worker on a dead suggestion.
				if (res.status === 404) {
					item.remove();
					setStatus(t('This suggestion was already handled.'), false);
					maybeHideCard();
					return;
				}
				buttons.forEach(function (btn) { btn.disabled = false; });
				const msg = res.data && typeof res.data.message === 'string' && res.data.message !== ''
					? res.data.message
					: t('Could not save your choice — please try again.');
				setStatus(msg, true);
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		const list = document.getElementById('azc-ext-stamps-list');
		if (!list) {
			return;
		}
		list.addEventListener('click', function (ev) {
			const btn = ev.target && ev.target.closest ? ev.target.closest('[data-stamp-action]') : null;
			if (!btn || !list.contains(btn)) {
				return;
			}
			const item = btn.closest('.azc-ext-stamps__item');
			const action = btn.getAttribute('data-stamp-action');
			if (item && (action === 'confirm' || action === 'dismiss')) {
				decide(item, action);
			}
		});
	});
})();
