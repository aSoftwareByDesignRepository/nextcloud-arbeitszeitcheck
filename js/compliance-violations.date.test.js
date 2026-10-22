import { describe, expect, it } from 'vitest'

import './compliance-violations.js'

const api = window.__ArbeitszeitCheckComplianceViolationsTestables

describe('compliance violation list', () => {
	it('shows calendar dates as day.month.year and leaves other text alone', () => {
		expect(api.formatViolationDate('2026-10-21')).toBe('21.10.2026')
		expect(api.formatViolationDate('2026-01-05')).toBe('05.01.2026')
		expect(api.formatViolationDate('')).toBe('-')
		expect(api.formatViolationDate(null)).toBe('-')
		expect(api.formatViolationDate(undefined)).toBe('-')
		expect(api.formatViolationDate('21.10.2026')).toBe('21.10.2026')
		expect(api.formatViolationDate('2026-10-21T08:00:00')).toBe('2026-10-21T08:00:00')
	})

	it('reads the scoped person from the table before the inline config', () => {
		document.body.innerHTML = '<table id="violations-table" data-filter-user-id="  employee1  "></table>'
		window.ArbeitszeitCheck = { complianceFilterUserId: 'someone-else' }
		expect(api.getFilterUserId()).toBe('employee1')

		document.body.innerHTML = '<table id="violations-table" data-filter-user-id=""></table>'
		window.ArbeitszeitCheck = { complianceFilterUserId: ' employee1 ' }
		expect(api.getFilterUserId()).toBe('employee1')

		window.ArbeitszeitCheck = {}
		expect(api.getFilterUserId()).toBe('')
	})
})
