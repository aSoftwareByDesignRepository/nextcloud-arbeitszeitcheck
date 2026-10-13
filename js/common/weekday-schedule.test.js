import { beforeEach, describe, expect, it, vi } from 'vitest'

const loadModule = async () => {
	vi.resetModules()
	await import('./utils.js')
	await import('./weekday-schedule.js')
	return window.ArbeitszeitCheckWeekdaySchedule
}

describe('ArbeitszeitCheckWeekdaySchedule', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('renders a seven-day matrix with per-day net cells', async () => {
		const mod = await loadModule()
		const html = mod.sectionHtml('ucm', null)
		expect(html).toContain('data-schedule-prefix="ucm"')
		expect(html.match(/data-day="/g)).toHaveLength(7)
		expect(html).toContain('ucm-mon-net')
		expect(html).toContain('ucm-sun-net')
	})

	it('renders existing schedule values into the rows', async () => {
		const mod = await loadModule()
		const html = mod.sectionHtml('ucm', {
			version: 1,
			days: { mon: { work: true, start: '08:00', end: '16:30', breaks: [{ start: '12:00', end: '12:30' }] } },
		})
		expect(html).toContain('id="ucm-mon-start" value="08:00"')
		expect(html).toContain('id="ucm-mon-bstart" value="12:00"')
		expect(html).toContain('id="ucm-tue-start" value="" disabled')
	})

	it('collect returns null when no day is marked as work', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form>${mod.sectionHtml('ucm', null)}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		expect(mod.collect(fs)).toBeNull()
	})

	it('collect gathers work rows and a single unpaid break', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form>${mod.sectionHtml('ucm', null)}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		document.getElementById('ucm-mon-work').checked = true
		document.getElementById('ucm-mon-start').value = '08:00'
		document.getElementById('ucm-mon-end').value = '16:30'
		document.getElementById('ucm-mon-bstart').value = '12:00'
		document.getElementById('ucm-mon-bend').value = '12:30'
		const collected = mod.collect(fs)
		expect(collected.version).toBe(1)
		expect(collected.days.mon).toEqual({
			work: true,
			start: '08:00',
			end: '16:30',
			breaks: [{ start: '12:00', end: '12:30', paid: false }],
		})
		expect(collected.days.sun).toEqual({ work: false })
	})

	it('netHoursFromRow subtracts unpaid breaks and rejects inverted times', async () => {
		const mod = await loadModule()
		expect(mod.netHoursFromRow('08:00', '16:30', '12:00', '12:30')).toBe(8)
		expect(mod.netHoursFromRow('08:00', '16:00', '', '')).toBe(8)
		expect(mod.netHoursFromRow('16:00', '08:00', '', '')).toBeNull()
		expect(mod.netHoursFromRow('bad', '16:00', '', '')).toBeNull()
		expect(mod.parseHmToMinutes('24:00')).toBeNull()
		expect(mod.parseHmToMinutes('7:30')).toBe(450)
	})

	it('refreshTotals writes derived scalars into data-scalar-fields targets', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form>
			<input name="customWeeklyHours"><input name="customDailyHours"><input name="customWorkDaysPerWeek">
			${mod.sectionHtml('ucm', null, { scalarFields: 'customWeeklyHours,customDailyHours,customWorkDaysPerWeek' })}
		</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		document.getElementById('ucm-mon-work').checked = true
		document.getElementById('ucm-mon-start').value = '08:00'
		document.getElementById('ucm-mon-end').value = '16:00'
		document.getElementById('ucm-fri-work').checked = true
		document.getElementById('ucm-fri-start').value = '08:00'
		document.getElementById('ucm-fri-end').value = '12:00'
		mod.refreshTotals(fs)
		expect(document.querySelector('[name="customWeeklyHours"]').value).toBe('12.00')
		expect(document.querySelector('[name="customDailyHours"]').value).toBe('6.00')
		expect(document.querySelector('[name="customWorkDaysPerWeek"]').value).toBe('2')
		expect(document.querySelector('[name="customWeeklyHours"]').readOnly).toBe(true)
		expect(document.getElementById('ucm-week-total').textContent).toContain('12.00')
	})

	it('refreshTotals re-enables scalars when the matrix is cleared', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form>
			<input name="weeklyHours"><input name="dailyHours"><input name="workDaysPerWeek">
			${mod.sectionHtml('wtm', null)}
		</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		const weekly = document.querySelector('[name="weeklyHours"]')
		document.getElementById('wtm-mon-work').checked = true
		document.getElementById('wtm-mon-start').value = '08:00'
		document.getElementById('wtm-mon-end').value = '16:00'
		mod.refreshTotals(fs)
		expect(weekly.readOnly).toBe(true)
		document.getElementById('wtm-mon-work').checked = false
		mod.refreshTotals(fs)
		expect(weekly.readOnly).toBe(false)
		expect(weekly.getAttribute('aria-readonly')).toBeNull()
	})

	it('bind wires preset and clear buttons', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form><input name="weeklyHours"><input name="dailyHours"><input name="workDaysPerWeek">${mod.sectionHtml('wtm', null)}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		mod.bind(fs)
		fs.querySelector('[data-action="apply-weekday-preset"]').click()
		expect(document.getElementById('wtm-mon-start').value).toBe('07:00')
		expect(document.getElementById('wtm-fri-end').value).toBe('11:45')
		expect(document.querySelector('[name="workDaysPerWeek"]').value).toBe('5')
		fs.querySelector('[data-action="clear-weekday-schedule"]').click()
		expect(document.getElementById('wtm-mon-start').value).toBe('')
		expect(document.querySelector('[name="weeklyHours"]').readOnly).toBe(false)
	})
})
