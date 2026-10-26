import { beforeEach, describe, expect, it } from 'vitest'

import './datepicker.js'

const DP = window.ArbeitszeitCheckDatepicker

function host(html, options = { openOnFocus: false }) {
	document.body.innerHTML = html
	const input = document.querySelector('.datepicker-input')
	DP.initializeDatepicker(input, options)
	return input
}

function press(el, key, opts = {}) {
	el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, ...opts }))
}

function change(el) {
	el.dispatchEvent(new Event('change', { bubbles: true }))
}

function calendar() {
	return document.querySelector('.arbeitszeitcheck-datepicker')
}

function dayButton(iso) {
	return calendar()?.querySelector(`[data-day="${iso}"]`)
}

describe('datepicker input typing', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		window.ArbeitszeitCheck = { l10n: {} }
	})

	it('keeps the input editable instead of readonly', () => {
		const input = host('<input type="text" class="form-input datepicker-input" readonly>')
		expect(input.readOnly).toBe(false)
		expect(input.getAttribute('readonly')).toBeNull()
		expect(input.getAttribute('inputmode')).toBe('decimal')
		expect(input.getAttribute('role')).toBe('combobox')
		expect(input.getAttribute('aria-haspopup')).toBe('dialog')
		expect(input.closest('.azc-datepicker-host')).not.toBeNull()
		expect(input.parentNode.querySelector('.azc-datepicker-toggle')).not.toBeNull()
	})

	it('normalizes dd.mm.yyyy typed input on change', () => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.value = '1.3.2009'
		change(input)
		expect(input.value).toBe('01.03.2009')
		expect(input.getAttribute('aria-invalid')).toBeNull()
	})

	it.each([
		['01032009', '01.03.2009'],
		['1.3.09', '01.03.2009'],
		['1/3/2009', '01.03.2009'],
		['1-3-2009', '01.03.2009'],
		['1 3 2009', '01.03.2009'],
		['2009-03-01', '01.03.2009'],
		['24.12.65', '24.12.1965'],
	])('parses %s as %s', (typed, expected) => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.value = typed
		change(input)
		expect(input.value).toBe(expected)
	})

	it.each([
		'31.02.2026',
		'32.01.2026',
		'00.10.2026',
		'hello',
		'1.3.',
		'2026/10/26',
	])('rejects %s with an inline error', (typed) => {
		const input = host('<input type="text" class="form-input datepicker-input" id="d1">')
		input.value = typed
		change(input)
		expect(input.value).toBe(typed)
		expect(input.getAttribute('aria-invalid')).toBe('true')
		const err = document.querySelector('.field-error[data-field-error], .field-error')
		expect(err).not.toBeNull()
		expect(err.textContent.length).toBeGreaterThan(5)
		expect(input.getAttribute('aria-describedby')).toContain(err.id)
	})

	it('clears the error once the user fixes the value', () => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.value = 'nope'
		change(input)
		expect(input.getAttribute('aria-invalid')).toBe('true')
		input.value = '2.4.2010'
		input.dispatchEvent(new Event('input', { bubbles: true }))
		change(input)
		expect(input.value).toBe('02.04.2010')
		expect(input.getAttribute('aria-invalid')).toBeNull()
		expect(document.querySelector('.field-error')).toBeNull()
	})

	it('rejects dates before data-datepicker-min', () => {
		const input = host('<input type="text" class="form-input datepicker-input" data-datepicker-min="01.01.2020">')
		input.value = '31.12.2019'
		change(input)
		expect(input.getAttribute('aria-invalid')).toBe('true')
		input.value = '01.01.2020'
		change(input)
		expect(input.getAttribute('aria-invalid')).toBeNull()
		expect(input.value).toBe('01.01.2020')
	})

	it('rejects dates after data-datepicker-max', () => {
		const input = host('<input type="text" class="form-input datepicker-input" data-datepicker-max="31.12.2030">')
		input.value = '01.01.2031'
		change(input)
		expect(input.getAttribute('aria-invalid')).toBe('true')
	})

	it('leaves an empty field valid', () => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.value = '   '
		change(input)
		expect(input.getAttribute('aria-invalid')).toBeNull()
	})
})

