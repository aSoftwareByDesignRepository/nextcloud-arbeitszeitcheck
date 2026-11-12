import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * NH-05: fractional-day rows in the manager absence list get a tooltip
 * so a bare "0.5" is never unexplained — the copy is type-aware, same
 * as the request-form previews (vacation "uses", credit types "credits
 * planned time", neutral otherwise).
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
		const html = T().durationCellHtml({ days: 0.5, type: 'sick_leave' })
		expect(html).toContain('azc-half-day-hint')
		expect(html).toContain('>0.5</span>')
		expect(html).toContain('credits half of the planned working time')
	})

	it('marks a quarter-weight half day (0.25 stored) too', () => {
		window.ArbeitszeitCheck.halfDayCreditTypes = ['sick_leave']
		const html = T().durationCellHtml({ days: 0.25, type: 'sick_leave' })
		expect(html).toContain('azc-half-day-hint')
		expect(html).toContain('>0.3</span>') // toFixed(1) rounds 0.25
	})

	it('uses the vacation copy for vacation rows', () => {
		const html = T().durationCellHtml({ days: 0.5, type: 'vacation' })
		expect(html).toContain('uses 0.5 vacation day')
	})

	it('falls back to the neutral copy for non-credit types', () => {
		window.ArbeitszeitCheck.halfDayCreditTypes = ['sick_leave']
		const html = T().durationCellHtml({ days: 0.5, type: 'home_office' })
		expect(html).toContain('uses 0.5 day.')
	})

	it('does not mark fractional HOURS display (duration_hours rows)', () => {
		const html = T().durationCellHtml({ days: 0.5, durationHours: 4, type: 'vacation' })
		expect(html).toContain('azc-half-day-hint')
		expect(html).toContain('4.0 h')
	})
})
