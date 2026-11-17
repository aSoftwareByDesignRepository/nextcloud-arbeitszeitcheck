import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * §9.6 closure rule: `review`-state split entries stay closable but are
 * listed as a visible warning (with the affected dates) before finalize.
 */

const L10N = {}

function buildDom() {
	document.body.innerHTML = `
		<select id="month-closure-period"></select>
		<p id="month-closure-status"></p>
		<p id="month-closure-blocked" hidden></p>
		<p id="month-closure-deadline" hidden></p>
		<p id="month-closure-review-warning" hidden></p>
		<p id="month-closure-finalized-notice" hidden></p>
		<p id="month-closure-feedback"></p>
		<button id="month-closure-finalize"></button>
		<a id="month-closure-pdf" hidden></a>
	`
}

function loadModule() {
	return import('./month-closure.js?test=' + Date.now() + Math.random())
}

function respondTo(handlers, urlPart, payload) {
	const call = handlers.find((h) => h.url.includes(urlPart))
	expect(call, 'expected ajax call to ' + urlPart).toBeTruthy()
	call.options.onSuccess(payload)
}

function makeHandlers() {
	const handlers = []
	window.ArbeitszeitCheckUtils = {
		ajax: vi.fn((url, options) => {
			handlers.push({ url, options })
		}),
	}
	return handlers
}

describe('month-closure review warning (spec §9.6)', () => {
	beforeEach(() => {
		vi.resetModules()
		document.body.innerHTML = ''
		window.ArbeitszeitCheckUtils = {}
		window.ArbeitszeitCheck = { l10n: L10N }
		window.OC = { getLocale: () => 'en' }
	})

	async function boot(statusPayload) {
		buildDom()
		const handlers = makeHandlers()
		await loadModule()
		respondTo(handlers, '/periods', {
			success: true,
			featureEnabled: true,
			periods: [{ year: 2026, month: 11 }],
		})
		respondTo(handlers, '/status', statusPayload)
	}

	it('lists review entries as a warning with dates, without blocking finalize', async () => {
		await boot({
			success: true,
			featureEnabled: true,
			status: 'open',
			canFinalize: true,
			reviewWarning: '2 days in this month need review before finalizing.',
			reviewEntries: [
				{ id: 11, date: '2026-11-03', reason: 'dst_review' },
				{ id: 12, date: '2026-11-29', reason: 'dst_review' },
			],
		})

		const el = document.getElementById('month-closure-review-warning')
		expect(el.hidden).toBe(false)
		expect(el.textContent).toContain('2 days in this month need review')
		// dates are rendered so HR can find the entries
		expect(el.textContent).toMatch(/November 3, 2026/)
		expect(el.textContent).toMatch(/November 29, 2026/)
		// never a blocker
		expect(document.getElementById('month-closure-finalize').disabled).toBe(false)
		expect(document.getElementById('month-closure-blocked').hidden).toBe(true)
	})

	it('stays hidden and finalize stays enabled when no review entries exist', async () => {
		await boot({
			success: true,
			featureEnabled: true,
			status: 'open',
			canFinalize: true,
			reviewWarning: null,
			reviewEntries: [],
		})

		expect(document.getElementById('month-closure-review-warning').hidden).toBe(true)
		expect(document.getElementById('month-closure-finalize').disabled).toBe(false)
	})

	it('does not surface the warning on an already finalized month', async () => {
		await boot({
			success: true,
			featureEnabled: true,
			status: 'finalized',
			canFinalize: false,
			reviewWarning: '1 day in this month needs review before finalizing.',
			reviewEntries: [{ id: 11, date: '2026-11-03', reason: 'dst_review' }],
		})

		expect(document.getElementById('month-closure-review-warning').hidden).toBe(true)
		expect(document.getElementById('month-closure-finalize').disabled).toBe(true)
	})
})
