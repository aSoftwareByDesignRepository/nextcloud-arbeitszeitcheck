/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ajax = vi.fn()
const pagerUpdate = vi.fn()
let pendingResponses = []

function european(date) {
	return `${String(date.getDate()).padStart(2, '0')}.${String(date.getMonth() + 1).padStart(2, '0')}.${date.getFullYear()}`
}

function stageDom() {
	document.body.innerHTML = `
		<section class="manager-scope-page__results" aria-busy="false">
			<p id="employee-absences-count" role="status"></p>
			<form id="employee-absences-filter-form" novalidate aria-describedby="employee-absences-filter-error">
				<input type="hidden" id="employee-absences-employee-filter-id" name="employee_id" value="">
				<input type="text" id="employee-absences-employee-filter-search" value="">
				<select id="employee-absences-type-filter" name="type">
					<option value="" selected>All types</option>
					<option value="vacation">Vacation</option>
				</select>
				<select id="employee-absences-status-filter" name="status">
					<option value="" selected>All statuses</option>
					<option value="approved">Approved</option>
				</select>
				<input id="employee-absences-start-date-filter" name="start_date" required>
				<input id="employee-absences-end-date-filter" name="end_date" required>
				<button type="submit" id="employee-absences-submit">Show</button>
				<button type="button" id="employee-absences-clear" aria-label="Reset"></button>
			</form>
			<p id="employee-absences-filter-error" hidden><span class="azc-callout__text"></span></p>
			<div id="employee-absences-empty" class="azc-empty-state">
				<h3 class="azc-empty-state__title">Select filters first</h3>
				<p class="azc-empty-state__text">Choose a date range to load absences.</p>
			</div>
			<div id="employee-absences-table-wrap" class="table-container visually-hidden">
				<table id="employee-absences-table"><tbody id="employee-absences-body"></tbody></table>
			</div>
			<div class="azc-table-pager" id="employee-absences-pager" data-mode="remote" data-per-page="25" hidden></div>
			<form id="manager-absence-record-form" novalidate>
				<input type="hidden" id="manager-absence-record-employee" value="">
				<input id="manager-absence-record-start">
				<input id="manager-absence-record-end">
				<button type="submit" id="manager-absence-record-submit">Save</button>
			</form>
		</section>
	`
}

function stubGlobals() {
	pendingResponses = []
	ajax.mockImplementation((url, opts) => {
		const call = { url, opts }
		call.promise = new Promise((resolve) => {
			call.resolve = resolve
		})
		pendingResponses.push(call)
		return call.promise
	})
	pagerUpdate.mockReset()
	window.ArbeitszeitCheckUtils = {
		ajax,
		formatDate: (d) => european(d),
		buildAppUrl: (path) => path,
		responsiveTd: (label, html, cls = '') => `<td${cls ? ` class="${cls}"` : ''}>${html}</td>`,
		badgeVariantForAbsenceStatus: () => 'secondary',
		renderBadgeHtml: (label, variant) => `<span class="badge badge--${variant}">${label}</span>`,
	}
	window.ArbeitszeitCheckMessaging = { showError: vi.fn(), showInfo: vi.fn() }
	window.ArbeitszeitCheckTime = {
		todayYmd: () => '2026-02-15',
		parseYmd: (s) => {
			const [y, m, d] = String(s).split('-').map(Number)
			return new Date(y, m - 1, d)
		},
		formatDate: (d) => european(d),
	}
	let selectedUid = ''
	window.ArbeitszeitCheck = {
		dateLocale: 'de-DE',
		vacationUnit: 'days',
		maxManagerListDateRangeDays: 365,
		l10n: {},
		initManagerScopedEmployeePicker: () => ({
			getUserId: () => selectedUid,
			setSelection: (uid) => { selectedUid = uid },
			clear: () => { selectedUid = '' },
		}),
		validateManagerFilterEmployeeSelection: (picker, searchSelector, hiddenSelector) => {
			const query = document.querySelector(searchSelector)?.value.trim() || ''
			const uid = picker?.getUserId?.() || document.querySelector(hiddenSelector)?.value.trim() || ''
			if (query !== '' && uid === '') {
				return { valid: false, message: 'Select an employee from the list', focusId: 'employee-absences-employee-filter-search' }
			}
			return { valid: true }
		},
	}
	window.AzcTablePager = { SIZES: [10, 25, 50, 100], update: pagerUpdate }
	window.AzcCatalog = { render: () => '<svg></svg>' }
}

