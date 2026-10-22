import { beforeEach, describe, expect, it } from 'vitest'

import './common/timepicker-logic.js'
import './common/timepicker.js'
import './time-entry-form.js'

function mount() {
	const now = new Date()
	const date = `${String(now.getDate()).padStart(2, '0')}.${String(now.getMonth() + 1).padStart(2, '0')}.${now.getFullYear()}`
	document.body.innerHTML = `
		<form id="time-entry-form">
			<input id="entry-date" value="${date}">
			<div id="entry-date-error"></div>
			<div data-azc-timepicker data-allow-empty="0" data-required="1" data-minute-step="1">
				<input type="hidden" id="entry-start-time" name="startTime" value="09:00">
			</div>
			<div id="entry-start-time-error" style="display:none"></div>
			<div data-azc-timepicker data-allow-empty="0" data-required="1" data-minute-step="1">
				<input type="hidden" id="entry-end-time" name="endTime" value="12:00">
			</div>
			<div id="entry-end-time-error" style="display:none"></div>
			<div id="time-summary"><span id="summary-working-hours">0.00</span><span id="summary-break-time">0.00</span></div>
			<div id="compliance-status"></div>
			<div id="breaks-container">
				<div class="break-entry" data-break-index="0">
					<button type="button" class="btn-remove-break" data-break-index="0" disabled>Remove</button>
				</div>
			</div>
			<button type="button" id="btn-add-break">Add</button>
		</form>
	`
	window.ArbeitszeitCheck = {
		timeEntryForm: { mode: 'create', minuteStep: 1, breakIndex: 1 },
		l10n: {
			breakStartHour: 'Break start hour',
			breakStartMinute: 'Break start minute',
			breakEndHour: 'Break end hour',
			breakEndMinute: 'Break end minute',
			chooseBreakStartTime: 'Choose break start time',
			chooseBreakEndTime: 'Choose break end time',
			startTimeRequired: 'Start time is required',
			remove: 'Remove',
			removeBreak: 'Remove break',
			removeThisBreak: 'Remove this break',
			breakRowStartLabel: 'Break %1$s start',
			breakRowEndLabel: 'Break %1$s end',
		},
	}
	return new window.ArbeitszeitCheckTimeEntryForm.TimeEntryFormManager()
}

describe('create form time picker', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('updates the day summary when the start time changes', () => {
		const form = mount()
		form.updateTimeSummary()
		expect(document.getElementById('summary-working-hours').textContent).toBe('3.00')
		const hour = document.getElementById('entry-start-time-hour')
		hour.focus()
		hour.dispatchEvent(new KeyboardEvent('keydown', { key: '8', bubbles: true }))
		expect(document.getElementById('entry-start-time').value).toBe('08:00')
		expect(document.getElementById('summary-working-hours').textContent).toBe('4.00')
	})

	it('adds a break row with an empty picker and enables remove once a time is set', () => {
		const form = mount()
		document.getElementById('btn-add-break').click()
		const added = document.querySelector('#break-1-start')
		expect(added).not.toBeNull()
		expect(added.value).toBe('')
		expect(document.getElementById('break-1-start-hour').value).toBe('--')
		const remove = document.querySelector('[data-break-index="1"] .btn-remove-break')
		expect(remove.disabled).toBe(false)
		window.ArbeitszeitCheckTimePicker.setValue(added, '12:15')
		form.syncRemoveBreakButtons()
		expect(added.value).toBe('12:15')
		expect(remove.disabled).toBe(false)
	})

	it('shows a start-time error and focuses the hour when the value is empty', () => {
		const form = mount()
		const host = document.getElementById('entry-start-time').closest('[data-azc-timepicker]')
		host.__azcTimePicker.assign(null, null, false)
		const hour = document.getElementById('entry-start-time-hour')
		expect(form.validateForm()).toBe(false)
		expect(document.getElementById('entry-start-time-error').textContent).toContain('Start time is required')
		expect(hour.getAttribute('aria-invalid')).toBe('true')
		expect(document.activeElement).toBe(hour)
	})
})
