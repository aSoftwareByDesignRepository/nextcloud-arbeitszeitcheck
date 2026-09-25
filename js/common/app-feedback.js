/**
 * App feedback — mailto builders + error-toast “Report this problem” hook.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */
(function (global) {
	'use strict';

	var APP_ID = 'arbeitszeitcheck';
	var PREFIX = 'azc';
	var EMAIL = 'dev@software-by-design.de';
	var DISPLAY = 'ArbeitszeitCheck';

	function t(key) {
		if (typeof global.t === 'function') {
			return global.t(APP_ID, key);
		}
		return key;
	}

	function readConfig() {
		var el = document.getElementById(PREFIX + '-app-feedback-config');
		if (!el || !el.textContent) {
			return {
				appId: APP_ID,
				appDisplayName: DISPLAY,
				appVersion: '',
				feedbackEmail: EMAIL,
				githubIssuesUrl: '',
				cssPrefix: PREFIX,
			};
		}
		try {
			var parsed = JSON.parse(el.textContent);
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (e) {
			return { appId: APP_ID, appDisplayName: DISPLAY, feedbackEmail: EMAIL, cssPrefix: PREFIX };
		}
	}

	function isBlockedQueryKey(key) {
		return /^(token|password|code|secret|key|auth|session|requesttoken|guestname|guestpassword|iknowthatthisisabiginstanceandtheupdaterequestcouldrunintoatimeoutandhowtorestoreabackup)$/i.test(key);
	}

	function stripBlockedQueryParams(parsed) {
		parsed.searchParams.forEach(function (_value, key) {
			if (isBlockedQueryKey(key)) {
				parsed.searchParams.delete(key);
			}
		});
	}

	function sanitizePageUrl(url) {
		url = String(url || '').trim();
		if (!url || url.length > 500) {
			return '';
		}
		if (Array.from(url).some(function (character) {
			const code = character.charCodeAt(0);
			return code <= 31 || code === 127;
		})) {
			return '';
		}
		var lower = url.toLowerCase();
		if (lower.indexOf('javascript:') === 0 || lower.indexOf('data:') === 0) {
			return '';
		}
		try {
			var abs = url.indexOf('://') === -1 && url.charAt(0) === '/'
				? (global.location.origin || '') + url
				: url;
			var parsed = new URL(abs, global.location && global.location.href ? global.location.href : undefined);
			if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
				return '';
			}
			stripBlockedQueryParams(parsed);
			var out = parsed.pathname + (parsed.search ? parsed.search : '');
			if (url.indexOf('://') !== -1) {
				out = parsed.origin + out;
			}
			return out.length > 500 ? out.slice(0, 500) : out;
		} catch (e) {
			return '';
		}
	}

	function stripNoiseFromLocation() {
		if (!global.location || !global.history || typeof global.history.replaceState !== 'function') {
			return;
		}
		try {
			var parsed = new URL(global.location.href);
			var before = parsed.search;
			stripBlockedQueryParams(parsed);
			if (parsed.search !== before) {
				var next = parsed.pathname + (parsed.search ? parsed.search : '') + parsed.hash;
				global.history.replaceState(null, '', next);
			}
		} catch (e) {
			/* never break navigation */
		}
	}

	function safeErrorCode(code) {
		var s = String(code || '').trim();
		return /^[A-Za-z0-9._:-]{1,64}$/.test(s) ? s : '';
	}

	function buildMailto(kind, extra) {
		var cfg = readConfig();
		var display = typeof cfg.appDisplayName === 'string' && cfg.appDisplayName ? cfg.appDisplayName : DISPLAY;
		var email = typeof cfg.feedbackEmail === 'string' && cfg.feedbackEmail.indexOf('@') !== -1
			? cfg.feedbackEmail
			: EMAIL;
		var lang = (document.documentElement && document.documentElement.lang) || '';
		var isDe = /^de(-|$)/i.test(lang);
		var subject = kind === 'idea'
			? display + ': Feedback'
			: (isDe ? display + ': Fehlermeldung' : display + ': Problem report');
		var page = sanitizePageUrl(
			(extra && extra.pageUrl) || (global.location && (global.location.pathname + global.location.search)) || ''
		);
		var errorCode = extra && extra.errorCode ? safeErrorCode(extra.errorCode) : '';
		var lines = [
			kind === 'idea'
				? '--- Please describe your idea below ---'
				: '--- Please describe what went wrong below ---\nSteps:\n1.\n2.\n\nExpected:\nActual:',
			'',
			'--- Auto-filled (you can delete) ---',
			'App: ' + display + (cfg.appVersion ? (' ' + cfg.appVersion) : ''),
			'App id: ' + (cfg.appId || APP_ID),
		];
		if (page) {
			lines.push('Page: ' + page);
		}
		if (errorCode) {
			lines.push('Error code: ' + errorCode);
		}
		var body = lines.join('\n');
		if (body.length > 1500) {
			body = body.slice(0, 1500);
		}
		return 'mailto:' + email + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
	}

	function refreshNavHrefs() {
		var problem = document.getElementById(PREFIX + '-feedback-problem');
		var idea = document.getElementById(PREFIX + '-feedback-idea');
		if (problem) {
			problem.setAttribute('href', buildMailto('problem'));
		}
		if (idea) {
			idea.setAttribute('href', buildMailto('idea'));
		}
	}

	function attachReportLink(toast, errorCode) {
		if (!toast || toast.getAttribute('data-app-feedback-bound') === '1') {
			return;
		}
		toast.setAttribute('data-app-feedback-bound', '1');
		var content = toast.querySelector('.toast-content') || toast;
		var a = document.createElement('a');
		a.className = PREFIX + '-nav-footer__toast-link';
		a.href = buildMailto('problem', { errorCode: errorCode });
		a.textContent = t('Report this problem');
		content.appendChild(a);
	}

	function wrapMethod(obj, name) {
		if (!obj || typeof obj[name] !== 'function' || obj[name]._sbdFeedbackWrapped) {
			return;
		}
		var orig = obj[name];
		var wrapped = function (first, second) {
			var result = orig.apply(this, arguments);
			try {
				var type = '';
				var message = '';
				var code = '';
				if (first && typeof first === 'object') {
					type = String(first.type || '');
					message = String(first.message || '');
					code = String(first.code || first.errorCode || '');
				} else {
					type = name === 'showError' ? 'error' : String(second || '');
					message = String(first || '');
				}
				if (type === 'error' || type === 'danger' || type === 'critical' || name === 'showError') {
					var toasts = document.querySelectorAll('.toast--error, .toast--danger, .toast--critical');
					var last = toasts.length ? toasts[toasts.length - 1] : null;
					attachReportLink(last, safeErrorCode(code) || (message.length <= 64 ? message : ''));
				}
			} catch (e) {
				/* never break the host toast */
			}
			return result;
		};
		wrapped._sbdFeedbackWrapped = true;
		obj[name] = wrapped;
	}

	function installToastHooks() {
		var candidates = [
			global.ArbeitszeitCheckComponents,
			global.ArbeitszeitCheckMessaging,
			global.DutyCheckComponents,
			global.DutyCheckMessaging,
			global.CustomerCheckComponents,
			global.BudgetCheckComponents,
			global.AudioCheckComponents,
			global.ProjectCheckComponents,
			global.TicketCheckComponents,
			global.SnackCheckComponents,
			global.InventoryCheckComponents,
			global.MaintenanceCheckComponents,
			global.InvoiceCheckComponents,
			global.DeskCheckComponents,
			global.MobilityCheckComponents,
		];
		for (var i = 0; i < candidates.length; i++) {
			wrapMethod(candidates[i], 'showToast');
			wrapMethod(candidates[i], 'showError');
		}
	}

	function installPopover() {
		var trigger = document.querySelector('.' + PREFIX + '-nav-footer__trigger');
		if (!trigger) {
			return;
		}
		var menuId = trigger.getAttribute('aria-controls');
		var menu = menuId ? document.getElementById(menuId) : null;
		if (!menu) {
			return;
		}

		function open() {
			menu.hidden = false;
			trigger.setAttribute('aria-expanded', 'true');
			var first = menu.querySelector('a, button');
			if (first) {
				first.focus();
			}
		}

		function close(returnFocus) {
			menu.hidden = true;
			trigger.setAttribute('aria-expanded', 'false');
			if (returnFocus) {
				trigger.focus();
			}
		}

		trigger.addEventListener('click', function () {
			var expanded = trigger.getAttribute('aria-expanded') === 'true';
			if (expanded) {
				close(true);
			} else {
				open();
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !menu.hidden) {
				close(true);
			}
		});

		document.addEventListener('click', function (e) {
			if (menu.hidden) {
				return;
			}
			if (!trigger.contains(e.target) && !menu.contains(e.target)) {
				close(false);
			}
		});
	}

	var api = {
		sanitizePageUrl: sanitizePageUrl,
		buildMailto: buildMailto,
		install: function () {
			stripNoiseFromLocation();
			refreshNavHrefs();
			installPopover();
			installToastHooks();
		},
	};

	global.SbdAppFeedback = api;
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			api.install();
		});
	} else {
		api.install();
	}
})(typeof window !== 'undefined' ? window : this);
