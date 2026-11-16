import { beforeEach, describe, expect, it } from 'vitest'

import './time.js'
import './time-entry-clock-form.js'

describe('ArbeitszeitCheckClockForm', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    // Production always bootstraps the org display/storage TZ
    // (templates/common/user-display-timezone.php). Seed it so rendered
    // instants do not fall back to the process timezone.
    window.ArbeitszeitCheck = {
      tz: { display: 'Europe/Berlin', storage: 'Europe/Berlin' },
    }
  })

  it('bindForm prefills date and clock fields from ISO summary data', () => {
    const ClockForm = window.ArbeitszeitCheckClockForm
    const idPrefix = 'test-correct-1'
    const root = document.createElement('div')
    root.innerHTML = ClockForm.buildFormHtml(idPrefix, {
      intro: 'Intro',
      workingDayLegend: 'Day',
      date: 'Date',
      required: 'required',
      datePlaceholder: 'dd.mm.yyyy',
      today: 'Today',
      dateHelp: 'Help',
      workingHours: 'Working Hours',
      startTime: 'Start Time',
      endTime: 'End Time',
      start: 'Start',
      end: 'End',
      nightShiftHint: 'Night',
      breaksOptional: 'Breaks',
      breaksHelp: 'Break help',
      breaksEmpty: 'No breaks',
      actions: 'Actions',
      addBreak: 'Add break',
      reason: 'Reason',
      reasonHelp: 'Reason help',
    })
    document.body.appendChild(root)

    const initial = {
      startTime: '2026-05-20T08:15:00+02:00',
      endTime: '2026-05-20T17:30:00+02:00',
      breaks: [{ start: '12:00', end: '12:30' }],
    }
    const t = (key, fallback) => fallback || key
    ClockForm.bindForm(root, idPrefix, initial, t)

    const dateInput = root.querySelector('#' + idPrefix + '-date')
    const startHour = root.querySelector('#' + idPrefix + '-start-hour')
    const startMinute = root.querySelector('#' + idPrefix + '-start-minute')
    const endHour = root.querySelector('#' + idPrefix + '-end-hour')
    const endMinute = root.querySelector('#' + idPrefix + '-end-minute')
    const breakRows = root.querySelectorAll('.clock-form-break-row')

    expect(dateInput.value).toMatch(/^20\.05\.2026$/)
    expect(startHour.value).toBe('08')
    expect(startMinute.value).toBe('15')
    expect(endHour.value).toBe('17')
    expect(endMinute.value).toBe('30')
    expect(breakRows.length).toBe(1)
    expect(root.querySelector('.clock-form-break-start-hidden').value).toBe('12:00')
    expect(root.querySelector('.clock-form-break-end-hidden').value).toBe('12:30')
  })

  it('validateAndCollect returns parsed payload from prefilled form', () => {
    const ClockForm = window.ArbeitszeitCheckClockForm
    const idPrefix = 'test-correct-2'
    const root = document.createElement('div')
    root.innerHTML = ClockForm.buildFormHtml(idPrefix, {
      intro: 'Intro',
      workingDayLegend: 'Day',
      date: 'Date',
      required: 'required',
      datePlaceholder: 'dd.mm.yyyy',
      today: 'Today',
      dateHelp: 'Help',
      workingHours: 'Working Hours',
      startTime: 'Start Time',
      endTime: 'End Time',
      start: 'Start',
      end: 'End',
      nightShiftHint: 'Night',
      breaksOptional: 'Breaks',
      breaksHelp: 'Break help',
      breaksEmpty: 'No breaks',
      actions: 'Actions',
      addBreak: 'Add break',
      reason: 'Reason',
      reasonHelp: 'Reason help',
    })
    document.body.appendChild(root)

    const api = ClockForm.bindForm(root, idPrefix, {
      startTime: '2026-05-20T09:00:00+02:00',
      endTime: '2026-05-20T17:00:00+02:00',
      breaks: [],
    }, (key, fallback) => fallback || key)

    root.querySelector('#' + idPrefix + '-reason').value = 'Adjusted after audit review.'
    const result = api.validateAndCollect()

    expect(result.ok).toBe(true)
    expect(result.payload.date).toMatch(/^20\.05\.2026$/)
    expect(result.payload.startTime).toBe('09:00')
    expect(result.payload.endTime).toBe('17:00')
    expect(result.payload.reason).toBe('Adjusted after audit review.')
  })

  it('accepts AZG 10-minute break portions when minBreakMinutes is 10', () => {
    window.ArbeitszeitCheck = {
      tz: { display: 'Europe/Berlin', storage: 'Europe/Berlin' },
      complianceParams: { minBreakMinutes: 10 },
    }
    const ClockForm = window.ArbeitszeitCheckClockForm
    const idPrefix = 'test-at-break'
    const root = document.createElement('div')
    root.innerHTML = ClockForm.buildFormHtml(idPrefix, {
      intro: 'Intro',
      workingDayLegend: 'Day',
      date: 'Date',
      required: 'required',
      datePlaceholder: 'dd.mm.yyyy',
      today: 'Today',
      dateHelp: 'Help',
      workingHours: 'Working Hours',
      startTime: 'Start Time',
      endTime: 'End Time',
      start: 'Start',
      end: 'End',
      nightShiftHint: 'Night',
      breaksOptional: 'Breaks',
      breaksHelp: 'Break help',
      breaksEmpty: 'No breaks',
      actions: 'Actions',
      addBreak: 'Add break',
      reason: 'Reason',
      reasonHelp: 'Reason help',
      breakTooShort: 'Each break must be at least 10 minutes.',
      invalidWorkTimes: 'invalid work',
      invalidBreakTimes: 'invalid break',
      breakOutsideWork: 'outside',
      breaksOverlap: 'overlap',
      reasonRequired: 'reason',
      invalidDate: 'date',
    })
    document.body.appendChild(root)

    const api = ClockForm.bindForm(root, idPrefix, {
      startTime: '2026-05-20T08:00:00+02:00',
      endTime: '2026-05-20T16:00:00+02:00',
      breaks: [{ start: '12:00', end: '12:10' }],
      minBreakMinutes: 10,
    }, (key, fallback) => fallback || key)

    root.querySelector('#' + idPrefix + '-reason').value = 'AZG three-times-ten pattern check.'
    const result = api.validateAndCollect()
    expect(result.ok).toBe(true)
    expect(result.payload.breaks).toEqual([{ start: '12:00', end: '12:10' }])
  })

  it('rejects 10-minute breaks when floor stays at DE 15', () => {
    window.ArbeitszeitCheck = {
      tz: { display: 'Europe/Berlin', storage: 'Europe/Berlin' },
      complianceParams: { minBreakMinutes: 15 },
    }
    const ClockForm = window.ArbeitszeitCheckClockForm
    const idPrefix = 'test-de-break'
    const root = document.createElement('div')
    root.innerHTML = ClockForm.buildFormHtml(idPrefix, {
      intro: 'Intro',
      workingDayLegend: 'Day',
      date: 'Date',
      required: 'required',
      datePlaceholder: 'dd.mm.yyyy',
      today: 'Today',
      dateHelp: 'Help',
      workingHours: 'Working Hours',
      startTime: 'Start Time',
      endTime: 'End Time',
      start: 'Start',
      end: 'End',
      nightShiftHint: 'Night',
      breaksOptional: 'Breaks',
      breaksHelp: 'Break help',
      breaksEmpty: 'No breaks',
      actions: 'Actions',
      addBreak: 'Add break',
      reason: 'Reason',
      reasonHelp: 'Reason help',
      breakTooShort: 'Each break must be at least 15 minutes.',
      invalidWorkTimes: 'invalid work',
      invalidBreakTimes: 'invalid break',
      breakOutsideWork: 'outside',
      breaksOverlap: 'overlap',
      reasonRequired: 'reason',
      invalidDate: 'date',
    })
    document.body.appendChild(root)

    const api = ClockForm.bindForm(root, idPrefix, {
      startTime: '2026-05-20T08:00:00+02:00',
      endTime: '2026-05-20T16:00:00+02:00',
      breaks: [{ start: '12:00', end: '12:10' }],
    }, (key, fallback) => fallback || key)

    root.querySelector('#' + idPrefix + '-reason').value = 'Should fail under ArbZG floor.'
    const result = api.validateAndCollect()
    expect(result.ok).toBe(false)
    expect(String(result.error)).toMatch(/15/)
  })
})

