/**
 * @vitest-environment jsdom
 *
 * Label-map contract for admin-year-end.js: wire constants emitted by
 * YearEndResetService (blockedReason ERROR_* codes, run/item STATE_*,
 * ORIGIN_*) must never render verbatim — every chip/detail/origin cell
 * paints a localized label, unknown codes a generic fallback.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const API_BASE = '/apps/arbeitszeitcheck/api/year-end'

function shellDom() {
	document.body.innerHTML = `
		<div id="azc-year-end" data-api-base="${API_BASE}" data-policy-revision="3">
			<div id="azc-ye-live"></div>
			<form id="azc-ye-preview-form" novalidate>
				<select id="azc-ye-year"><option value="2025" selected>2025</option></select>
				<input type="checkbox" id="azc-ye-positive" checked />
				<input type="checkbox" id="azc-ye-negative" />
				<select id="azc-ye-users" multiple></select>
				<textarea id="azc-ye-reason">annual close reason</textarea>
				<p id="azc-ye-signs-error" hidden></p>
				<p id="azc-ye-users-error" hidden></p>
				<p id="azc-ye-reason-error" hidden></p>
				<button type="submit" id="azc-ye-preview-btn"></button>
			</form>
			<section id="azc-ye-preview-card" hidden>
				<table id="azc-ye-preview-table"><tbody></tbody></table>
			</section>
			<button type="button" id="azc-ye-confirm-btn"></button>
			<button type="button" id="azc-ye-discard-btn"></button>
			<section id="azc-ye-run-card" hidden>
				<p id="azc-ye-run-summary"></p>
				<table id="azc-ye-run-table"><tbody></tbody></table>
			</section>
			<table id="azc-ye-history-table"><tbody></tbody></table>
		</div>
		<script type="application/json" id="azc-ye-users-data">${JSON.stringify([
			{ id: 'u-blocked', name: 'Blocked User' },
			{ id: 'u-unknown', name: 'Unknown User' },
			{ id: 'u-fine', name: 'Fine User' },
		])}</script>`
}

const previewData = {
	success: true,
	preview_id: 'pv-1',
	policy_revision: 3,
	targets: [
		{ user_id: 'u-blocked', blocked: 'NEXT_OPENING_NONZERO', before_centi_hours: 1200, delta_centi_hours: -1200 },
		{ user_id: 'u-unknown', blocked: 'SOME_FUTURE_CODE', before_centi_hours: 0, delta_centi_hours: 0 },
		{ user_id: 'u-fine', blocked: null, before_centi_hours: 500, delta_centi_hours: -500 },
	],
}

const runData = {
	id: 7,
	state: 'executing',
	counts: { done: 0, failed: 0, skipped: 0 },
	items: [
		{
			user_id: 'u-blocked',
			state: 'in_progress',
			review_state: 'needs_review',
			error_code: 'SOURCE_CHANGED',
			before_centi_hours: 1200,
			delta_centi_hours: -1200,
		},
	],
}

const histItems = [
	{ id: 6, closing_year: 2024, origin: 'annual', state: 'completed', created_at: '2025-01-02T03:00:00+00:00' },
	{ id: 5, closing_year: 2024, origin: 'cron_job', state: 'previewed', created_at: '2025-01-01T03:00:00+00:00' },
]

async function loadModule() {
	vi.resetModules()
	shellDom()
	window.t = (_app, s) => s
	Element.prototype.scrollIntoView = vi.fn()
	window.AzcApi = {
		fetch: vi.fn(async (url, opts) => {
			if (String(url).includes('/preview')) {
				return { ok: true, data: previewData }
			}
			if (String(url).includes('limit=25')) {
				return { ok: true, data: { items: histItems } }
			}
			if (opts && opts.method === 'POST' && url === API_BASE) {
				return { ok: true, data: { success: true, run: runData } }
			}
			return { ok: true, data: { run: runData } }
		}),
	}
	await import('./admin-year-end.js')
}

function chipTexts(tableSel) {
	return Array.from(document.querySelectorAll(`${tableSel} .azc-status-chip`)).map((c) => c.textContent.trim())
}

describe('admin-year-end wire-code label maps', () => {
	beforeEach(async () => {
		await loadModule()
	})

	afterEach(() => {
		document.body.innerHTML = ''
		delete window.AzcApi
		delete window.t
	})

	it('renders localized labels instead of raw blocked codes in the preview', async () => {
		document.getElementById('azc-ye-users').options[0].selected = true
		document.getElementById('azc-ye-preview-form')
			.dispatchEvent(new Event('submit', { cancelable: true }))

		await vi.waitFor(() => {
			expect(document.querySelectorAll('#azc-ye-preview-table .azc-status-chip')).toHaveLength(3)
		})

		const chips = chipTexts('#azc-ye-preview-table')
		expect(chips[0]).toBe('An opening balance already exists for the new year.')
		expect(chips[1]).toBe('Blocked')
		expect(chips[2]).toBe('Will reset')
		for (const text of chips) {
			expect(text).not.toMatch(/[A-Z_]{8,}/)
			expect(text).not.toContain('NEXT_OPENING_NONZERO')
			expect(text).not.toContain('SOME_FUTURE_CODE')
		}
	})

	it('renders localized run/item state and error-code labels, never raw enums', async () => {
		document.getElementById('azc-ye-users').options[0].selected = true
		document.getElementById('azc-ye-preview-form')
			.dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => {
			expect(document.querySelectorAll('#azc-ye-preview-table .azc-status-chip')).toHaveLength(3)
		})

		document.getElementById('azc-ye-confirm-btn').dispatchEvent(new Event('click', { cancelable: true }))
		await vi.waitFor(() => {
			expect(document.querySelectorAll('#azc-ye-run-table .azc-status-chip')).toHaveLength(1)
		})

		const summary = document.getElementById('azc-ye-run-summary').textContent
		expect(summary).toContain('Running')
		expect(summary).not.toContain('executing')

		const chip = document.querySelector('#azc-ye-run-table .azc-status-chip')
		expect(chip.textContent).toContain('Ongoing')
		expect(chip.textContent).toContain('needs review')
		expect(chip.getAttribute('data-status')).toBe('in_progress')

		const detail = document.querySelector('#azc-ye-run-table td[data-cell="Detail"]').textContent
		expect(detail).toBe('The data changed since the preview — run a new preview.')
		expect(detail).not.toMatch(/[A-Z_]{8,}/)
	})

	it('renders localized origin and state labels in the history table', async () => {
		await vi.waitFor(() => {
			expect(document.querySelectorAll('#azc-ye-history-table tbody tr')).toHaveLength(2)
		})

		const rows = Array.from(document.querySelectorAll('#azc-ye-history-table tbody tr'))
		expect(rows[0].querySelector('td[data-cell="Origin"]').textContent).toBe('Annual')
		expect(rows[0].querySelector('td[data-cell="State"] .azc-status-chip').textContent).toBe('Completed')
		expect(rows[1].querySelector('td[data-cell="Origin"]').textContent).toBe('Unknown')
		expect(rows[1].querySelector('td[data-cell="State"] .azc-status-chip').textContent).toBe('Previewed')

		for (const row of rows) {
			for (const td of row.querySelectorAll('td')) {
				expect(td.textContent).not.toMatch(/\b(executing|selecting|previewed|in_progress)\b/)
			}
		}
	})
})
