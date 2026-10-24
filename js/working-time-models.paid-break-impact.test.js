/**
 * @vitest-environment jsdom
 *
 * NH-03: the paid-break impact preview in the working-time-model editor —
 * debounced, stale-response-safe, and hidden when the editor matches the
 * persisted model.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

let T
let ajaxCalls

function buildForm({ flag = true, cap = '45' } = {}) {
	document.body.innerHTML = `
		<form id="f">
			<input type="checkbox" id="wtm-model-breaks-count-as-work" ${flag ? 'checked' : ''}>
			<input type="text" id="wtm-model-paid-break-cap" value="${cap}">
			<div id="wtm-model-paid-break-impact" role="status" hidden>
				<p id="wtm-model-paid-break-impact-text"></p>
			</div>
		</form>`
	return {
		form: document.getElementById('f'),
		box: document.getElementById('wtm-model-paid-break-impact'),
		text: document.getElementById('wtm-model-paid-break-impact-text'),
		checkbox: document.getElementById('wtm-model-breaks-count-as-work'),
		cap: document.getElementById('wtm-model-paid-break-cap'),
	}
}

function respond(call, data) {
	call.onSuccess({ success: true, data })
}

beforeAll(async () => {
	ajaxCalls = []
	window.ArbeitszeitCheckUtils = {
		ajax: (url, opts) => {
			ajaxCalls.push({ url, opts })
			return {
				onSuccess: (d) => opts.onSuccess(d),
				onError: () => opts.onError && opts.onError(),
			}
		},
		escapeHtml: (s) => String(s),
		$: (sel) => document.querySelector(sel),
		$$: (sel) => Array.from(document.querySelectorAll(sel)),
	}
	await import('./working-time-models.js')
	T = window.__ArbeitszeitCheckWorkingTimeModelsTestables
})

beforeEach(() => {
	ajaxCalls = []
	vi.useFakeTimers()
})

describe('bindPaidBreakImpact', () => {
	it('exports the binding helper', () => {
		expect(typeof T.bindPaidBreakImpact).toBe('function')
	})

	it('fetches and renders counts when the flag differs from saved state', () => {
		const { form, box, text, checkbox } = buildForm({ flag: true, cap: '45' })
		T.bindPaidBreakImpact(form, 42, true, '45')

		checkbox.checked = false
		checkbox.dispatchEvent(new Event('change'))
		expect(ajaxCalls).toHaveLength(0) // debounced — not immediate

		vi.advanceTimersByTime(300)
		expect(ajaxCalls).toHaveLength(1)
		expect(ajaxCalls[0].url).toBe('/apps/arbeitszeitcheck/api/admin/working-time-models/42/paid-break-impact')

		respond(ajaxCalls[0].opts, { affected_user_count: 2, affected_entry_count: 7 })
		expect(box.hidden).toBe(false)
		expect(text.textContent).toContain('7')
		expect(text.textContent).toContain('2')
	})

	it('stays hidden and silent while the editor matches the saved model', () => {
		const { form, box, cap } = buildForm({ flag: true, cap: '45' })
		T.bindPaidBreakImpact(form, 42, true, '45')

		cap.value = '45' // unchanged value still fires input
		cap.dispatchEvent(new Event('input'))
		vi.advanceTimersByTime(300)
		expect(ajaxCalls).toHaveLength(0)
		expect(box.hidden).toBe(true)
	})

	it('treats an empty cap as null and compares against the saved cap', () => {
		const { form, box, cap } = buildForm({ flag: true, cap: '45' })
		T.bindPaidBreakImpact(form, 42, true, null)

		cap.value = '' // cleared → null, but saved is also null → unchanged
		cap.dispatchEvent(new Event('input'))
		vi.advanceTimersByTime(300)
		expect(ajaxCalls).toHaveLength(0)
		expect(box.hidden).toBe(true)

		cap.value = '30'
		cap.dispatchEvent(new Event('input'))
		vi.advanceTimersByTime(300)
		expect(ajaxCalls).toHaveLength(1)
	})

	it('ignores stale responses from superseded requests', () => {
		const { form, text, checkbox, cap } = buildForm({ flag: true, cap: '45' })
		T.bindPaidBreakImpact(form, 42, true, '45')

		// First change → request 1
		checkbox.checked = false
		checkbox.dispatchEvent(new Event('change'))
		vi.advanceTimersByTime(300)
		expect(ajaxCalls).toHaveLength(1)
		// Second change before request 1 resolves → request 2
		cap.value = '10'
		cap.dispatchEvent(new Event('input'))
		vi.advanceTimersByTime(300)
		expect(ajaxCalls).toHaveLength(2)

		// Resolve request 1 late — its payload must be dropped.
		respond(ajaxCalls[0].opts, { affected_user_count: 99, affected_entry_count: 99 })
		expect(text.textContent).not.toContain('99')

		respond(ajaxCalls[1].opts, { affected_user_count: 3, affected_entry_count: 11 })
		expect(text.textContent).toContain('11')
		expect(text.textContent).toContain('3')
	})

	it('renders the no-impact message for zero affected rows', () => {
		const { form, box, text, checkbox } = buildForm({ flag: true, cap: '45' })
		T.bindPaidBreakImpact(form, 42, true, '45')

		checkbox.checked = false
		checkbox.dispatchEvent(new Event('change'))
		vi.advanceTimersByTime(300)

		respond(ajaxCalls[0].opts, { affected_user_count: 0, affected_entry_count: 0 })
		expect(box.hidden).toBe(false)
		expect(text.textContent).toMatch(/new records only/i)
	})

	it('renders the error message when the endpoint fails', () => {
		const { form, box, text, checkbox } = buildForm({ flag: true, cap: '45' })
		T.bindPaidBreakImpact(form, 42, true, '45')

		checkbox.checked = false
		checkbox.dispatchEvent(new Event('change'))
		vi.advanceTimersByTime(300)

		ajaxCalls[0].opts.onError()
		expect(box.hidden).toBe(false)
		expect(text.textContent).toMatch(/unavailable/i)
	})

	it('does nothing for an unsaved model (no id)', () => {
		const { form, box, checkbox } = buildForm({ flag: true, cap: '45' })
		T.bindPaidBreakImpact(form, null, true, '45')

		checkbox.checked = false
		checkbox.dispatchEvent(new Event('change'))
		vi.advanceTimersByTime(300)
		expect(ajaxCalls).toHaveLength(0)
		expect(box.hidden).toBe(true)
	})
})