describe('time-entry-clock-form work location (GH #43)', () => {
  const locationLabels = {
    workLocationLegend: 'Work location',
    workLocationUnspecified: 'Keep current location',
    workLocationOffice: 'Office',
    workLocationHome: 'Home office',
    workLocationExternal: 'External appointment',
    workLocationHelp: 'help text',
  }

  function baseLabels(overrides = {}) {
    return {
      intro: 'Intro',
      workingDayLegend: 'Working day',
      date: 'Date',
      required: 'required',
      datePlaceholder: 'dd.mm.yyyy',
      today: 'Today',
      dateHelp: 'help',
      workingHours: 'Working hours',
      startTime: 'Start',
      endTime: 'End',
      actions: 'Actions',
      start: 'Start',
      end: 'End',
      nightShiftHint: 'hint',
      breaksOptional: 'Breaks (optional)',
      breaksHelp: 'help',
      breaksEmpty: 'none',
      addBreak: 'Add break',
      reason: 'Reason',
      reasonHelp: 'help',
      ...overrides,
    }
  }

  function mount(labels, initial = {}) {
    const ClockForm = window.ArbeitszeitCheckClockForm
    const root = document.createElement('div')
    root.innerHTML = ClockForm.buildFormHtml('cf', labels)
    document.body.appendChild(root)
    const bound = ClockForm.bindForm(root, 'cf', initial, (k, f) => f || k, {})
    return { root, bound }
  }

  function fillValidForm(root) {
    root.querySelector('#cf-date').value = '15.01.2026'
    root.querySelector('#cf-start-time').value = '09:00'
    root.querySelector('#cf-end-time').value = '17:00'
    root.querySelector('#cf-reason').value = 'forgot to correct earlier'
  }

  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('renders the location select only when labels are provided', () => {
    const without = document.createElement('div')
    without.innerHTML = window.ArbeitszeitCheckClockForm.buildFormHtml('cf', baseLabels())
    expect(without.querySelector('#cf-work-location')).toBeNull()

    const { root } = mount(baseLabels(locationLabels))
    const select = root.querySelector('#cf-work-location')
    expect(select).not.toBeNull()
    const values = Array.from(select.options).map((o) => o.value)
    expect(values).toEqual(['', 'office', 'home', 'external'])
  })

  it('adds the __clear sentinel option only for correction dialogs', () => {
    const { root } = mount(baseLabels({ ...locationLabels, workLocationClearOption: 'Not specified (remove mark)' }))
    const values = Array.from(root.querySelector('#cf-work-location').options).map((o) => o.value)
    expect(values).toEqual(['', 'office', 'home', 'external', '__clear'])
  })

  it('prefills a valid initial location and defends against unknown values', () => {
    const { root } = mount(baseLabels(locationLabels), { workLocation: 'home' })
    expect(root.querySelector('#cf-work-location').value).toBe('home')

    // A stored code no longer in the catalog is kept as a synthetic option —
    // correcting such an entry must never silently drop the mark.
    const second = mount(baseLabels(locationLabels), { workLocation: 'moon' })
    expect(second.root.querySelector('#cf-work-location').value).toBe('moon')

    const third = mount(baseLabels(locationLabels), { workLocation: null })
    expect(third.root.querySelector('#cf-work-location').value).toBe('')
  })

  it('collects the selected location into the payload', () => {
    const { root, bound } = mount(baseLabels(locationLabels))
    fillValidForm(root)
    root.querySelector('#cf-work-location').value = 'external'

    const result = bound.validateAndCollect()
    expect(result.ok).toBe(true)
    expect(result.payload.workLocation).toBe('external')
  })

  it('passes __clear through so callers can propose removing the mark', () => {
    const { root, bound } = mount(baseLabels({ ...locationLabels, workLocationClearOption: 'Not specified (remove mark)' }))
    fillValidForm(root)
    root.querySelector('#cf-work-location').value = '__clear'

    const result = bound.validateAndCollect()
    expect(result.ok).toBe(true)
    expect(result.payload.workLocation).toBe('__clear')
  })

  it('keeps "" as keep-current in the collected payload', () => {
    const { root, bound } = mount(baseLabels(locationLabels), { workLocation: 'home' })
    fillValidForm(root)
    root.querySelector('#cf-work-location').value = ''

    const result = bound.validateAndCollect()
    expect(result.ok).toBe(true)
    expect(result.payload.workLocation).toBe('')
  })

  it('escapes catalog labels and codes — admin-defined labels must not inject markup', () => {
    const evil = '<img src=x onerror="window.__pwned=1">'
    const root = document.createElement('div')
    root.innerHTML = window.ArbeitszeitCheckClockForm.buildFormHtml('cf', baseLabels(locationLabels), {
      workLocations: [
        { code: 'office', label: evil, category: 'office' },
        { code: 'home', label: 'Homeoffice', category: 'home' },
        { code: 'evil"><script>alert(1)</script>', label: null, category: 'other' },
      ],
    })
    const select = root.querySelector('#cf-work-location')
    expect(select.querySelector('img')).toBeNull()
    expect(select.querySelector('script')).toBeNull()
    const office = [...select.options].find((o) => o.value === 'office')
    expect(office.textContent).toBe(evil)
    const evilOpt = [...select.options].find((o) => o.value === 'evil"><script>alert(1)</script>')
    expect(evilOpt).toBeDefined()
    expect(root.querySelectorAll('img, script').length).toBe(0)
  })
})

