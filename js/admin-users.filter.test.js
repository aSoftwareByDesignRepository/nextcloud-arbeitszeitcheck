/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ajax = vi.fn()

function stageDom() {
	document.body.innerHTML = `
		<button type="button" id="btn-filter" aria-expanded="false">Filter</button>
		<section id="filter-section" style="display: none">
		<form id="employee-list-filter-form">
			<input id="user-search" value="" />
			<p id="user-search-error" hidden></p>
			<select id="employee-list-access-filter">
				<option value="app_access">Can open</option>
				<option value="all" selected>All</option>
			</select>
			<select id="employee-list-model-filter">
				<option value="" selected>All models</option>
				<option value="none">Not assigned</option>
				<option value="personal">Personal</option>
				<option value="5">Full time</option>
			</select>
			<select id="employee-list-status-filter">
				<option value="" selected>All</option>
				<option value="enabled">Enabled</option>
				<option value="disabled">Disabled</option>
			</select>
			<button type="submit" id="employee-list-filter-apply">Apply</button>
			<button type="button" id="employee-list-filter-clear">Clear</button>
		</form>
		</section>
		<a id="export-users-csv" href="/apps/arbeitszeitcheck/api/admin/users/export?format=csv" data-export-base="/apps/arbeitszeitcheck/api/admin/users/export?format=csv"></a>
		<div id="users-table-region"></div>
		<div id="admin-users-list-card"></div>
		<p id="users-list-status"></p>
		<table><tbody id="users-tbody"></tbody></table>
		<div id="users-pagination">
			<p id="users-pagination-text"></p>
		</div>
		<nav id="users-pager" hidden></nav>
		<button id="users-page-prev"></button>
		<button id="users-page-next"></button>
		<div id="employee-list-hidden-banner" hidden><p class="admin-users-hidden-banner__text"><span class="admin-users-hidden-banner__prefix"></span></p></div>
		<div id="employee-list-truncated-banner" hidden></div>
	`
}

beforeEach(() => {
	ajax.mockReset()
	stageDom()
	window.ArbeitszeitCheckUtils = {
		$: (sel) => document.querySelector(sel),
		on: (el, ev, fn) => el.addEventListener(ev, fn),
		ajax,
		escapeHtml: (value) => String(value),
		resolveUrl: (path) => path,
	}
	window.ArbeitszeitCheck = {
		l10n: {
			searchMinLength: 'Type at least 2 characters to search.',
			loadingEllipsis: 'Loading…',
			noUsersFound: 'No users found',
			clearSearch: 'Clear search',
		},
		adminUsersConfig: {
			pageSize: 50,
			minSearchLength: 2,
			initialOffset: 0,
			initialTotal: 1,
			initialShown: 1,
			accessFilter: 'all',
			defaultFilter: 'all',
			hiddenCount: 0,
			isAccessRestricted: false,
			truncated: false,
			accessSettingsUrl: '/settings/access',
		},
	}
})

