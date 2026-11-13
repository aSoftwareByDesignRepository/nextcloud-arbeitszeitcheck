import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * NH-05: fractional-day rows in the manager absence list get a tooltip
 * so a bare "0.5" is never unexplained. The copy branches on the
 * persisted boundary shape: rows carrying a real half day get
 * type-aware half-day copy; schedule-derived fractional counts
 * (weight-reduced days, ambiguous legacy rows) get neutral wording so
 * the tooltip never claims a half day that is not stored.
 */

const T = () => window.__ArbeitszeitCheckManagerAbsencesTestables

beforeEach(async () => {
	vi.resetModules()
	document.body.innerHTML = ''
	window.ArbeitszeitCheck = { l10n: {}, vacationUnit: 'days' }
	window.OC = { L10N: { translate: (app, s) => s } }
	await import('./manager-absences.js')
})

describe('durationCellHtml', () => {
	it('escapes plain full-day durations without a hint', () => {
		const html = T().durationCellHtml({ days: 1, type: 'sick_leave' })
		expect(html).toBe('1.0')
		expect(html).not.toContain('azc-half-day-hint')
	})

	it('marks a stored half day with the credit-aware tooltip', () => {
		window.ArbeitszeitCheck.halfDayCreditTypes = ['sick_leave']
		const html = T().durationCellHtml({ days: 0.5, type: 'sick_leave', startDayFraction: 0.5 })
		expect(html).toContain('azc-half-day-hint')
		expect(html).toContain('>0.5</span>')
		expect(html).toContain('credits half of the planned working time')
	})

	it('renders a quarter-weight half day (0.25 stored) truthfully', () => {
		window.ArbeitszeitCheck.halfDayCreditTypes = ['sick_leave']
		const html = T().durationCellHtml({ days: 0.25, type: 'sick_leave', startDayFraction: 0.5 })
		expect(html).toContain('azc-half-day-hint')
		expect(html).toContain('>0.25</span>')
		expect(html).toContain('credits half of the planned working time')
	})

	it('uses the vacation copy for vacation rows', () => {
		const html = T().durationCellHtml({ days: 0.5, type: 'vacation', startDayFraction: 0.5 })
		expect(html).toContain('includes a half vacation day')
	})

	it('uses the vacation copy for a boundary half on a 4.5-day range', () => {
		const html = T().durationCellHtml({ days: 4.5, type: 'vacation', endDayFraction: 0.5 })
		expect(html).toContain('includes a half vacation day')
		expect(html).toContain('>4.5</span>')
		expect(html).not.toContain('0.5 vacation day')
	})

	it('falls back to the neutral half-day copy for non-credit types', () => {
		window.ArbeitszeitCheck.halfDayCreditTypes = ['sick_leave']
		const html = T().durationCellHtml({ days: 0.5, type: 'home_office', startDayFraction: 0.5 })
		expect(html).toContain('includes a half day.')
	})

	it('uses schedule wording for a fractional count without stored shape', () => {
		// 4.5 from a half-weight Wednesday — no half day was requested.
		const html = T().durationCellHtml({ days: 4.5, type: 'vacation' })
		expect(html).toContain('work schedule')
		expect(html).not.toContain('a half day')
		expect(html).not.toContain('half vacation')
	})

	it('accepts snake_case shape fields from API payloads', () => {
		const html = T().durationCellHtml({ days: 0.5, type: 'vacation', start_day_fraction: 0.5 })
		expect(html).toContain('includes a half vacation day')
	})

	it('does not mark fractional HOURS display (duration_hours rows)', () => {
		const html = T().durationCellHtml({ days: 0.5, durationHours: 4, type: 'vacation', startDayFraction: 0.5 })
		expect(html).toContain('azc-half-day-hint')
		expect(html).toContain('4.0 h')
	})
})
