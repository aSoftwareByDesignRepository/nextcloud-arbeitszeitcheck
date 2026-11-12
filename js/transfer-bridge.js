/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.ArbeitszeitCheckApi / ArbeitszeitCheckMessaging
 * contract the synced js/transfer.js expects, in ArbeitszeitCheck's own
 * API envelope ({success:true,data} / {success:false,error,context}).
 * App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.ArbeitszeitCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/arbeitszeitcheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	// common/messaging.js already exposes ArbeitszeitCheckMessaging with
	// show*/announce* methods — the synced page wants .alert/.polite, so
	// extend the existing object rather than replace it.
	const M = window.ArbeitszeitCheckMessaging || (window.ArbeitszeitCheckMessaging = {});
	if (typeof M.alert !== 'function') {
		M.alert = function (text) {
			if (typeof M.showError === 'function') {
				M.showError(text);
				return;
			}
			if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(text);
			}
			const el = document.getElementById('azc-transfer-result-live');
			if (el) el.textContent = text;
		};
	}
	if (typeof M.polite !== 'function') {
		M.polite = function (text) {
			if (typeof M.showInfo === 'function') {
				M.showInfo(text);
				return;
			}
			if (typeof M.announcePolite === 'function') {
				M.announcePolite(text);
				return;
			}
			M.alert(text);
		};
	}
})();
