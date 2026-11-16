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
				<input type="hidden" id="manager-absence-record-employee-id" value="">
				<input type="text" id="manager-absence-record-employee-search" value="">
				<select id="manager-absence-record-type">
					<option value="vacation">Vacation</option>
					<option value="sick">Sick leave</option>
				</select>
				<input id="manager-absence-record-start">
				<input id="manager-absence-record-end">
				<div id="manager-absence-record-day-fraction-field" hidden>
					<input type="radio" name="record_day_fraction" id="manager-absence-day-fraction-full" value="1" checked>
					<input type="radio" name="record_day_fraction" id="manager-absence-day-fraction-half" value="0.5">
					<p id="manager-absence-day-fraction-live" class="sr-only"></p>
				</div>
				<div id="manager-absence-record-hours-field" hidden>
					<input id="manager-absence-record-hours" type="number">
				</div>
				<div class="manager-scope-page__reason-field">
					<textarea id="manager-absence-record-reason"></textarea>
				</div>
				<div class="manager-scope-page__record-preview">
					<p id="manager-absence-day-fraction-preview" role="status" aria-live="polite" hidden></p>
					<p id="manager-absence-record-days-consumption" role="status" aria-live="polite" hidden></p>
					<p id="manager-absence-record-hours-preview" role="status" aria-live="polite" hidden></p>
				</div>
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
	window.ArbeitszeitCheckMessaging = { showError: vi.fn(), showInfo: vi.fn(), showSuccess: vi.fn() }
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
		initManagerScopedEmployeePicker: (opts) => {
			const picker = {
				getUserId: () => selectedUid,
				setSelection: (uid) => { selectedUid = uid },
				clear: () => { selectedUid = '' },
			}
			window.__lastEmployeePicker = picker
			window.__lastEmployeePickerOpts = opts || {}
			return picker
		},
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
	window.ArbeitszeitCheckDatepicker = {
		convertEuropeanToISO: (value) => {
			const parts = String(value || '').split('.')
			return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : String(value || '')
		},
	}
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

