/**
 * @vitest-environment jsdom
 *
 * Per-surface open → cancel (no mutate) → confirm for admin/web confirms
 * previously umbrella-covered by utils.confirmDestructiveAction alone.
 * Each case imports the product module / exported call-site handler.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

function dialogShell(extra = '') {
	document.body.innerHTML = `
		<header id="header"></header>
		<nav id="app-navigation"></nav>
		<main id="azc-main-content" tabindex="-1">${extra}</main>`
}

async function loadUtilsComponents() {
	vi.resetModules()
	dialogShell()
	window.OC = { generateUrl: (u) => u, requestToken: 'tok', getLocale: () => 'en' }
	window.t = (_a, s) => s
	window.ArbeitszeitCheckMessaging = {
		showError: vi.fn(),
		showSuccess: vi.fn(),
		announceAssertive: vi.fn(),
		announcePolite: vi.fn(),
	}
	await import('./common/utils.js')
	await import('./common/components.js')
	window.AzcComponents = window.ArbeitszeitCheckComponents
}

async function openCancelConfirm(trigger, { typedPhrase = null, reason = null } = {}) {
	// Do not await trigger — product handlers await the dialog Promise.
	const first = Promise.resolve().then(() => trigger())
	await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy())
	document.querySelector('.confirm-dialog__cancel').click()
	await first
	await vi.waitFor(() => expect(document.querySelector('.confirm-dialog')).toBeFalsy())

	const second = Promise.resolve().then(() => trigger())
	await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy())
	const confirm = document.querySelector('.confirm-dialog__confirm')
	if (typedPhrase) {
		const typed = document.querySelector('.confirm-dialog__typed, input[id$="-typed"]')
		expect(typed).toBeTruthy()
		typed.value = typedPhrase
		typed.dispatchEvent(new Event('input', { bubbles: true }))
	}
	if (reason) {
		const reasonEl = document.querySelector('.confirm-dialog__reason')
		expect(reasonEl).toBeTruthy()
		reasonEl.value = reason
		reasonEl.dispatchEvent(new Event('input', { bubbles: true }))
	}
	await vi.waitFor(() => expect(confirm.disabled).toBe(false))
	confirm.click()
	await second
	await vi.waitFor(() => expect(document.querySelector('.confirm-dialog')).toBeFalsy())
}

afterEach(() => {
	document.body.innerHTML = ''
	vi.unstubAllGlobals()
	;[
		'ArbeitszeitCheckUtils',
		'ArbeitszeitCheckComponents',
		'AzcComponents',
		'ArbeitszeitCheckMessaging',
		'ArbeitszeitCheck',
		'ArbeitszeitCheckSettings',
		'AzcApi',
		'ARBEITSZEITCHECK_OT_PAYOUT',
		'__ArbeitszeitCheckAdminTeamsTestables',
		'__ArbeitszeitCheckComplianceViolationsTestables',
		'__ArbeitszeitCheckComplianceDashboardTestables',
		'__ArbeitszeitCheckWorkingTimeModelsTestables',
		'__ArbeitszeitCheckTariffRulesTestables',
		'__ArbeitszeitCheckOvertimePayoutsTestables',
		'__ArbeitszeitCheckAdminSettingsTestables',
	].forEach((k) => { delete window[k] })
})

describe('dlg-web GDPR delete (settings.js)', () => {
	beforeEach(async () => {
		await loadUtilsComponents()
		document.getElementById('azc-main-content').innerHTML =
			'<button type="button" id="btn-gdpr-delete" data-delete-url="/apps/arbeitszeitcheck/api/gdpr/delete">Delete</button>'
		window.ArbeitszeitCheck = { apiUrl: { gdprDelete: '/apps/arbeitszeitcheck/api/gdpr/delete' } }
		const fetchFn = vi.fn(async () => ({ ok: true, data: { success: true } }))
		window.AzcApi = { fetch: fetchFn }
		window.__gdprFetch = fetchFn
		await import('./settings.js')
		window.ArbeitszeitCheckSettings.setupGdprDelete()
	})

	it('cancel skips POST; typed confirm posts delete', async () => {
		const fetchFn = window.__gdprFetch
		await openCancelConfirm(() => document.getElementById('btn-gdpr-delete').click(), {
			typedPhrase: 'DELETE',
			reason: 'atlas-proof',
		})
		await vi.waitFor(() => expect(fetchFn).toHaveBeenCalledTimes(1))
		expect(fetchFn.mock.calls[0][1].method).toBe('POST')
	})
})

describe('dlg-web month-closure finalize', () => {
	beforeEach(async () => {
		await loadUtilsComponents()
		document.getElementById('azc-main-content').innerHTML = `
			<select id="month-closure-period"></select>
			<button type="button" id="month-closure-finalize" data-confirm-finalize="Lock March 2026?">Finalize</button>
			<div id="month-closure-blocked" hidden></div>
			<div id="month-closure-feedback"></div>
			<div id="month-closure-status"></div>
			<a id="month-closure-pdf" href="#"></a>`
		const ajax = vi.fn((url, opts) => {
			if (String(url).includes('/periods')) {
				Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({
					success: true,
					featureEnabled: true,
					periods: [{ year: 2026, month: 3 }],
				}))
				return Promise.resolve(null)
			}
			if (String(url).includes('/status')) {
				Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({
					success: true,
					finalized: false,
					canFinalize: true,
					blocked: false,
				}))
				return Promise.resolve(null)
			}
			if (String(url).includes('/finalize')) {
				Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({ success: true }))
				return Promise.resolve(null)
			}
			return Promise.resolve(null)
		})
		window.ArbeitszeitCheckUtils.ajax = ajax
		window.__mcAjax = ajax
		window.ArbeitszeitCheck = { l10n: {} }
		await import('./month-closure.js')
		const fin = document.getElementById('month-closure-finalize')
		await vi.waitFor(() => expect(fin.getAttribute('data-mc-bound')).toBe('1'))
		fin.disabled = false
	})

	it('cancel skips finalize POST; confirm finalizes', async () => {
		const ajax = window.__mcAjax
		await openCancelConfirm(() => document.getElementById('month-closure-finalize').click())
		await vi.waitFor(() => {
			expect(ajax.mock.calls.filter((c) => String(c[0]).includes('/finalize'))).toHaveLength(1)
		})
	})
})

describe('dlg-web admin-teams product handlers', () => {
	beforeEach(async () => {
		await loadUtilsComponents()
		document.getElementById('azc-main-content').innerHTML = `
			<div id="admin-teams-tree"></div>
			<div id="admin-teams-status"></div>
			<div id="teams-loading" class="hidden"></div>
			<div id="teams-empty" class="hidden"></div>`
		window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => {
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true, teams: [] }))
			return Promise.resolve({ success: true, teams: [] })
		})
		window.ArbeitszeitCheck = { teamsL10n: {}, teamsConfig: {} }
		await import('./admin-teams.js')
	})

	it('confirmRemoveMember cancel skips DELETE; confirm DELETEs', async () => {
		const t = window.__ArbeitszeitCheckAdminTeamsTestables
		const ajax = window.ArbeitszeitCheckUtils.ajax
		ajax.mockClear()
		ajax.mockImplementation((url, opts) => {
			if (opts && opts.method === 'DELETE') {
				Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({ success: true }))
				return Promise.resolve({ success: true })
			}
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true }))
			return Promise.resolve({ success: true })
		})
		await openCancelConfirm(() => { t.confirmRemoveMember(3, 'alice', 'Alice') })
		await vi.waitFor(() => {
			expect(ajax.mock.calls.filter((c) => c[1] && c[1].method === 'DELETE')).toHaveLength(1)
		})
	})

	it('showSimpleDeleteConfirm cancel then confirm DELETEs unit', async () => {
		const t = window.__ArbeitszeitCheckAdminTeamsTestables
		const ajax = window.ArbeitszeitCheckUtils.ajax
		ajax.mockClear()
		ajax.mockImplementation((url, opts) => {
			if (opts && opts.method === 'DELETE') {
				Promise.resolve().then(() => opts.onSuccess && opts.onSuccess({ success: true }))
				return Promise.resolve({ success: true })
			}
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true }))
			return Promise.resolve({ success: true })
		})
		await openCancelConfirm(() => { t.showSimpleDeleteConfirm(12, 'Ops') })
		await vi.waitFor(() => {
			expect(ajax.mock.calls.some((c) => c[1] && c[1].method === 'DELETE')).toBe(true)
		})
	})
})

describe('dlg-web admin-kiosk revoke (product bindRevokeButtons)', () => {
	beforeEach(async () => {
		await loadUtilsComponents()
		document.body.innerHTML = `
			<header id="header"></header>
			<nav id="app-navigation"></nav>
			<main id="azc-main-content" tabindex="-1">
				<div id="azc-kiosk-page"
					data-requesttoken="tok"
					data-i18n="{}"
					data-api-terminals="/apps/arbeitszeitcheck/api/admin/kiosk/terminals"
					data-api-terminal-revoke="/apps/arbeitszeitcheck/api/admin/kiosk/terminals/__ID__/revoke"
					data-api-credentials="/apps/arbeitszeitcheck/api/admin/kiosk/credentials"
					data-api-user-allowed="/apps/arbeitszeitcheck/api/admin/kiosk/users/__ID__/allowed"
					data-api-pin="/apps/arbeitszeitcheck/api/admin/kiosk/pin"
					data-api-users-search="/apps/arbeitszeitcheck/api/admin/kiosk/users">
					<div id="azc-kiosk-live"></div>
					<div id="azc-kiosk-alert"></div>
					<div id="azc-kiosk-feedback" hidden></div>
					<table><tbody id="azc-kiosk-terminals-body">
						<tr><td>
							<button type="button" class="azc-kiosk-revoke-terminal" data-terminal-id="term-1">Revoke</button>
						</td></tr>
					</tbody></table>
					<table><tbody id="azc-kiosk-creds-body"></tbody></table>
				</div>
			</main>`
		const fetchMock = vi.fn(async (url, init) => {
			if (String(url).includes('/credentials') && !(init && String(init.method || '').toUpperCase() === 'DELETE')) {
				return {
					ok: true,
					json: async () => ({
						data: {
							credentials: [{
								id: 'cred-1',
								userId: 'alice',
								displayName: 'Alice',
								kioskAllowed: true,
								type: 'pin',
							}],
						},
					}),
				}
			}
			if (String(url).includes('revoke') || (init && String(init.method || '').toUpperCase() === 'DELETE')) {
				return { ok: true, json: async () => ({ success: true }) }
			}
			return { ok: true, json: async () => ({ success: true, terminals: [] }) }
		})
		vi.stubGlobal('fetch', fetchMock)
		window.__kioskFetch = fetchMock
		await import('./admin-kiosk.js')
		await vi.waitFor(() => expect(document.querySelector('.azc-kiosk-delete-cred, .azc-kiosk-revoke-terminal')).toBeTruthy())
	})

	it('revoke cancel skips POST; confirm POSTs', async () => {
		const btn = document.querySelector('.azc-kiosk-revoke-terminal')
		expect(btn.dataset.bound).toBe('1')
		const fetchMock = window.__kioskFetch
		fetchMock.mockClear()
		await openCancelConfirm(() => btn.click())
		await vi.waitFor(() => {
			expect(fetchMock.mock.calls.some((c) => String(c[0]).includes('revoke'))).toBe(true)
		})
	})

	it('delete credential cancel skips DELETE; confirm DELETEs', async () => {
		await vi.waitFor(() => expect(document.querySelector('.azc-kiosk-delete-cred')).toBeTruthy())
		const btn = document.querySelector('.azc-kiosk-delete-cred')
		const fetchMock = window.__kioskFetch
		fetchMock.mockClear()
		await openCancelConfirm(() => btn.click())
		await vi.waitFor(() => {
			expect(fetchMock.mock.calls.some((c) => String(c[1]?.method || '').toUpperCase() === 'DELETE')).toBe(true)
		})
	})

	it('new PIN (hasPin) cancel skips POST; confirm POSTs pin', async () => {
		await vi.waitFor(() => expect(document.querySelector('.azc-kiosk-row-pin[data-has-pin="1"]')).toBeTruthy())
		const btn = document.querySelector('.azc-kiosk-row-pin[data-has-pin="1"]')
		expect(btn.disabled).toBe(false)
		const fetchMock = window.__kioskFetch
		fetchMock.mockImplementation(async (url, init) => {
			if (String(url).includes('/credentials') && !(init && String(init.method || '').toUpperCase() === 'DELETE')) {
				return {
					ok: true,
					json: async () => ({
						data: {
							credentials: [{
								id: 'cred-1',
								userId: 'alice',
								displayName: 'Alice',
								kioskAllowed: true,
								type: 'pin',
							}],
						},
					}),
				}
			}
			if (String(url).includes('pin') || String(url).includes('/api/admin/kiosk/pin')) {
				return { ok: true, json: async () => ({ success: true, pin: '123456' }) }
			}
			return { ok: true, json: async () => ({ success: true }) }
		})
		fetchMock.mockClear()
		await openCancelConfirm(() => btn.click())
		await vi.waitFor(() => {
			expect(fetchMock.mock.calls.some((c) => String(c[0]).includes('pin'))).toBe(true)
		})
	})
})

describe('dlg-web compliance / WTM / tariff / overtime / settings reopen (product testables)', () => {
	beforeEach(async () => {
		await loadUtilsComponents()
	})

	it('compliance-violations resolveViolation cancel then confirm', async () => {
		const ajax = vi.fn((url, opts) => {
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true, violations: [] }))
			return Promise.resolve({ success: true })
		})
		window.ArbeitszeitCheckUtils.ajax = ajax
		document.getElementById('azc-main-content').innerHTML =
			'<table><tbody id="violations-table-body"></tbody></table><div id="violations-empty" hidden></div>'
		await import('./compliance-violations.js')
		const t = window.__ArbeitszeitCheckComplianceViolationsTestables
		await openCancelConfirm(() => t.resolveViolation('5'))
		await vi.waitFor(() => expect(ajax.mock.calls.some((c) => String(c[0]).includes('/resolve'))).toBe(true))
	})

	it('compliance-dashboard runComplianceCheck cancel then confirm', async () => {
		const btn = document.createElement('button')
		btn.setAttribute('data-run-check-url', '/apps/arbeitszeitcheck/api/compliance/run')
		btn.innerHTML = '<span>Run</span>'
		document.getElementById('azc-main-content').appendChild(btn)
		const fetchFn = vi.fn(async () => ({ ok: true, data: { success: true, stats: { usersChecked: 1 } } }))
		window.AzcApi = {
			fetch: fetchFn,
			isApiSuccess: (r) => !!(r && r.ok),
		}
		await import('./compliance-dashboard.js')
		const t = window.__ArbeitszeitCheckComplianceDashboardTestables
		expect(t && typeof t.runComplianceCheck).toBe('function')
		await openCancelConfirm(() => t.runComplianceCheck(btn))
		await vi.waitFor(() => expect(fetchFn).toHaveBeenCalledTimes(1))
	})

	it('working-time-models handleDeleteModel cancel then typed confirm', async () => {
		const ajax = vi.fn((url, opts) => {
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true }))
			return Promise.resolve({ success: true })
		})
		window.ArbeitszeitCheckUtils.ajax = ajax
		window.ArbeitszeitCheck = { l10n: {} }
		document.getElementById('azc-main-content').innerHTML =
			'<table><tr><td>Full time</td><td><button type="button" data-model-id="2" class="btn-delete-model">Delete</button></td></tr></table>'
		await import('./working-time-models.js')
		const t = window.__ArbeitszeitCheckWorkingTimeModelsTestables
		const button = document.querySelector('.btn-delete-model')
		await openCancelConfirm(() => t.handleDeleteModel({ currentTarget: button, target: button }), {
			typedPhrase: 'DELETE',
		})
		await vi.waitFor(() => expect(ajax.mock.calls.some((c) => c[1] && c[1].method === 'DELETE')).toBe(true))
	})

	it('admin-tariff confirmAndCall cancel then confirm', async () => {
		document.getElementById('azc-main-content').innerHTML =
			'<div id="tariff-rules-root"></div><button id="tariff-rules-create"></button><button id="tariff-rules-refresh"></button>'
		vi.stubGlobal('fetch', vi.fn(async () => ({
			ok: true,
			json: async () => ({ success: true, ruleSets: [] }),
		})))
		window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => {
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true, ruleSets: [] }))
			return Promise.resolve({ success: true, ruleSets: [] })
		})
		await import('./admin-tariff-rules.js')
		const t = window.__ArbeitszeitCheckTariffRulesTestables
		const fn = vi.fn(async () => {})
		await openCancelConfirm(() => t.confirmAndCall('Disable', 'Disable module?', 'Disable', 'destructive', fn))
		expect(fn).toHaveBeenCalledTimes(1)
	})

	it('admin-overtime processBulk cancel then confirm', async () => {
		window.ARBEITSZEITCHECK_OT_PAYOUT = {
			bankEnabled: true,
			apiBulk: '/apps/arbeitszeitcheck/api/admin/overtime/bulk',
			apiList: '/apps/arbeitszeitcheck/api/admin/overtime/list',
			apiProcess: '/apps/arbeitszeitcheck/api/admin/overtime/process',
			apiExport: '/apps/arbeitszeitcheck/api/admin/overtime/export',
			i18n: {
				confirmBulkTitle: 'Pay out all pending',
				confirmBulk: 'Confirm bulk payout?',
				confirmBtn: 'Confirm',
			},
		}
		document.getElementById('azc-main-content').innerHTML = `
			<input id="ot-payout-year" value="2026" />
			<input id="ot-payout-month" value="3" />
			<div id="ot-payout-live"></div>
			<button id="ot-payout-bulk">Bulk</button>
			<button id="ot-payout-refresh"></button>
			<button id="ot-payout-export"></button>
			<table><tbody id="ot-payout-tbody"></tbody></table>`
		const fetchMock = vi.fn(async (url) => {
			if (String(url).includes('bulk')) {
				return { ok: true, json: async () => ({ success: true, result: { paid: 0, failed: 0 } }) }
			}
			return { ok: true, json: async () => ({ success: true, items: [] }) }
		})
		vi.stubGlobal('fetch', fetchMock)
		await import('./admin-overtime-payouts.js')
		const t = window.__ArbeitszeitCheckOvertimePayoutsTestables
		await openCancelConfirm(() => t.processBulk())
		await vi.waitFor(() => expect(fetchMock.mock.calls.some((c) => String(c[0]).includes('bulk'))).toBe(true))
	})

	it('admin-settings handleMonthReopen cancel then confirm', async () => {
		window.ArbeitszeitCheckUtils.$ = (sel) => document.querySelector(sel)
		window.ArbeitszeitCheckUtils.on = (el, ev, fn) => el && el.addEventListener(ev, fn)
		window.ArbeitszeitCheck = {
			l10n: {},
			monthClosureReopenUrl: '/apps/arbeitszeitcheck/api/admin/month-closure/reopen',
		}
		document.getElementById('azc-main-content').innerHTML = `
			<input id="monthClosureReopenUserId" value="emp1" />
			<input id="monthClosureReopenYear" value="2026" />
			<input id="monthClosureReopenMonth" value="3" />
			<textarea id="monthClosureReopenReason">correction</textarea>
			<button type="button" id="monthClosureReopenBtn">Reopen</button>
			<div id="monthClosureReopenLive"></div>
			<form id="admin-settings-form"></form>`
		const ajax = vi.fn((url, opts) => {
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true }))
			return Promise.resolve({ success: true })
		})
		window.ArbeitszeitCheckUtils.ajax = ajax
		await import('./admin-settings.js')
		const t = window.__ArbeitszeitCheckAdminSettingsTestables
		await openCancelConfirm(() => t.handleMonthReopen())
		await vi.waitFor(() => expect(ajax.mock.calls.some((c) => String(c[0]).includes('reopen'))).toBe(true))
	})
	it('time-entry-correction withdraw cancel then confirm (product init binder)', async () => {
		window.ArbeitszeitCheck = {
			apiUrl: { cancelCorrection: '/apps/arbeitszeitcheck/api/time-entries/__ID__/cancel-correction' },
			l10n: {
				confirmCancelCorrection: 'Withdraw this correction request?',
				correctionWithdrawTitle: 'Withdraw correction',
				correctionRemove: 'Withdraw',
				correctionWithdrawn: 'Withdrawn',
			},
		}
		const ajax = vi.fn((url, opts) => {
			Promise.resolve().then(() => opts && opts.onSuccess && opts.onSuccess({ success: true }))
			return Promise.resolve({ success: true })
		})
		window.ArbeitszeitCheckUtils.ajax = ajax
		document.getElementById('azc-main-content').innerHTML =
			'<button type="button" class="btn-cancel-correction" data-entry-id="88">Withdraw</button>'
		await import('./time-entry-correction.js')
		document.dispatchEvent(new Event('DOMContentLoaded'))
		await openCancelConfirm(() => document.querySelector('.btn-cancel-correction').click())
		await vi.waitFor(() => expect(ajax.mock.calls.some((c) => String(c[0]).includes('cancel-correction'))).toBe(true))
	})

	it('admin-user-detail promptUnsavedLeave cancel then confirm', async () => {
		window.ArbeitszeitCheck = { l10n: {}, adminUserDetail: {} }
		await import('./admin-user-detail.js')
		const t = window.__ArbeitszeitCheckAdminUserDetailTestables
		expect(t && typeof t.promptUnsavedLeave).toBe('function')
		let confirmedOnce = false
		const first = Promise.resolve().then(() => t.promptUnsavedLeave())
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy())
		document.querySelector('.confirm-dialog__cancel').click()
		await expect(first).resolves.toBeFalsy()

		const second = Promise.resolve().then(async () => {
			confirmedOnce = !!(await t.promptUnsavedLeave())
		})
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy())
		document.querySelector('.confirm-dialog__confirm').click()
		await second
		expect(confirmedOnce).toBe(true)
	})
})

describe('dlg-web admin-license seat remove (product listener)', () => {
	beforeEach(async () => {
		await loadUtilsComponents()
		document.body.innerHTML = `
			<header id="header"></header>
			<nav id="app-navigation"></nav>
			<main id="azc-main-content">
				<div id="azc-license-page"
					data-api-license="/apps/arbeitszeitcheck/api/admin/license"
					data-api-clear-license="/apps/arbeitszeitcheck/api/admin/license"
					data-api-seats="/apps/arbeitszeitcheck/api/admin/license/seats"
					data-api-remove-seat="/apps/arbeitszeitcheck/api/admin/license/remove-seat"
					data-api-search-users="/apps/arbeitszeitcheck/api/admin/license/users"
					data-requesttoken="tok"
					data-i18n="{}">
					<div id="azc-license-live"></div>
					<div id="azc-license-alert"></div>
					<div id="azc-license-feedback" hidden></div>
					<table><tbody id="azc-seat-list-body">
						<tr><td><button type="button" class="azc-seat-remove" data-user-id="bob">Remove</button></td></tr>
					</tbody></table>
					<div id="azc-seat-empty" hidden></div>
					<span id="azc-seat-count"></span>
				</div>
			</main>`
		const fetchMock = vi.fn(async (url, init) => {
			if (String(url).includes('remove-seat')) {
				return { ok: true, json: async () => ({ ok: true }) }
			}
			return { ok: true, json: async () => ({ ok: true, seats: [{ userId: 'bob', displayName: 'Bob' }] }) }
		})
		vi.stubGlobal('fetch', fetchMock)
		window.__licenseFetch = fetchMock
		await import('./admin-license.js')
		await Promise.resolve()
	})

	it('seat remove cancel skips POST; confirm removes', async () => {
		const fetchMock = window.__licenseFetch
		fetchMock.mockClear()
		await openCancelConfirm(() => document.querySelector('.azc-seat-remove').click())
		await vi.waitFor(() => {
			expect(fetchMock.mock.calls.some((c) => String(c[0]).includes('remove-seat'))).toBe(true)
		})
	})
})
