import { describe, expect, it } from 'vitest'

import './timepicker-logic.js'

const L = window.ArbeitszeitCheckTimePickerLogic

describe('time picker logic', () => {
	it('parses only canonical HH:MM and rejects impossible clocks', () => {
		expect(L.parseHm('09:30')).toEqual({ hour: 9, minute: 30 })
		expect(L.parseHm('00:00')).toEqual({ hour: 0, minute: 0 })
		expect(L.parseHm('23:59')).toEqual({ hour: 23, minute: 59 })
		expect(L.parseHm('24:00')).toBeNull()
		expect(L.parseHm('12:60')).toBeNull()
		expect(L.parseHm('9:30')).toBeNull()
		expect(L.parseHm('')).toBeNull()
		expect(L.parseHm(null)).toBeNull()
		expect(L.parseHm('12:30:00')).toBeNull()
	})

	it('accepts a typed H:MM and rejects an hour or minute past the clock', () => {
		expect(L.parseFlexible('9:05')).toEqual({ hour: 9, minute: 5 })
		expect(L.parseFlexible(' 9.5 ')).toEqual({ hour: 9, minute: 5 })
		expect(L.parseFlexible('24:00')).toBeNull()
		expect(L.parseFlexible('12:60')).toBeNull()
		expect(L.parseFlexible('nope')).toBeNull()
	})

	it('formats only in-range values', () => {
		expect(L.formatHm(9, 5)).toBe('09:05')
		expect(L.formatHm(0, 0)).toBe('00:00')
		expect(L.formatHm(24, 0)).toBe('')
		expect(L.formatHm(12, 60)).toBe('')
		expect(L.formatHm(null, 0)).toBe('')
	})

	it('keeps the minute grid and inserts an off-grid value once', () => {
		expect(L.normalizeStep(1)).toBe(1)
		expect(L.normalizeStep(15)).toBe(15)
		expect(L.normalizeStep(45)).toBe(1)
		expect(L.normalizeStep('nope')).toBe(1)
		const fine = L.minuteChoices(null, 1)
		expect(fine[0]).toBe('00')
		expect(fine).toHaveLength(60)
		expect(fine).toContain('51')
		const coarse = L.minuteChoices(null, 5)
		expect(coarse).toEqual(['00', '05', '10', '15', '20', '25', '30', '35', '40', '45', '50', '55'])
		const kept = L.minuteChoices('07', 5)
		expect(kept).toContain('07')
		expect(kept).toContain('05')
		expect(L.minuteChoices('99', 5)).not.toContain('99')
		expect(L.minuteChoices('abc', 5)).toHaveLength(12)
		expect(L.minuteChoices(7.5, 5)).toHaveLength(12)
		expect(L.hourChoices()).toHaveLength(24)
		expect(L.hourChoices()[23]).toBe('23')
	})

	it('commits a digit immediately when it cannot start a second digit', () => {
		expect(L.applySectionDigit('hour', '', '3')).toEqual({ value: 3, buffer: '', advance: true })
		expect(L.applySectionDigit('hour', '', '1')).toEqual({ value: 1, buffer: '1', advance: false })
		expect(L.applySectionDigit('hour', '1', '5')).toEqual({ value: 15, buffer: '', advance: true })
		expect(L.applySectionDigit('hour', '2', '3')).toEqual({ value: 23, buffer: '', advance: true })
		expect(L.applySectionDigit('hour', '2', '4')).toEqual({ value: 4, buffer: '', advance: true })
		expect(L.applySectionDigit('minute', '', '6')).toEqual({ value: 6, buffer: '', advance: true })
		expect(L.applySectionDigit('minute', '', '5')).toEqual({ value: 5, buffer: '5', advance: false })
		expect(L.applySectionDigit('minute', '5', '9')).toEqual({ value: 59, buffer: '', advance: true })
		expect(L.applySectionDigit('minute', '6', '0')).toEqual({ value: 0, buffer: '0', advance: false })
		expect(L.applySectionDigit('hour', '', 'x')).toBeNull()
		expect(L.applySectionDigit('hour', 'ab', '1')).toEqual({ value: 1, buffer: '1', advance: false })
	})

	it('wraps hours and snaps minutes onto the step grid', () => {
		expect(L.stepSection('hour', 23, 1, 1)).toBe(0)
		expect(L.stepSection('hour', 0, -1, 1)).toBe(23)
		expect(L.stepSection('hour', null, 1, 1)).toBe(0)
		expect(L.stepSection('hour', null, -1, 1)).toBe(23)
		expect(L.stepSection('minute', 10, 1, 5)).toBe(15)
		expect(L.stepSection('minute', 7, 1, 5)).toBe(10)
		expect(L.stepSection('minute', 7, -1, 5)).toBe(5)
		expect(L.stepSection('minute', 0, -1, 5)).toBe(55)
		expect(L.stepSection('minute', 59, 1, 1)).toBe(0)
		expect(L.stepSection('minute', null, -1, 5)).toBe(55)
		expect(L.endValue('hour', 5)).toBe(23)
		expect(L.endValue('minute', 5)).toBe(55)
		expect(L.endValue('minute', 1)).toBe(59)
	})

	it('reads a pasted clock, an appended digit, or a cleared field', () => {
		expect(L.reconcileRaw('08:07', '09')).toEqual({ type: 'full', hour: 8, minute: 7 })
		expect(L.reconcileRaw('015', '01')).toEqual({ type: 'digit', digit: '5' })
		expect(L.reconcileRaw('4', '09')).toEqual({ type: 'digit', digit: '4' })
		expect(L.reconcileRaw('', '09')).toEqual({ type: 'clear' })
		expect(L.reconcileRaw('09', '09')).toEqual({ type: 'noop' })
	})
})
