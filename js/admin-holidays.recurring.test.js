/**
 * @vitest-environment jsdom
 *
 * Recurring holiday rules on the admin page (B-R7): ↻ badge + rule actions
 * on synthesized instances, muted skipped rows, the repeat-controls editor
 * row, the year-vs-series dialog, convert action and 'Add annually'.
 *
 * EC-B13 needle: a rule-instance row must never carry data-id — it is not a
 * persisted row and must not round-trip through row endpoints.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const fetchMock = vi.fn(async () => ({
	json: async () => ({ success: true, rule: { id: 9, name: 'X' } }),
}))

beforeAll(() => {
	window.ArbeitszeitCheckUtils = {
		$: (sel) => document.querySelector(sel),
		on: (el, ev, fn) => el.addEventListener(ev, fn),
		$$: () => [],
	}
	window.OC = {
		generateUrl: (u) => u,
		requestToken: 'test',
	}
	vi.stubGlobal('fetch', fetchMock)
})

beforeEach(() => {
	document.body.innerHTML = ''
	fetchMock.mockClear()
	t.setHolidayRules([])
	t.setSkippedRules([])
})

/** @type {typeof window.__ArbeitszeitCheckAdminHolidaysTestables} */
let t

beforeAll(async () => {
	await import('./admin-holidays.js')
	t = window.__ArbeitszeitCheckAdminHolidaysTestables
})

function makeTbody() {
	const tbody = document.createElement('tbody')
	tbody.id = 'holiday-tbody'
	document.body.appendChild(tbody)
	return tbody
}

describe('recurring rule instances (B-R7, EC-B13)', () => {
	it('renders ↻ badge + rule actions and never a data-id row', () => {
		const tbody = makeTbody()
		t.appendExistingHolidayRow(tbody, {
			id: null,
			rule_id: 7,
			date: '2027-12-24',
			name: 'Heiligabend',
			kind: 'full',
			scope: 'company',
			recurring: true,
		})

		const row = tbody.querySelector('tr')
		expect(row.getAttribute('data-rule-id')).toBe('7')
		// EC-B13: rule instances are not rows — no data-id ever.
		expect(row.hasAttribute('data-id')).toBe(false)
		expect(row.classList.contains('admin-holidays-row--rule')).toBe(true)

		const badge = row.querySelector('.admin-holidays-badge--rule')
		expect(badge).toBeTruthy()
		expect(badge.textContent).toContain('annual')

		const buttons = Array.from(row.querySelectorAll('button'))
		// edit + remove — never a plain row-delete.
		expect(buttons.length).toBe(2)
	})

	it('renders skipped instances muted with a restore action', () => {
		const tbody = makeTbody()
		t.appendExistingHolidayRow(tbody, {
			id: null,
			rule_id: 7,
			date: '2027-12-24',
			name: 'Heiligabend',
			kind: 'full',
			scope: 'company',
			recurring: true,
			skipped: true,
		})

		const row = tbody.querySelector('tr')
		expect(row.classList.contains('admin-holidays-row--skipped')).toBe(true)
		expect(row.hasAttribute('data-id')).toBe(false)
		expect(row.textContent).toContain('Skipped')
		const restore = row.querySelector('button')
		expect(restore).toBeTruthy()
		restore.click()
		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toContain('/api/admin/holiday-rules/7/skips/')
		expect(init.method).toBe('DELETE')
	})

	it('persisted rows keep data-id + delete + convert actions', () => {
		const tbody = makeTbody()
		t.appendExistingHolidayRow(tbody, {
			id: 33,
			date: '2027-12-24',
			name: 'Firmenfest',
			kind: 'full',
			scope: 'company',
		})
		const row = tbody.querySelector('tr')
		expect(row.getAttribute('data-id')).toBe('33')
		const buttons = Array.from(row.querySelectorAll('button'))
		expect(buttons.length).toBe(2) // delete + make-annual
	})
})

describe('repeat-controls editor row', () => {
	it('builds checkbox + anchor select with curated movable days', () => {
		const editorRow = document.createElement('tr')
		const extras = t.buildRepeatExtrasRow(editorRow)

		const cb = extras.querySelector('input[name="recurring"]')
		const anchor = extras.querySelector('select[name="anchor"]')
		expect(cb).toBeTruthy()
		expect(anchor).toBeTruthy()
		expect(anchor.disabled).toBe(true)

		const values = Array.from(anchor.options).map((o) => o.value)
		expect(values).toEqual([
			'date',
			'easter:-48', 'easter:-47', 'easter:-46', 'easter:-2',
			'easter:1', 'easter:39', 'easter:50', 'easter:60',
		])

		cb.checked = true
		cb.dispatchEvent(new Event('change'))
		expect(anchor.disabled).toBe(false)
		cb.checked = false
		cb.dispatchEvent(new Event('change'))
		expect(anchor.disabled).toBe(true)
		expect(anchor.value).toBe('date')

		// extras row is glued to its editor row for joint removal
		expect(editorRow.__extrasRow).toBe(extras)
	})
})

