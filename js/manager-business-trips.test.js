/**
 * @vitest-environment jsdom
 *
 * Manager business-trip review queue: the design-system table recipe —
 * real column headers via data-label cells, name + uid employee cell,
 * icon-only review action, shared client pager markers, plus the
 * last-write-wins queue fetch guard and the reject-form reset that must
 * not leak state between trips or survive a completed decision.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchMock = vi.fn()

const DETAIL_IDS = [
	'mbt-detail-subtitle', 'mbt-detail-status',
	'mbt-detail-status-msg', 'mbt-error', 'mbt-error-text', 'mbt-conflict',
	'mbt-segments', 'mbt-linked-summary', 'mbt-day-rows',
	'mbt-sum-recorded', 'mbt-sum-work', 'mbt-sum-topup',
	'mbt-reject-toggle', 'mbt-approve', 'mbt-back',
]

function stageDom() {
	document.body.innerHTML = `
		<div id="mbt-root"
			data-queue-url="/api/manager/business-trips"
			data-detail-template="/api/manager/business-trips/__ID__"
			data-approve-template="/api/manager/business-trips/__ID__/approve"
			data-reject-template="/api/manager/business-trips/__ID__/reject"></div>
		<input id="mbt-filter-employee" type="search">
		<select id="mbt-filter-status">
			<option value=""></option>
			<option value="submitted"></option>
			<option value="approved"></option>
			<option value="rejected"></option>
			<option value="cancelled"></option>
		</select>
		<input id="mbt-filter-date-from">
		<input id="mbt-filter-date-to">
		<button id="mbt-filter-reset"></button>
		<p id="mbt-queue-status"></p>
		<table id="mbt-queue-table">
			<tbody id="mbt-queue-body">
				<tr id="mbt-empty-row" hidden><td colspan="6" class="azc-table-empty">
					<h3 id="mbt-empty-title"></h3><p id="mbt-empty-text"></p>
				</td></tr>
			</tbody>
		</table>
		<dialog id="mbt-detail"><button id="mbt-detail-close"></button></dialog>
		<dialog id="mbt-reject-dialog">
			<textarea id="mbt-reject-reason"></textarea>
			<p id="mbt-reject-error" hidden></p>
			<button id="mbt-reject-confirm"></button>
			<button id="mbt-reject-cancel"></button>
			<button id="mbt-reject-close"></button>
		</dialog>
	` + DETAIL_IDS.map((id) => `<div id="${id}"></div>`).join('')
}

function trip(over = {}) {
	return {
		id: 7,
		userId: 'alice',
		displayName: 'Alice Employee',
		status: 'submitted',
		startsAtUtc: '2026-11-16T07:00:00+00:00',
		endsAtUtc: '2026-11-18T16:00:00+00:00',
		originTimezone: 'Europe/Berlin',
		destinationTimezone: 'Europe/London',
		evaluationTimezone: 'Europe/Berlin',
		purpose: 'Kundentermin',
		revision: 3,
		currentSnapshotId: 1,
		updatedAt: '2026-11-10T09:30:00+00:00',
		...over,
	}
}

function stubQueue(items) {
	fetchMock.mockImplementation(async (url) => {
		if (url === '/api/manager/business-trips') {
			return { ok: true, status: 200, data: { success: true, items } }
		}
		return { ok: true, status: 200, data: { success: true } }
	})
}

const dataRows = () =>
	Array.from(document.querySelectorAll('#mbt-queue-body tr[data-azc-row]'))
const emptyRow = () => document.getElementById('mbt-empty-row')

async function boot() {
	vi.resetModules()
	await import('./manager-business-trips.js')
	await new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
	fetchMock.mockReset()
	stageDom()
	window.t = (app, text) => text
	window.AzcCatalog = { render: () => '<svg></svg>' }
	window.AzcApi = {
		fetch: fetchMock,
		isApiSuccess: (res) => !!(res && res.data && res.data.success === true),
	}
})

describe('manager trip queue table', () => {
	it('renders one data row per submitted trip with labelled cells', async () => {
		stubQueue([trip()])
		await boot()
		const rows = dataRows()
		expect(rows).toHaveLength(1)
		const tds = rows[0].querySelectorAll('td')
		expect(tds).toHaveLength(6)
		expect(tds[0].dataset.label).toBe('Employee')
		expect(tds[1].dataset.label).toBe('Business trip')
		expect(tds[2].dataset.label).toBe('Purpose')
		expect(tds[3].dataset.label).toBe('Status')
		expect(tds[4].dataset.label).toBe('Updated')
		expect(tds[5].dataset.label).toBe('Actions')
		expect(emptyRow().hidden).toBe(true)
		expect(document.getElementById('mbt-queue-status').textContent).toBe('')
	})

	it('renders a status chip per row for every workflow state', async () => {
		stubQueue([trip(), trip({ id: 11, status: 'approved' }), trip({ id: 12, status: 'rejected' })])
		await boot()
		const chips = document.querySelectorAll('#mbt-queue-body .azc-status-chip')
		expect(chips).toHaveLength(3)
		expect(chips[0].dataset.status).toBe('submitted')
		expect(chips[0].textContent).toBe('Submitted')
		expect(chips[1].dataset.status).toBe('approved')
		expect(chips[1].textContent).toBe('Approved')
		expect(chips[2].dataset.status).toBe('rejected')
		expect(chips[2].textContent).toBe('Rejected')
	})

	it('renders display name plus uid, falling back to uid only when no name exists', async () => {
		stubQueue([trip(), trip({ id: 8, userId: 'bob', displayName: undefined })])
		await boot()
		const [alice, bob] = dataRows()
		expect(alice.querySelector('.mbt-emp__name').textContent).toBe('Alice Employee')
		expect(alice.querySelector('.mbt-emp__uid').textContent).toBe('alice')
		expect(bob.querySelector('.mbt-emp__name').textContent).toBe('bob')
		// No redundant uid line when the label already is the uid.
		expect(bob.querySelector('.mbt-emp__uid')).toBeNull()
	})

	it('shows an em dash for a missing purpose and a formatted submitted stamp', async () => {
		stubQueue([trip({ purpose: null })])
		await boot()
		const tds = dataRows()[0].querySelectorAll('td')
		expect(tds[2].textContent).toBe('—')
		expect(tds[4].textContent).not.toBe('')
		expect(tds[4].textContent).not.toBe('—')
	})

	it('gives each row an accessible, distinguishable review action', async () => {
		stubQueue([trip(), trip({ id: 9, userId: 'bob', displayName: 'Bob Builder' })])
		await boot()
		const buttons = document.querySelectorAll('#mbt-queue-body .azc-table-actions .azc-icon-btn')
		expect(buttons).toHaveLength(2)
		expect(buttons[0].getAttribute('aria-label')).toContain('Review business trip')
		expect(buttons[0].getAttribute('aria-label')).toContain('Alice Employee')
		expect(buttons[1].getAttribute('aria-label')).toContain('Bob Builder')
		expect(buttons[0].title).toBe('Review')
		expect(buttons[0].closest('.azc-table-actions').getAttribute('role')).toBe('group')
	})

	it('opens the detail card for the clicked trip only', async () => {
		stubQueue([trip(), trip({ id: 11 })])
		fetchMock.mockImplementation(async (url) => {
			if (url === '/api/manager/business-trips') {
				return { ok: true, status: 200, data: { success: true, items: [trip(), trip({ id: 11 })] } }
			}
			if (url === '/api/manager/business-trips/11') {
				return {
					ok: true, status: 200,
					data: {
						success: true, trip: trip({ id: 11 }), snapshotToken: 'tok',
						segments: [], days: [], linkedTimeEntryIds: [],
					},
				}
			}
			return { ok: true, status: 200, data: { success: true } }
		})
		await boot()
		const buttons = document.querySelectorAll('#mbt-queue-body .azc-icon-btn')
		buttons[1].click()
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(fetchMock).toHaveBeenCalledWith('/api/manager/business-trips/11', undefined)
		expect(document.getElementById('mbt-detail').open).toBe(true)
	})

	it('shows the empty-state row and no data rows for an empty queue', async () => {
		stubQueue([])
		await boot()
		expect(dataRows()).toHaveLength(0)
		expect(emptyRow().hidden).toBe(false)
		expect(document.getElementById('mbt-queue-status').textContent).toBe('')
	})

	it('reports fetch failures in the live status region', async () => {
		fetchMock.mockResolvedValue({ ok: false, status: 500, error: 'boom', data: {} })
		await boot()
		expect(document.getElementById('mbt-queue-status').textContent).toBe('boom')
	})

	it('keeps only the newest queue response when loads overlap', async () => {
		// First load resolves LAST — its stale payload must not clobber the
		// newer result triggered by "Back to list".
		let resolveFirst
		const first = new Promise((resolve) => { resolveFirst = resolve })
		let call = 0
		fetchMock.mockImplementation(() => {
			call += 1
			if (call === 1) return first
			return Promise.resolve({
				ok: true, status: 200,
				data: { success: true, items: [trip({ id: 42 })] },
			})
		})
		vi.resetModules()
		await import('./manager-business-trips.js')
		document.getElementById('mbt-back').click()
		await new Promise((resolve) => setTimeout(resolve, 0))
		resolveFirst({ ok: true, status: 200, data: { success: true, items: [] } })
		await new Promise((resolve) => setTimeout(resolve, 0))
		// Newer load won: its single row is in the DOM, the stale empty
		// payload must not have emptied the table afterwards.
		expect(dataRows()).toHaveLength(1)
		expect(emptyRow().hidden).toBe(true)
	})

	it('ignores stale error responses superseded by a newer load', async () => {
		let rejectFirst
		const first = new Promise((_, reject) => { rejectFirst = reject })
		let call = 0
		fetchMock.mockImplementation(() => {
			call += 1
			if (call === 1) return first
			return Promise.resolve({
				ok: true, status: 200,
				data: { success: true, items: [trip()] },
			})
		})
		vi.resetModules()
		await import('./manager-business-trips.js')
		document.getElementById('mbt-back').click()
		await new Promise((resolve) => setTimeout(resolve, 0))
		rejectFirst(new Error('stale failure'))
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(document.getElementById('mbt-queue-status').textContent).toBe('')
		expect(dataRows()).toHaveLength(1)
	})

	it('clears a failed reject validation when another trip is opened', async () => {
		stubQueue([trip(), trip({ id: 11 })])
		fetchMock.mockImplementation(async (url, options) => {
			const method = (options && options.method) || 'GET'
			if (url === '/api/manager/business-trips' && method === 'GET') {
				return { ok: true, status: 200, data: { success: true, items: [trip(), trip({ id: 11 })] } }
			}
			if (url.startsWith('/api/manager/business-trips/') && method === 'GET') {
				const id = Number(url.split('/').pop())
				return {
					ok: true, status: 200,
					data: {
						success: true, trip: trip({ id }), snapshotToken: 'tok',
						segments: [], days: [], linkedTimeEntryIds: [],
					},
				}
			}
			return { ok: true, status: 200, data: { success: true } }
		})
		await boot()
		const buttons = document.querySelectorAll('#mbt-queue-body .azc-icon-btn')
		buttons[0].click()
		await new Promise((resolve) => setTimeout(resolve, 0))

		// Reject toggle → confirm with empty reason → validation error shows.
		document.getElementById('mbt-reject-toggle').click()
		document.getElementById('mbt-reject-confirm').click()
		const reason = document.getElementById('mbt-reject-reason')
		const error = document.getElementById('mbt-reject-error')
		expect(error.hidden).toBe(false)
		expect(reason.getAttribute('aria-invalid')).toBe('true')
		reason.value = 'leftover reason'

		// Open the second trip: stale reason + error must not leak over.
		buttons[1].click()
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(document.getElementById('mbt-reject-dialog').open).toBe(false)
		expect(reason.value).toBe('')
		expect(reason.getAttribute('aria-invalid')).toBeNull()
		expect(error.hidden).toBe(true)
		expect(error.textContent).toBe('')
	})

	it('hides and clears the reject form after a successful rejection', async () => {
		stubQueue([trip()])
		fetchMock.mockImplementation(async (url, options) => {
			const method = (options && options.method) || 'GET'
			if (url === '/api/manager/business-trips' && method === 'GET') {
				return { ok: true, status: 200, data: { success: true, items: [trip()] } }
			}
			if (url === '/api/manager/business-trips/7' && method === 'GET') {
				return {
					ok: true, status: 200,
					data: {
						success: true, trip: trip(), snapshotToken: 'tok',
						segments: [], days: [], linkedTimeEntryIds: [],
					},
				}
			}
			if (url === '/api/manager/business-trips/7/reject' && method === 'POST') {
				return {
					ok: true, status: 200,
					data: { success: true, trip: trip({ status: 'rejected' }) },
				}
			}
			return { ok: true, status: 200, data: { success: true } }
		})
		await boot()
		document.querySelector('#mbt-queue-body .azc-icon-btn').click()
		await new Promise((resolve) => setTimeout(resolve, 0))

		document.getElementById('mbt-reject-toggle').click()
		const reason = document.getElementById('mbt-reject-reason')
		reason.value = 'Not enough detail.'
		document.getElementById('mbt-reject-confirm').click()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(document.getElementById('mbt-reject-dialog').open).toBe(false)
		expect(reason.value).toBe('')
		// Decision made → the review dialog closes and the queue reloads.
		expect(document.getElementById('mbt-detail').open).toBe(false)
		expect(fetchMock.mock.calls.filter(([u]) => u === '/api/manager/business-trips').length).toBe(2)
	})

	it('closes the overlay, toasts, and reloads the queue after a successful approval', async () => {
		const showSuccess = vi.fn()
		window.ArbeitszeitCheckMessaging = { showSuccess }
		fetchMock.mockImplementation(async (url, options) => {
			const method = (options && options.method) || 'GET'
			if (url === '/api/manager/business-trips' && method === 'GET') {
				return { ok: true, status: 200, data: { success: true, items: [trip()] } }
			}
			if (url === '/api/manager/business-trips/7' && method === 'GET') {
				return {
					ok: true, status: 200,
					data: {
						success: true, trip: trip(), snapshotToken: 'tok',
						segments: [], days: [], linkedTimeEntryIds: [],
					},
				}
			}
			if (url === '/api/manager/business-trips/7/approve' && method === 'POST') {
				return {
					ok: true, status: 200,
					data: { success: true, trip: trip({ status: 'approved' }) },
				}
			}
			return { ok: true, status: 200, data: { success: true } }
		})
		await boot()
		document.querySelector('#mbt-queue-body .azc-icon-btn').click()
		await new Promise((resolve) => setTimeout(resolve, 0))

		document.getElementById('mbt-approve').click()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(document.getElementById('mbt-detail').open).toBe(false)
		expect(showSuccess).toHaveBeenCalledWith('Approved — the top-up was booked to the time account.')
		// Second queue GET = the reload that refreshes the row's status chip.
		expect(fetchMock.mock.calls.filter(([u]) => u === '/api/manager/business-trips').length).toBe(2)
	})
})

describe('manager trip queue filters', () => {
	const mixed = () => [
		trip({ id: 1, displayName: 'Alice Employee', userId: 'alice', status: 'submitted', startsAtUtc: '2026-11-16T07:00:00+00:00', endsAtUtc: '2026-11-18T16:00:00+00:00' }),
		trip({ id: 2, displayName: 'Bob Worker', userId: 'bob', status: 'approved', startsAtUtc: '2026-12-01T07:00:00+00:00', endsAtUtc: '2026-12-03T16:00:00+00:00' }),
		trip({ id: 3, displayName: 'Carla Rej', userId: 'carla', status: 'rejected', startsAtUtc: '2026-11-20T07:00:00+00:00', endsAtUtc: '2026-11-21T16:00:00+00:00' }),
	]
	const fire = (el, type) => el.dispatchEvent(new Event(type, { bubbles: true }))

	it('filters rows by employee name or user id, case-insensitive', async () => {
		stubQueue(mixed())
		await boot()
		expect(dataRows().length).toBe(3)
		const input = document.getElementById('mbt-filter-employee')
		input.value = 'bob'
		fire(input, 'input')
		expect(dataRows().length).toBe(1)
		expect(dataRows()[0].textContent).toContain('Bob Worker')
		input.value = 'CARLA'
		fire(input, 'input')
		expect(dataRows().length).toBe(1)
		input.value = 'alice' // userId match when displayName differs
		fire(input, 'input')
		expect(dataRows().length).toBe(1)
	})

	it('filters rows by workflow status', async () => {
		stubQueue(mixed())
		await boot()
		const sel = document.getElementById('mbt-filter-status')
		sel.value = 'rejected'
		fire(sel, 'change')
		expect(dataRows().length).toBe(1)
		expect(dataRows()[0].textContent).toContain('Rejected')
		sel.value = ''
		fire(sel, 'change')
		expect(dataRows().length).toBe(3)
	})

	it('filters by overlapping date range and treats open bounds correctly', async () => {
		stubQueue(mixed())
		await boot()
		const from = document.getElementById('mbt-filter-date-from')
		const to = document.getElementById('mbt-filter-date-to')
		// Only Carla's trip overlaps 20.–22.11.
		from.value = '20.11.2026'; fire(from, 'input')
		to.value = '22.11.2026'; fire(to, 'input')
		expect(dataRows().length).toBe(1)
		expect(dataRows()[0].textContent).toContain('Carla')
		// From-only: every trip ending on/after 01.12. → only Bob's
		to.value = ''; fire(to, 'input')
		from.value = '01.12.2026'; fire(from, 'input')
		expect(dataRows().length).toBe(1)
		expect(dataRows()[0].textContent).toContain('Bob')
		// Boundary: a trip ending exactly on "from" still overlaps — an
		// exclusive comparison would wrongly drop Alice's row here.
		from.value = '18.11.2026'; fire(from, 'input')
		expect(dataRows().length).toBe(3)
		expect(dataRows().some((r) => r.textContent.includes('Alice'))).toBe(true)
	})

	it('ignores an unparsable date bound, keeps filtering on the valid fields, and shows the no-match state', async () => {
		stubQueue(mixed())
		await boot()
		const from = document.getElementById('mbt-filter-date-from')
		// Invalidity display belongs to the shared datepicker component — the
		// filter itself must not drop rows silently over a half-typed value.
		from.value = '32.99.2026'
		fire(from, 'input')
		expect(dataRows().length).toBe(3)
		// A filter that matches nothing shows the no-match copy, not the empty-team copy.
		document.getElementById('mbt-filter-employee').value = 'nobody'
		fire(document.getElementById('mbt-filter-employee'), 'input')
		expect(dataRows().length).toBe(0)
		expect(document.getElementById('mbt-empty-row').hidden).toBe(false)
		expect(document.getElementById('mbt-empty-title').textContent).toContain('match your filters')
	})

	it('reset clears every control and restores all rows', async () => {
		stubQueue(mixed())
		await boot()
		const from = document.getElementById('mbt-filter-date-from')
		document.getElementById('mbt-filter-employee').value = 'zzz'
		from.value = 'nonsense'
		fire(document.getElementById('mbt-filter-employee'), 'input')
		expect(dataRows().length).toBe(0)
		document.getElementById('mbt-filter-reset').click()
		expect(dataRows().length).toBe(3)
		expect(document.getElementById('mbt-filter-employee').value).toBe('')
		expect(from.value).toBe('')
	})
})