function respond(index, data) {
	const call = pendingResponses[index]
	if (!call) {
		throw new Error(`no pending ajax call at index ${index}`)
	}
	call.opts.onSuccess(data)
	call.resolve()
}

function respondError(index, error) {
	const call = pendingResponses[index]
	if (!call) {
		throw new Error(`no pending ajax call at index ${index}`)
	}
	call.opts.onError(error)
	call.resolve()
}

async function flush() {
	await new Promise((resolve) => setTimeout(resolve, 0))
}

async function boot() {
	await import('./manager-absences.js')
	await flush()
}

const OK = (entries, total, employees = []) => ({
	success: true,
	requiresFilters: false,
	entries,
	total,
	employees,
	filters: {},
})

beforeEach(() => {
	vi.resetModules()
	stageDom()
	stubGlobals()
	window.history.replaceState({}, '', '/apps/arbeitszeitcheck/manager/absences')
})

describe('manager absences auto-load', () => {
	it('loads the prefilled last-month range on init without a Show click', async () => {
		await boot()
		expect(ajax).toHaveBeenCalledTimes(1)
		const url = pendingResponses[0].url
		expect(url).toContain('/api/manager/employee-absences?')
		expect(url).toContain('startDate=2026-01-15')
		expect(url).toContain('endDate=2026-02-15')
		expect(url).toContain('limit=25')
		expect(url).toContain('offset=0')
	})

	it('renders rows, count badge and remote pager state after the auto-load', async () => {
		await boot()
		respond(0, OK([
			{
				id: 7, userId: 'alice', displayName: 'Alice A', type: 'vacation', typeLabel: 'Vacation',
				startDate: '2026-02-01', endDate: '2026-02-03', days: 3, status: 'approved',
				statusLabel: 'Approved', reason: 'Skiing', updatedAt: '2026-02-01T10:00:00Z',
			},
		], 1))
		await flush()
		const rows = document.querySelectorAll('#employee-absences-body tr')
		expect(rows.length).toBe(1)
		expect(rows[0].innerHTML).toContain('Alice A')
		expect(document.getElementById('employee-absences-table-wrap').classList.contains('visually-hidden')).toBe(false)
		expect(document.getElementById('employee-absences-count').textContent).toContain('entr')
		expect(pagerUpdate).toHaveBeenCalledWith(
			document.getElementById('employee-absences-pager'),
			{ page: 1, pages: 1, total: 1, perPage: 25 },
		)
	})

	it('shows the no-results empty state when the default range has no entries', async () => {
		await boot()
		respond(0, OK([], 0))
		await flush()
		const emptyEl = document.getElementById('employee-absences-empty')
		expect(emptyEl.classList.contains('visually-hidden')).toBe(false)
		expect(emptyEl.querySelector('.azc-empty-state__title').textContent).toContain('No matching absences')
		expect(document.getElementById('employee-absences-pager').hidden).toBe(true)
	})
})

describe('manager absences load queue', () => {
	it('queues a submitted filter change while a load is in flight', async () => {
		await boot()
		expect(ajax).toHaveBeenCalledTimes(1)
		document.getElementById('employee-absences-type-filter').value = 'vacation'
		document.getElementById('employee-absences-filter-form').requestSubmit()
		expect(ajax).toHaveBeenCalledTimes(1)
		respond(0, OK([], 0))
		await flush()
		expect(ajax).toHaveBeenCalledTimes(2)
		const url = pendingResponses[1].url
		expect(url).toContain('type=vacation')
		expect(url).toContain('offset=0')
	})

	it('does not queue a reload when the submitted filters are invalid', async () => {
		await boot()
		document.getElementById('employee-absences-start-date-filter').value = 'garbage'
		document.getElementById('employee-absences-filter-form').requestSubmit()
		expect(document.getElementById('employee-absences-filter-error').hidden).toBe(false)
		respond(0, OK([], 0))
		await flush()
		expect(ajax).toHaveBeenCalledTimes(1)
	})

	it('keeps only the newest queued intent', async () => {
		await boot()
		const typeSel = document.getElementById('employee-absences-type-filter')
		const statusSel = document.getElementById('employee-absences-status-filter')
		typeSel.value = 'vacation'
		document.getElementById('employee-absences-filter-form').requestSubmit()
		typeSel.value = ''
		statusSel.value = 'approved'
		document.getElementById('employee-absences-filter-form').requestSubmit()
		respond(0, OK([], 0))
		await flush()
		expect(ajax).toHaveBeenCalledTimes(2)
		const url = pendingResponses[1].url
		expect(url).toContain('status=approved')
		expect(url).not.toContain('type=vacation')
	})
})

