import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Split-service wiring in the model editor (split-day spec §7.2/§7.6):
 * the shared weekday-schedule component emits `split_service` inside the
 * v2 weekday_schedule document; an automatic split without a cut-off is
 * an inline field error, never a server round-trip.
 */

const T = () => window.__ArbeitszeitCheckWorkingTimeModelsTestables

function mountPanel(mode, model) {
	document.body.innerHTML = '<section id="wtm-editor-panel" hidden></section>'
	T().openEditorPanel(mode, model)
	return document.getElementById('wtm-editor-panel')
}

/** Monday 06:00–10:00 + 13:30–16:30 → one interrupted work day. */
function fillTwoBlockMonday(form) {
	const work = document.getElementById('create-wtm-mon-work')
	work.checked = true
	document.getElementById('create-wtm-mon-start').value = '06:00'
	document.getElementById('create-wtm-mon-end').value = '10:00'
	const fs = form.querySelector('.wtm-weekday-schedule')
	// Fire the change so refreshTotals re-enables the add-block button.
	work.dispatchEvent(new Event('change', { bubbles: true }))
	fs.querySelector('.wtm-add-block[data-day="mon"]').click()
	const extra = fs.querySelector('tr[data-day="mon"][data-extra-block]')
	extra.querySelector('.wtm-day-start').value = '13:30'
	extra.querySelector('.wtm-day-end').value = '16:30'
	return fs
}

beforeEach(async () => {
	vi.resetModules()
	window.ArbeitszeitCheckUtils = {
		escapeHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
		$: (sel) => document.querySelector(sel),
		$$: (sel) => Array.from(document.querySelectorAll(sel)),
		on: (el, ev, fn) => el.addEventListener(ev, fn),
		ajax: vi.fn(),
		confirmDestructiveAction: vi.fn().mockResolvedValue(false),
	}
	await import('./common/weekday-schedule.js')
	window.ArbeitszeitCheckComponents = { showConfirmDialog: vi.fn().mockResolvedValue(false) }
	window.ArbeitszeitCheckMessaging = { showError: vi.fn(), showSuccess: vi.fn() }
	window.ArbeitszeitCheck = {
		page: 'admin-working-time-models',
		l10n: {},
	}
	await import('./working-time-models.js')
})

