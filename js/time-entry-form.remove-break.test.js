import { beforeEach, describe, expect, it } from 'vitest'

import './common/timepicker-logic.js'
import './common/timepicker.js'
import './time-entry-form.js'

function mountForm(rows) {
	document.body.innerHTML = `
		<form id="time-entry-form">
			<div id="time-summary" hidden></div>
			<span id="summary-working-hours"></span>
			<span id="summary-break-time"></span>
			<div id="compliance-status"></div>
			<div id="breaks-container">
				${rows.map((row, index) => `
					<div class="break-entry" data-break-index="${index}">
						<input type="hidden" class="break-start-time" data-break-index="${index}" value="${row.start}">
						<input type="hidden" class="break-end-time" data-break-index="${index}" value="${row.end}">
						<button type="button" class="btn-remove-break" data-break-index="${index}">Remove</button>
					</div>
				`).join('')}
			</div>
		</form>
	`
	window.ArbeitszeitCheck = { timeEntryForm: { mode: 'create' } }
	return new window.ArbeitszeitCheckTimeEntryForm.TimeEntryFormManager()
}

describe('break remove button', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('stays disabled on the single empty row and enables once a time is set', () => {
		const form = mountForm([{ start: '', end: '' }])
		const button = document.querySelector('.btn-remove-break')
		form.syncRemoveBreakButtons()
		expect(button.disabled).toBe(true)

		document.querySelector('.break-start-time').value = '12:00'
		form.syncRemoveBreakButtons()
		expect(button.disabled).toBe(false)

		document.querySelector('.break-start-time').value = ''
		form.syncRemoveBreakButtons()
		expect(button.disabled).toBe(true)
	})

	it('shows the missing-break warning only when submit validation fails', () => {
		document.body.innerHTML = `
			<form id="time-entry-form">
				<input id="entry-date" value="20.10.2026">
				<input id="entry-start-time" value="09:00">
				<input id="entry-end-time" value="17:00">
				<div id="time-summary" hidden></div>
				<span id="summary-working-hours"></span>
				<span id="summary-break-time"></span>
				<div id="compliance-status"></div>
				<div id="breaks-container">
					<div class="break-entry" data-break-index="0">
						<input type="hidden" class="break-start-time" value="">
						<input type="hidden" class="break-end-time" value="">
					</div>
				</div>
				<div id="break-submit-error" class="form-error" hidden></div>
			</form>
		`
		window.ArbeitszeitCheck = { timeEntryForm: { mode: 'create' } }
		window.ArbeitszeitCheckValidation = {
			evaluateBreakCompliance: () => ({ ok: false, requiredMinutes: 30, splitInvalid: false, message: 'need 30' }),
		}
		const form = new window.ArbeitszeitCheckTimeEntryForm.TimeEntryFormManager()
		const error = document.getElementById('break-submit-error')
		expect(error.hidden).toBe(true)
		expect(form.validateWorkDuration()).toBe(false)
		expect(error.hidden).toBe(false)
		expect(error.textContent).toBe('30 minutes break required')
		form.clearValidationErrors()
		expect(error.hidden).toBe(true)
		delete window.ArbeitszeitCheckValidation
	})

	it('restores an empty break row after the automatic row is removed', () => {
		const form = mountForm([{ start: '12:00', end: '12:30' }])
		document.querySelector('.break-entry').setAttribute('data-auto-break', 'true')
		form.removeAutoAddedBreaks()
		const rows = document.querySelectorAll('.break-entry')
		expect(rows.length).toBe(1)
		expect(rows[0].hasAttribute('data-auto-break')).toBe(false)
		expect(rows[0].querySelector('.break-start-time').value).toBe('')
		expect(rows[0].querySelector('.break-end-time').value).toBe('')
	})

	it('enables remove on extra empty rows so they can be deleted', () => {
		const form = mountForm([
			{ start: '', end: '' },
			{ start: '', end: '' },
		])
		form.syncRemoveBreakButtons()
		const buttons = document.querySelectorAll('.btn-remove-break')
		expect(buttons[0].disabled).toBe(false)
		expect(buttons[1].disabled).toBe(false)
	})
})
