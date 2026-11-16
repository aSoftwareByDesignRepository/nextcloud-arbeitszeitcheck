import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Contract tests for the split-service accounting presentation
 * (spec split-day §7.3 / §7.6 / §9.8):
 *
 *  - split entries render partial-service + interruption lines
 *  - covered_by_interruption shows the muted break line
 *  - each engine reason maps to a human hint — raw codes never render
 *  - review state shows the "Needs review" banner + correction action
 *  - active state shows the "provisional" label
 *  - legacy entries (no `accounting` key) render byte-identical
 */

const snapshot = {}

function accountingFixture(overrides = {}) {
	return {
		contractVersion: 2,
		state: 'applied',
		split: true,
		reason: 'clock_out_after_cutoff',
		segments: [
			{ no: 1, start: '2026-11-16T06:00:00+01:00', end: '2026-11-16T10:00:00+01:00', workSeconds: 14400 },
			{ no: 2, start: '2026-11-16T13:30:00+01:00', end: '2026-11-16T16:30:00+01:00', workSeconds: 10800 },
		],
		interruptions: [
			{ start: '2026-11-16T10:00:00+01:00', end: '2026-11-16T13:30:00+01:00', seconds: 12600 },
		],
		excludedSeconds: 12600,
		breakHandling: 'covered_by_interruption',
		netWorkSeconds: 25200,
		paidWorkSeconds: 25200,
		...overrides,
	}
}

function entryFixture(accounting) {
	const entry = {
		id: 42,
		status: 'completed',
		startTime: '2026-11-16T06:00:00+01:00',
		endTime: '2026-11-16T16:30:00+01:00',
		workingDurationHours: 7,
		durationHours: 10.5,
		breakDurationHours: 0,
	}
	if (accounting !== undefined) {
		entry.accounting = accounting
	}
	return entry
}

beforeEach(async () => {
	snapshot.ArbeitszeitCheck = globalThis.window.ArbeitszeitCheck
	snapshot.ArbeitszeitCheckApp = globalThis.window.ArbeitszeitCheckApp
	snapshot.ArbeitszeitCheckTime = globalThis.window.ArbeitszeitCheckTime
	vi.resetModules()

	globalThis.window.ArbeitszeitCheck = { page: 'none', l10n: {}, mainUiStrings: {} }
	// Deterministic display-time stub: HH:mm straight from the stored ISO.
	globalThis.window.ArbeitszeitCheckTime = {
		parseInstant: (v) => (v ? new Date(v) : null),
		formatTime: (v) => String(v).slice(11, 16),
		formatDate: (v) => String(v).slice(0, 10),
		ymd: (d) => '2026-11-16',
		todayYmd: () => '2026-11-16',
		parseYmd: (v) => new Date(v + 'T12:00:00'),
	}
	await import('./arbeitszeitcheck-main.js')
})

afterEach(() => {
	globalThis.window.ArbeitszeitCheck = snapshot.ArbeitszeitCheck
	globalThis.window.ArbeitszeitCheckApp = snapshot.ArbeitszeitCheckApp
	globalThis.window.ArbeitszeitCheckTime = snapshot.ArbeitszeitCheckTime
})