describe('record approved absence form', () => {
	function fillRecordForm({ uid = 'alice', type = 'vacation', start = '16.02.2026', end = '20.02.2026', reason = '' } = {}) {
		window.__lastEmployeePicker.setSelection(uid)
		document.getElementById('manager-absence-record-type').value = type
		document.getElementById('manager-absence-record-start').value = start
		document.getElementById('manager-absence-record-end').value = end
		document.getElementById('manager-absence-record-reason').value = reason
	}

	async function bootWithInitialLoad() {
		await boot()
		respond(0, OK([], 0))
		await flush()
	}

	it('posts the record payload and shows a design-system success toast', async () => {
		await bootWithInitialLoad()
		fillRecordForm({ reason: 'Approved by HR' })
		document.getElementById('manager-absence-record-form').requestSubmit()
		expect(ajax).toHaveBeenCalledTimes(2)
		const post = pendingResponses[1]
		expect(post.url).toBe('/apps/arbeitszeitcheck/api/manager/employee-absences')
		expect(post.opts.method).toBe('POST')
		expect(post.opts.data.userId).toBe('alice')
		expect(post.opts.data.type).toBe('vacation')
		expect(post.opts.data.startDate).toBe('2026-02-16')
		expect(post.opts.data.endDate).toBe('2026-02-20')
		expect(post.opts.data.reason).toBe('Approved by HR')
		respond(1, { success: true })
		await flush()
		expect(window.ArbeitszeitCheckMessaging.showSuccess).toHaveBeenCalledWith('Absence recorded and approved.')
		expect(document.getElementById('manager-absence-record-submit').disabled).toBe(false)
		expect(document.getElementById('manager-absence-record-reason').value).toBe('')
		// Success triggers a fresh list load.
		expect(ajax).toHaveBeenCalledTimes(3)
		expect(pendingResponses[2].url).toContain('/api/manager/employee-absences?')
	})

	it('clears all record fields back to the initial state after a save', async () => {
		await bootWithInitialLoad()
		fillRecordForm({ type: 'sick', start: '10.02.2026', end: '12.02.2026', reason: 'Notes' })
		document.getElementById('manager-absence-day-fraction-half').checked = true
		document.getElementById('manager-absence-record-form').requestSubmit()
		respond(1, { success: true })
		await flush()
		// Employee picker, hidden field and search box are empty again.
		expect(window.__lastEmployeePicker.getUserId()).toBe('')
		expect(document.getElementById('manager-absence-record-employee-id').value).toBe('')
		expect(document.getElementById('manager-absence-record-employee-search').value).toBe('')
		// Type falls back to the first option, dates to the today default,
		// reason is empty and the day length is back on full day.
		expect(document.getElementById('manager-absence-record-type').selectedIndex).toBe(0)
		expect(document.getElementById('manager-absence-record-start').value).toBe('15.02.2026')
		expect(document.getElementById('manager-absence-record-end').value).toBe('15.02.2026')
		expect(document.getElementById('manager-absence-record-reason').value).toBe('')
		expect(document.getElementById('manager-absence-day-fraction-full').checked).toBe(true)
		expect(document.getElementById('manager-absence-day-fraction-half').checked).toBe(false)
	})

	it('shows the server error message when saving fails', async () => {
		await bootWithInitialLoad()
		fillRecordForm()
		document.getElementById('manager-absence-record-form').requestSubmit()
		respondError(1, { error: 'This calendar month is finalized. Contact an administrator if a correction must be made.' })
		await flush()
		expect(window.ArbeitszeitCheckMessaging.showError).toHaveBeenCalledWith(
			'This calendar month is finalized. Contact an administrator if a correction must be made.'
		)
		expect(document.getElementById('manager-absence-record-submit').disabled).toBe(false)
		expect(window.ArbeitszeitCheckMessaging.showSuccess).not.toHaveBeenCalled()
	})

	it('maps raw HTTP failure text to the translated generic error', async () => {
		await bootWithInitialLoad()
		fillRecordForm()
		document.getElementById('manager-absence-record-form').requestSubmit()
		respondError(1, { error: 'HTTP error! status: 500' })
		await flush()
		expect(window.ArbeitszeitCheckMessaging.showError).toHaveBeenCalledWith('Could not save absence.')
	})

	it('renders the self-target inline notice instead of a toast', async () => {
		await bootWithInitialLoad()
		fillRecordForm()
		const form = document.getElementById('manager-absence-record-form')
		form.requestSubmit()
		respondError(1, { error: 'Record your own absence via Absences.', data: { selfTarget: true, selfServiceArea: 'absences' } })
		await flush()
		expect(form.querySelector('.azc-self-target-notice')).toBeTruthy()
		expect(window.ArbeitszeitCheckMessaging.showError).not.toHaveBeenCalled()
	})

	it('rejects submit without an employee and shows the error via Messaging', async () => {
		await bootWithInitialLoad()
		fillRecordForm()
		window.__lastEmployeePicker.clear()
		document.getElementById('manager-absence-record-form').requestSubmit()
		expect(ajax).toHaveBeenCalledTimes(1)
		expect(window.ArbeitszeitCheckMessaging.showError).toHaveBeenCalledWith('Select an employee')
	})

	it('reveals the day-usage preview below the reason field for a single half-day-capable day', async () => {
		window.ArbeitszeitCheck.halfDayEnabledTypes = ['vacation']
		await bootWithInitialLoad()
		const end = document.getElementById('manager-absence-record-end')
		const preview = document.getElementById('manager-absence-day-fraction-preview')
		// Record dates default to today (FA-RECDATE) — a single day — so the
		// preview is already visible after boot.
		expect(preview.hidden).toBe(false)
		expect(preview.textContent).toContain('vacation day')
		// The preview must sit below the reason textarea in DOM order.
		const reasonField = document.querySelector('.manager-scope-page__reason-field')
		const position = reasonField.compareDocumentPosition(preview)
		expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		// Widening to a multi-day range hides it again…
		end.value = '17.02.2026'
		end.dispatchEvent(new Event('input'))
		expect(preview.hidden).toBe(true)
		// …and shrinking back to one day reveals it once more.
		end.value = '15.02.2026'
		end.dispatchEvent(new Event('input'))
		expect(preview.hidden).toBe(false)
	})

	it('reads the employee id from the picker hidden field for the server estimate', async () => {
		window.ArbeitszeitCheck.vacationUnit = 'hours'
		window.ArbeitszeitCheck.estimateEmployeeVacationHoursUrl = '/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-hours'
		window.fetch = vi.fn().mockResolvedValue({ json: async () => ({ success: true, hours: 16 }) })
		await bootWithInitialLoad()
		document.getElementById('manager-absence-record-employee-id').value = 'alice'
		const start = document.getElementById('manager-absence-record-start')
		const end = document.getElementById('manager-absence-record-end')
		start.value = '16.02.2026'
		end.value = '17.02.2026'
		start.dispatchEvent(new Event('input'))
		end.dispatchEvent(new Event('input'))
		await new Promise((resolve) => setTimeout(resolve, 300))
		expect(window.fetch).toHaveBeenCalled()
		expect(String(window.fetch.mock.calls[0][0])).toContain('userId=alice')
	})

	describe('vacation-days consumption badge', () => {
		const consumption = () => document.getElementById('manager-absence-record-days-consumption')
		const setRange = (start, end) => {
			const startEl = document.getElementById('manager-absence-record-start')
			const endEl = document.getElementById('manager-absence-record-end')
			startEl.value = start
			endEl.value = end
			endEl.dispatchEvent(new Event('input'))
		}
		const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

		it('shows the local Mon–Fri count for a multi-day vacation range', async () => {
			await bootWithInitialLoad()
			// Today is Sunday 15.02.2026 — a single-day vacation consumes 0
			// weekdays, so the badge is already up with an honest 0.
			expect(consumption().hidden).toBe(false)
			expect(consumption().textContent).toContain('uses 0 vacation days')
			// 16.02–20.02.2026 is Mon–Fri → 5 weekdays.
			setRange('16.02.2026', '20.02.2026')
			expect(consumption().hidden).toBe(false)
			expect(consumption().textContent).toContain('uses 5 vacation days')
			expect(consumption().textContent).not.toContain('(Mon–Fri)')
		})

		it('updates the count when the range changes instead of disappearing', async () => {
			await bootWithInitialLoad()
			setRange('16.02.2026', '20.02.2026')
			expect(consumption().textContent).toContain('uses 5 vacation days')
			// Extending to the following Monday adds a weekday — badge stays visible.
			setRange('16.02.2026', '23.02.2026')
			expect(consumption().hidden).toBe(false)
			expect(consumption().textContent).toContain('uses 6 vacation days')
		})

		it('refines the count via the manager estimate endpoint once an employee is picked', async () => {
			window.ArbeitszeitCheck.estimateEmployeeAbsenceDaysUrl = '/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-days'
			window.fetch = vi.fn().mockResolvedValue({
				json: async () => ({ success: true, days: 4, basis: 'weekday_schedule' }),
			})
			await bootWithInitialLoad()
			window.__lastEmployeePicker.setSelection('alice')
			setRange('16.02.2026', '20.02.2026')
			await sleep(300)
			expect(window.fetch).toHaveBeenCalledTimes(1)
			const url = String(window.fetch.mock.calls[0][0])
			expect(url).toContain('userId=alice')
			expect(url).toContain('startDate=2026-02-16')
			expect(url).toContain('endDate=2026-02-20')
			await flush()
			expect(consumption().textContent).toContain('uses 4 vacation days')
			expect(consumption().textContent).toContain('(per work model)')
		})

		it('keeps the local count when the estimate request fails', async () => {
			window.ArbeitszeitCheck.estimateEmployeeAbsenceDaysUrl = '/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-days'
			window.fetch = vi.fn().mockRejectedValue(new Error('network'))
			await bootWithInitialLoad()
			window.__lastEmployeePicker.setSelection('alice')
			setRange('16.02.2026', '20.02.2026')
			await sleep(300)
			await flush()
			expect(consumption().hidden).toBe(false)
			expect(consumption().textContent).toContain('uses 5 vacation days')
		})

		it('ignores a stale estimate response after the range changes again', async () => {
			window.ArbeitszeitCheck.estimateEmployeeAbsenceDaysUrl = '/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-days'
			const resolvers = []
			window.fetch = vi.fn().mockImplementation(() => new Promise((res) => resolvers.push(res)))
			await bootWithInitialLoad()
			window.__lastEmployeePicker.setSelection('alice')
			setRange('16.02.2026', '20.02.2026')
			await sleep(300)
			setRange('16.02.2026', '23.02.2026')
			await sleep(300)
			expect(resolvers.length).toBe(2)
			// Newest response wins …
			resolvers[1]({ json: async () => ({ success: true, days: 6, basis: 'weekday_schedule' }) })
			await flush()
			expect(consumption().textContent).toContain('uses 6 vacation days')
			// … and the stale earlier response cannot overwrite it.
			resolvers[0]({ json: async () => ({ success: true, days: 4, basis: 'weekday_schedule' }) })
			await flush()
			expect(consumption().textContent).toContain('uses 6 vacation days')
		})

		it('re-estimates when the selected employee changes', async () => {
			window.ArbeitszeitCheck.estimateEmployeeAbsenceDaysUrl = '/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-days'
			window.fetch = vi.fn().mockResolvedValue({
				json: async () => ({ success: true, days: 3, basis: 'weekday_schedule' }),
			})
			await bootWithInitialLoad()
			setRange('16.02.2026', '20.02.2026')
			window.__lastEmployeePicker.setSelection('bob')
			window.__lastEmployeePickerOpts.onChange('bob', {})
			await sleep(300)
			expect(String(window.fetch.mock.calls[0][0])).toContain('userId=bob')
		})

		it('hides for non-vacation types, invalid and reversed ranges', async () => {
			await bootWithInitialLoad()
			setRange('16.02.2026', '20.02.2026')
			expect(consumption().hidden).toBe(false)
			document.getElementById('manager-absence-record-type').value = 'sick'
			document.getElementById('manager-absence-record-type').dispatchEvent(new Event('change'))
			expect(consumption().hidden).toBe(true)
			document.getElementById('manager-absence-record-type').value = 'vacation'
			document.getElementById('manager-absence-record-type').dispatchEvent(new Event('change'))
			setRange('20.02.2026', '16.02.2026')
			expect(consumption().hidden).toBe(true)
		})

		it('yields to the day-fraction preview on a single day', async () => {
			window.ArbeitszeitCheck.halfDayEnabledTypes = ['vacation']
			await bootWithInitialLoad()
			setRange('16.02.2026', '16.02.2026')
			expect(consumption().hidden).toBe(true)
		})

		it('covers the single day itself with singular grammar when no fraction control exists', async () => {
			// halfDayEnabledTypes is unset → the fraction preview never shows,
			// so the consumption badge owns single-day vacation counts.
			await bootWithInitialLoad()
			setRange('16.02.2026', '16.02.2026')
			expect(consumption().hidden).toBe(false)
			expect(consumption().textContent).toContain('uses 1 vacation day')
			expect(consumption().textContent).not.toContain('vacation days')
		})

		it('shows an honest 0 count for a weekend-only range and still refines via the server', async () => {
			window.ArbeitszeitCheck.estimateEmployeeAbsenceDaysUrl = '/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-days'
			window.fetch = vi.fn().mockResolvedValue({
				json: async () => ({ success: true, days: 2, basis: 'weekday_schedule' }),
			})
			await bootWithInitialLoad()
			// 21.02–22.02.2026 is Sat–Sun → 0 Mon–Fri days locally, but a
			// weekend-scheduled employee may still consume days — fetch anyway.
			window.__lastEmployeePicker.setSelection('alice')
			setRange('21.02.2026', '22.02.2026')
			expect(consumption().hidden).toBe(false)
			expect(consumption().textContent).toContain('uses 0 vacation days')
			await sleep(300)
			expect(window.fetch).toHaveBeenCalled()
			await flush()
			expect(consumption().textContent).toContain('uses 2 vacation days')
		})

		it('formats fractional counts with the app locale decimal separator', async () => {
			window.ArbeitszeitCheck.dateLocale = 'de-DE'
			window.ArbeitszeitCheck.estimateEmployeeAbsenceDaysUrl = '/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-days'
			window.fetch = vi.fn().mockResolvedValue({
				json: async () => ({ success: true, days: 2.5, basis: 'weekday_schedule' }),
			})
			await bootWithInitialLoad()
			window.__lastEmployeePicker.setSelection('alice')
			setRange('16.02.2026', '20.02.2026')
			await sleep(300)
			await flush()
			expect(consumption().textContent).toContain('uses 2,5 vacation days')
		})

		it('does not re-autofill cleared fields when the reset clears the picker', async () => {
			window.ArbeitszeitCheck.vacationUnit = 'hours'
			await bootWithInitialLoad()
			const initialHours = document.getElementById('manager-absence-record-hours').value
			window.__lastEmployeePicker.setSelection('alice')
			setRange('16.02.2026', '20.02.2026')
			document.getElementById('manager-absence-record-form').requestSubmit()
			respond(1, { success: true })
			await flush()
			// picker.clear() inside the reset fires onChange — the hook must stay
			// inert during reset or it could blend a stale estimate into the
			// freshly reset autofill value.
			await sleep(300)
			expect(document.getElementById('manager-absence-record-hours').value).toBe(initialHours)
		})

		it('does not double-badge in hours orgs', async () => {
			window.ArbeitszeitCheck.vacationUnit = 'hours'
			await bootWithInitialLoad()
			setRange('16.02.2026', '20.02.2026')
			expect(consumption().hidden).toBe(true)
			expect(document.getElementById('manager-absence-record-hours-field').hidden).toBe(false)
		})
	})
})