describe('employee list filters', () => {
	it('sends name, access, model, and status only after Apply', async () => {
		await import('./admin-users.js?apply=1')
		expect(document.getElementById('filter-section').style.display).toBe('none')
		document.getElementById('btn-filter').click()
		expect(document.getElementById('filter-section').style.display).toBe('block')
		expect(document.getElementById('btn-filter').getAttribute('aria-expanded')).toBe('true')
		document.getElementById('user-search').value = 'Ada'
		document.getElementById('employee-list-access-filter').value = 'app_access'
		document.getElementById('employee-list-model-filter').value = '5'
		document.getElementById('employee-list-status-filter').value = 'enabled'
		expect(ajax).not.toHaveBeenCalled()

		document.getElementById('employee-list-filter-form').requestSubmit()
		expect(ajax).toHaveBeenCalledTimes(1)
		const url = ajax.mock.calls[0][0]
		expect(url).toContain('search=Ada')
		expect(url).toContain('filter=app_access')
		expect(url).toContain('model=5')
		expect(url).toContain('status=enabled')
		expect(document.getElementById('export-users-csv').getAttribute('href')).toContain('model=5')
		expect(document.getElementById('export-users-csv').getAttribute('href')).toContain('status=enabled')
	})

	it('keeps the table and marks the name field when the search is one character', async () => {
		document.getElementById('users-tbody').innerHTML = '<tr id="existing"><td>Ada</td></tr>'
		await import('./admin-users.js?short=1')
		document.getElementById('user-search').value = 'A'
		document.getElementById('employee-list-filter-form').requestSubmit()
		expect(ajax).not.toHaveBeenCalled()
		expect(document.getElementById('user-search').getAttribute('aria-invalid')).toBe('true')
		expect(document.getElementById('user-search-error').hidden).toBe(false)
		expect(document.getElementById('existing')).not.toBeNull()
	})

	it('ignores a stale list response', async () => {
		await import('./admin-users.js?stale=1')
		document.getElementById('user-search').value = 'Ada'
		document.getElementById('employee-list-filter-form').requestSubmit()
		document.getElementById('user-search').value = 'Bea'
		document.getElementById('employee-list-filter-form').requestSubmit()
		const first = ajax.mock.calls[0][1]
		const second = ajax.mock.calls[1][1]
		first.onSuccess({
			success: true,
			users: [{ userId: 'ada', displayName: 'Ada', email: '', enabled: true, workingTimeModel: null }],
			total: 1,
			filter: 'all',
			model: '',
			status: '',
		})
		expect(document.getElementById('users-tbody').textContent).toContain('Loading')
		second.onSuccess({
			success: true,
			users: [{ userId: 'bea', displayName: 'Bea', email: '', enabled: true, workingTimeModel: null }],
			total: 1,
			filter: 'all',
			model: '',
			status: '',
		})
		expect(document.getElementById('users-tbody').textContent).toContain('Bea')
		expect(document.getElementById('users-tbody').textContent).not.toContain('Ada')
	})

	it('does not repeat "No users found" under an empty table', async () => {
		await import('./admin-users.js?empty=1')
		document.getElementById('employee-list-status-filter').value = 'disabled'
		document.getElementById('employee-list-filter-form').requestSubmit()
		ajax.mock.calls.at(-1)[1].onSuccess({
			success: true,
			users: [],
			total: 0,
			filter: 'all',
			model: '',
			status: 'disabled',
		})
		const copies = [...document.querySelectorAll('p')]
			.map((el) => el.textContent.trim())
			.filter((text) => text === 'No users found')
		expect(copies).toEqual(['No users found'])
		expect(document.getElementById('users-pagination').hidden).toBe(true)
	})

	it('clear restores the default access filter and drops model and status', async () => {
		window.ArbeitszeitCheck.adminUsersConfig.accessFilter = 'app_access'
		window.ArbeitszeitCheck.adminUsersConfig.defaultFilter = 'app_access'
		await import('./admin-users.js?clear=1')
		document.getElementById('user-search').value = 'Ada'
		document.getElementById('employee-list-access-filter').value = 'all'
		document.getElementById('employee-list-model-filter').value = 'none'
		document.getElementById('employee-list-status-filter').value = 'disabled'
		document.getElementById('employee-list-filter-clear').click()
		expect(document.getElementById('user-search').value).toBe('')
		expect(document.getElementById('employee-list-access-filter').value).toBe('app_access')
		expect(document.getElementById('employee-list-model-filter').value).toBe('')
		expect(document.getElementById('employee-list-status-filter').value).toBe('')
		const url = ajax.mock.calls.at(-1)[0]
		expect(url).toContain('filter=app_access')
		expect(url).not.toContain('model=')
		expect(url).not.toContain('status=')
		expect(url).not.toContain('search=')
		expect(document.getElementById('filter-section').style.display).toBe('none')
		expect(document.getElementById('btn-filter').getAttribute('aria-expanded')).toBe('false')
	})
})