describe('renderEntryAccounting (split-day §7.3)', () => {
	it('returns empty string for legacy entries (no accounting key)', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		expect(app.renderEntryAccounting(entryFixture(undefined))).toBe('')
		expect(app.renderEntryAccounting(entryFixture(null))).toBe('')
		expect(app.renderEntryAccounting(entryFixture('bogus'))).toBe('')
	})

	it('renders partial services and interruption in chronological order', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const html = app.renderEntryAccounting(entryFixture(accountingFixture()))
		const ps1 = html.indexOf('Partial service 1')
		const intr = html.indexOf('Interruption')
		const ps2 = html.indexOf('Partial service 2')
		expect(ps1).toBeGreaterThanOrEqual(0)
		expect(intr).toBeGreaterThan(ps1)
		expect(ps2).toBeGreaterThan(intr)
		expect(html).toContain('06:00–10:00')
		expect(html).toContain('13:30–16:30')
		expect(html).toContain('4:00')
		expect(html).toContain('3:00')
		expect(html).toContain('not counted as working time')
	})

	it('shows the covered-by-interruption break line only for covered_by_interruption', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const covered = app.renderEntryAccounting(entryFixture(accountingFixture()))
		expect(covered).toContain('Break covered by interruption (employer setting)')
		const standard = app.renderEntryAccounting(
			entryFixture(accountingFixture({ breakHandling: 'standard' }))
		)
		expect(standard).not.toContain('Break covered by interruption')
	})

	it('renders "View calculation" as a disclosure (details/summary), never a modal', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const html = app.renderEntryAccounting(entryFixture(accountingFixture()))
		expect(html).toContain('<details class="azc-entry-accounting__calc">')
		expect(html).toContain('<summary>')
		expect(html).toContain('View calculation')
		expect(html).toContain('3:30') // excluded 12600 s formatted
		expect(html).toContain('7:00') // net 25200 s
		expect(html).not.toContain('role="dialog"')
	})

	it('renders each non-split reason as a muted hint, never a raw code', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const cases = [
			['not_after_cutoff', 'Not split: clock-out was not after the cut-off time'],
			['clock_in_during_interruption', 'Not split: clock-in during interruption'],
			['no_interruption_today', 'Not split: no interruption today'],
			['crosses_midnight', 'Not split: crosses midnight'],
			['dst_review', 'Needs review: time change (DST)'],
		]
		for (const [reason, text] of cases) {
			const html = app.renderEntryAccounting(
				entryFixture(accountingFixture({ split: false, reason, segments: [], interruptions: [], state: 'applied' }))
			)
			expect(html, `reason ${reason}`).toContain(text)
			expect(html).not.toContain('azc-entry-accounting__line')
		}
	})

	it('renders no hint for unknown reasons', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const html = app.renderEntryAccounting(
			entryFixture(accountingFixture({ split: false, reason: 'future_engine_code', segments: [], interruptions: [] }))
		)
		expect(html).not.toContain('azc-entry-accounting__hint')
		expect(html).not.toContain('future_engine_code')
	})

	it('never renders raw reason codes anywhere', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		for (const reason of [
			'clock_out_after_cutoff', 'not_after_cutoff', 'clock_in_during_interruption',
			'no_interruption_today', 'crosses_midnight', 'dst_review',
			'override_split', 'override_continuous',
		]) {
			const html = app.renderEntryAccounting(entryFixture(accountingFixture({ reason })))
			expect(html, `reason ${reason}`).not.toContain(reason)
		}
	})

	it('shows "Needs review" banner plus a Request correction action in review state', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const html = app.renderEntryAccounting(
			entryFixture(accountingFixture({ state: 'review', reason: 'dst_review', split: false, segments: [], interruptions: [] }))
		)
		expect(html).toContain('azc-entry-accounting__review')
		expect(html).toContain('Needs review')
		expect(html).toContain('role="status"')
		expect(html).toContain('Request correction')
		expect(html).toContain('/apps/arbeitszeitcheck/time-entries')
	})

	it('labels open/active accounting as provisional', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const html = app.renderEntryAccounting(entryFixture(accountingFixture({ state: 'active' })))
		expect(html).toContain('provisional')
	})
})

describe('renderTimeEntryItem with accounting (timeline day row)', () => {
	it('appends the accounting block under the times for context entries', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const html = app.renderTimeEntryItem(entryFixture(accountingFixture()))
		expect(html).toContain('azc-entry-accounting')
		expect(html).toContain('Partial service 1')
		expect(html).toContain('Interruption')
	})

	it('renders legacy entries byte-identical (no accounting markup)', () => {
		const app = globalThis.window.ArbeitszeitCheckApp
		const html = app.renderTimeEntryItem(entryFixture(undefined))
		expect(html).not.toContain('azc-entry-accounting')
		expect(html).toContain('timeline-item--time-entry')
	})
})
