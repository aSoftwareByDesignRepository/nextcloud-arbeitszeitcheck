import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import './csv.js'
import '../reports.js'

describe('ArbeitszeitCheckCsv', () => {
	const csv = () => window.ArbeitszeitCheckCsv

	it('exposes neutralize and cell', () => {
		expect(csv()).toBeTruthy()
		expect(typeof csv().neutralize).toBe('function')
		expect(typeof csv().cell).toBe('function')
	})

	it.each([
		{ name: 'equals', ch: '=' },
		{ name: 'plus', ch: '+' },
		{ name: 'minus', ch: '-' },
		{ name: 'at', ch: '@' },
		{ name: 'tab', ch: '\t' },
		{ name: 'cr', ch: '\r' },
		{ name: 'lf', ch: '\n' },
		{ name: 'vt', ch: '\x0B' },
	])('neutralize() apostrophe-prefixes a leading $name', ({ ch }) => {
		expect(csv().neutralize(ch + 'PAYLOAD')).toBe("'" + ch + 'PAYLOAD')
	})

	it('neutralize() prefixes a formula lead after whitespace (PHP guard parity)', () => {
		expect(csv().neutralize(' =cmd')).toBe("' =cmd")
		expect(csv().neutralize('\t=cmd')).toBe("'\t=cmd")
	})

	it('neutralize() leaves ordinary values and mid-string = alone', () => {
		expect(csv().neutralize('plain')).toBe('plain')
		expect(csv().neutralize(' mid=formula')).toBe(' mid=formula')
		expect(csv().neutralize('')).toBe('')
	})

	it('neutralize() maps null/undefined to an empty cell', () => {
		expect(csv().neutralize(null)).toBe('')
		expect(csv().neutralize(undefined)).toBe('')
	})

	it('cell() keeps the apostrophe inside RFC 4180 quoting', () => {
		expect(csv().cell('=x;y')).toBe('"\'=x;y"')
		expect(csv().cell('say "hi"')).toBe('"say ""hi"""')
		expect(csv().cell('a,b')).toBe('"a,b"')
		expect(csv().cell('plain')).toBe('plain')
	})
})

describe('reports.js overtime CSV export', () => {
	const PAYLOAD = {
		success: true,
		report: {
			bank_enabled: false,
			users: [
				{
					user_id: 'alice',
					display_name: '=HYPERLINK("https://evil.example","x")',
					total_hours_worked: 40,
					required_hours: 38,
					period_overtime_hours: 2,
					period_undertime_hours: 0,
					effective_balance: 5,
				},
				{
					user_id: '\t=cmd',
					display_name: 'Semi;Colon',
					total_hours_worked: 10,
					required_hours: 10,
					period_overtime_hours: 0,
					period_undertime_hours: 0,
					effective_balance: 0,
				},
				{
					user_id: 'bob',
					display_name: ' Plain Name ',
					total_hours_worked: null,
					required_hours: null,
					period_overtime_hours: null,
					period_undertime_hours: null,
					effective_balance: null,
				},
			],
		},
	}

	let blobs

	beforeEach(() => {
		document.body.innerHTML = `
			<form id="report-form"></form>
			<input id="report-type" value="overtime">
			<input id="report-scope" value="personal">
			<input id="start-date">
			<input id="end-date">
			<input id="format" value="csv">
			<section id="report-preview"></section>
			<div id="report-preview-content"></div>
			<div id="report-preview-live"></div>
		`
		window.ArbeitszeitCheck = {
			page: 'reports',
			canAccessReports: true,
			isAdmin: false,
			isManager: false,
			apiUrl: { overtime: '/apps/arbeitszeitcheck/api/reports/overtime' },
			l10n: {},
		}
		blobs = []
		URL.createObjectURL = (blob) => {
			blobs.push(blob)
			return 'blob:mock-' + blobs.length
		}
		URL.revokeObjectURL = () => {}
		if (!Element.prototype.scrollIntoView) {
			Element.prototype.scrollIntoView = () => {}
		}
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		globalThis.fetch = vi.fn(() =>
			Promise.resolve({
				ok: true,
				status: 200,
				text: () => Promise.resolve(JSON.stringify(PAYLOAD)),
			}),
		)
	})

	afterEach(() => {
		delete window.ArbeitszeitCheck
	})

	async function submitReportForm() {
		document.dispatchEvent(new Event('DOMContentLoaded'))
		document.getElementById('start-date').value = '2026-10-01'
		document.getElementById('end-date').value = '2026-10-07'
		document
			.getElementById('report-form')
			.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
		await vi.waitFor(() => expect(blobs.length).toBe(1))
		return new Promise((resolve, reject) => {
			const reader = new FileReader()
			reader.onload = () => resolve(reader.result)
			reader.onerror = () => reject(reader.error)
			reader.readAsText(blobs[0])
		})
	}

	it('apostrophe-prefixes formula leads in user-controlled cells', async () => {
		const text = await submitReportForm()
		expect(text).toContain(
			'user_id;display_name;worked_h;required_h;period_overtime_h;period_undertime_h;effective_balance_h',
		)
		// display_name leading '=' → apostrophe inside the quoting
		expect(text).toContain('"\'=HYPERLINK(""https://evil.example"",""x"")"')
		// user_id leading TAB+'=' → apostrophe prefix
		expect(text).toContain("'\t=cmd")
		// embedded delimiter still survives via quoting (not flattened to ',')
		expect(text).toContain('"Semi;Colon"')
		// null numerics emit empty cells; plain name untouched
		expect(text).toContain('bob; Plain Name ;;;;;')
	})

	it('fails closed when the CSV guard helper is absent', async () => {
		const saved = window.ArbeitszeitCheckCsv
		delete window.ArbeitszeitCheckCsv
		try {
			document.dispatchEvent(new Event('DOMContentLoaded'))
			document.getElementById('start-date').value = '2026-10-01'
			document.getElementById('end-date').value = '2026-10-07'
			document
				.getElementById('report-form')
				.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
			await vi.waitFor(() => {
				const err = document.querySelector('#report-preview-content .report-error')
				expect(err).toBeTruthy()
			})
			expect(blobs.length).toBe(0)
		} finally {
			window.ArbeitszeitCheckCsv = saved
		}
	})
})
