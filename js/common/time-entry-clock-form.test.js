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
