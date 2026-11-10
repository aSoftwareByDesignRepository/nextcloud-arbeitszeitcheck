import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Filter behaviour for the compliance violations list.
 * The module captures window.ArbeitszeitCheckUtils once at import, so the
 * stub is installed before the dynamic import runs.
 */

const ajaxCalls = []
let api

beforeAll(async () => {
	window.ArbeitszeitCheckUtils = {
		$: (sel) => document.querySelector(sel),
		on: (el, ev, fn) => el.addEventListener(ev, fn),
		escapeHtml: (text) => {
			const div = document.createElement('div')
			div.textContent = text == null ? '' : String(text)
			return div.innerHTML
		},
		ajax: vi.fn((url, opts) => {
			ajaxCalls.push({ url, opts })
		}),
		confirmDestructiveAction: vi.fn(async () => true),
	}
	window.ArbeitszeitCheck = { complianceViolationsL10n: {}, complianceViolationsScope: { canSeeScope: false, columns: 5 } }
	await import('./compliance-violations.js')
	api = window.__ArbeitszeitCheckComplianceViolationsTestables
})

function violationsDom() {
	document.body.innerHTML = `
		<dd id="azc-compliance-open-count" class="compliance-status__tile-value--alert">3</dd>
		<dd id="azc-compliance-fixed-count">12</dd>
		<select id="severity-filter">
			<option value=""></option>
			<option value="error"></option>
			<option value="warning"></option>
			<option value="info"></option>
		</select>
		<select id="type-filter">
			<option value=""></option>
			<option value="missing_break"></option>
		</select>
		<input id="start-date" value="">
		<input id="end-date" value="">
		<table id="violations-table" data-filter-user-id="">
			<tbody id="violations-tbody"><tr><td>old</td></tr></tbody>
		</table>`
}

describe('buildViolationParams — wire contract with the controller', () => {
	it('uses the exact camelCase names the dispatcher binds', () => {
		const params = api.buildViolationParams({
			startDate: '2026-10-01',
			endDate: '2026-10-31',
			severity: 'error',
			violationType: 'missing_break',
			userId: 'ada',
			scope: '',
		})
		const qs = params.toString()
		expect(qs).toContain('startDate=2026-10-01')
		expect(qs).toContain('endDate=2026-10-31')
		expect(qs).toContain('severity=error')
		expect(qs).toContain('violationType=missing_break')
		expect(qs).toContain('userId=ada')
		// Regression: snake_case never binds to the controller signature.
		expect(qs).not.toContain('start_date')
		expect(qs).not.toContain('end_date')
		expect(qs).not.toContain('violation_type')
		expect(qs).not.toContain('scope=')
	})

	it('omits empty filters and prefers userId over scope', () => {
		expect(api.buildViolationParams({}).toString()).toBe('')
		const scoped = api.buildViolationParams({ severity: 'info', userId: '', scope: 'managed' }).toString()
		expect(scoped).toBe('severity=info&scope=managed')
		const picked = api.buildViolationParams({ userId: 'ada', scope: 'managed' }).toString()
		expect(picked).toBe('userId=ada')
	})
})

describe('loadViolations', () => {
	beforeEach(() => {
		ajaxCalls.length = 0
		violationsDom()
	})

	it('sends the current filter values through the API', () => {
		document.getElementById('severity-filter').value = 'error'
		document.getElementById('type-filter').value = 'missing_break'
		api.loadViolations()

		expect(ajaxCalls).toHaveLength(1)
		expect(ajaxCalls[0].url).toContain('/api/compliance/violations?')
		expect(ajaxCalls[0].url).toContain('severity=error')
		expect(ajaxCalls[0].url).toContain('violationType=missing_break')
	})

	it('a stale response cannot overwrite a newer filter result', () => {
		api.loadViolations()
		api.loadViolations()
		expect(ajaxCalls).toHaveLength(2)

		const stale = ajaxCalls[0].opts
		const fresh = ajaxCalls[1].opts
		stale.onSuccess({
			success: true,
			violations: [{ id: 1, type: 'missing_break', severity: 'error', date: '2026-10-21', resolved: false, can_resolve: false, description: 'STALE ROW' }],
		})
		expect(document.getElementById('violations-tbody').textContent).not.toContain('STALE ROW')

		fresh.onSuccess({
			success: true,
			violations: [{ id: 2, type: 'night_work', severity: 'warning', date: '2026-10-22', resolved: false, can_resolve: false, description: 'FRESH ROW' }],
		})
		expect(document.getElementById('violations-tbody').textContent).toContain('FRESH ROW')
	})
})

describe('status tiles after resolving a violation', () => {
	beforeEach(() => {
		ajaxCalls.length = 0
		violationsDom()
	})

	it('moves the count from open to fixed exactly once per success', async () => {
		await api.resolveViolation('42')
		const call = ajaxCalls.find((c) => c.url.includes('/resolve'))
		expect(call, 'resolve POST was issued').toBeTruthy()
		call.opts.onSuccess({ success: true })

		expect(document.getElementById('azc-compliance-open-count').textContent).toBe('2')
		expect(document.getElementById('azc-compliance-fixed-count').textContent).toBe('13')
	})

	it('a double-fired resolve sends exactly one POST', async () => {
		await Promise.all([api.resolveViolation('42'), api.resolveViolation('42')])
		const posts = ajaxCalls.filter((c) => c.url.includes('/resolve'))
		expect(posts).toHaveLength(1)
	})

	it('never lets the counters go negative and keeps the alert class honest', () => {
		api.adjustComplianceTiles(99)
		expect(document.getElementById('azc-compliance-open-count').textContent).toBe('0')
		expect(document.getElementById('azc-compliance-fixed-count').textContent).toBe('111')
		expect(document.getElementById('azc-compliance-open-count').classList.contains('compliance-status__tile-value--alert')).toBe(false)
		// Un-resolving (negative delta) moves the count back, still clamped.
		api.adjustComplianceTiles(-5)
		expect(document.getElementById('azc-compliance-open-count').textContent).toBe('5')
		expect(document.getElementById('azc-compliance-fixed-count').textContent).toBe('106')
		expect(document.getElementById('azc-compliance-open-count').classList.contains('compliance-status__tile-value--alert')).toBe(true)
	})
})