describe('datepicker calendar popup', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		window.ArbeitszeitCheck = { l10n: {} }
	})

	it('opens on click and offers month + year selects', () => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		const cal = calendar()
		expect(cal).not.toBeNull()
		expect(cal.getAttribute('role')).toBe('dialog')
		expect(cal.querySelector('.arbeitszeitcheck-datepicker__month-select')).not.toBeNull()
		expect(cal.querySelector('.arbeitszeitcheck-datepicker__year-select')).not.toBeNull()
		expect(input.getAttribute('aria-expanded')).toBe('true')
	})

	it('jumps straight to a distant year via the year select', () => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		const yearSel = calendar().querySelector('.arbeitszeitcheck-datepicker__year-select')
		const monthSel = calendar().querySelector('.arbeitszeitcheck-datepicker__month-select')
		expect([...yearSel.options].map(o => o.value)).toContain('2009')
		yearSel.value = '2009'
		yearSel.dispatchEvent(new Event('change', { bubbles: true }))
		monthSel.value = '2' // March
		monthSel.dispatchEvent(new Event('change', { bubbles: true }))
		expect(dayButton('2009-03-15')).not.toBeNull()
		expect(dayButton('2009-03-15').disabled).toBe(false)
	})

	it('applies a clicked day to the input and closes', () => {
		const input = host('<input type="text" class="form-input datepicker-input" value="15.03.2009">')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		let changed = null
		input.addEventListener('change', () => { changed = input.value })
		dayButton('2009-03-20').dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(input.value).toBe('20.03.2009')
		expect(changed).toBe('20.03.2009')
		expect(calendar()).toBeNull()
		expect(input.getAttribute('aria-expanded')).toBe('false')
	})

	it('closes on Escape and restores focus to the input', () => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(calendar()).not.toBeNull()
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		expect(calendar()).toBeNull()
		expect(document.activeElement).toBe(input)
	})

	it('closes when focus leaves the widget', () => {
		const input = host('<input type="text" class="form-input datepicker-input"><button id="other">x</button>')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(calendar()).not.toBeNull()
		document.getElementById('other').dispatchEvent(
			new FocusEvent('focusin', { bubbles: true })
		)
		expect(calendar()).toBeNull()
	})

	it('opens the typed month when the field already holds a date', () => {
		const input = host('<input type="text" class="form-input datepicker-input" value="15.03.2009">')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(calendar().querySelector('.arbeitszeitcheck-datepicker__year-select').value).toBe('2009')
		expect(calendar().querySelector('.arbeitszeitcheck-datepicker__month-select').value).toBe('2')
	})

	it('respects openOnFocus:false', () => {
		const input = host('<input type="text" class="form-input datepicker-input">', { openOnFocus: false })
		input.dispatchEvent(new FocusEvent('focus', { bubbles: true }))
		expect(calendar()).toBeNull()
	})

	it('ArrowDown opens the calendar and moves focus to a day', () => {
		const input = host('<input type="text" class="form-input datepicker-input" value="15.03.2009">')
		press(input, 'ArrowDown')
		expect(calendar()).not.toBeNull()
		expect(document.activeElement.dataset.day).toBe('2009-03-15')
	})

	it('arrow keys navigate days and cross month boundaries', () => {
		const input = host('<input type="text" class="form-input datepicker-input" value="31.03.2009">')
		press(input, 'ArrowDown')
		press(document.activeElement, 'ArrowRight')
		expect(document.activeElement.dataset.day).toBe('2009-04-01')
		press(document.activeElement, 'ArrowUp')
		expect(document.activeElement.dataset.day).toBe('2009-03-25')
	})

	it('leaves arrow keys alone inside the month/year selects', () => {
		const input = host('<input type="text" class="form-input datepicker-input" value="15.03.2009">')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		const monthSel = calendar().querySelector('.arbeitszeitcheck-datepicker__month-select')
		monthSel.focus()
		press(monthSel, 'ArrowDown')
		// native select handles the key — the datepicker must not re-render/jump
		expect(document.activeElement).toBe(monthSel)
	})

	it('disables days outside min/max bounds', () => {
		const input = host(
			'<input type="text" class="form-input datepicker-input" value="15.06.2026" ' +
			'data-datepicker-min="10.06.2026" data-datepicker-max="20.06.2026">',
		)
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(dayButton('2026-06-09').disabled).toBe(true)
		expect(dayButton('2026-06-21').disabled).toBe(true)
		expect(dayButton('2026-06-15').disabled).toBe(false)
		expect([...calendar().querySelector('.arbeitszeitcheck-datepicker__year-select').options].map(o => o.value))
			.toEqual(['2026'])
	})

	it('keeps focus in the input when typing while the calendar is open', () => {
		const input = host('<input type="text" class="form-input datepicker-input">')
		input.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		input.value = '4.7.2009'
		input.dispatchEvent(new Event('input', { bubbles: true }))
		expect(calendar().querySelector('.arbeitszeitcheck-datepicker__year-select').value).toBe('2009')
	})
})
