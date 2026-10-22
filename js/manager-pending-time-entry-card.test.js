/**
 * @vitest-environment jsdom
 *
 * Manager pending time-entry approval cards must read nested `summary`
 * and present manual_create differently from corrections.
 */
import { beforeAll, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

import './manager-pending-time-entry-card.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

function escapeHtml(str) {
	if (str == null) return ''
	const div = document.createElement('div')
	div.textContent = str
	return div.innerHTML
}

function t(key, fallback) {
	return fallback || key
}

const deps = {
	escapeHtml,
	t,
	formatDateForDisplay: (d) => (d === '2026-09-17' ? '17.09.2026' : d || ''),
	formatCorrectionTime: (iso) => {
		if (!iso) return '–'
		if (/^\d{1,2}:\d{2}$/.test(String(iso))) return String(iso)
		return String(iso).slice(0, 16).replace('T', ' ')
	},
	formatCorrectionBreaks: (breaks) => {
		if (!Array.isArray(breaks) || breaks.length === 0) return '–'
		return 'break'
	},
	buildCorrectionDiffRow: (label, origVal, propVal) =>
		`<div class="manager-correction-diff__row"><span>${label}</span><span>${origVal}</span><span>${propVal}</span></div>`,
}

describe('manager pending time-entry approval card', () => {
	beforeAll(() => {
		expect(window.ArbeitszeitCheckManagerPendingTimeEntry).toBeTruthy()
	})

	it('exposes helpers on window', () => {
		expect(typeof window.ArbeitszeitCheckManagerPendingTimeEntry.renderTimeEntryApprovalCardHtml).toBe('function')
	})

	it('renders manual_create from nested summary (reporter payload)', () => {
		const Card = window.ArbeitszeitCheckManagerPendingTimeEntry
		const html = Card.renderTimeEntryApprovalCardHtml({
			id: 8,
			type: 'time_entry',
			userId: 'i.sesma@tecnologicaccs.com',
			displayName: 'Iban Sesma',
			summary: {
				date: '2026-09-17',
				startTime: '09:00',
				endTime: '14:00',
				durationHours: 5,
				description: '',
				justification: 'estoy probando',
				requestType: 'manual_create',
				original: [],
				proposed: {
					startTime: '2026-09-17T09:00:00+02:00',
					endTime: '2026-09-17T14:00:00+02:00',
					description: '',
				},
			},
		}, deps)

		expect(html).toContain('Iban Sesma')
		expect(html).toContain('New manual time entry')
		expect(html).not.toContain('Time entry correction')
		expect(html).toContain('17.09.2026')
		expect(html).toContain('09:00')
		expect(html).toContain('14:00')
		expect(html).toContain('5 hours')
		expect(html).toContain('estoy probando')
		expect(html).toContain('data-request-type="manual_create"')
		expect(html).not.toContain('manager-correction-diff')
		expect(html).toContain('btn-approve-time-entry')
		expect(html).toContain('btn-reject-time-entry')
	})

	it('does not read top-level fields when summary is present (regression)', () => {
		const Card = window.ArbeitszeitCheckManagerPendingTimeEntry
		const html = Card.renderTimeEntryApprovalCardHtml({
			id: 8,
			displayName: 'Iban Sesma',
			// Misleading flat fields that caused empty cards before the fix
			startTime: undefined,
			justification: '',
			original: undefined,
			proposed: undefined,
			summary: {
				date: '2026-09-17',
				startTime: '09:00',
				endTime: '14:00',
				durationHours: 5,
				justification: 'estoy probando',
				requestType: 'manual_create',
				original: [],
				proposed: { startTime: '2026-09-17T09:00:00+02:00', endTime: '2026-09-17T14:00:00+02:00' },
			},
		}, deps)

		expect(html).toContain('17.09.2026')
		expect(html).toContain('estoy probando')
		const meta = html.match(/pending-approval-card__meta">([^<]*)</)
		expect(meta?.[1]).toBe('17.09.2026')
	})

	it('renders correction Ist/Soll from summary.original/proposed', () => {
		const Card = window.ArbeitszeitCheckManagerPendingTimeEntry
		const html = Card.renderTimeEntryApprovalCardHtml({
			id: 3,
			displayName: 'Ada',
			summary: {
				date: '2026-09-10',
				startTime: '08:00',
				endTime: '16:00',
				justification: 'Forgot break',
				requestType: 'correction',
				original: {
					startTime: '2026-09-10T08:00:00+02:00',
					endTime: '2026-09-10T16:00:00+02:00',
					breaks: [],
				},
				proposed: {
					startTime: '2026-09-10T08:00:00+02:00',
					endTime: '2026-09-10T17:00:00+02:00',
					breaks: [{ start: '12:00', end: '12:30' }],
				},
			},
		}, deps)

		expect(html).toContain('Time entry correction')
		expect(html).not.toContain('New manual time entry')
		expect(html).toContain('manager-correction-diff')
		expect(html).toContain('Forgot break')
		expect(html).toContain('data-request-type="correction"')
	})

	it('escapes justification HTML', () => {
		const Card = window.ArbeitszeitCheckManagerPendingTimeEntry
		const html = Card.renderTimeEntryApprovalCardHtml({
			id: 1,
			displayName: 'X',
			summary: {
				date: '2026-01-01',
				startTime: '09:00',
				endTime: '10:00',
				durationHours: 1,
				justification: '<script>alert(1)</script>',
				requestType: 'manual_create',
				original: [],
				proposed: {},
			},
		}, deps)
		expect(html).not.toContain('<script>alert(1)</script>')
		expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
	})

	it('detects manual_create when original is an empty array (API shape)', () => {
		const Card = window.ArbeitszeitCheckManagerPendingTimeEntry
		expect(Card.isManualCreateApproval({
			requestType: 'manual_create',
			original: [],
		})).toBe(true)
		expect(Card.isManualCreateApproval({
			requestType: 'correction',
			original: { startTime: 'x' },
		})).toBe(false)
		expect(Card.isManualCreateApproval({
			original: [],
			proposed: { startTime: 'x' },
		})).toBe(true)
	})
})

describe('manager-dashboard wires summary-aware card renderer', () => {
	const dash = readFileSync(path.join(root, 'js/manager-dashboard.js'), 'utf8')
	const card = readFileSync(path.join(root, 'js/manager-pending-time-entry-card.js'), 'utf8')

	it('delegates renderTimeEntryCard to ArbeitszeitCheckManagerPendingTimeEntry', () => {
		expect(dash).toContain('ArbeitszeitCheckManagerPendingTimeEntry')
		expect(dash).toContain('return Card.renderTimeEntryApprovalCardHtml(item, {')
		expect(dash).not.toMatch(/const date = formatDateForDisplay\(\(item\.startTime/)
		expect(dash).not.toMatch(/const justificationText = item\.justification/)
	})

	it('card module reads item.summary fields', () => {
		expect(card).toContain('parseSummary(item.summary)')
		expect(card).toContain('summary.justification')
		expect(card).toContain('isManualCreateApproval')
		expect(card).toContain('New manual time entry')
	})
})
