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
		expect(html.match(/<tr data-day="/g)).toHaveLength(7)
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

	it('collect returns an all-off payload when the caller opted into allOffSchedule', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form>${mod.sectionHtml('ucm', null)}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		fs.dataset.allOffSchedule = '1'
		const collected = mod.collect(fs)
		expect(collected).not.toBeNull()
		expect(collected.version).toBe(1)
		expect(Object.values(collected.days).every((d) => d.work === false)).toBe(true)
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

	it('renders v2 blocks as extra rows and collects version 2', async () => {
		const mod = await loadModule()
		const html = mod.sectionHtml('ucm', {
			version: 2,
			interruption_mode: 'recorded_only',
			days: {
				mon: {
					work: true,
					blocks: [
						{ start: '06:00', end: '10:00' },
						{ start: '13:30', end: '16:30' },
					],
					breaks: [],
				},
			},
		})
		expect(html).toContain('data-schedule-version="2"')
		expect(html.match(/data-extra-block/g)).toHaveLength(1)
		document.body.innerHTML = `<form>${html}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		const collected = mod.collect(fs)
		expect(collected.version).toBe(2)
		expect(collected.interruption_mode).toBe('recorded_only')
		expect(collected.days.mon.blocks).toEqual([
			{ start: '06:00', end: '10:00' },
			{ start: '13:30', end: '16:30' },
		])
	})

	it('add-block click inserts a row and upgrades collect to v2', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form><input name="weeklyHours"><input name="dailyHours"><input name="workDaysPerWeek">${mod.sectionHtml('wtm', null)}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		mod.bind(fs)
		document.getElementById('wtm-mon-work').checked = true
		document.getElementById('wtm-mon-start').value = '06:00'
		document.getElementById('wtm-mon-end').value = '10:00'
		mod.refreshTotals(fs)
		fs.querySelector('.wtm-add-block[data-day="mon"]').click()
		const extras = fs.querySelectorAll('tr[data-day="mon"][data-extra-block]')
		expect(extras).toHaveLength(1)
		extras[0].querySelector('.wtm-day-start').value = '13:30'
		extras[0].querySelector('.wtm-day-end').value = '16:30'
		mod.refreshTotals(fs)
		expect(document.querySelector('[name="weeklyHours"]').value).toBe('7.00')
		const collected = mod.collect(fs)
		expect(collected.version).toBe(2)
		expect(collected.days.mon.blocks).toHaveLength(2)
	})

	it('remove-block drops the extra row; loaded v2 stays v2', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form>${mod.sectionHtml('wtm', {
			version: 2,
			interruption_mode: 'recorded_only',
			days: {
				mon: {
					work: true,
					blocks: [{ start: '06:00', end: '10:00' }, { start: '13:30', end: '16:30' }],
					breaks: [],
				},
			},
		})}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		mod.bind(fs)
		fs.querySelector('[data-action="remove-block"][data-day="mon"]').click()
		expect(fs.querySelectorAll('tr[data-extra-block]')).toHaveLength(0)
		const collected = mod.collect(fs)
		// Loaded as v2 → stays v2 (server would 409 a downgrade anyway)
		expect(collected.version).toBe(2)
		expect(collected.days.mon.blocks).toEqual([{ start: '06:00', end: '10:00' }])
	})

	it('net computation clips break overlap to blocks', async () => {
		const mod = await loadModule()
		document.body.innerHTML = `<form>${mod.sectionHtml('ucm', {
			version: 2,
			days: {
				mon: {
					work: true,
					blocks: [{ start: '06:00', end: '10:00' }, { start: '13:30', end: '16:30' }],
					breaks: [{ start: '11:00', end: '12:00', paid: false }],
				},
			},
		})}</form>`
		const fs = document.querySelector('.wtm-weekday-schedule')
		mod.refreshTotals(fs)
		// Break sits inside the gap → net stays 7h (7h blocks, gap not work)
		expect(fs.querySelector('#ucm-mon-net .wtm-day-net-value').textContent).toBe('7.00 h')
	})

	const v2Schedule = (split) => ({
		version: 2,
		interruption_mode: 'recorded_only',
		days: {
			mon: {
				work: true,
				blocks: [{ start: '06:00', end: '10:00' }, { start: '13:30', end: '16:30' }],
				breaks: [],
			},
		},
		...(split ? { split_service: split } : {}),
	})

	describe('v1/v2 choice cards', () => {
		it('renders both cards and checks v1 for a blank/v1 schedule', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', null)}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			expect(fs.querySelectorAll('.azc-choice-card input[type="radio"]')).toHaveLength(2)
			expect(fs.querySelector('input[name="ucm-schedule-mode"][value="1"]').checked).toBe(true)
			expect(fs.querySelector('input[name="ucm-schedule-mode"][value="2"]').checked).toBe(false)
			expect(fs.classList.contains('wtm-weekday-schedule--v1')).toBe(true)
			expect(fs.getAttribute('data-loaded-version')).toBe('1')
		})

		it('checks the v2 card when the loaded document is version 2 or has multi-block days', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			expect(fs.querySelector('input[name="ucm-schedule-mode"][value="2"]').checked).toBe(true)
			expect(fs.classList.contains('wtm-weekday-schedule--v2')).toBe(true)
			expect(fs.getAttribute('data-loaded-version')).toBe('2')
		})

		it('v1→v2 keeps block 1 and upgrades collect; v2→v1 collapses extras after confirmation', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form><input name="weeklyHours"><input name="dailyHours"><input name="workDaysPerWeek">${mod.sectionHtml('ucm', null)}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			mod.bind(fs)
			document.getElementById('ucm-mon-work').checked = true
			document.getElementById('ucm-mon-start').value = '06:00'
			document.getElementById('ucm-mon-end').value = '10:00'
			// Switch to v2
			const v2Radio = fs.querySelector('input[name="ucm-schedule-mode"][value="2"]')
			v2Radio.checked = true
			v2Radio.dispatchEvent(new Event('change', { bubbles: true }))
			expect(fs.getAttribute('data-schedule-version')).toBe('2')
			expect(fs.classList.contains('wtm-weekday-schedule--v2')).toBe(true)
			// Add a block, then switch back to v1 → confirm dialog removes it
			fs.querySelector('.wtm-add-block[data-day="mon"]').click()
			expect(fs.querySelectorAll('tr[data-day="mon"][data-extra-block]')).toHaveLength(1)
			const confirmSpy = vi.fn().mockResolvedValue(true)
			window.ArbeitszeitCheckComponents = { showConfirmDialog: confirmSpy }
			const v1Radio = fs.querySelector('input[name="ucm-schedule-mode"][value="1"]')
			v1Radio.checked = true
			v1Radio.dispatchEvent(new Event('change', { bubbles: true }))
			await Promise.resolve()
			expect(confirmSpy).toHaveBeenCalledTimes(1)
			expect(fs.querySelectorAll('tr[data-extra-block]')).toHaveLength(0)
			// Loaded as v1 → single-block draft emits a v1 document again
			expect(fs.getAttribute('data-schedule-version')).toBe('1')
			expect(mod.collect(fs).version).toBe(1)
		})

		it('declining the collapse keeps the extra blocks and re-checks v2', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			mod.bind(fs)
			window.ArbeitszeitCheckComponents = { showConfirmDialog: vi.fn().mockResolvedValue(false) }
			const v1Radio = fs.querySelector('input[name="ucm-schedule-mode"][value="1"]')
			v1Radio.checked = true
			v1Radio.dispatchEvent(new Event('change', { bubbles: true }))
			await Promise.resolve()
			expect(fs.querySelectorAll('tr[data-extra-block]')).toHaveLength(1)
			expect(fs.querySelector('input[name="ucm-schedule-mode"][value="2"]').checked).toBe(true)
		})

		it('a loaded v2 document is never downgraded to a v1 collect', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			mod.bind(fs)
			window.ArbeitszeitCheckComponents = { showConfirmDialog: vi.fn().mockResolvedValue(true) }
			const v1Radio = fs.querySelector('input[name="ucm-schedule-mode"][value="1"]')
			v1Radio.checked = true
			v1Radio.dispatchEvent(new Event('change', { bubbles: true }))
			await Promise.resolve()
			expect(fs.querySelectorAll('tr[data-extra-block]')).toHaveLength(0)
			const collected = mod.collect(fs)
			expect(collected.version).toBe(2)
			expect(collected.days.mon.blocks).toEqual([{ start: '06:00', end: '10:00' }])
		})
	})

	describe('split-service section', () => {
		it('stays hidden for single-block schedules and appears once a day has two blocks', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form><input name="weeklyHours"><input name="dailyHours"><input name="workDaysPerWeek">${mod.sectionHtml('ucm', null)}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			mod.bind(fs)
			const details = fs.querySelector('.wtm-split-service')
			expect(details.hidden).toBe(true)
			const work = document.getElementById('ucm-mon-work')
			work.checked = true
			document.getElementById('ucm-mon-start').value = '06:00'
			document.getElementById('ucm-mon-end').value = '10:00'
			// Real user input: the change event re-enables the add-block button.
			work.dispatchEvent(new Event('change', { bubbles: true }))
			fs.querySelector('.wtm-add-block[data-day="mon"]').click()
			expect(details.hidden).toBe(false)
			// Collapsing the second block hides the section again
			fs.querySelector('[data-action="remove-block"][data-day="mon"]').click()
			expect(details.hidden).toBe(true)
		})

		it('is visible on load for a multi-block schedule', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-split-service')
			expect(fs.hidden).toBe(false)
		})

		it('emits canonical split_service when automatic split is on with a cut-off', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			fs.querySelector('.wtm-split-auto').checked = true
			fs.querySelector('.wtm-split-cutoff').value = '16:00'
			const collected = mod.collect(fs)
			expect(collected.version).toBe(2)
			expect(collected.split_service).toEqual({
				automatic_split: true,
				split_when_clock_out_after: '16:00',
				interruption_counts_as_break: false,
			})
		})

		it('emits canonical split_service for the break-cover option only (cut-off null)', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			fs.querySelector('.wtm-split-break-cover').checked = true
			const collected = mod.collect(fs)
			expect(collected.split_service).toEqual({
				automatic_split: false,
				split_when_clock_out_after: null,
				interruption_counts_as_break: true,
			})
		})

		it('omits split_service entirely when every option is off', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			const collected = mod.collect(fs)
			expect('split_service' in collected).toBe(false)
		})

		it('omits split_service when the section is hidden even if an option is on', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', {
				version: 2,
				days: { mon: { work: true, blocks: [{ start: '08:00', end: '12:00' }], breaks: [] } },
			})}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			// Section hidden (only one block) but a switch was left on → nothing emitted.
			fs.querySelector('.wtm-split-break-cover').checked = true
			const collected = mod.collect(fs)
			expect(collected.version).toBe(2)
			expect('split_service' in collected).toBe(false)
		})

		it('keeps the cut-off empty by default (deliberately not prefilled)', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const input = document.querySelector('.wtm-split-cutoff')
			expect(input.value).toBe('')
			expect(input.closest('.wtm-split-cutoff-group').hidden).toBe(true)
		})

		it('reveals the cut-off field when the automatic switch is turned on', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			mod.bind(fs)
			const auto = fs.querySelector('.wtm-split-auto')
			auto.checked = true
			auto.dispatchEvent(new Event('change', { bubbles: true }))
			const group = fs.querySelector('.wtm-split-cutoff-group')
			expect(group.hidden).toBe(false)
			expect(fs.querySelector('.wtm-split-cutoff').disabled).toBe(false)
		})

		it('populates switches and cut-off from a loaded split_service document', async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule({
				automatic_split: true,
				split_when_clock_out_after: '16:00',
				interruption_counts_as_break: true,
			}))}</form>`
			expect(document.querySelector('.wtm-split-auto').checked).toBe(true)
			expect(document.querySelector('.wtm-split-cutoff').value).toBe('16:00')
			expect(document.querySelector('.wtm-split-break-cover').checked).toBe(true)
			expect(document.querySelector('.wtm-split-cutoff-group').hidden).toBe(false)
			// Round-trip: collect returns the same canonical payload.
			const fs = document.querySelector('.wtm-weekday-schedule')
			expect(mod.collect(fs).split_service).toEqual({
				automatic_split: true,
				split_when_clock_out_after: '16:00',
				interruption_counts_as_break: true,
			})
		})
	})

	describe('split preview', () => {
		const previewRows = (cliff) => ({
			success: true,
			at_cutoff: { clockIn: '06:00', clockOut: '16:00', split: false, segments: [['06:00', '16:00']], interruptionSeconds: 12600, creditedSeconds: 33300, breakCovered: false },
			just_after: { clockIn: '06:00', clockOut: '16:01', split: true, segments: [['06:00', '10:00'], ['13:30', '16:01']], interruptionSeconds: 12600, creditedSeconds: 23460, breakCovered: true },
			planned_day: { clockIn: '06:00', clockOut: '16:30', split: true, segments: [['06:00', '10:00'], ['13:30', '16:30']], interruptionSeconds: 12600, creditedSeconds: 25200, breakCovered: true },
			sample: { clockIn: '06:00', clockOut: '10:00', split: false, segments: [['06:00', '10:00']], interruptionSeconds: 0, creditedSeconds: 14400, breakCovered: false },
			cliffDeltaSeconds: cliff,
		})

		const mountV2 = async () => {
			const mod = await loadModule()
			document.body.innerHTML = `<form>${mod.sectionHtml('ucm', v2Schedule())}</form>`
			const fs = document.querySelector('.wtm-weekday-schedule')
			mod.bind(fs)
			return { mod, fs }
		}

		it('posts the draft schedule and renders the server rows with a caption', async () => {
			const { fs } = await mountV2()
			const calls = []
			window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => { calls.push({ url, opts }) })
			fs.querySelector('[data-action="split-preview"]').click()
			expect(calls).toHaveLength(1)
			expect(calls[0].url).toBe('/apps/arbeitszeitcheck/api/admin/working-time-models/split-preview')
			expect(calls[0].opts.data.weekday_schedule.version).toBe(2)
			expect(calls[0].opts.data.sample).toEqual({ weekday: 'mon', start: '06:00', end: '10:00' })
			calls[0].opts.onSuccess(previewRows(0))
			const rendered = fs.querySelectorAll('tr[data-preview-result]')
			expect(rendered).toHaveLength(3)
			expect(rendered[0].querySelector('th').textContent).toContain('At cut-off')
			expect(rendered[1].textContent).toContain('06:00–10:00, 13:30–16:01')
			expect(fs.querySelector('.wtm-split-preview-table caption')).toBeTruthy()
			expect(fs.querySelector('.wtm-split-preview-sample-result').textContent).toContain('Continuous')
		})

		it('shows the role=status cliff notice when the delta reaches one hour', async () => {
			const { fs } = await mountV2()
			const calls = []
			window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => { calls.push({ url, opts }) })
			fs.querySelector('[data-action="split-preview"]').click()
			calls[0].opts.onSuccess(previewRows(9899))
			const cliff = fs.querySelector('.wtm-split-preview-cliff')
			expect(cliff.hidden).toBe(false)
			expect(cliff.getAttribute('role')).toBe('status')
			expect(cliff.textContent).toContain('2:44')
		})

		it('aborts the in-flight request and drops its stale response', async () => {
			const { fs } = await mountV2()
			const calls = []
			window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => { calls.push({ url, opts }) })
			const btn = fs.querySelector('[data-action="split-preview"]')
			btn.click()
			btn.click()
			expect(calls).toHaveLength(2)
			expect(calls[0].opts.signal.aborted).toBe(true)
			calls[0].opts.onSuccess(previewRows(99999)) // stale — must be ignored
			expect(fs.querySelector('.wtm-split-preview-cliff').hidden).toBe(true)
			calls[1].opts.onSuccess(previewRows(0))
			expect(fs.querySelectorAll('tr[data-preview-result]')).toHaveLength(3)
		})

		it('shows an inline error callout while saving stays possible', async () => {
			const { fs } = await mountV2()
			const calls = []
			window.ArbeitszeitCheckUtils.ajax = vi.fn((url, opts) => { calls.push({ url, opts }) })
			fs.querySelector('[data-action="split-preview"]').click()
			calls[0].opts.onError({ error: 'boom' })
			const callout = fs.querySelector('.wtm-split-preview-error')
			expect(callout.hidden).toBe(false)
			expect(callout.textContent).toContain('boom')
			expect(fs.querySelector('.wtm-split-preview').getAttribute('aria-busy')).toBeNull()
		})
	})
})
