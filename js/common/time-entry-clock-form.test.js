import { beforeEach, describe, expect, it } from 'vitest'

import './time.js'
import './time-entry-clock-form.js'

describe('ArbeitszeitCheckClockForm', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
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

    const second = mount(baseLabels(locationLabels), { workLocation: 'moon' })
    expect(second.root.querySelector('#cf-work-location').value).toBe('')

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
})
