/**
 * @vitest-environment jsdom
 *
 * Employee trip list: the standard table recipe — real column headers,
 * status chips, and per-status row actions (edit/view link, delete draft,
 * withdraw) wired to the CAS revision of each row.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchMock = vi.fn()
const confirmMock = vi.fn(async () => true)

const IDS = [
	'bt-list-view', 'bt-create-view', 'bt-detail-view', 'bt-list-status',
	'bt-new-trip', 'bt-create-form', 'bt-create-title', 'bt-create-lead',
	'bt-create-submit', 'bt-create-error', 'bt-create-error-text',
	'bt-create-cancel', 'bt-start', 'bt-end', 'bt-origin-tz',
	'bt-destination-tz', 'bt-purpose', 'bt-detail-title', 'bt-detail-subtitle',
	'bt-detail-status', 'bt-detail-status-msg', 'bt-detail-error',
	'bt-detail-error-text', 'bt-conflict', 'bt-conflict-reload',
	'bt-segment-list', 'bt-segment-form', 'bt-seg-start', 'bt-seg-end',
	'bt-seg-kind', 'bt-seg-mode', 'bt-seg-error', 'bt-entry-suggestions',
	'bt-links-status', 'bt-links-step', 'bt-sum-recorded', 'bt-sum-work',
	'bt-sum-topup', 'bt-day-details', 'bt-day-rows', 'bt-warnings',
	'bt-back', 'bt-edit-draft', 'bt-refresh-preview', 'bt-export-csv',
	'bt-export-pdf', 'bt-cancel-trip', 'bt-delete-draft', 'bt-submit',
]

function stageDom() {
	document.body.innerHTML = `
		<div id="bt-root"
			data-enabled="1"
			data-initial-trip-id="0"
			data-list-url="/api/business-trips"
			data-trip-template="/api/business-trips/__ID__"
			data-create-url="/api/business-trips"
			data-update-template="/api/business-trips/__ID__"
			data-delete-template="/api/business-trips/__ID__"
			data-segments-template="/api/business-trips/__ID__/segments"
			data-link-template="/api/business-trips/__ID__/links"
			data-preview-template="/api/business-trips/__ID__/preview"
			data-submit-template="/api/business-trips/__ID__/submit"
			data-cancel-template="/api/business-trips/__ID__/cancel"
			data-export-csv-template="/api/business-trips/__ID__/export.csv"
			data-export-pdf-template="/api/business-trips/__ID__/export.pdf"
			data-time-entries-url="/api/time-entries"
			data-detail-page-template="/business-trips/__ID__"
			data-list-page-url="/business-trips"></div>
		<table><tbody id="bt-trip-list">
			<tr id="bt-empty" hidden><td colspan="4"><div class="azc-empty-state"><h3 class="azc-empty-state__title">No business trips yet.</h3></div></td></tr>
		</tbody></table>
	` + IDS.map((id) => `<div id="${id}"></div>`).join('')
	// Form-submit listeners attach via addEventListener — fine on divs.
}

function trip(over) {
	return {
		id: 7,
		status: 'draft',
		startsAtUtc: '2026-03-10T06:00:00+00:00',
		endsAtUtc: '2026-03-10T18:00:00+00:00',
		originTimezone: 'UTC',
		destinationTimezone: 'UTC',
		evaluationTimezone: 'UTC',
		purpose: null,
		revision: 3,
		...over,
	}
}

function stubList(items) {
	fetchMock.mockImplementation(async (url, options) => {
		const method = (options && options.method) || 'GET'
		if (url === '/api/business-trips' && method === 'GET') {
			return { ok: true, status: 200, data: { success: true, items } }
		}
		return { ok: true, status: 200, data: { success: true } }
	})
}

const actionButtons = (tr) => Array.from(tr.querySelectorAll('.azc-table-actions button'))
const dataRows = () => Array.from(document.querySelectorAll('#bt-trip-list tr')).filter((tr) => tr.id !== 'bt-empty')
const calls = (method, url) =>
	fetchMock.mock.calls.filter(([u, o]) => u === url && ((o && o.method) || 'GET') === method)

beforeEach(() => {
	fetchMock.mockReset()
	confirmMock.mockReset().mockResolvedValue(true)
	stageDom()
	window.t = (app, text) => text
	window.AzcCatalog = { render: () => '<svg></svg>' }
	window.AzcApi = {
		fetch: fetchMock,
		isApiSuccess: (res) => !!(res && res.data && res.data.success === true),
	}
	window.AzcComponents = { confirmDialog: confirmMock }
})

describe('trip list table', () => {
	it('renders the standard empty-state row when there are no trips', async () => {
		stubList([])
		await import('./business-trips.js?empty=1')
		await vi.waitFor(() => expect(document.getElementById('bt-empty').hidden).toBe(false))
		expect(dataRows()).toHaveLength(0)
	})

	it('renders a table row per trip with columns, status chip and actions', async () => {
		stubList([trip({ purpose: 'Client visit' })])
		await import('./business-trips.js?rows=1')
		await vi.waitFor(() => expect(dataRows()).toHaveLength(1))

		const [tr] = dataRows()
		expect(document.getElementById('bt-empty').hidden).toBe(true)
		expect(tr.querySelectorAll('td')).toHaveLength(4)
		const link = tr.querySelector('td a')
		expect(link.getAttribute('href')).toBe('/business-trips/7')
		expect(tr.querySelectorAll('td')[1].textContent).toBe('Client visit')
		const chip = tr.querySelector('.azc-status-chip')
		expect(chip.dataset.status).toBe('draft')
		expect(tr.querySelector('.azc-table-actions').getAttribute('role')).toBe('group')
	})

	it('shows edit + delete actions for drafts, view + withdraw for submitted', async () => {
		stubList([
			trip({ id: 7, status: 'draft' }),
			trip({ id: 8, status: 'submitted' }),
			trip({ id: 9, status: 'cancelled' }),
		])
		await import('./business-trips.js?matrix=1')
		await vi.waitFor(() => expect(dataRows()).toHaveLength(3))

		const [draft, submitted, cancelled] = dataRows()
		expect(draft.querySelectorAll('a').length).toBe(2) // row link + edit icon link
		expect(actionButtons(draft)).toHaveLength(1)
		expect(actionButtons(draft)[0].title).toBe('Delete draft')
		expect(actionButtons(submitted)).toHaveLength(1)
		expect(actionButtons(submitted)[0].title).toBe('Withdraw')
		expect(actionButtons(cancelled)).toHaveLength(0)
	})

	it('deletes a draft after confirmation with the row revision, then reloads', async () => {
		stubList([trip({ revision: 5 })])
		await import('./business-trips.js?del=1')
		await vi.waitFor(() => expect(dataRows()).toHaveLength(1))
		fetchMock.mockClear()

		actionButtons(dataRows()[0])[0].click()
		await vi.waitFor(() => expect(calls('DELETE', '/api/business-trips/7?revision=5')).toHaveLength(1))
		await vi.waitFor(() => expect(calls('GET', '/api/business-trips')).toHaveLength(1))
	})

	it('does not delete when the dialog is dismissed', async () => {
		stubList([trip()])
		confirmMock.mockResolvedValue(false)
		await import('./business-trips.js?del-no=1')
		await vi.waitFor(() => expect(dataRows()).toHaveLength(1))
		fetchMock.mockClear()

		actionButtons(dataRows()[0])[0].click()
		await vi.waitFor(() => expect(confirmMock).toHaveBeenCalled())
		expect(fetchMock.mock.calls.filter(([, o]) => (o && o.method) === 'DELETE')).toHaveLength(0)
	})

	it('withdraws a submitted trip with CAS revision', async () => {
		stubList([trip({ id: 9, status: 'submitted', revision: 4 })])
		await import('./business-trips.js?wd=1')
		await vi.waitFor(() => expect(dataRows()).toHaveLength(1))
		fetchMock.mockClear()

		actionButtons(dataRows()[0])[0].click()
		await vi.waitFor(() => expect(calls('POST', '/api/business-trips/9/cancel')).toHaveLength(1))
		expect(calls('POST', '/api/business-trips/9/cancel')[0][1].json.revision).toBe(4)
		await vi.waitFor(() => expect(calls('GET', '/api/business-trips')).toHaveLength(1))
	})

	it('reloads the list when a stale revision is reported', async () => {
		let deleted = false
		fetchMock.mockImplementation(async (url, options) => {
			const method = (options && options.method) || 'GET'
			if (url === '/api/business-trips' && method === 'GET') {
				return { ok: true, status: 200, data: { success: true, items: deleted ? [] : [trip()] } }
			}
			if (method === 'DELETE') {
				return {
					ok: false, status: 409, error: 'changed',
					data: { success: false, error: 'changed', error_code: 'TRIP_REVISION_STALE', fields: { revision: 'stale' } },
				}
			}
			return { ok: true, status: 200, data: { success: true } }
		})
		await import('./business-trips.js?stale=1')
		await vi.waitFor(() => expect(dataRows()).toHaveLength(1))

		actionButtons(dataRows()[0])[0].click()
		await vi.waitFor(() => expect(calls('GET', '/api/business-trips').length).toBeGreaterThan(1))
		expect(document.getElementById('bt-list-status').textContent).toBe('changed')
	})
})
