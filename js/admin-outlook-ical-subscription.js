/**
 * Admin UI for calendar (iCal) subscription links.
 */
(function () {
	'use strict'

	const Utils = window.ArbeitszeitCheckUtils || {}
	const Messaging = window.ArbeitszeitCheckMessaging || {}
	const App = window.ArbeitszeitCheck || {}
	const l10n = App.l10n || {}
	let activeSubscriptions = []
	let teamPickerApi = null
	let highlightSubscriptionId = null
	let activeSubscriptionsRequestId = 0

	function q(selector) {
		return document.querySelector(selector)
	}

	function setLive(message, isError) {
		const live = q('#outlookIcalLive')
		if (!live) {
			return
		}
		live.textContent = message || ''
		live.classList.toggle('admin-settings-live--error', !!isError)
		live.classList.toggle('admin-settings-live--success', !!message && !isError)
	}

	function escapeHtml(value) {
		if (Utils.escapeHtml) {
			return Utils.escapeHtml(value)
		}
		const div = document.createElement('div')
		div.textContent = String(value)
		return div.innerHTML
	}

	function formatWindowRange(windowStart, windowEnd) {
		if (!windowStart || !windowEnd) {
			return ''
		}
		const template = l10n.outlookWindowDates || '%1$s – %2$s'
		return template.replace('%1$s', windowStart).replace('%2$s', windowEnd)
	}

	function feedLanguageLabel(code) {
		if (!code) {
			return '—'
		}
		const select = q('#outlookIcalFeedLanguage')
		if (select) {
			const option = select.querySelector('option[value="' + String(code).replace(/"/g, '\\"') + '"]')
			if (option && option.textContent) {
				return option.textContent.trim()
			}
		}
		return String(code)
	}

	function formatCreateExistsNotice(entry) {
		const scope = entry.scopeLabel || entry.scopePath || String(entry.teamId)
		const language = feedLanguageLabel(entry.feedLanguageCode)
		const template = l10n.outlookCreateExists
			|| 'A subscription link already exists for %1$s (%2$s). Use “Rotate link” in the table below.'
		return template.replace('%1$s', scope).replace('%2$s', language)
	}

	function findSubscription(teamId, languageCode) {
		if (teamId === null || teamId === undefined || String(teamId) === '') {
			return null
		}
		if (!languageCode || String(languageCode).trim() === '') {
			return null
		}
		return activeSubscriptions.find((entry) => {
			return Number(entry.teamId) === Number(teamId)
				&& String(entry.feedLanguageCode) === String(languageCode)
		}) || null
	}

	function hasTeamScopeSelected() {
		const raw = q('#outlookIcalTeamId')?.value
		return raw !== null && raw !== undefined && String(raw) !== ''
	}

	function hasFeedLanguageSelected() {
		const select = q('#outlookIcalFeedLanguage')
		return !!(select && String(select.value || '').trim() !== '')
	}

	function refreshCreateForm() {
		const createButton = q('#outlookIcalCreateBtn')
		const existsNotice = q('#outlookIcalCreateExistsNotice')
		const existsNoticeText = q('#outlookIcalCreateExistsNoticeText')
		const teamId = q('#outlookIcalTeamId')?.value
		const languageCode = q('#outlookIcalFeedLanguage')?.value || ''
		const ready = hasTeamScopeSelected() && hasFeedLanguageSelected()
		const existing = ready ? findSubscription(teamId, languageCode) : null

		if (createButton) {
			createButton.disabled = !ready || !!existing
		}

		if (existsNotice && existsNoticeText) {
			if (existing) {
				existsNotice.hidden = false
				existsNoticeText.textContent = formatCreateExistsNotice(existing)
			} else {
				existsNotice.hidden = true
				existsNoticeText.textContent = ''
			}
		}
	}

	function readSubscriptionTableColumnLabels() {
		const labels = {}
		const table = q('#outlookIcalSubscriptionTable')
		if (!table) {
			return labels
		}
		table.querySelectorAll('thead th[data-col]').forEach((th) => {
			const key = th.getAttribute('data-col')
			if (key) {
				labels[key] = String(th.textContent || '').trim()
			}
		})
		return labels
	}

	function renderSubscriptionRow(entry, colLabels) {
		const subscriptionId = Number(entry.id)
		const scopeName = escapeHtml(entry.scopeLabel || entry.scopePath || String(entry.teamId))
		const languageName = escapeHtml(feedLanguageLabel(entry.feedLanguageCode))
		const windowLine = escapeHtml(formatWindowRange(entry.windowStart, entry.windowEnd) || '—')
		const feedFieldId = 'outlookIcalFeedUrl-' + subscriptionId
		const webcalUrl = String(entry.feedWebcalUrl || entry.feedUrl || '')
		const copyLabel = escapeHtml(l10n.outlookCopyLink || 'Copy link')
		const rotateLabel = escapeHtml(l10n.outlookRotateLink || 'Rotate link')
		const col = (key) => escapeHtml(colLabels[key] || key)

		const hasCopyableUrl = entry.urlAvailable && webcalUrl !== ''
		let urlCell = ''
		if (hasCopyableUrl) {
			urlCell = '<label class="visually-hidden" for="' + feedFieldId + '">' + col('url') + '</label>'
				+ '<input type="text" readonly id="' + feedFieldId + '" class="form-input outlook-ical-subscription-table__url-input" value="'
				+ escapeHtml(webcalUrl)
				+ '">'
		} else {
			urlCell = '<span class="outlook-ical-subscription-table__legacy">'
				+ escapeHtml(l10n.outlookLegacyUrlUnavailable || 'This link was created before encrypted storage was enabled. Rotate the link to generate a copyable URL.')
				+ '</span>'
		}

		const actions = '<div class="azc-table-actions outlook-ical-subscription-table__actions" role="group" aria-label="' + col('actions') + '">'
			+ (hasCopyableUrl
				? '<button type="button" class="azc-btn azc-btn--secondary azc-btn--touch outlook-ical-subscription-table__copy">' + copyLabel + '</button>'
				: '')
			+ '<button type="button" class="azc-btn azc-btn--secondary azc-btn--touch outlook-ical-subscription-table__rotate"'
			+ ' data-team-id="' + escapeHtml(String(entry.teamId)) + '"'
			+ ' data-language-code="' + escapeHtml(String(entry.feedLanguageCode || '')) + '">'
			+ rotateLabel
			+ '</button>'
			+ '</div>'

		return '<tr class="outlook-ical-subscription-table__row" data-subscription-id="' + subscriptionId + '">'
			+ '<td data-label="' + col('scope') + '">' + scopeName + '</td>'
			+ '<td data-label="' + col('language') + '">' + languageName + '</td>'
			+ '<td data-label="' + col('absences') + '" class="outlook-ical-subscription-table__absences tab-num">' + escapeHtml(String(entry.eventCount ?? 0)) + '</td>'
			+ '<td data-label="' + col('window') + '" class="outlook-ical-subscription-table__window">' + windowLine + '</td>'
			+ '<td data-label="' + col('url') + '" class="outlook-ical-subscription-table__url-cell">' + urlCell + '</td>'
			+ '<td data-label="' + col('actions') + '" class="actions-cell azc-table-actions-col">' + actions + '</td>'
			+ '</tr>'
	}

	function bindSubscriptionTableActions(container) {
		if (!container) {
			return
		}

		container.querySelectorAll('.outlook-ical-subscription-table__copy').forEach((button) => {
			button.addEventListener('click', () => {
				const row = button.closest('[data-subscription-id]')
				const field = row?.querySelector('.outlook-ical-subscription-table__url-input')
				const value = field ? String(field.value || '') : ''
				copyFeedUrl(value, field)
			})
		})

		container.querySelectorAll('.outlook-ical-subscription-table__rotate').forEach((button) => {
			button.addEventListener('click', () => {
				const row = button.closest('[data-subscription-id]')
				const subscriptionId = Number(row?.getAttribute('data-subscription-id'))
				const entry = activeSubscriptions.find((item) => Number(item.id) === subscriptionId)
				if (entry) {
					rotateSubscription(entry, button)
				}
			})
		})
	}

	function resetSubscriptionsEmptyMessage() {
		const empty = q('#outlookIcalActiveSubscriptionsEmpty')
		if (!empty) {
			return
		}
		const defaultText = empty.dataset.emptyText || 'No subscription links yet — create one below.'
		empty.textContent = defaultText
	}

	function setSubscriptionsLoading(isLoading) {
		const loading = q('#outlookIcalSubscriptionsLoading')
		const wrap = q('#outlookIcalActiveSubscriptionsSection .outlook-ical-subscription-table__wrap')
		const table = q('#outlookIcalSubscriptionTable')
		const empty = q('#outlookIcalActiveSubscriptionsEmpty')

		if (loading) {
			loading.hidden = !isLoading
			loading.setAttribute('aria-busy', isLoading ? 'true' : 'false')
		}
		if (isLoading) {
			if (wrap) {
				wrap.hidden = true
			}
			if (table) {
				table.hidden = true
			}
			if (empty) {
				empty.hidden = true
			}
		}
	}

	function showSubscriptionsLoadError(err) {
		const wrap = q('#outlookIcalActiveSubscriptionsSection .outlook-ical-subscription-table__wrap')
		const table = q('#outlookIcalSubscriptionTable')
		const empty = q('#outlookIcalActiveSubscriptionsEmpty')
		if (wrap) {
			wrap.hidden = true
		}
		if (table) {
			table.hidden = true
		}
		if (empty) {
			empty.hidden = false
			empty.textContent = (err && err.error)
				|| l10n.outlookActiveLoadFailed
				|| 'Could not load subscription links. Please try again.'
		}
		const message = (err && err.error)
			|| l10n.outlookActiveLoadFailed
			|| 'Could not load subscription links. Please try again.'
		Messaging.showError?.(message)
		setLive(message, true)
	}

	function renderSubscriptionTable(subscriptions) {
		activeSubscriptions = Array.isArray(subscriptions) ? subscriptions : []
		const wrap = q('#outlookIcalActiveSubscriptionsSection .outlook-ical-subscription-table__wrap')
		const table = q('#outlookIcalSubscriptionTable')
		const tbody = q('#outlookIcalSubscriptionTableBody')
		const empty = q('#outlookIcalActiveSubscriptionsEmpty')
		if (!table || !tbody || !empty) {
			refreshCreateForm()
			return
		}

		resetSubscriptionsEmptyMessage()

		if (activeSubscriptions.length === 0) {
			if (wrap) {
				wrap.hidden = true
			}
			table.hidden = true
			tbody.innerHTML = ''
			empty.hidden = false
			refreshCreateForm()
			return
		}

		empty.hidden = true
		if (wrap) {
			wrap.hidden = false
		}
		table.hidden = false
		const colLabels = readSubscriptionTableColumnLabels()
		tbody.innerHTML = activeSubscriptions.map((entry) => renderSubscriptionRow(entry, colLabels)).join('')
		bindSubscriptionTableActions(tbody)

		if (highlightSubscriptionId !== null) {
			highlightSubscriptionRow(highlightSubscriptionId)
			highlightSubscriptionId = null
		}

		refreshCreateForm()
	}

	function highlightSubscriptionRow(subscriptionId) {
		const tbody = q('#outlookIcalSubscriptionTableBody')
		if (!tbody || subscriptionId === null || subscriptionId === undefined) {
			return
		}
		const row = tbody.querySelector('[data-subscription-id="' + String(subscriptionId) + '"]')
		if (!row) {
			return
		}
		row.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
		row.classList.add('outlook-ical-subscription-table__row--highlight')
		window.setTimeout(() => {
			row.classList.remove('outlook-ical-subscription-table__row--highlight')
		}, 3000)
	}

	function loadActiveSubscriptions(highlightId) {
		const url = outlookActiveSubscriptionsApiUrl()
		if (!url) {
			setSubscriptionsLoading(false)
			return
		}
		if (highlightId !== null && highlightId !== undefined) {
			highlightSubscriptionId = highlightId
		}
		const requestId = ++activeSubscriptionsRequestId
		setSubscriptionsLoading(true)
		Utils.ajax(url, {
			method: 'GET',
			onSuccess(data) {
				if (requestId !== activeSubscriptionsRequestId) {
					return
				}
				setSubscriptionsLoading(false)
				renderSubscriptionTable(data && data.subscriptions ? data.subscriptions : [])
			},
			onError(err) {
				if (requestId !== activeSubscriptionsRequestId) {
					return
				}
				setSubscriptionsLoading(false)
				activeSubscriptions = []
				showSubscriptionsLoadError(err)
				refreshCreateForm()
			},
		})
	}

	async function copyFeedUrl(value, textarea) {
		if (!value) {
			return
		}
		try {
			await navigator.clipboard.writeText(value)
			Messaging.showSuccess?.(l10n.outlookCopySuccess || 'Subscription link copied.')
			setLive(l10n.outlookCopySuccess || 'Subscription link copied.', false)
		} catch (_error) {
			if (textarea) {
				textarea.focus()
				textarea.select()
			}
			try {
				document.execCommand('copy')
			} catch (_copyError) {
				// Fall through to manual-copy messaging.
			}
			Messaging.showSuccess?.(l10n.outlookCopyFallback || 'Copy the subscription link manually.')
			setLive(l10n.outlookCopyFallback || 'Copy the subscription link manually.', false)
		}
	}

	const OUTLOOK_API_PATHS = {
		teams: '/apps/arbeitszeitcheck/api/admin/outlook-ical/teams',
		create: '/apps/arbeitszeitcheck/api/admin/outlook-ical/create',
		rotate: '/apps/arbeitszeitcheck/api/admin/outlook-ical/rotate',
		activeSubscriptions: '/apps/arbeitszeitcheck/api/admin/outlook-ical/active-subscriptions',
		webcalLocalAccess: '/apps/arbeitszeitcheck/api/admin/outlook-ical/webcal-local-access',
	}

	function resolveOutlookApiUrl(path) {
		if (!path || typeof path !== 'string') {
			return ''
		}
		return Utils.buildAppUrl ? Utils.buildAppUrl(path) : path
	}

	function outlookCreateApiUrl() {
		return resolveOutlookApiUrl(OUTLOOK_API_PATHS.create)
	}

	function outlookRotateApiUrl() {
		return resolveOutlookApiUrl(OUTLOOK_API_PATHS.rotate)
	}

	function outlookActiveSubscriptionsApiUrl() {
		return resolveOutlookApiUrl(OUTLOOK_API_PATHS.activeSubscriptions)
	}

	function outlookTeamsApiUrl() {
		return resolveOutlookApiUrl(OUTLOOK_API_PATHS.teams)
	}

	function outlookWebcalLocalAccessApiUrl() {
		return resolveOutlookApiUrl(OUTLOOK_API_PATHS.webcalLocalAccess)
	}

	function createSubscription() {
		const teamId = q('#outlookIcalTeamId')?.value
		const languageCode = q('#outlookIcalFeedLanguage')?.value || ''
		const createButton = q('#outlookIcalCreateBtn')

		if (!hasTeamScopeSelected() || !hasFeedLanguageSelected()) {
			const message = l10n.outlookPickScopeLanguage || 'Pick who is included and a calendar language first.'
			Messaging.showError?.(message)
			setLive(message, true)
			return
		}

		if (findSubscription(teamId, languageCode)) {
			refreshCreateForm()
			return
		}

		createButton && (createButton.disabled = true)
		setLive(l10n.outlookCreating || l10n.outlookRotating || 'Generating subscription link…', false)

		Utils.ajax(outlookCreateApiUrl(), {
			method: 'POST',
			data: {
				teamId: Number(teamId),
				languageCode,
			},
			onSuccess(data) {
				const subscriptionId = data && data.subscriptionId
				loadActiveSubscriptions(subscriptionId)
				const message = l10n.outlookFeedReady || 'Subscription link ready.'
				Messaging.showSuccess?.(message)
				setLive(message, false)
				refreshCreateForm()
			},
			onError(err) {
				let message = (err && err.error) || l10n.outlookGenerateFailed || 'Failed to generate the subscription link.'
				if (err && err.status === 404) {
					message = l10n.outlookCreateRouteMissing || 'Could not reach the subscription API on this server. Reload the page; if it persists, disable and re-enable the ArbeitszeitCheck app in Apps.'
				}
				Messaging.showError?.(message)
				setLive(message, true)
				refreshCreateForm()
			},
		})
	}

	async function rotateSubscription(entry, triggerButton) {
		// Fail closed when confirm API missing — never native window.confirm (§7.3 / Soft→Full inventory).
		const confirmed = await (window.ArbeitszeitCheckUtils?.confirmDestructiveAction?.({
			title: l10n.outlookRotateLink || 'Rotate link',
			message: l10n.outlookRotateConfirm
				|| 'Rotate the subscription link now? Calendar apps will stop refreshing the old link immediately.',
			variant: 'danger',
			confirmLabel: l10n.outlookRotateLink || 'Rotate link',
		}) ?? Promise.resolve(null))
		if (!confirmed) {
			return
		}

		if (triggerButton) {
			triggerButton.disabled = true
		}
		setLive(l10n.outlookRotating || 'Generating subscription link…', false)

		Utils.ajax(outlookRotateApiUrl(), {
			method: 'POST',
			data: {
				teamId: Number(entry.teamId),
				languageCode: entry.feedLanguageCode,
			},
			onSuccess() {
				loadActiveSubscriptions(entry.id)
				const message = l10n.outlookFeedReady || 'Subscription link ready.'
				Messaging.showSuccess?.(message)
				setLive(message, false)
			},
			onError(err) {
				const message = (err && err.error) || l10n.outlookGenerateFailed || 'Failed to generate the subscription link.'
				Messaging.showError?.(message)
				setLive(message, true)
				if (triggerButton) {
					triggerButton.disabled = false
				}
			},
		})
	}

	function initTeamPicker() {
		const search = q('#outlookIcalTeamSearch')
		const hidden = q('#outlookIcalTeamId')
		const list = q('#outlookIcalTeamListbox')
		const status = q('#outlookIcalTeamStatus')
		const clear = q('#outlookIcalTeamClear')
		const wrap = q('#outlook-ical-team-picker')
		if (!search || !hidden || !list || !wrap) {
			return null
		}

		let requestId = 0

		function closeList() {
			list.hidden = true
			list.innerHTML = ''
			search.setAttribute('aria-expanded', 'false')
		}

		function openList() {
			list.hidden = false
			search.setAttribute('aria-expanded', 'true')
		}

		function setStatus(message) {
			if (status) {
				status.textContent = message || ''
			}
		}

		function selectTeam(team) {
			hidden.value = String(team.id)
			search.value = team.path || team.name || String(team.id)
			search.dataset.selectedLabel = search.value
			if (clear) {
				clear.hidden = false
			}
			closeList()
			setStatus(search.value)
			refreshCreateForm()
		}

		function clearSelection() {
			hidden.value = ''
			search.value = ''
			search.dataset.selectedLabel = ''
			if (clear) {
				clear.hidden = true
			}
			closeList()
			setStatus('')
			refreshCreateForm()
		}

		function effectiveSearchTerm() {
			const query = String(search.value || '').trim()
			const selected = String(search.dataset.selectedLabel || '').trim()
			// Re-opening the picker after a selection must list every scope, not filter by the label already shown.
			if (query !== '' && selected !== '' && query === selected) {
				return ''
			}
			return query
		}

		function renderTeamOption(team, index) {
			const name = String(team.name || team.path || team.id)
			const path = String(team.path || name)
			const showMeta = !team.orgWide && path !== name
			let inner = '<span class="user-picker__name">' + escapeHtml(name) + '</span>'
			if (showMeta) {
				inner += '<span class="user-picker__meta">' + escapeHtml(path) + '</span>'
			}
			return '<button type="button" class="user-picker__item" role="option" data-team-index="' + index + '">' + inner + '</button>'
		}

		function renderTeams(teams) {
			if (!Array.isArray(teams) || teams.length === 0) {
				list.innerHTML = '<div class="user-picker__item user-picker__item--muted" role="presentation">' + escapeHtml(l10n.outlookNoTeams || 'No matching teams found.') + '</div>'
				openList()
				setStatus(l10n.outlookNoTeams || 'No matching teams found.')
				return
			}
			list.innerHTML = teams.map((team, index) => renderTeamOption(team, index)).join('')
			openList()
			list.querySelectorAll('[data-team-index]').forEach((button) => {
				button.addEventListener('click', () => {
					const idx = Number(button.getAttribute('data-team-index'))
					selectTeam(teams[idx])
				})
			})
			setStatus(String(teams.length))
		}

		function loadTeams() {
			const params = new URLSearchParams({
				search: effectiveSearchTerm(),
				limit: '20',
			})
			const url = outlookTeamsApiUrl() + '?' + params.toString()
			const thisRequest = ++requestId
			list.innerHTML = '<div class="user-picker__item user-picker__item--muted" role="presentation">' + escapeHtml(l10n.outlookLoadingTeams || 'Loading teams…') + '</div>'
			openList()
			Utils.ajax(url, {
				method: 'GET',
				onSuccess(data) {
					if (thisRequest !== requestId) {
						return
					}
					renderTeams(data && data.teams ? data.teams : [])
				},
				onError(err) {
					if (thisRequest !== requestId) {
						return
					}
					const message = (err && err.error) || l10n.outlookTeamLoadFailed || 'Failed to load teams. Please try again.'
					list.innerHTML = '<div class="user-picker__item user-picker__item--muted" role="presentation">' + escapeHtml(message) + '</div>'
					openList()
					setStatus(message)
				},
			})
		}

		search.addEventListener('focus', () => {
			loadTeams()
		})
		search.addEventListener('input', () => {
			const query = String(search.value || '').trim()
			const selected = String(search.dataset.selectedLabel || '').trim()
			if (query !== selected) {
				hidden.value = ''
				search.dataset.selectedLabel = ''
			}
			if (clear) {
				clear.hidden = query === ''
			}
			refreshCreateForm()
			loadTeams()
		})
		search.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				closeList()
			}
		})
		document.addEventListener('click', (event) => {
			if (!wrap.contains(event.target)) {
				closeList()
			}
		})
		if (clear) {
			clear.addEventListener('click', clearSelection)
		}

		return {
			getValue: () => hidden.value,
			clear: clearSelection,
			prefetchOrgWide() {
				if (hidden.value !== '' || App.useAppTeams) {
					return
				}
				Utils.ajax(outlookTeamsApiUrl() + '?' + new URLSearchParams({ limit: '5' }).toString(), {
					method: 'GET',
					onSuccess(data) {
						const teams = data && data.teams ? data.teams : []
						const orgWide = teams.find((team) => team.orgWide || Number(team.id) === 0)
						if (orgWide) {
							selectTeam(orgWide)
						}
					},
				})
			},
		}
	}

	function initLanguageSelect() {
		const select = q('#outlookIcalFeedLanguage')
		if (!select) {
			return
		}
		const root = q('#outlook-ical-subscription')
		if (root && root.dataset.defaultFeedLanguage && !select.value) {
			select.value = root.dataset.defaultFeedLanguage
		}
		select.addEventListener('change', () => {
			refreshCreateForm()
		})
	}

	function initWebcalLocalAccessPanel() {
		const panel = q('#outlookIcalWebcalLocalAccess')
		const text = q('#outlookIcalWebcalLocalAccessText')
		const button = q('#outlookIcalEnableWebcalLocalBtn')
		const url = outlookWebcalLocalAccessApiUrl()
		if (!panel || !text || !url) {
			return
		}

		function renderState(data) {
			if (!data || data.enabled) {
				panel.hidden = true
				text.textContent = ''
				if (button) {
					button.hidden = true
				}
				return
			}

			panel.hidden = false
			if (data.canEnable) {
				text.textContent = l10n.outlookWebcalLocalAccessNeeded || 'Nextcloud Calendar on this server needs a one-time setting before it can subscribe to links hosted here.'
				if (button) {
					button.hidden = false
				}
				return
			}

			text.textContent = l10n.outlookWebcalLocalAccessAskAdmin || 'To subscribe in Nextcloud Calendar on this server, ask a Nextcloud server administrator to open this page and click “Allow Nextcloud Calendar subscriptions on this server”. Thunderbird and Outlook work without that step.'
			if (button) {
				button.hidden = true
			}
		}

		Utils.ajax(url, {
			method: 'GET',
			onSuccess(data) {
				renderState(data || {})
			},
			onError() {
				panel.hidden = true
			},
		})

		button?.addEventListener('click', () => {
			button.disabled = true
			Utils.ajax(url, {
				method: 'POST',
				onSuccess(data) {
					renderState(data || { enabled: true })
					const message = l10n.outlookWebcalLocalAccessEnabled || 'Nextcloud Calendar can now subscribe to feeds on this server.'
					Messaging.showSuccess?.(message)
					setLive(message, false)
				},
				onError(err) {
					const message = (err && err.error) || (l10n.outlookWebcalLocalAccessFailed || 'Could not enable calendar subscriptions on this server.')
					Messaging.showError?.(message)
					setLive(message, true)
					button.disabled = false
				},
			})
		})
	}

	function init() {
		if (!q('#section-outlook-subscription-heading')) {
			return
		}
		const root = q('#outlook-ical-subscription')
		if (root) {
			App.outlookIcalTeamsUrl = App.outlookIcalTeamsUrl || root.dataset.outlookTeamsUrl || ''
			App.outlookIcalCreateUrl = App.outlookIcalCreateUrl || root.dataset.outlookCreateUrl || ''
			App.outlookIcalRotateUrl = App.outlookIcalRotateUrl || root.dataset.outlookRotateUrl || ''
			App.outlookIcalActiveSubscriptionsUrl = App.outlookIcalActiveSubscriptionsUrl || root.dataset.outlookActiveSubscriptionsUrl || ''
			App.outlookIcalWebcalLocalAccessUrl = App.outlookIcalWebcalLocalAccessUrl || root.dataset.outlookWebcalLocalAccessUrl || ''
			App.useAppTeams = root.dataset.useAppTeams === '1'
			App.outlookIcalOrgWideAvailable = root.dataset.orgWideAvailable === '1'
		}
		teamPickerApi = initTeamPicker()
		initLanguageSelect()
		initWebcalLocalAccessPanel()
		loadActiveSubscriptions()
		q('#outlookIcalCreateBtn')?.addEventListener('click', createSubscription)
		refreshCreateForm()
		if (teamPickerApi && App.outlookIcalOrgWideAvailable) {
			teamPickerApi.prefetchOrgWide?.()
		}
	}

	if (typeof window !== 'undefined') {
		window.__ArbeitszeitCheckOutlookIcalTestables = {
			rotateSubscription: rotateSubscription,
		}
	}

	document.addEventListener('DOMContentLoaded', init)
})()