describe('split-service payload', () => {
	it('emits split_service inside the v2 weekday_schedule once configured', () => {
		const host = mountPanel('create', null)
		const form = host.querySelector('#wtm-model-form')
		const fs = fillTwoBlockMonday(form)
		expect(fs.querySelector('.wtm-split-service').hidden).toBe(false)

		fs.querySelector('.wtm-split-auto').checked = true
		fs.querySelector('.wtm-split-cutoff').value = '16:00'
		fs.querySelector('.wtm-split-break-cover').checked = true

		const payload = T().buildBreakRulesPayload(form, null)
		expect(payload.error).toBeUndefined()
		expect(payload.weekday_schedule.version).toBe(2)
		expect(payload.weekday_schedule.split_service).toEqual({
			automatic_split: true,
			split_when_clock_out_after: '16:00',
			interruption_counts_as_break: true,
		})
	})

	it('blocks save with an inline cut-off error when automatic split lacks a time', () => {
		const host = mountPanel('create', null)
		const form = host.querySelector('#wtm-model-form')
		const fs = fillTwoBlockMonday(form)
		fs.querySelector('.wtm-split-auto').checked = true

		const payload = T().buildBreakRulesPayload(form, null)
		expect(payload.error).toBeTruthy()
		expect(payload.field).toBe('splitService')
	})

	it('routes the cut-off error inline on submit: disclosure opens, field marked invalid', () => {
		const host = mountPanel('create', null)
		const form = host.querySelector('#wtm-model-form')
		const fs = fillTwoBlockMonday(form)
		fs.querySelector('.wtm-split-auto').checked = true
		document.getElementById('wtm-model-name').value = 'Split test'

		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))

		expect(window.ArbeitszeitCheckUtils.ajax).not.toHaveBeenCalled()
		const details = fs.querySelector('.wtm-split-service')
		expect(details.open).toBe(true)
		const err = fs.querySelector('.wtm-split-cutoff-error')
		expect(err.hidden).toBe(false)
		expect(err.textContent.length).toBeGreaterThan(0)
		expect(fs.querySelector('.wtm-split-cutoff').getAttribute('aria-invalid')).toBe('true')
	})

	it('sends the canonical split_service to the models endpoint on submit', () => {
		const host = mountPanel('create', null)
		const form = host.querySelector('#wtm-model-form')
		const fs = fillTwoBlockMonday(form)
		fs.querySelector('.wtm-split-break-cover').checked = true
		document.getElementById('wtm-model-name').value = 'Split test'

		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))

		expect(window.ArbeitszeitCheckUtils.ajax).toHaveBeenCalledTimes(1)
		const [, opts] = window.ArbeitszeitCheckUtils.ajax.mock.calls[0]
		expect(opts.data.breakRules.weekday_schedule.split_service).toEqual({
			automatic_split: false,
			split_when_clock_out_after: null,
			interruption_counts_as_break: true,
		})
	})

	it('emits weekday_cutoffs only for days with overrides (NH-01)', () => {
		const host = mountPanel('create', null)
		const form = host.querySelector('#wtm-model-form')
		const fs = fillTwoBlockMonday(form)

		// Give Tuesday two blocks too so both days get a per-day input.
		const tueWork = document.getElementById('create-wtm-tue-work')
		tueWork.checked = true
		document.getElementById('create-wtm-tue-start').value = '06:00'
		document.getElementById('create-wtm-tue-end').value = '10:00'
		tueWork.dispatchEvent(new Event('change', { bubbles: true }))
		fs.querySelector('.wtm-add-block[data-day="tue"]').click()
		const tueExtra = fs.querySelector('tr[data-day="tue"][data-extra-block]')
		tueExtra.querySelector('.wtm-day-start').value = '13:30'
		tueExtra.querySelector('.wtm-day-end').value = '16:30'
		tueExtra.dispatchEvent(new Event('change', { bubbles: true }))

		fs.querySelector('.wtm-split-auto').checked = true
		fs.querySelector('.wtm-split-auto').dispatchEvent(new Event('change', { bubbles: true }))
		fs.querySelector('.wtm-split-cutoff').value = '16:00'

		// Per-day inputs exist for both multi-block days; override only Monday.
		const monInput = fs.querySelector('.wtm-split-day-cutoff[data-day="mon"]')
		const tueInput = fs.querySelector('.wtm-split-day-cutoff[data-day="tue"]')
		expect(monInput).toBeTruthy()
		expect(tueInput).toBeTruthy()
		expect(fs.querySelector('.wtm-split-day-cutoff[data-day="sat"]')).toBeNull()
		monInput.value = '17:00'

		const payload = T().buildBreakRulesPayload(form, null)
		expect(payload.error).toBeUndefined()
		expect(payload.weekday_schedule.split_service.weekday_cutoffs).toEqual({ mon: '17:00' })
	})
})

describe('copy-day helper (NH-03)', () => {
	it('arms a source day then pastes its blocks and break into the target day', () => {
		const host = mountPanel('create', null)
		const form = host.querySelector('#wtm-model-form')
		const fs = fillTwoBlockMonday(form)

		const monCopy = fs.querySelector('.wtm-copy-day[data-day="mon"]')
		const tueCopy = fs.querySelector('.wtm-copy-day[data-day="tue"]')
		expect(monCopy).toBeTruthy()
		expect(tueCopy).toBeTruthy()

		// Arm Monday — Tuesday becomes a paste target.
		monCopy.click()
		expect(monCopy.getAttribute('aria-pressed')).toBe('true')
		expect(fs.querySelector('.wtm-copy-status').textContent).toContain('Monday')

		// Paste into Tuesday — blocks are copied and the day activates.
		tueCopy.click()
		expect(document.getElementById('create-wtm-tue-work').checked).toBe(true)
		const tueRows = fs.querySelectorAll('tr[data-day="tue"]')
		expect(tueRows.length).toBe(2)
		expect(tueRows[0].querySelector('.wtm-day-start').value).toBe('06:00')
		expect(tueRows[1].querySelector('.wtm-day-start').value).toBe('13:30')
		expect(document.getElementById('create-wtm-tue-bstart').value).toBe('')
	})

	it('cancels arming on a second click of the same day', () => {
		const host = mountPanel('create', null)
		const form = host.querySelector('#wtm-model-form')
		const fs = fillTwoBlockMonday(form)
		const monCopy = fs.querySelector('.wtm-copy-day[data-day="mon"]')

		monCopy.click()
		expect(monCopy.getAttribute('aria-pressed')).toBe('true')
		monCopy.click()
		expect(monCopy.getAttribute('aria-pressed')).toBe('false')
		expect(fs.querySelector('.wtm-copy-status').textContent).toBe('')
	})
})
