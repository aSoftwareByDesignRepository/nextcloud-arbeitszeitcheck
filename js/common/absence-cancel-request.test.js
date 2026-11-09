/**
 * @vitest-environment jsdom
 *
 * FA-2c owner cancel-request surface (js/common/absence-cancel-request.js):
 * delegated click → native <dialog>, single required reason field, CAS
 * payload, busy state, withdraw path, Escape close.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const MODULE = './absence-cancel-request.js'

function setupDom() {
	document.body.innerHTML = `
		<button id="req" data-cancel-request
			data-cancel-request-url="/api/absences/5/cancel-request"
			data-updated-at="2026-02-01T08:00:00+00:00">Request</button>
		<button id="wd" data-cancel-request-withdraw
			data-withdraw-url="/api/absences/5/cancel-request/withdraw"
			data-updated-at="2026-02-01T08:00:00+00:00">Withdraw</button>`
}

async function loadModule() {
	vi.resetModules()
	window.t = (_a, s) => s
	await import(MODULE)
}

beforeEach(() => {
	setupDom()
	// jsdom does not implement the dialog contract — polyfill the three
	// members the module relies on (open/showModal/close + close event).
	if (!HTMLDialogElement.prototype.showModal) {
		HTMLDialogElement.prototype.showModal = function () { this.open = true }
	}
	if (!HTMLDialogElement.prototype.close) {
		HTMLDialogElement.prototype.close = function () {
			this.open = false
			this.dispatchEvent(new Event('close'))
		}
	}
	if (!HTMLDialogElement.prototype.show) {
		HTMLDialogElement.prototype.show = function () { this.open = true }
	}
	window.AzcApi = { fetch: vi.fn(async () => ({ ok: true, data: { success: true } })) }
})

afterEach(() => {
	delete window.AzcApi
	delete window.t
	delete window.OC
	document.body.innerHTML = ''
})

describe('absence-cancel-request', () => {
	it('opens a dialog with one required reason field on request click', async () => {
		await loadModule()
		document.getElementById('req').click()
		const dlg = document.querySelector('dialog.azc-cancel-request')
		expect(dlg).toBeTruthy()
		expect(dlg.querySelector('#azc-cancel-request-title').textContent).toBe('Request cancellation')
		const reason = dlg.querySelector('#azc-cancel-request-reason')
		expect(reason.required).toBe(true)
		expect(reason.maxLength).toBe(500)
		expect(dlg.querySelector('label[for="azc-cancel-request-reason"]').textContent).toBe('Reason')
	})

	it('blocks empty submit with field error + aria-invalid, sends no request', async () => {
		await loadModule()
		document.getElementById('req').click()
		const dlg = document.querySelector('dialog.azc-cancel-request')
		dlg.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		expect(window.AzcApi.fetch).not.toHaveBeenCalled()
		const reason = dlg.querySelector('#azc-cancel-request-reason')
		expect(reason.getAttribute('aria-invalid')).toBe('true')
		const err = dlg.querySelector('#azc-cancel-request-error')
		expect(err.hidden).toBe(false)
		expect(err.textContent).toBe('Please provide a reason.')
	})

	it('posts expectedUpdatedAt + trimmed reason on valid submit', async () => {
		await loadModule()
		document.getElementById('req').click()
		const dlg = document.querySelector('dialog.azc-cancel-request')
		dlg.querySelector('#azc-cancel-request-reason').value = '  Trip fell through  '
		dlg.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		await vi.waitFor(() => expect(window.AzcApi.fetch).toHaveBeenCalledTimes(1))
		expect(window.AzcApi.fetch).toHaveBeenCalledWith('/api/absences/5/cancel-request', {
			method: 'POST',
			json: { expectedUpdatedAt: '2026-02-01T08:00:00+00:00', reason: 'Trip fell through' },
		})
	})

	it('shows the server error and re-enables submit on failure', async () => {
		window.AzcApi.fetch = vi.fn(async () => ({
			ok: true,
			data: { success: false, error: 'boom', error_code: 'cancel_request_pending' },
		}))
		await loadModule()
		document.getElementById('req').click()
		const dlg = document.querySelector('dialog.azc-cancel-request')
		dlg.querySelector('#azc-cancel-request-reason').value = 'reason'
		dlg.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		await vi.waitFor(() => {
			expect(dlg.querySelector('#azc-cancel-request-error').textContent).toBe('boom')
		})
		expect(dlg.querySelector('#azc-cancel-request-error').hidden).toBe(false)
		expect(dlg.querySelector('#azc-cancel-request-submit').disabled).toBe(false)
	})

	it('withdraw posts only the CAS token and disables the button in flight', async () => {
		let resolveFetch
		window.AzcApi.fetch = vi.fn(() => new Promise((r) => { resolveFetch = r }))
		await loadModule()
		const btn = document.getElementById('wd')
		btn.click()
		expect(btn.disabled).toBe(true)
		expect(window.AzcApi.fetch).toHaveBeenCalledWith('/api/absences/5/cancel-request/withdraw', {
			method: 'POST',
			json: { expectedUpdatedAt: '2026-02-01T08:00:00+00:00' },
		})
		resolveFetch({ ok: true, data: { success: false, error: 'gone' } })
		await vi.waitFor(() => expect(btn.disabled).toBe(false))
	})

	it('Escape closes the dialog without sending', async () => {
		await loadModule()
		document.getElementById('req').click()
		const dlg = document.querySelector('dialog.azc-cancel-request')
		dlg.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		expect(dlg.open).toBe(false)
		expect(window.AzcApi.fetch).not.toHaveBeenCalled()
	})
})