describe('ArbeitszeitCheckClockForm picker variant (manager create dialog)', () => {
	const pickerLabels = {
		intro: 'Intro',
		workingDayLegend: 'Working day',
		dateTimeLegend: 'Date and time',
		date: 'Date',
		required: 'required',
		datePlaceholder: 'dd.mm.yyyy',
		today: 'Today',
		dateHelp: 'Day you worked (dd.mm.yyyy).',
		workingHours: 'Working Hours',
		startTime: 'Start Time',
		endTime: 'End Time',
		start: 'Start',
		end: 'End',
		startTimeHelp: 'Type or open the clock.',
		startHourLabel: 'Start hour',
		startMinuteLabel: 'Start minute',
		endHourLabel: 'End hour',
		endMinuteLabel: 'End minute',
		startOpenLabel: 'Choose start time',
		endOpenLabel: 'Choose end time',
		pickerHoursLabel: 'Hours',
		pickerMinutesLabel: 'Minutes',
		pickerDoneLabel: 'Done',
		pickerNotSetLabel: 'Not set',
		nightShiftHint: 'Night shift hint',
		breaksOptional: 'Breaks (optional)',
		breaksHelp: 'help',
		breaksEmpty: 'No breaks added.',
		actions: 'Actions',
		addBreak: 'Add break',
		reason: 'Reason',
		reasonHelp: 'help',
		workLocationLegend: 'Work location',
		workLocationUnspecified: 'Not specified',
		workLocationOffice: 'Office',
		workLocationHome: 'Home office',
		workLocationExternal: 'External appointment',
		daySummary: 'Day Summary',
		breakTime: 'Break Time',
	}

	function installTimePickerMock() {
		const calls = { initInRoot: [], setValue: [] }
		window.ArbeitszeitCheckTimePicker = {
			create: () => document.createElement('div'),
			initInRoot: (root) => {
				calls.initInRoot.push(root)
				root.querySelectorAll('[data-azc-timepicker]').forEach((h) => {
					h.setAttribute('data-azc-timepicker-ready', '1')
				})
			},
			setValue: (host, value) => {
				calls.setValue.push(value)
				const hidden = host.querySelector('input[type="hidden"]')
				if (hidden) hidden.value = value
			},
			setInvalid: () => {},
		}
		return calls
	}

	function mountPicker(options = {}, initial = {}) {
		const ClockForm = window.ArbeitszeitCheckClockForm
		const root = document.createElement('div')
		root.innerHTML = ClockForm.buildFormHtml('cfp', pickerLabels, {
			workLocations: null,
			useTimePicker: true,
			summary: true,
			defaults: { start: '09:00', end: '17:00' },
			dateMin: '09.11.2025',
			dateMax: '10.11.2026',
			...options,
		})
		document.body.appendChild(root)
		const bound = ClockForm.bindForm(root, 'cfp', initial, (k, f) => f || k, {
			useTimePicker: true,
			defaults: { start: '09:00', end: '17:00' },
		})
		return { root, bound }
	}

	beforeEach(() => {
		document.body.innerHTML = ''
		window.ArbeitszeitCheck = {
			tz: { display: 'Europe/Berlin', storage: 'Europe/Berlin' },
		}
	})

	it('renders timepicker hosts and keeps hidden-input ids intact', () => {
		installTimePickerMock()
		const { root } = mountPicker()

		// 2 work-time hosts + one always-visible empty break row (2 more)
		const hosts = root.querySelectorAll('[data-azc-timepicker]')
		expect(hosts.length).toBe(4)
		expect(root.querySelector('#cfp-start-time')).not.toBeNull()
		expect(root.querySelector('#cfp-end-time')).not.toBeNull()
		expect(root.querySelector('#cfp-start-time').type).toBe('hidden')
		expect(root.querySelector('#cfp-start-hour')).toBeNull()

		const startHost = root.querySelector('#cfp-start-time').closest('[data-azc-timepicker]')
		expect(startHost.getAttribute('data-allow-empty')).toBe('0')
		expect(startHost.getAttribute('data-required')).toBe('1')
		expect(startHost.getAttribute('data-label-id')).toBe('cfp-start-time-label')
		expect(startHost.getAttribute('data-open-label')).toBe('Choose start time')
		expect(root.querySelector('#cfp-start-time-label')).not.toBeNull()
	})

	it('renders datepicker class, min/max bounds, summary card and form-select location', () => {
		installTimePickerMock()
		const { root } = mountPicker()

		const date = root.querySelector('#cfp-date')
		expect(date.classList.contains('datepicker-input')).toBe(true)
		expect(date.getAttribute('data-datepicker-min')).toBe('09.11.2025')
		expect(date.getAttribute('data-datepicker-max')).toBe('10.11.2026')
		expect(date.getAttribute('aria-describedby')).toBe('cfp-date-help')

		expect(root.querySelector('#cfp-summary')).not.toBeNull()
		expect(root.querySelector('#cfp-summary-working').textContent).not.toBe('0')
		expect(root.querySelector('#cfp-work-location').classList.contains('form-select')).toBe(true)
	})

	it('falls back to the select markup when the picker component is absent', () => {
		delete window.ArbeitszeitCheckTimePicker
		const { root } = mountPicker()

		expect(root.querySelectorAll('[data-azc-timepicker]').length).toBe(0)
		expect(root.querySelector('#cfp-start-hour')).not.toBeNull()
		expect(root.querySelector('#cfp-start-minute')).not.toBeNull()
		expect(root.querySelector('#cfp-summary')).toBeNull()
	})

	it('initializes the picker and applies default times', () => {
		const calls = installTimePickerMock()
		const { root } = mountPicker()

		expect(calls.initInRoot.length).toBeGreaterThan(0)
		expect(root.querySelector('#cfp-start-time').value).toBe('09:00')
		expect(root.querySelector('#cfp-end-time').value).toBe('17:00')
		expect(calls.setValue).toContain('09:00')
		expect(calls.setValue).toContain('17:00')
	})

	it('computes the live day summary from hidden values and breaks', () => {
		installTimePickerMock()
		const { root } = mountPicker()

		root.querySelector('#cfp-date').value = '09.11.2026'
		root.querySelector('#cfp-date').dispatchEvent(new Event('input', { bubbles: true }))

		expect(root.querySelector('#cfp-summary-working').textContent).toBe('8.00')
		expect(root.querySelector('#cfp-summary-break').textContent).toBe('0.00')

		root.querySelector('.cfp-add-break').click()
		const row = root.querySelector('.clock-form-break-row')
		expect(row.querySelectorAll('[data-azc-timepicker]').length).toBe(2)

		row.querySelector('.clock-form-break-start-hidden').value = '12:00'
		row.querySelector('.clock-form-break-end-hidden').value = '12:30'
		row.querySelector('.clock-form-break-start-hidden').dispatchEvent(new Event('change', { bubbles: true }))

		expect(root.querySelector('#cfp-summary-break').textContent).toBe('0.50')
		expect(root.querySelector('#cfp-summary-working').textContent).toBe('7.50')
	})

	it('shows — in the summary while required inputs are incomplete', () => {
		installTimePickerMock()
		const { root } = mountPicker({}, { startTime: null, endTime: null })
		// defaults fill the pickers — clear them again to simulate unfinished input
		root.querySelector('#cfp-start-time').value = ''
		root.querySelector('#cfp-date').value = '09.11.2026'
		root.querySelector('#cfp-start-time').dispatchEvent(new Event('change', { bubbles: true }))

		expect(root.querySelector('#cfp-summary-working').textContent).toBe('—')
	})

	it('validateAndCollect still reads the hidden contract in picker mode', () => {
		installTimePickerMock()
		const { root, bound } = mountPicker()

		root.querySelector('#cfp-date').value = '09.11.2026'
		root.querySelector('#cfp-reason').value = 'entered by manager for payroll'
		const result = bound.validateAndCollect()

		expect(result.ok).toBe(true)
		expect(result.payload.startTime).toBe('09:00')
		expect(result.payload.endTime).toBe('17:00')
		expect(result.payload.date).toBe('09.11.2026')
	})

	describe('automatic breaks', () => {
		const tiers = {
			breakTiers: [
				{ afterHours: 6, breakMinutes: 30 },
				{ afterHours: 9, breakMinutes: 45 },
			],
			minBreakMinutes: 15,
			lawLabels: { breaks: 'ArbZG §4' },
		}

		function mountAutoBreak(extraOptions = {}) {
			installTimePickerMock()
			window.ArbeitszeitCheck.complianceParams = tiers
			const mounted = mountPicker({ autoBreak: true, ...extraOptions })
			mounted.root.querySelector('#cfp-date').value = '09.11.2026'
			mounted.root.querySelector('#cfp-date').dispatchEvent(new Event('change', { bubbles: true }))
			return mounted
		}

		function checkToggle(root) {
			const toggle = root.querySelector('#cfp-auto-break-enabled')
			toggle.checked = true
			toggle.dispatchEvent(new Event('change', { bubbles: true }))
		}

		it('renders the auto-break toggle panel only when enabled', () => {
			installTimePickerMock()
			const off = mountPicker()
			expect(off.root.querySelector('#cfp-auto-break-enabled')).toBeNull()

			const on = mountAutoBreak()
			const toggle = on.root.querySelector('#cfp-auto-break-enabled')
			expect(toggle).not.toBeNull()
			expect(toggle.checked).toBe(false)
			expect(on.root.querySelector('#cfp-auto-break-state').textContent).toBe('Disabled')
			expect(on.root.querySelector('.cfp-break-tiers-intro').textContent).toContain('ArbZG §4')
		})

		it('inserts a 30-minute statutory break for an 8-hour shift when enabled', () => {
			const { root } = mountAutoBreak()
			checkToggle(root)

			const auto = root.querySelectorAll('.clock-form-break-row[data-auto-break]')
			expect(auto.length).toBe(1)
			const start = auto[0].querySelector('.clock-form-break-start-hidden').value
			const end = auto[0].querySelector('.clock-form-break-end-hidden').value
			expect(start).toMatch(/^\d{2}:\d{2}$/)
			expect(end).toMatch(/^\d{2}:\d{2}$/)
			expect(auto[0].querySelector('.auto-break-note')).not.toBeNull()
			expect(root.querySelector('#cfp-auto-break-state').textContent).toBe('Enabled')
			// net 8h – 0.5h break
			expect(root.querySelector('#cfp-summary-break').textContent).toBe('0.50')
			expect(root.querySelector('#cfp-summary-working').textContent).toBe('7.50')
		})

		it('submits the auto-generated break in the payload', () => {
			const { root, bound } = mountAutoBreak()
			checkToggle(root)
			root.querySelector('#cfp-reason').value = 'entered by manager for payroll'

			const result = bound.validateAndCollect()
			expect(result.ok).toBe(true)
			expect(result.payload.breaks.length).toBe(1)
		})

		it('removes auto rows and restores the summary when switched off', () => {
			const { root } = mountAutoBreak()
			checkToggle(root)
			expect(root.querySelectorAll('.clock-form-break-row[data-auto-break]').length).toBe(1)

			const toggle = root.querySelector('#cfp-auto-break-enabled')
			toggle.checked = false
			toggle.dispatchEvent(new Event('change', { bubbles: true }))

			expect(root.querySelectorAll('.clock-form-break-row[data-auto-break]').length).toBe(0)
			expect(root.querySelector('#cfp-summary-break').textContent).toBe('0.00')
			expect(root.querySelector('#cfp-summary-working').textContent).toBe('8.00')
		})

		it('does not add an auto break when manual breaks already cover the requirement', () => {
			const { root } = mountAutoBreak()
			root.querySelector('.cfp-add-break').click()
			const row = root.querySelector('.clock-form-break-row')
			row.querySelector('.clock-form-break-start-hidden').value = '12:00'
			row.querySelector('.clock-form-break-end-hidden').value = '12:30'
			row.querySelector('.clock-form-break-start-hidden').dispatchEvent(new Event('change', { bubbles: true }))

			checkToggle(root)
			expect(root.querySelectorAll('.clock-form-break-row[data-auto-break]').length).toBe(0)
			// seeded empty row + the filled manual row
			expect(root.querySelectorAll('.clock-form-break-row').length).toBe(2)
		})

		it('tops up the shortfall when manual breaks do not cover the requirement', () => {
			const { root } = mountAutoBreak()
			root.querySelector('.cfp-add-break').click()
			const row = root.querySelector('.clock-form-break-row')
			row.querySelector('.clock-form-break-start-hidden').value = '12:00'
			row.querySelector('.clock-form-break-end-hidden').value = '12:15'
			row.querySelector('.clock-form-break-start-hidden').dispatchEvent(new Event('change', { bubbles: true }))

			checkToggle(root)
			// manual 15 < required 30 → one auto row covering 15
			const auto = root.querySelectorAll('.clock-form-break-row[data-auto-break]')
			expect(auto.length).toBe(1)
			expect(root.querySelector('#cfp-summary-break').textContent).toBe('0.50')
		})

		it('adds no auto break for shifts at or under the lowest tier', () => {
			const { root } = mountAutoBreak()
			root.querySelector('#cfp-end-time').value = '14:00'
			root.querySelector('#cfp-end-time').dispatchEvent(new Event('change', { bubbles: true }))
			checkToggle(root)
			expect(root.querySelectorAll('.clock-form-break-row[data-auto-break]').length).toBe(0)
		})

		it('recomputes when the work range changes', () => {
			const { root } = mountAutoBreak()
			checkToggle(root)
			expect(root.querySelectorAll('.clock-form-break-row[data-auto-break]').length).toBe(1)

			// 09:00–18:30 = 9.5h net → 45-minute requirement
			root.querySelector('#cfp-end-time').value = '18:30'
			root.querySelector('#cfp-end-time').dispatchEvent(new Event('change', { bubbles: true }))

			const auto = root.querySelectorAll('.clock-form-break-row[data-auto-break]')
			expect(auto.length).toBe(1)
			expect(root.querySelector('#cfp-summary-break').textContent).toBe('0.75')
		})

		it('removes auto rows when the work range becomes invalid', () => {
			const { root } = mountAutoBreak()
			checkToggle(root)
			expect(root.querySelectorAll('.clock-form-break-row[data-auto-break]').length).toBe(1)

			root.querySelector('#cfp-date').value = ''
			root.querySelector('#cfp-date').dispatchEvent(new Event('change', { bubbles: true }))

			expect(root.querySelectorAll('.clock-form-break-row[data-auto-break]').length).toBe(0)
		})
	})
})
