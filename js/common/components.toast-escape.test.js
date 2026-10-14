import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Guard: toast must never render raw HTML/proxy error pages (giant "400").
 */

describe('ArbeitszeitCheckComponents showToast HTML safety', () => {
	beforeEach(async () => {
		document.body.innerHTML = '<div id="toast-container"></div>'
		vi.resetModules()
		delete globalThis.window.ArbeitszeitCheckComponents
		delete globalThis.window.AzcComponents
		await import('./components.js')
	})

	afterEach(() => {
		document.body.innerHTML = ''
		vi.restoreAllMocks()
	})

	it('escapes HTML error bodies so NC guest pages do not render inside the toast', () => {
		const Components = window.ArbeitszeitCheckComponents
		const html = '<!DOCTYPE html><html><body class="body-login"><div class="empty-content"><h2>400</h2></div></body></html>'
		Components.showToast({ type: 'error', message: html, duration: 0 })

		const toast = document.querySelector('.toast')
		expect(toast).toBeTruthy()
		expect(toast.querySelector('.empty-content')).toBeNull()
		expect(toast.querySelector('h2')).toBeNull()
		const msg = toast.querySelector('.toast-message')
		expect(msg).toBeTruthy()
		expect(msg.textContent).toContain('<!DOCTYPE html>')
		expect(msg.innerHTML).not.toContain('<div class="empty-content"')
		expect(msg.innerHTML).toContain('&lt;!DOCTYPE html&gt;')
	})

	it('escapes a bare status code string without creating special markup', () => {
		const Components = window.ArbeitszeitCheckComponents
		Components.showToast({ type: 'error', message: '400', title: '<b>Boom</b>', duration: 0 })
		const msg = document.querySelector('.toast-message')
		const title = document.querySelector('.toast-title')
		expect(msg.textContent).toBe('400')
		expect(title.textContent).toBe('<b>Boom</b>')
		expect(title.querySelector('b')).toBeNull()
	})

	it('dedups identical kind+text toasts instead of stacking', () => {
		const Components = window.ArbeitszeitCheckComponents
		Components.showToast({ type: 'error', message: 'Save failed', duration: 5000 })
		Components.showToast({ type: 'error', message: 'Save failed', duration: 5000 })
		Components.showToast({ type: 'error', message: 'Save failed', duration: 5000 })
		expect(document.querySelectorAll('.toast')).toHaveLength(1)

		// Different kind or different text must still render separately.
		Components.showToast({ type: 'success', message: 'Save failed', duration: 5000 })
		Components.showToast({ type: 'error', message: 'Other failure', duration: 5000 })
		expect(document.querySelectorAll('.toast')).toHaveLength(3)
	})

	it('resets the dismiss timer on a dedup hit', () => {
		vi.useFakeTimers()
		try {
			const Components = window.ArbeitszeitCheckComponents
			const first = Components.showToast({ type: 'info', message: 'Retryable', duration: 1000 })
			vi.advanceTimersByTime(900)
			const second = Components.showToast({ type: 'info', message: 'Retryable', duration: 1000 })
			expect(second).toBe(first)
			vi.advanceTimersByTime(900)
			// Original timer would have fired at t=1000; the reset keeps it alive.
			expect(document.querySelectorAll('.toast')).toHaveLength(1)
			vi.advanceTimersByTime(500) // dismiss at t≈1900 + 300ms removal
			expect(document.querySelectorAll('.toast')).toHaveLength(0)
		} finally {
			vi.useRealTimers()
		}
	})
})