describe('year-vs-series choice dialog (NN-04)', () => {
	it('opens a native <dialog> with skip-year / stop / cancel', () => {
		if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) {
			return
		}
		const showModal = vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(() => {})
		t.openRuleRemoveChoiceDialog(7, 'Heiligabend', 2027)

		const dlg = document.querySelector('dialog.azc-dialog')
		expect(dlg).toBeTruthy()
		expect(showModal).toHaveBeenCalled()
		const labels = Array.from(dlg.querySelectorAll('button')).map((b) => b.textContent)
		expect(labels).toHaveLength(3)
		expect(labels[0]).toContain('2027')
		expect(dlg.querySelector('.azc-btn--danger')).toBeTruthy()
		showModal.mockRestore()
	})

	it('skip button posts to the skips endpoint', () => {
		if (!HTMLDialogElement.prototype.showModal) {
			return
		}
		vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(() => {})
		t.openRuleRemoveChoiceDialog(7, 'Heiligabend', 2027)

		const dlg = document.querySelector('dialog.azc-dialog')
		const skipBtn = dlg.querySelector('.azc-btn--secondary')
		skipBtn.click()
		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toContain('/api/admin/holiday-rules/7/skips')
		expect(init.method).toBe('POST')
		expect(JSON.parse(init.body).year).toBe(2027)
		HTMLDialogElement.prototype.showModal.mockRestore()
	})
})

describe('suggestions', () => {
	it('renders Add annually button when no rule exists yet', () => {
		const item = t.buildSuggestionItem(
			{ date: '2027-12-24', name: 'Heiligabend', kind: 'half', exists: false, recurring_exists: false },
			'NW'
		)
		const buttons = Array.from(item.querySelectorAll('button')).map((b) => b.textContent)
		expect(buttons.length).toBe(2) // Add as company holiday + Add annually
	})

	it('renders Already annual badge when a rule exists', () => {
		const item = t.buildSuggestionItem(
			{ date: '2027-12-24', name: 'Heiligabend', exists: false, recurring_exists: true },
			'NW'
		)
		expect(item.textContent).toContain('annual')
		expect(item.querySelectorAll('button')).toHaveLength(1) // only row-add
	})

	it('addSuggestionAnnually posts anchor metadata for easter days', async () => {
		const btn = document.createElement('button')
		t.addSuggestionAnnually(
			{
				date: '2027-03-26',
				name: 'Good Friday',
				name_msgid: 'Good Friday',
				kind: 'full',
				anchor: { type: 'easter', offset: -2 },
			},
			'NW',
			btn
		)
		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toContain('/api/admin/holiday-rules')
		const body = JSON.parse(init.body)
		expect(body.recurring).toBe(true)
		expect(body.anchor).toBe('easter')
		expect(body.easterOffset).toBe(-2)
		expect(body.nameMsgid).toBe('Good Friday')
		expect(body.scope).toBe('company')
	})
})

describe('loadExistingHolidays merge order', () => {
	it('renders skipped-only years instead of the empty row', async () => {
		document.body.innerHTML = [
			'<select id="holiday-state-select"><option value="NW" selected>NW</option></select>',
			'<select id="holiday-year-select"><option value="2027" selected>2027</option></select>',
			'<div id="holiday-results"></div>',
			'<table><tbody id="holiday-tbody"></tbody></table>',
		].join('')
		fetchMock.mockResolvedValueOnce({
			json: async () => ({
				success: true,
				holidays: [],
				rules: [{
					id: 7, name: 'Heiligabend', kind: 'full', scope: 'company',
					rule_key: 'd:12-24', start_year: 2025, active: true,
				}],
				skipped_rules: [{
					rule_id: 7, year: 2027, date: '2027-12-24',
					name: 'Heiligabend', kind: 'full', scope: 'company',
				}],
			}),
		})

		t.loadExistingHolidays()
		await vi.waitFor(() => {
			expect(document.querySelector('#holiday-tbody tr')).toBeTruthy()
		})

		const row = document.querySelector('#holiday-tbody tr')
		expect(row.classList.contains('admin-holidays-row--skipped')).toBe(true)
		expect(row.querySelector('.admin-holidays-empty')).toBeNull()
		expect(row.getAttribute('data-rule-id')).toBe('7')
	})

	it('still renders the empty row when nothing exists at all', async () => {
		document.body.innerHTML = [
			'<select id="holiday-state-select"><option value="NW" selected>NW</option></select>',
			'<select id="holiday-year-select"><option value="2027" selected>2027</option></select>',
			'<table><tbody id="holiday-tbody"></tbody></table>',
		].join('')
		fetchMock.mockResolvedValueOnce({
			json: async () => ({ success: true, holidays: [], rules: [], skipped_rules: [] }),
		})

		t.loadExistingHolidays()
		await vi.waitFor(() => {
			expect(document.querySelector('#holiday-tbody tr')).toBeTruthy()
		})
		expect(document.querySelector('#holiday-tbody .admin-holidays-empty')).toBeTruthy()
	})
})

describe('convert action', () => {
	it('calls the atomic recurring endpoint after confirm', async () => {
		window.AzcComponents = {
			confirmDialog: vi.fn(async () => true),
		}
		await t.convertRowToRule(
			{ id: 33, date: '2027-12-24', name: 'Firmenfest', kind: 'full', scope: 'company' },
			null
		)
		expect(window.AzcComponents.confirmDialog).toHaveBeenCalled()
		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toContain('/api/admin/state-holidays/33/recurring')
		expect(init.method).toBe('POST')
	})
})