describe('manager absences remote pager', () => {
	it('restores page and size from ab_page/ab_per_page URL params before the auto-load', async () => {
		window.history.replaceState({}, '', '/apps/arbeitszeitcheck/manager/absences?ab_page=3&ab_per_page=50')
		await boot()
		expect(ajax).toHaveBeenCalledTimes(1)
		const url = pendingResponses[0].url
		expect(url).toContain('limit=50')
		expect(url).toContain('offset=100')
	})

	it('clamps to the last page when the requested offset is past the total', async () => {
		window.history.replaceState({}, '', '/apps/arbeitszeitcheck/manager/absences?ab_page=99&ab_per_page=10')
		await boot()
		expect(pendingResponses[0].url).toContain('offset=980')
		respond(0, OK([], 30))
		await flush()
		expect(ajax).toHaveBeenCalledTimes(2)
		expect(pendingResponses[1].url).toContain('offset=20')
	})

	it('delegates __goTo page changes to limit/offset fetches', async () => {
		await boot()
		respond(0, OK([], 60))
		await flush()
		const nav = document.getElementById('employee-absences-pager')
		nav.__goTo(2, null)
		await flush()
		expect(ajax).toHaveBeenCalledTimes(2)
		expect(pendingResponses[1].url).toContain('offset=25')
		respond(1, OK([], 60))
		await flush()
		nav.__goTo(1, 50)
		await flush()
		expect(ajax).toHaveBeenCalledTimes(3)
		expect(pendingResponses[2].url).toContain('limit=50')
		expect(pendingResponses[2].url).toContain('offset=0')
	})
})

describe('manager absences reset', () => {
	it('restores the default range and reloads instead of clearing to an empty state', async () => {
		await boot()
		respond(0, OK([], 0))
		await flush()
		document.getElementById('employee-absences-start-date-filter').value = '01.01.2020'
		document.getElementById('employee-absences-end-date-filter').value = '31.01.2020'
		document.getElementById('employee-absences-status-filter').value = 'approved'
		document.getElementById('employee-absences-clear').click()
		await flush()
		expect(document.getElementById('employee-absences-start-date-filter').value).toBe('15.01.2026')
		expect(document.getElementById('employee-absences-end-date-filter').value).toBe('15.02.2026')
		expect(document.getElementById('employee-absences-status-filter').value).toBe('')
		expect(ajax).toHaveBeenCalledTimes(2)
		const url = pendingResponses[1].url
		expect(url).toContain('startDate=2026-01-15')
		expect(url).toContain('endDate=2026-02-15')
		expect(url).toContain('offset=0')
	})

	it('does not reset while a load is in flight', async () => {
		await boot()
		const startEl = document.getElementById('employee-absences-start-date-filter')
		const before = startEl.value
		document.getElementById('employee-absences-clear').click()
		expect(startEl.value).toBe(before)
		expect(ajax).toHaveBeenCalledTimes(1)
	})
})

describe('manager absences error handling', () => {
	it('surfaces an API error and clears the count badge', async () => {
		await boot()
		respondError(0, { status: 500, error: 'boom' })
		await flush()
		expect(document.getElementById('employee-absences-filter-error').hidden).toBe(false)
		expect(document.getElementById('employee-absences-filter-error').textContent).toContain('boom')
		expect(window.ArbeitszeitCheckMessaging.showError).toHaveBeenCalled()
	})
})
