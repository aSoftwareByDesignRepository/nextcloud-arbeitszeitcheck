(function () {
	'use strict';

	if (window.__arbeitszeitcheckDeskletLoader) {
		return;
	}
	window.__arbeitszeitcheckDeskletLoader = true;

	const APP_PATH_FRAGMENTS = [
		'/apps/arbeitszeitcheck/',
		'/custom_apps/arbeitszeitcheck/',
	];
	const DEFAULT_WIDGET_PANEL_ID = 'arbeitszeitcheck-employee-status';

	function loadDeskletInitialState() {
		try {
			if (window.OCP?.InitialState && typeof window.OCP.InitialState.loadState === 'function') {
				return window.OCP.InitialState.loadState('arbeitszeitcheck', 'desklet');
			}
			if (window.OC?.initialState && typeof window.OC.initialState.loadState === 'function') {
				return window.OC.initialState.loadState('arbeitszeitcheck', 'desklet');
			}
		} catch (_e) {
			// Missing state on non-dashboard pages.
		}
		return null;
	}

	function pathTargetsApp(pathname) {
		return APP_PATH_FRAGMENTS.some((fragment) => pathname.includes(fragment));
	}

	function findDeskletMountPoint(widgetPanelId) {
		const root = document.getElementById('app-dashboard');
		if (!root) {
			return null;
		}

		const panelId = (typeof widgetPanelId === 'string' && widgetPanelId !== '')
			? widgetPanelId
			: DEFAULT_WIDGET_PANEL_ID;
		const marker = root.querySelector(`[data-id="${panelId}"]`);
		if (marker) {
			const panel = marker.closest('.panel');
			if (panel) {
				return panel.querySelector('.panel--content') || panel;
			}
		}

		const panels = root.querySelectorAll('.panel');
		for (let i = 0; i < panels.length; i++) {
			const panel = panels[i];
			const header = panel.querySelector('.panel--header h2');
			const title = header ? String(header.textContent || '').trim() : '';
			if (/my work status/i.test(title)) {
				return panel.querySelector('.panel--content') || panel;
			}
		}

		return null;
	}

	function mountDeskletWorkspaceFromInitialState() {
		const state = loadDeskletInitialState();
		if (!state || typeof state !== 'object') {
			return null;
		}
		const html = typeof state.workspaceHtml === 'string' ? state.workspaceHtml.trim() : '';
		if (!html) {
			return null;
		}
		const mount = findDeskletMountPoint(state.widgetPanelId);
		if (!mount || mount.querySelector('[data-arbeitszeitcheck-desklet]')) {
			return null;
		}
		const wrap = document.createElement('div');
		wrap.className = 'dz-mount dz-mount--nc-dashboard';
		wrap.setAttribute('data-arbeitszeitcheck-desklet-mount', '1');
		wrap.innerHTML = html;
		mount.insertBefore(wrap, mount.firstChild);
		return wrap.querySelector('#dz-config');
	}

	function resolveConfigElement() {
		const existing = document.querySelector('[data-arbeitszeitcheck-desklet] #dz-config');
		if (existing) {
			return existing;
		}
		return mountDeskletWorkspaceFromInitialState();
	}

	/**
	 * Only allow same-origin URLs that target this app's API routes.
	 *
	 * @param {string} url
	 * @returns {boolean}
	 */
	function isSafeAppUrl(url) {
		if (typeof url !== 'string') {
			return false;
		}
		const trimmed = url.trim();
		if (trimmed === '') {
			return false;
		}
		if (/^https?:\/\//i.test(trimmed)) {
			try {
				const parsed = new URL(trimmed);
				return parsed.origin === window.location.origin
					&& pathTargetsApp(parsed.pathname);
			} catch (_e) {
				return false;
			}
		}
		if (trimmed.startsWith('//')) {
			return false;
		}
		return trimmed.startsWith('/')
			&& pathTargetsApp(trimmed);
	}

	function normalizeDeskletUrl(url) {
		if (typeof url !== 'string') {
			return '';
		}
		const trimmed = url.trim();
		if (trimmed === '') {
			return '';
		}
		if (/^https?:\/\//i.test(trimmed)) {
			return trimmed;
		}
		if (trimmed.startsWith('/') && window.OC?.generateUrl) {
			const route = trimmed.replace(/^\/index\.php\//, '');
			try {
				return window.OC.generateUrl(route);
			} catch (_e) {
				return trimmed;
			}
		}
		return trimmed;
	}

	function bootDesklet(configEl) {
		const workspace = configEl.closest('[data-arbeitszeitcheck-desklet]');
		if (workspace?.dataset.dzInitialized === '1') {
			return;
		}
		if (workspace) {
			workspace.dataset.dzInitialized = '1';
		}

		let config;
		try {
			config = JSON.parse(configEl.textContent);
		} catch (_e) {
			return;
		}

		if (!config || typeof config !== 'object') {
			return;
		}

		runDesklet(config, workspace);
	}

	function scheduleDeskletBoot() {
		const configEl = resolveConfigElement();
		if (configEl) {
			bootDesklet(configEl);
			return;
		}
		const state = loadDeskletInitialState();
		if (!state?.workspaceHtml) {
			return;
		}
		let attempts = 0;
		const timer = window.setInterval(() => {
			attempts++;
			const el = resolveConfigElement();
			if (el) {
				window.clearInterval(timer);
				bootDesklet(el);
			} else if (attempts >= 40) {
				window.clearInterval(timer);
			}
		}, 250);
	}

	function runDesklet(config, workspace) {
		const l10n = (config.l10n && typeof config.l10n === 'object') ? config.l10n : {};
		const root = workspace || document;
		const el = (id) => root.querySelector('#' + id);

		const statusSectionEl = el('dz-status-section');
		const statusCardEl = el('dz-status-card');
		const statusBadgeEl = el('dz-status-badge');
		const statusTextEl = el('dz-status-text');
		const statusIconEl = el('dz-status-icon');
		const workedTodayEl = el('dz-worked-today');
		const sessionEl = el('dz-session-duration');
		const feedbackEl = el('dz-feedback');
		const lastUpdatedEl = el('dz-last-updated');
		const liveStatusEl = el('dz-live-status');
		const managerListEl = el('dz-manager-list');
		const adminListEl = el('dz-admin-list');
		const errorPanelEl = el('dz-error-panel');
		const errorEl = el('dz-error');
		const retryBtn = el('dz-retry');
		const actionButtons = ['dz-clock-in', 'dz-start-break', 'dz-end-break', 'dz-clock-out']
			.map((id) => el(id))
			.filter(Boolean);

		const deskletActions = window.AzcDeskletActions || null;

		let lastKnown = {
			status: 'clocked_out',
			workingTodayHours: 0,
			currentSessionDuration: 0,
			clockStampingEnabled: true,
			manualTimeEntryEnabled: true,
			atDailyMaximum: false,
		};
		let mutationInFlight = false;
		let sessionTickTimer = null;
		let sessionTickBase = null;

		const statusLabel = (status) => {
			switch (status) {
				case 'active': return l10n.working || 'Working';
				case 'break': return l10n.onBreak || 'On Break';
				case 'paused': return l10n.paused || 'Paused';
				case 'completed': return l10n.clockedOut || 'Clocked Out';
				default: return l10n.clockedOut || 'Clocked Out';
			}
		};

		const timeApi = () => window.ArbeitszeitCheckTime || null;

		const formatDuration = (seconds) => {
			const api = timeApi();
			if (api) {
				const formatted = api.formatDuration(seconds);
				return formatted.length >= 8 ? formatted.slice(0, 5) : formatted;
			}
			const s = Math.max(0, Math.floor(Number(seconds) || 0));
			const h = Math.floor(s / 3600);
			const m = Math.floor((s % 3600) / 60);
			return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
		};

		const formatHours = (hours) => {
			if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.formatHours === 'function') {
				return window.ArbeitszeitCheckUtils.formatHours(hours);
			}
			return Number.isFinite(hours) ? String(Math.round(hours * 100) / 100) : '0';
		};

		const statusIcon = (status) => {
			const map = {
				active: 'clock',
				break: 'coffee',
				paused: 'pause',
			};
			const name = map[status] || 'circle';
			if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
				return window.AzcCatalog.render(name, 'dashboard-widget-status-icon');
			}
			return '';
		};

		const formatTime = (value) => {
			const api = timeApi();
			if (api) {
				return api.formatTime(value, { withSeconds: true }) || '';
			}
			const date = value instanceof Date ? value : new Date(value);
			return new Intl.DateTimeFormat(undefined, {
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit',
			}).format(date);
		};

		const showFeedback = (message) => {
			if (!feedbackEl) {
				return;
			}
			feedbackEl.textContent = message;
			feedbackEl.removeAttribute('hidden');
		};

		const hideFeedback = () => {
			if (feedbackEl) {
				feedbackEl.setAttribute('hidden', '');
			}
		};

		const announce = (message) => {
			if (!liveStatusEl || !message) {
				return;
			}
			liveStatusEl.textContent = '';
			window.setTimeout(() => {
				liveStatusEl.textContent = message;
			}, 10);
		};

		const updateLastRefreshed = () => {
			if (!lastUpdatedEl) {
				return;
			}
			const template = l10n.lastUpdated || 'Last updated: %1$s';
			const now = timeApi() ? timeApi().serverNow() : new Date();
			lastUpdatedEl.textContent = template.replace('%1$s', formatTime(now));
		};

		const normaliseStatus = (raw) => {
		if (!raw || typeof raw !== 'object') {
			return {
				status: 'clocked_out',
				workingTodayHours: 0,
				currentSessionDuration: 0,
				sessionStartFormatted: '',
				clockStampingEnabled: true,
				manualTimeEntryEnabled: true,
				atDailyMaximum: false,
			};
		}
		const capture = (raw.timeCapture && typeof raw.timeCapture === 'object')
			? raw.timeCapture
			: {};
		return {
			status: String(raw.status ?? 'clocked_out'),
			workingTodayHours: parseFloat(
				raw.workingTodayHours ?? raw.working_today_hours ?? 0,
			),
			currentSessionDuration: parseInt(
				raw.currentSessionDuration ?? raw.current_session_duration ?? 0,
				10,
			),
			sessionStartFormatted: String(
				raw.sessionStartFormatted ?? raw.session_start_formatted ?? '',
			),
			clockStampingEnabled: capture.clockStampingEnabled !== false,
			manualTimeEntryEnabled: capture.manualTimeEntryEnabled !== false,
			atDailyMaximum: Boolean(raw.atDailyMaximum ?? raw.at_daily_maximum),
		};
	};

		const setLoading = (loading) => {
			if (statusSectionEl) {
				statusSectionEl.setAttribute('aria-busy', loading ? 'true' : 'false');
			}
			if (managerListEl) {
				managerListEl.setAttribute('aria-busy', loading ? 'true' : 'false');
			}
			if (adminListEl) {
				adminListEl.setAttribute('aria-busy', loading ? 'true' : 'false');
			}
		};

		const showError = (message) => {
			const text = message || l10n.statusLoadError || l10n.networkError || 'Could not load status.';
			if (errorEl) {
				errorEl.textContent = text;
			}
			if (errorPanelEl) {
				errorPanelEl.removeAttribute('hidden');
			}
		};

		const hideError = () => {
			if (errorPanelEl) {
				errorPanelEl.setAttribute('hidden', '');
			}
		};

		const projectPickerEl = el('dz-project-picker');
		const projectSelectEl = el('dz-clock-in-project');
		const dailyMaxNoticeEl = el('dz-daily-max-notice');
		const captureNoticeEl = el('dz-capture-notice');

		const setButtonsLocked = (locked) => {
			actionButtons.forEach((btn) => {
				btn.disabled = locked;
				btn.setAttribute('aria-disabled', locked ? 'true' : 'false');
				btn.classList.toggle('btn--loading', locked);
			});
			if (projectSelectEl) {
				projectSelectEl.disabled = locked
					|| !Boolean(
						(deskletActions
							? deskletActions.getEffectiveButtonStates(
								lastKnown.status,
								lastKnown.clockStampingEnabled !== false,
								Boolean(lastKnown.atDailyMaximum),
							)
							: { 'dz-clock-in': true })['dz-clock-in'],
					);
			}
		};

		const stopSessionTicker = () => {
			if (sessionTickTimer) {
				window.clearInterval(sessionTickTimer);
				sessionTickTimer = null;
			}
			sessionTickBase = null;
		};

		const startSessionTicker = (baseDuration, status) => {
			stopSessionTicker();
			if (!sessionEl || (status !== 'active' && status !== 'break')) {
				return;
			}
			sessionTickBase = {
				duration: Math.max(0, Number(baseDuration) || 0),
				at: Date.now(),
			};
			sessionTickTimer = window.setInterval(() => {
				if (!sessionTickBase || !sessionEl) {
					return;
				}
				const elapsed = Math.floor((Date.now() - sessionTickBase.at) / 1000);
				sessionEl.textContent = formatDuration(sessionTickBase.duration + elapsed);
			}, 1000);
		};

		const getEffectiveButtonStates = (status, clockStampingEnabled, atDailyMaximum = false) => {
			if (deskletActions && typeof deskletActions.getEffectiveButtonStates === 'function') {
				return deskletActions.getEffectiveButtonStates(status, clockStampingEnabled, atDailyMaximum);
			}
			const fallback = {
				clocked_out: { 'dz-clock-in': true, 'dz-start-break': false, 'dz-end-break': false, 'dz-clock-out': false },
				active: { 'dz-clock-in': false, 'dz-start-break': true, 'dz-end-break': false, 'dz-clock-out': true },
				break: { 'dz-clock-in': false, 'dz-start-break': false, 'dz-end-break': true, 'dz-clock-out': true },
				paused: { 'dz-clock-in': true, 'dz-start-break': false, 'dz-end-break': false, 'dz-clock-out': true },
				completed: { 'dz-clock-in': true, 'dz-start-break': false, 'dz-end-break': false, 'dz-clock-out': false },
			};
			const states = { ...(fallback[status] ?? fallback.clocked_out) };
			if (!clockStampingEnabled) {
				states['dz-clock-in'] = false;
			}
			if (atDailyMaximum && (status === 'clocked_out' || status === 'paused' || status === 'completed')) {
				states['dz-clock-in'] = false;
			}
			return states;
		};

		const updateCaptureNotice = (data) => {
			if (!captureNoticeEl) {
				return;
			}
			const stampingOff = data.clockStampingEnabled === false;
			const showNotice = stampingOff
				&& (data.status === 'clocked_out' || data.status === 'paused');
			if (!showNotice) {
				captureNoticeEl.setAttribute('hidden', '');
				captureNoticeEl.textContent = '';
				return;
			}
			const title = l10n.stampingDisabledTitle || 'Clock in/out is turned off';
			const body = data.status === 'paused'
				? (l10n.stampingDisabledPausedBody
					|| 'Finish the paused session on the dashboard, or contact your administrator.')
				: (data.manualTimeEntryEnabled
					? (l10n.stampingDisabledBodyManual
						|| 'Add your hours under Time entries in the app.')
					: (l10n.stampingDisabledBody
						|| 'Contact HR if you need to record time.'));
			captureNoticeEl.removeAttribute('hidden');
			captureNoticeEl.innerHTML = '';
			const titleEl = document.createElement('p');
			titleEl.className = 'dz-capture-notice__title';
			titleEl.textContent = title;
			const textEl = document.createElement('p');
			textEl.className = 'dz-capture-notice__text';
			textEl.textContent = body;
			captureNoticeEl.appendChild(titleEl);
			captureNoticeEl.appendChild(textEl);
			captureNoticeEl.setAttribute('role', 'status');
		};

		const updateDailyMaxNotice = (data) => {
			if (!dailyMaxNoticeEl) {
				return;
			}
			const show = deskletActions && typeof deskletActions.shouldShowDailyMaxNotice === 'function'
				? deskletActions.shouldShowDailyMaxNotice(data.status, Boolean(data.atDailyMaximum))
				: (Boolean(data.atDailyMaximum)
					&& (data.status === 'clocked_out' || data.status === 'paused' || data.status === 'completed'));
			if (!show) {
				dailyMaxNoticeEl.setAttribute('hidden', '');
				dailyMaxNoticeEl.textContent = '';
				return;
			}
			dailyMaxNoticeEl.removeAttribute('hidden');
			dailyMaxNoticeEl.innerHTML = '';
			const titleEl = document.createElement('p');
			titleEl.className = 'dz-capture-notice__title';
			titleEl.textContent = l10n.dailyMaximumTitle || 'Daily maximum reached';
			const textEl = document.createElement('p');
			textEl.className = 'dz-capture-notice__text';
			textEl.textContent = l10n.dailyMaximumBody
				|| 'You already reached today’s working-time limit. Clock-in is available again tomorrow.';
			dailyMaxNoticeEl.appendChild(titleEl);
			dailyMaxNoticeEl.appendChild(textEl);
		};

		const updateProjectPickerVisibility = (status, clockStampingEnabled, atDailyMaximum) => {
			if (!projectPickerEl) {
				return;
			}
			const canClockIn = deskletActions && typeof deskletActions.canShowProjectPicker === 'function'
				? deskletActions.canShowProjectPicker(status, clockStampingEnabled, atDailyMaximum)
				: Boolean(getEffectiveButtonStates(status, clockStampingEnabled, atDailyMaximum)['dz-clock-in']);
			projectPickerEl.hidden = !canClockIn;
			if (projectSelectEl) {
				projectSelectEl.disabled = !canClockIn || mutationInFlight;
			}
		};

		const updateButtonStates = (status, clockStampingEnabled = true, atDailyMaximum = false) => {
			if (mutationInFlight) {
				setButtonsLocked(true);
				return;
			}
			const states = getEffectiveButtonStates(status, clockStampingEnabled, atDailyMaximum);
			// Contextual actions: only show what the user can do *right now*. Hiding the
			// inapplicable actions (rather than showing them greyed-out) keeps the row
			// unambiguous — there are never more than two live choices at once.
			Object.entries(states).forEach(([id, enabled]) => {
				const btn = el(id);
				if (!btn) {
					return;
				}
				btn.hidden = !enabled;
				btn.disabled = !enabled;
				btn.setAttribute('aria-disabled', enabled ? 'false' : 'true');
				btn.classList.remove('btn--loading');
			});
			updateProjectPickerVisibility(status, clockStampingEnabled, atDailyMaximum);
		};

		const renderEmployee = (rawData) => {
			const data = (deskletActions && typeof deskletActions.normaliseStatus === 'function')
				? deskletActions.normaliseStatus(rawData)
				: normaliseStatus(rawData);
			const {
				status,
				workingTodayHours,
				currentSessionDuration,
				clockStampingEnabled,
				atDailyMaximum,
			} = data;
			lastKnown = data;

			if (statusBadgeEl) {
				statusBadgeEl.dataset.status = status;
				statusBadgeEl.textContent = statusLabel(status);
			}

			if (statusTextEl) {
				// The badge already names the state, so this line carries *extra* context:
				// when a session is running, show when it started ("Since 09:30").
				if ((status === 'active' || status === 'break') && data.sessionStartFormatted) {
					const template = l10n.sessionSince || 'Since %1$s';
					statusTextEl.textContent = template.replace('%1$s', data.sessionStartFormatted);
					statusTextEl.hidden = false;
				} else {
					statusTextEl.textContent = '';
					statusTextEl.hidden = true;
				}
			}

			if (statusIconEl) {
				statusIconEl.innerHTML = statusIcon(status);
				statusIconEl.setAttribute('aria-hidden', 'true');
			}

			if (workedTodayEl) {
				workedTodayEl.textContent = `${formatHours(workingTodayHours)} h`;
			}

			if (sessionEl) {
				sessionEl.textContent = formatDuration(currentSessionDuration);
			}

			if (statusCardEl) {
				statusCardEl.dataset.status = status;
			}

			updateButtonStates(status, clockStampingEnabled, atDailyMaximum);
			updateCaptureNotice(data);
			updateDailyMaxNotice(data);
			startSessionTicker(currentSessionDuration, status);
		};

		const renderPeopleList = (target, rows, emptyMsg) => {
			if (!target) {
				return;
			}

			while (target.firstChild) {
				target.removeChild(target.firstChild);
			}

			if (!Array.isArray(rows) || rows.length === 0) {
				const msg = document.createElement('p');
				msg.className = 'dz-empty';
				msg.textContent = emptyMsg || l10n.noEntriesFound || 'No entries found.';
				target.appendChild(msg);
				return;
			}

			const list = document.createElement('ul');
			list.className = 'dz-list';
			list.setAttribute('role', 'list');

			rows.forEach((row) => {
				const item = document.createElement('li');
				item.className = 'dz-list-item';
				item.dataset.status = String(row.status || 'clocked_out');

				const badge = document.createElement('span');
				badge.className = 'dz-badge';
				badge.dataset.status = item.dataset.status;
				badge.setAttribute('aria-hidden', 'true');
				badge.textContent = statusLabel(item.dataset.status);

				const text = document.createElement('span');
				text.className = 'dz-list-item__text';
				const template = l10n.peopleRow || '%1$s: %2$s (%3$s h)';
				text.textContent = template
					.replace('%1$s', String(row.displayName || ''))
					.replace('%2$s', statusLabel(item.dataset.status))
					.replace('%3$s', parseFloat(row.workingTodayHours || 0).toFixed(2));

				item.setAttribute('aria-label',
					String(row.displayName || '') + ': ' + statusLabel(item.dataset.status));

				item.appendChild(badge);
				item.appendChild(text);
				list.appendChild(item);
			});

			target.appendChild(list);
		};

		const apiClient = window.AzcApi || null;

		const fetchDesklet = async (url, method = 'GET', jsonBody = undefined) => {
			const requestUrl = normalizeDeskletUrl(url);
			if (!isSafeAppUrl(requestUrl)) {
				return {
					ok: false,
					status: 0,
					data: null,
					error: l10n.statusLoadError || l10n.networkError || 'Could not load status.',
				};
			}
			const upper = String(method || 'GET').toUpperCase();
			const isMutating = upper === 'POST' || upper === 'PUT' || upper === 'PATCH' || upper === 'DELETE';
			const payload = isMutating
				? ((jsonBody && typeof jsonBody === 'object') ? jsonBody : {})
				: undefined;
			if (apiClient && typeof apiClient.fetch === 'function') {
				// Always pass json for mutating desklet actions so AzcApi sends a body.
				if (isMutating) {
					return apiClient.fetch(requestUrl, { method: upper, json: payload, silent: true });
				}
				return apiClient.fetch(requestUrl, { method: upper, silent: true });
			}
			const requestToken = (() => {
				if (window.OC?.requestToken) {
					return window.OC.requestToken;
				}
				const fromHead = document.head?.getAttribute?.('data-requesttoken');
				if (fromHead) {
					return fromHead;
				}
				const meta = document.head.querySelector('meta[name="requesttoken"]');
				return meta ? meta.getAttribute('content') : '';
			})();
			try {
				const headers = {
					Accept: 'application/json',
					requesttoken: requestToken,
				};
				const init = {
					method: upper,
					headers,
					credentials: 'same-origin',
				};
				if (isMutating) {
					headers['Content-Type'] = 'application/json';
					const bodyObj = { ...(payload || {}) };
					if (requestToken && bodyObj.requesttoken == null) {
						bodyObj.requesttoken = requestToken;
					}
					init.body = JSON.stringify(bodyObj);
				}
				const response = await fetch(requestUrl, init);
				let data = null;
				const contentType = response.headers.get('content-type') || '';
				if (contentType.includes('application/json')) {
					try {
						data = await response.json();
					} catch (_e) {
						data = null;
					}
				}
				if (!response.ok) {
					const error = (data && typeof data.error === 'string' && data.error)
						|| (data && typeof data.message === 'string' && data.message)
						|| l10n.networkError
						|| 'Could not load status.';
					return { ok: false, status: response.status, data, error };
				}
				return { ok: true, status: response.status, data, error: null };
			} catch (_e) {
				return {
					ok: false,
					status: 0,
					data: null,
					error: l10n.networkError || 'Could not load status. Please check your connection.',
				};
			}
		};

		const isSuccessPayload = (result) => {
			if (!result || result.ok !== true) {
				return false;
			}
			if (apiClient && typeof apiClient.isApiSuccess === 'function') {
				return apiClient.isApiSuccess(result);
			}
			const body = result.data;
			return !!(body && body.success !== false);
		};

		const loadData = async () => {
			const employeeUrl = normalizeDeskletUrl(config.employeeDataUrl);
			if (!isSafeAppUrl(employeeUrl)) {
				showError(l10n.statusLoadError || l10n.networkError);
				return;
			}

			setLoading(true);
			if (!mutationInFlight) {
				hideError();
			}

			let employeeLoaded = false;

			try {
				const result = await fetchDesklet(employeeUrl);
				if (!result.ok) {
					if (!mutationInFlight) {
						if (result.status === 401 || result.status === 412 || result.status === 419) {
							showError(l10n.sessionExpired
								|| 'Your session has expired. Please refresh the page and try again.');
						} else {
							showError(result.error);
						}
					}
				} else if (isSuccessPayload(result) && result.data?.data) {
					renderEmployee(result.data.data);
					updateLastRefreshed();
					employeeLoaded = true;
				} else if (!mutationInFlight) {
					showError(result.error || l10n.statusLoadError || l10n.networkError);
				}
			} catch (_e) {
				if (!mutationInFlight) {
					showError(l10n.networkError
						|| 'Could not load status. Please check your connection.');
				}
			}

			if (employeeLoaded && config.isManager && managerListEl) {
				const managerUrl = normalizeDeskletUrl(config.managerDataUrl);
				if (isSafeAppUrl(managerUrl)) {
				try {
					const result = await fetchDesklet(managerUrl);
					if (result.ok && isSuccessPayload(result) && result.data?.data) {
						renderPeopleList(
							managerListEl,
							result.data.data.members || [],
							l10n.noTeamMembers || 'No team members found.',
						);
					}
				} catch (_e) {
					// Non-critical section.
				}
				}
			}

			if (employeeLoaded && config.isAdmin && adminListEl) {
				const adminUrl = normalizeDeskletUrl(config.adminDataUrl);
				if (isSafeAppUrl(adminUrl)) {
				try {
					const result = await fetchDesklet(adminUrl);
					if (result.ok && isSuccessPayload(result) && result.data?.data) {
						renderPeopleList(
							adminListEl,
							result.data.data.users || [],
							l10n.noUsersFound || 'No users found.',
						);
					}
				} catch (_e) {
					// Non-critical section.
				}
				}
			}

			setLoading(false);
		};

		const restoreButtonsFromLastKnown = () => {
			updateButtonStates(
				lastKnown.status,
				lastKnown.clockStampingEnabled !== false,
				Boolean(lastKnown.atDailyMaximum),
			);
		};

		const wireAction = (id, url) => {
			const actionUrl = normalizeDeskletUrl(url);
			const btn = el(id);
			if (!btn || !isSafeAppUrl(actionUrl)) {
				return;
			}

			btn.addEventListener('click', async () => {
				if (btn.disabled || mutationInFlight) {
					return;
				}

				mutationInFlight = true;
				setButtonsLocked(true);
				hideError();
				hideFeedback();

				try {
					const jsonBody = (id === 'dz-clock-in' && deskletActions
						&& typeof deskletActions.buildClockInJsonBody === 'function')
						? deskletActions.buildClockInJsonBody(projectSelectEl ? projectSelectEl.value : '')
						: (id === 'dz-clock-in' && projectSelectEl && String(projectSelectEl.value || '').trim() !== ''
							? { projectCheckProjectId: String(projectSelectEl.value).trim() }
							: {});
					const result = await fetchDesklet(actionUrl, 'POST', jsonBody);
					const body = result.data || {};

					if (!isSuccessPayload(result)) {
						const errMsg = (deskletActions
							&& typeof deskletActions.resolveActionErrorMessage === 'function')
							? deskletActions.resolveActionErrorMessage(result, l10n)
							: (result.error
								|| body.error
								|| body.message
								|| l10n.actionFailed
								|| 'Action failed');
						if (result.status === 403 && body.error_code === 'clock_stamping_disabled') {
							await loadData();
						}
						showError(errMsg);
						if (window.AzcMessaging?.announceAssertive) {
							window.AzcMessaging.announceAssertive(errMsg);
						} else {
							announce(errMsg);
						}
						restoreButtonsFromLastKnown();
						return;
					}

					if (body.status && typeof body.status === 'object') {
						renderEmployee(body.status);
						updateLastRefreshed();
					} else {
						await loadData();
					}

					const actionLabel = btn.textContent ? btn.textContent.trim() : (l10n.actionDone || 'Action');
					const doneTemplate = l10n.actionDone || '%1$s successful';
					const successMsg = doneTemplate.replace('%1$s', actionLabel);
					showFeedback(successMsg);
					announce(successMsg);
				} catch (_e) {
					const errMsg = l10n.networkError
						|| 'Could not load status. Please check your connection.';
					showError(errMsg);
					announce(errMsg);
					restoreButtonsFromLastKnown();
				} finally {
					mutationInFlight = false;
					restoreButtonsFromLastKnown();
				}
			});
		};

		if (retryBtn) {
			retryBtn.addEventListener('click', () => {
				hideError();
				loadData();
			});
		}

		wireAction('dz-clock-in', config.clockInUrl);
		wireAction('dz-start-break', config.startBreakUrl);
		wireAction('dz-end-break', config.endBreakUrl);
		wireAction('dz-clock-out', config.clockOutUrl);

		// Hide every action until the first successful status load confirms what
		// the user may actually do. If the initial load fails we show only the
		// error panel + retry, never a button that could fire an invalid action.
		actionButtons.forEach((btn) => {
			btn.hidden = true;
			btn.disabled = true;
			btn.setAttribute('aria-disabled', 'true');
		});
		if (projectPickerEl) {
			projectPickerEl.hidden = true;
		}
		if (projectSelectEl) {
			projectSelectEl.disabled = true;
		}

		loadData();
		const refreshTimer = window.setInterval(loadData, 30000);

		window.addEventListener('beforeunload', () => {
			window.clearInterval(refreshTimer);
			stopSessionTicker();
		});
	}

	scheduleDeskletBoot();
})();
