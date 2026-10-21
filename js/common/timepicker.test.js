import { beforeEach, describe, expect, it } from 'vitest'

import './timepicker-logic.js'
import './timepicker.js'

const TP = window.ArbeitszeitCheckTimePicker

function host(html) {
	document.body.innerHTML = html
	const root = document.querySelector('[data-azc-timepicker]')
	return TP.enhance(root)
}

function press(el, key) {
	el.dispatchEvent(new KeyboardEvent('keydown', { key: key, bubbles: true }))
}

describe('time picker field', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		window.ArbeitszeitCheck = { timeEntryForm: { minuteStep: 5 }, l10n: {} }
	})

	it('shows the hidden value as hour and minute sections', () => {
		host(`
			<div data-azc-timepicker data-allow-empty="0" data-required="1"
				data-hour-label="Start hour" data-minute-label="Start minute" data-open-label="Choose start time">
				<input type="hidden" id="entry-start-time" name="startTime" value="09:00">
			</div>
		`)
		const hour = document.getElementById('entry-start-time-hour')
		const minute = document.getElementById('entry-start-time-minute')
		expect(hour.value).toBe('09')
		expect(minute.value).toBe('00')
		expect(hour.getAttribute('role')).toBe('spinbutton')
		expect(hour.getAttribute('aria-valuenow')).toBe('9')
		expect(hour.getAttribute('aria-label')).toBe('Start hour')
		expect(document.getElementById('entry-start-time').value).toBe('09:00')
	})

	it('types a two-digit hour and keeps the hidden value in sync', () => {
		host(`
			<div data-azc-timepicker data-allow-empty="0" data-minute-step="1">
				<input type="hidden" id="entry-start-time" name="startTime" value="09:00">
			</div>
		`)
		const hour = document.getElementById('entry-start-time-hour')
		hour.focus()
		press(hour, '1')
		expect(hour.value).toBe('01')
		expect(document.getElementById('entry-start-time').value).toBe('01:00')
		press(hour, '5')
		expect(hour.value).toBe('15')
		expect(document.getElementById('entry-start-time').value).toBe('15:00')
		expect(document.activeElement.id).toBe('entry-start-time-minute')
	})

	it('lets a typed minute sit off the step grid and offers it in the list', () => {
		const picker = host(`
			<div data-azc-timepicker data-allow-empty="0" data-minute-step="5">
				<input type="hidden" id="entry-start-time" value="08:00">
			</div>
		`)
		const minute = document.getElementById('entry-start-time-minute')
		minute.focus()
		press(minute, '0')
		press(minute, '7')
		expect(minute.value).toBe('07')
		expect(document.getElementById('entry-start-time').value).toBe('08:07')
		picker.open()
		const labels = Array.from(document.querySelectorAll('[data-column="minute"] .azc-timepicker__option'))
			.map((el) => el.textContent)
		expect(labels).toContain('07')
		expect(labels).toContain('05')
		picker.close(false)
	})

	it('picks an hour then a minute and closes', () => {
		const picker = host(`
			<div data-azc-timepicker data-allow-empty="0" data-minute-step="15"
				data-open-label="Choose end time" data-hours-label="Hours" data-minutes-label="Minutes">
				<input type="hidden" id="entry-end-time" value="17:00">
			</div>
		`)
		document.getElementById('entry-end-time-open').click()
		expect(document.querySelector('[role="dialog"]').getAttribute('aria-label')).toBe('Choose end time')
		const hour = document.querySelector('[data-column="hour"] [data-value="18"]')
		hour.click()
		expect(document.getElementById('entry-end-time').value).toBe('18:00')
		expect(picker.isOpen).toBe(true)
		document.querySelector('[data-column="minute"] [data-value="45"]').click()
		expect(document.getElementById('entry-end-time').value).toBe('18:45')
		expect(picker.isOpen).toBe(false)
		expect(document.querySelector('[role="dialog"]')).toBeNull()
	})

	it('closes the previous picker when another opens', () => {
		document.body.innerHTML = `
			<div data-azc-timepicker data-allow-empty="0">
				<input type="hidden" id="entry-start-time" value="09:00">
			</div>
			<div data-azc-timepicker data-allow-empty="0">
				<input type="hidden" id="entry-end-time" value="17:00">
			</div>
		`
		TP.initInRoot(document)
		document.getElementById('entry-start-time-open').click()
		document.getElementById('entry-end-time-open').click()
		expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1)
		expect(document.getElementById('entry-end-time-open').getAttribute('aria-expanded')).toBe('true')
		expect(document.getElementById('entry-start-time-open').getAttribute('aria-expanded')).toBe('false')
	})

	it('closes on Escape and on a press outside the field', () => {
		const picker = host(`
			<div data-azc-timepicker data-allow-empty="0">
				<input type="hidden" id="entry-start-time" value="09:00">
			</div>
			<button type="button" id="elsewhere">Elsewhere</button>
		`)
		const open = document.getElementById('entry-start-time-open')
		open.click()
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
		expect(picker.isOpen).toBe(false)
		expect(document.activeElement).toBe(open)
		open.click()
		document.getElementById('elsewhere').dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }))
		expect(picker.isOpen).toBe(false)
	})

	it('clears an optional break and refuses to clear a required time', () => {
		const picker = host(`
			<div data-azc-timepicker data-allow-empty="1" data-clear-label="Clear time">
				<input type="hidden" id="break-0-start" class="break-start-time" value="12:30">
			</div>
		`)
		const hour = document.getElementById('break-0-start-hour')
		press(hour, 'Backspace')
		expect(hour.value).toBe('--')
		expect(hour.getAttribute('aria-valuetext')).toBe('Not set')
		expect(document.getElementById('break-0-start').value).toBe('')
		picker.open()
		document.querySelector('.azc-timepicker__clear').click()
		expect(picker.isOpen).toBe(false)

		host(`
			<div data-azc-timepicker data-allow-empty="0">
				<input type="hidden" id="entry-start-time" value="09:00">
			</div>
		`)
		const required = document.getElementById('entry-start-time-hour')
		press(required, 'Delete')
		expect(required.value).toBe('00')
		expect(document.getElementById('entry-start-time').value).toBe('00:00')
	})

	it('rejects a bad setValue and emits one change for a real edit', () => {
		const picker = host(`
			<div data-azc-timepicker data-allow-empty="0">
				<input type="hidden" id="entry-start-time" value="09:00">
			</div>
		`)
		let changes = 0
		document.getElementById('entry-start-time').addEventListener('change', () => {
			changes += 1
		})
		expect(picker.setValue('25:00')).toBe(false)
		expect(document.getElementById('entry-start-time').value).toBe('09:00')
		expect(picker.setValue('08:07')).toBe(true)
		expect(document.getElementById('entry-start-time-minute').value).toBe('07')
		expect(changes).toBe(1)
		expect(picker.setValue('08:07')).toBe(true)
		expect(changes).toBe(1)
	})

	it('pastes a whole clock into either section', () => {
		host(`
			<div data-azc-timepicker data-allow-empty="0">
				<input type="hidden" id="entry-start-time" value="09:00">
			</div>
		`)
		const hour = document.getElementById('entry-start-time-hour')
		const event = new Event('paste', { bubbles: true, cancelable: true })
		Object.defineProperty(event, 'clipboardData', {
			value: { getData: () => '8:07' },
		})
		hour.dispatchEvent(event)
		expect(document.getElementById('entry-start-time').value).toBe('08:07')
		expect(document.getElementById('entry-start-time-minute').value).toBe('07')
	})

	it('wraps the hour with the arrow keys and moves between sections', () => {
		host(`
			<div data-azc-timepicker data-allow-empty="0" data-minute-step="1">
				<input type="hidden" id="entry-start-time" value="23:59">
			</div>
		`)
		const hour = document.getElementById('entry-start-time-hour')
		const minute = document.getElementById('entry-start-time-minute')
		press(hour, 'ArrowUp')
		expect(hour.value).toBe('00')
		press(hour, 'ArrowRight')
		expect(document.activeElement).toBe(minute)
		press(minute, 'Home')
		expect(minute.value).toBe('00')
		press(minute, 'End')
		expect(minute.value).toBe('59')
		press(minute, 'ArrowLeft')
		expect(document.activeElement).toBe(hour)
	})

	it('uses a dialog with Done on a narrow screen and traps Tab', () => {
		const original = window.matchMedia
		window.matchMedia = (query) => ({
			matches: String(query).includes('max-width'),
			media: query,
			addEventListener() {},
			removeEventListener() {},
		})
		const picker = host(`
			<div data-azc-timepicker data-allow-empty="0" data-done-label="Done">
				<input type="hidden" id="entry-start-time" value="09:00">
			</div>
		`)
		picker.open()
		expect(document.querySelector('.azc-timepicker__popover--sheet')).not.toBeNull()
		expect(document.querySelector('.azc-timepicker__done').textContent).toBe('Done')
		const done = document.querySelector('.azc-timepicker__done')
		done.focus()
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }))
		expect(picker.isOpen).toBe(true)
		document.querySelector('.azc-timepicker__done').click()
		expect(picker.isOpen).toBe(false)
		window.matchMedia = original
	})

	it('marks the field invalid and builds a dynamic break picker', () => {
		const picker = host(`
			<div data-azc-timepicker data-allow-empty="0">
				<input type="hidden" id="entry-start-time" value="09:00">
			</div>
		`)
		TP.setInvalid(document.querySelector('[data-azc-timepicker]'), true)
		expect(picker.hourInput.getAttribute('aria-invalid')).toBe('true')
		const created = TP.create({
			id: 'break-1-end',
			name: 'breaks[1][end]',
			hiddenClass: 'break-end-time',
			breakIndex: 1,
			allowEmpty: true,
			value: '',
			hourLabel: 'Break end hour',
			openLabel: 'Choose break end time',
			parent: document.body,
		})
		expect(created.querySelector('.break-end-time').name).toBe('breaks[1][end]')
		expect(created.querySelector('[data-section="hour"]').value).toBe('--')
		expect(TP.enhance(created)).toBe(created.__azcTimePicker)
	})
})
