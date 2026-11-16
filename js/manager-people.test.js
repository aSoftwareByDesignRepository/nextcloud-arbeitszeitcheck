/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ajax = vi.fn()
let pendingResponses = []

function stageDom() {
	document.body.innerHTML = `
		<section class="manager-scope-page__results" aria-busy="false">
			<p id="manager-people-count"></p>
			<form id="manager-people-filter-form" novalidate>
				<input type="hidden" id="manager-people-employee-filter-id" name="employee_id" value="">
				<input type="text" id="manager-people-employee-filter-search" value="">
				<input type="number" id="manager-people-year" name="year" value="2026">
				<select id="manager-people-sort" name="sort">
					<option value="violations" selected>Open issues first</option>
					<option value="name">Name</option>
				</select>
				<button type="submit" id="manager-people-submit">Show</button>
				<button type="button" id="manager-people-clear" aria-label="Reset"></button>
			</form>
			<div id="manager-people-quick-filters"></div>
			<p id="manager-people-filter-error" hidden></p>
			<div id="manager-people-empty" class="azc-empty-state visually-hidden">
				<h3 class="azc-empty-state__title"></h3>
				<p class="azc-empty-state__text"></p>
			</div>
			<div id="manager-people-table-wrap" class="table-container visually-hidden">
				<table><tbody id="manager-people-body"></tbody></table>
			</div>
			<nav id="manager-people-pager" hidden></nav>
		</section>
	`
}

function stubGlobals() {
	pendingResponses = []
	ajax.mockReset()
	ajax.mockImplementation((url, opts) => {
		const call = { url, opts }
		call.promise = new Promise((resolve) => {
			call.resolve = resolve
		})
		call.promise.finally = (fn) => { call.onFinally = fn; return call.promise }
		pendingResponses.push(call)
		return call.promise
	})
	window.ArbeitszeitCheckUtils = {
		ajax,
		escapeHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'),
		responsiveTd: (label, html) => `<td data-label="${label}">${html}</td>`,
	}
	window.ArbeitszeitCheckMessaging = { showError: vi.fn(), showInfo: vi.fn() }
	window.ArbeitszeitCheck = {
		l10n: {},
		peopleOverview: { apiUrl: '/api/manager/people-overview' },
	}
	window.AzcTablePager = { update: vi.fn(), SIZES: [10, 25, 50, 100] }
}

async function boot() {
	vi.resetModules()
	await import('./manager-people.js')
}

function respond(call, data) {
	call.opts.onSuccess(data)
	if (call.onFinally) call.onFinally()
	call.resolve(data)
}

const EMP = (uid, extra = {}) => ({
	userId: uid,
	displayName: uid,
	vacationRemaining: '10',
	vacationTotal: '30',
	approvedUpcomingDays: '0',
	overtimeHours: '0',
	openViolations: 0,
	criticalViolations: 0,
	pendingAbsences: 0,
	...extra,
})

describe('manager people overview', () => {
	beforeEach(() => {
		stageDom()
		stubGlobals()
	})

	it('renders the employees returned by the initial load', async () => {
		await boot()
		expect(ajax).toHaveBeenCalledTimes(1)
		respond(pendingResponses[0], {
			success: true,
			total: 2,
			employees: [EMP('alice'), EMP('bob')],
		})
		const body = document.getElementById('manager-people-body')
		expect(body.querySelectorAll('tr').length).toBe(2)
		expect(body.textContent).toContain('alice')
		expect(document.getElementById('manager-people-table-wrap').classList.contains('visually-hidden')).toBe(false)
	})

	it('clears stale tbody rows when a reload lands on an empty result', async () => {
		await boot()
		respond(pendingResponses[0], { success: true, total: 2, employees: [EMP('alice'), EMP('bob')] })
		expect(document.getElementById('manager-people-body').children.length).toBe(2)

		// Second load returns zero rows — the empty state must not leave
		// the old rows in the (screen-reader-visible) DOM.
		document.getElementById('manager-people-filter-form').dispatchEvent(new Event('submit', { cancelable: true }))
		expect(ajax).toHaveBeenCalledTimes(2)
		respond(pendingResponses[1], { success: true, total: 0, employees: [] })

		expect(document.getElementById('manager-people-body').children.length).toBe(0)
		expect(document.getElementById('manager-people-empty').classList.contains('visually-hidden')).toBe(false)
	})

	it('queues a load submitted while another fetch is in flight and replays the newest intent', async () => {
		await boot()
		expect(ajax).toHaveBeenCalledTimes(1)
		// While the init fetch is still pending, a filter submit must queue
		// — not fire a second parallel request that can arrive out of order.
		document.getElementById('manager-people-employee-filter-id').value = 'bob'
		document.getElementById('manager-people-filter-form').dispatchEvent(new Event('submit', { cancelable: true }))
		expect(ajax).toHaveBeenCalledTimes(1)

		respond(pendingResponses[0], { success: true, total: 3, employees: [EMP('a'), EMP('b'), EMP('c')] })
		expect(ajax).toHaveBeenCalledTimes(2)
		expect(pendingResponses[1].url).toContain('employeeId=bob')
		respond(pendingResponses[1], { success: true, total: 1, employees: [EMP('bob')] })
		expect(document.getElementById('manager-people-body').textContent).toContain('bob')
	})

	it('pager goTo during a load is queued, not dropped', async () => {
		await boot()
		const nav = document.getElementById('manager-people-pager')
		nav.__goTo(2, 25) // fires while init fetch is in flight
		respond(pendingResponses[0], { success: true, total: 3, employees: [EMP('a'), EMP('b'), EMP('c')] })
		expect(ajax).toHaveBeenCalledTimes(2)
		expect(pendingResponses[1].url).toContain('offset=25')
	})

	it('out-of-page offsets clamp and refetch when the result set shrank', async () => {
		await boot()
		const nav = document.getElementById('manager-people-pager')
		respond(pendingResponses[0], { success: true, total: 60, employees: Array.from({ length: 25 }, (_, i) => EMP('u' + i)) })
		nav.__goTo(3, 25) // offset 50 — valid
		respond(pendingResponses[1], { success: true, total: 10, employees: [] })
		// total 10 but offset 50 → clamp to page 1 and refetch through the queue.
		expect(ajax).toHaveBeenCalledTimes(3)
		expect(pendingResponses[2].url).toContain('offset=0')
	})
})
