/**
 * @vitest-environment jsdom
 *
 * Contract + behaviour coverage for the special-entitlements admin page.
 * The IIFE binds on load, so every test stages the production template shape
 * (same ids, same data-* endpoints) and then drives the module through real
 * DOM events — no internals are stubbed.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const T = () => window.__ArbeitszeitCheckSeAdminTestables

const TYPE_A = {
	id: 7,
	code: 'se_sonderurlaub',
	publicLabel: 'Betriebsjubiläum',
	confidentialLabel: '§616 BGB',
	unit: 'days',
	periodType: 'calendar_year',
	active: true,
	version: 3,
	policies: [
		{ id: 21, scopeType: 'org', scopeId: 'org', scopeLabel: null, operation: 'add', amount: '10.00', priority: 0, effectiveFrom: '2026-01-01', effectiveTo: null, version: 1 },
		{ id: 22, scopeType: 'team', scopeId: '42', scopeLabel: 'Nachtschicht', operation: 'add', amount: '2.00', priority: 5, effectiveFrom: '2026-01-01', effectiveTo: null, version: 1 },
	],
}
const TYPE_B = {
	id: 9,
	code: 'se_pferd',
	publicLabel: 'Pferdeführerschein',
	confidentialLabel: null,
	unit: 'hours',
	periodType: 'one_time',
	active: false,
	version: 1,
	policies: [
		{ id: 30, scopeType: 'user', scopeId: 'u9', scopeLabel: 'Max Muster', operation: 'disable', amount: null, priority: 0, effectiveFrom: '2026-01-01', effectiveTo: null, version: 1 },
	],
}

function jsonResponse(body, status = 200) {
	return { ok: status >= 200 && status < 300, status, json: async () => body }
}

function stageDom({ enabled = '1' } = {}) {
	document.body.innerHTML = `
<div id="se-admin-root" data-enabled="${enabled}"
	data-types-url="/api/types"
	data-type-create-url="/api/types"
	data-type-show-template="/api/types/__ID__"
	data-type-update-template="/api/types/__ID__"
	data-type-active-template="/api/types/__ID__/active"
	data-policies-template="/api/types/__ID__/policies"
	data-policy-create-url="/api/policies"
	data-policy-update-template="/api/policies/__ID__"
	data-preview-url="/api/preview"
	data-trace-template="/api/types/__ID__/trace"
	data-cohorts-url="/api/cohorts"
	data-cohort-create-url="/api/cohorts"
	data-cohort-members-template="/api/cohorts/__ID__/members"
	data-cohort-membership-template="/api/memberships/__ID__"
	data-users-url="/api/users"
	data-teams-url="/api/teams"
	data-models-url="/api/models"
	data-enable-url="/api/enable">
	<section id="se-editor" hidden>
		<h2 id="se-editor-title"></h2>
		<div id="se-form-error" hidden><p id="se-form-error-text"></p></div>
		<form id="se-create-form">
			<input type="hidden" id="se-type-version" name="version">
			<input id="se-label" name="publicLabel" required maxlength="80">
			<p id="se-label-error" class="azc-field-error" hidden></p>
			<input id="se-amount" name="amount" type="number" required min="0.25" max="4000" step="0.25">
			<p id="se-amount-error" class="azc-field-error" hidden></p>
			<select id="se-unit" name="unit"><option value="days">days</option><option value="hours">hours</option></select>
			<p id="se-unit-error" class="azc-field-error" hidden></p>
			<select id="se-scope" name="scopeType" required>
				<option value="org">org</option><option value="policy_group">g</option>
				<option value="model">m</option><option value="team">t</option><option value="user">u</option>
			</select>
			<p id="se-scopeType-error" class="azc-field-error" hidden></p>
			<div id="se-target-group" hidden>
				<select id="se-target" name="scopeId"></select>
				<p id="se-target-empty"></p>
				<div id="se-user-wrap" hidden>
					<input type="hidden" id="se-user-id"><input id="se-user-search">
					<div id="se-user-list" hidden></div><p id="se-user-status"></p>
				</div>
				<p id="se-scopeId-error" class="azc-field-error" hidden></p>
			</div>
			<div id="se-operation-group" hidden>
				<input type="radio" name="operation" value="add" checked>
				<input type="radio" name="operation" value="replace">
				<input type="radio" name="operation" value="disable">
				<p id="se-operation-error" class="azc-field-error" hidden></p>
			</div>
			<input id="se-from" name="effectiveFrom" type="date" required>
			<p id="se-effectiveFrom-error" class="azc-field-error" hidden></p>
			<select id="se-period" name="periodType">
				<option value="calendar_year">cy</option><option value="service_year">sy</option><option value="one_time">ot</option>
			</select>
			<p id="se-periodType-error" class="azc-field-error" hidden></p>
			<input id="se-confidential" name="confidentialLabel" maxlength="80">
			<p id="se-confidentialLabel-error" class="azc-field-error" hidden></p>
			<input id="se-absence-type" name="absenceTypeCode" maxlength="64">
			<p id="se-absenceTypeCode-error" class="azc-field-error" hidden></p>
			<input id="se-priority" name="priority" type="number" min="-1000" max="1000" value="0">
			<p id="se-priority-error" class="azc-field-error" hidden></p>
			<input id="se-to" name="effectiveTo" type="date">
			<p id="se-effectiveTo-error" class="azc-field-error" hidden></p>
			<input id="se-reason" name="reason" maxlength="500">
			<p id="se-reason-error" class="azc-field-error" hidden></p>
			<p id="se-preview-text"></p>
			<p id="se-preview-warn" hidden></p>
			<button id="se-activate" type="submit"></button>
			<button id="se-cancel" type="button"></button>
		</form>
	</section>
	<section id="se-detail" hidden>
		<h2 id="se-detail-title"></h2><p id="se-detail-meta"></p>
		<button id="se-detail-add-policy" type="button"></button>
		<button id="se-detail-close" type="button"></button>
		<div id="se-conflict" hidden><p id="se-conflict-text"></p><button id="se-conflict-reload" type="button"></button></div>
		<p id="se-policy-status"></p>
		<div id="se-policy-groups"></div>
		<input id="se-trace-user"><input id="se-trace-date" type="date">
		<button id="se-trace-run" type="button"></button><pre id="se-trace-out"></pre>
	</section>
	<section>
		<button id="se-new-type" type="button"></button>
		<input type="search" id="se-search">
		<select id="se-filter-active">
			<option value="all">all</option><option value="active" selected>active</option><option value="inactive">inactive</option>
		</select>
		<p id="se-list-status"></p>
		<table id="se-type-list"><tbody id="se-type-tbody"></tbody></table>
	</section>
	<section>
		<form id="se-cohort-form">
			<input id="se-cohort-label" name="label" required maxlength="80">
			<p id="se-cohort-label-error" class="azc-field-error" hidden></p>
			<input id="se-cohort-confidential" name="confidentialLabel" maxlength="80">
			<button type="submit"></button>
		</form>
		<p id="se-cohort-status"></p>
		<ul id="se-cohort-list"></ul>
	</section>
</div>`
}

let fetchMock

function routeFetch(routes = {}) {
	// Longest prefix wins so '/api/types/7/active' is not swallowed by '/api/types'.
	const keys = Object.keys(routes).sort((a, b) => b.length - a.length)
	fetchMock.mockImplementation((url, opts = {}) => {
		const u = String(url)
		const key = keys.find((k) => u === k || u.startsWith(k))
		if (key) {
			const hit = [key, routes[key]]
			const v = typeof hit[1] === 'function' ? hit[1](u, opts) : hit[1]
			return Promise.resolve(jsonResponse(v))
		}
		return Promise.resolve(jsonResponse({ success: true }))
	})
}

async function flush(rounds = 6) {
	for (let i = 0; i < rounds; i++) {
		await new Promise((r) => setTimeout(r, 0))
	}
}

function showAllRows() {
	const filter = document.getElementById('se-filter-active')
	filter.value = 'all'
	filter.dispatchEvent(new Event('change'))
}

beforeEach(async () => {
	vi.resetModules()
	document.head.dataset.requesttoken = 'test-token'
	stageDom()
	fetchMock = vi.fn()
	vi.stubGlobal('fetch', fetchMock)
	routeFetch({
		'/api/types': { success: true, items: [] },
		'/api/cohorts': { success: true, items: [] },
		'/api/preview': { success: true, recipientCount: 0, summary: { amount: 0 }, warnings: [], samples: [] },
	})
	window.ArbeitszeitCheck = {
		initAdminUserPicker: () => ({ destroy: vi.fn(), clear: vi.fn() }),
	}
	window.ArbeitszeitCheckTime = {
		ymd: (d) => d.toISOString().slice(0, 10),
		todayYmd: () => '2026-11-05',
	}
	window.AzcCatalog = { render: (name) => `<svg data-icon="${name}"></svg>` }
	window.Element.prototype.scrollIntoView = window.Element.prototype.scrollIntoView || vi.fn()
	await import('./admin-special-entitlements.js')
	await flush()
})

describe('types table contract', () => {
	it('renders rows with data-label cells, badges and an actions group', async () => {
		routeFetch({ '/api/types': { success: true, items: [TYPE_A, TYPE_B] } })
		await T().loadTypes()
		await flush()
		showAllRows()

		const rows = document.querySelectorAll('#se-type-tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].dataset.typeId).toBe('7')

		const cells = rows[0].querySelectorAll('td')
		expect(cells).toHaveLength(7)
		expect(Array.from(cells).map((td) => td.getAttribute('data-label'))).toEqual([
			'Name', 'Amount', 'For whom?', 'Unit', 'Period', 'Status', 'Actions',
		])
		expect(cells[0].querySelector('.se-type-name').textContent).toBe('Betriebsjubiläum')
		expect(cells[0].querySelector('.se-type-code').textContent).toBe('se_sonderurlaub')
		expect(cells[0].querySelector('.se-type-confidential').textContent).toContain('§616 BGB')
		// One line per rule, aligned across the amount and scope cells.
		expect(Array.from(cells[1].querySelectorAll('.se-type-rule')).map((s) => s.textContent)).toEqual([
			'10 days', '2 days',
		])
		expect(Array.from(cells[2].querySelectorAll('.se-type-rule')).map((s) => s.textContent)).toEqual([
			'Everyone', 'Team: Nachtschicht',
		])
		expect(cells[3].textContent).toBe('Days')
		expect(cells[5].querySelector('.badge--success')).toBeTruthy()
		// Inactive row: neutral badge; a disable rule reads "Disabled".
		expect(rows[1].querySelector('.badge--neutral')).toBeTruthy()
		expect(rows[1].querySelector('td[data-label="Amount"]').textContent).toBe('Disabled')
		expect(rows[1].querySelector('td[data-label="For whom?"]').textContent).toBe('Single person: Max Muster')

		const actions = cells[6]
		expect(actions.classList.contains('actions-cell')).toBe(true)
		const group = actions.querySelector('.azc-table-actions')
		expect(group.getAttribute('role')).toBe('group')
		expect(group.getAttribute('aria-label')).toBe('Actions: Betriebsjubiläum')
		const btns = group.querySelectorAll('button.azc-icon-btn')
		expect(btns).toHaveLength(2)
		expect(btns[0].getAttribute('aria-label')).toBe('Manage rules: Betriebsjubiläum')
		expect(btns[1].getAttribute('aria-label')).toBe('Deactivate: Betriebsjubiläum')
		expect(btns[0].querySelector('svg')).toBeTruthy()
	})

	it('escapes admin-entered text (no markup injection)', async () => {
		const evil = { ...TYPE_A, publicLabel: '<img src=x onerror=alert(1)>', confidentialLabel: '<b>x</b>' }
		routeFetch({ '/api/types': { success: true, items: [evil] } })
		await T().loadTypes()
		await flush()

		const td = document.querySelector('#se-type-tbody td[data-label="Name"]')
		expect(td.querySelector('img')).toBeNull()
		expect(td.querySelector('b')).toBeNull()
		expect(td.querySelector('.se-type-name').textContent).toBe('<img src=x onerror=alert(1)>')
	})

	it('keeps the empty state inside the table frame', async () => {
		await T().loadTypes()
		await flush()

		const td = document.querySelector('#se-type-tbody td.azc-table-empty')
		expect(td).toBeTruthy()
		expect(td.getAttribute('colspan')).toBe('7')
		expect(td.querySelector('.azc-empty-state__title').textContent).toBe('No additional leave accounts exist.')
	})

	it('distinguishes “no data” from “filtered out”', async () => {
		T().types = [TYPE_A]
		document.getElementById('se-filter-active').value = 'inactive'
		document.getElementById('se-filter-active').dispatchEvent(new Event('change'))
		const td = document.querySelector('#se-type-tbody td.azc-table-empty')
		expect(td.querySelector('.azc-empty-state__title').textContent).toBe('No entitlements match the current filters.')
	})

	it('search narrows rows without a reload', async () => {
		T().types = [TYPE_A, TYPE_B]
		document.getElementById('se-filter-active').value = 'all'
		document.getElementById('se-filter-active').dispatchEvent(new Event('change'))
		expect(document.querySelectorAll('#se-type-tbody tr')).toHaveLength(2)
		document.getElementById('se-search').value = 'jubil'
		document.getElementById('se-search').dispatchEvent(new Event('input'))
		expect(document.querySelectorAll('#se-type-tbody tr')).toHaveLength(1)
	})
})

describe('row actions', () => {
	it('deactivate posts the active flag with the CAS version', async () => {
		const calls = []
		routeFetch({
			'/api/types': { success: true, items: [TYPE_A] },
			'/api/types/7/active': (u, opts) => {
				calls.push(JSON.parse(opts.body))
				return { success: true, item: { ...TYPE_A, active: false } }
			},
		})
		await T().loadTypes()
		await flush()

		const toggle = document.querySelector('#se-type-tbody .azc-table-actions button[aria-label^="Deactivate"]')
		toggle.click()
		await flush()
		expect(calls).toEqual([{ active: '0', version: 3 }])
	})

	it('manage rules opens the detail panel and fetches policies', async () => {
		const calls = []
		routeFetch({
			'/api/types': { success: true, items: [TYPE_A] },
			'/api/types/7/policies': () => {
				calls.push(1)
				return { success: true, items: [] }
			},
		})
		await T().loadTypes()
		await flush()

		document.querySelector('#se-type-tbody .azc-table-actions button[aria-label^="Manage rules"]').click()
		await flush()

		const detail = document.getElementById('se-detail')
		expect(detail.hidden).toBe(false)
		expect(document.getElementById('se-detail-title').textContent).toBe('Betriebsjubiläum')
		expect(document.getElementById('se-detail-meta').textContent).toContain('se_sonderurlaub')
		expect(calls).toHaveLength(1)
		expect(document.getElementById('se-policy-status').textContent).toBe('No rules yet.')
	})
})

describe('editor panel', () => {
	it('opens the create editor, hides detail and focuses the name field', async () => {
		document.getElementById('se-new-type').click()
		await flush()

		expect(document.getElementById('se-editor').hidden).toBe(false)
		expect(document.getElementById('se-detail').hidden).toBe(true)
		expect(document.getElementById('se-editor-title').textContent).toBe('New special leave entitlement')
		expect(document.activeElement).toBe(document.getElementById('se-label'))
	})

	it('cancel hides the editor and returns focus to the trigger', async () => {
		const btn = document.getElementById('se-new-type')
		btn.click()
		await flush()
		document.getElementById('se-cancel').click()
		await flush()

		expect(document.getElementById('se-editor').hidden).toBe(true)
		expect(document.activeElement).toBe(btn)
	})

	it('opening a detail single-flights the editor closed', async () => {
		routeFetch({ '/api/types': { success: true, items: [TYPE_A] } })
		await T().loadTypes()
		await flush()
		document.getElementById('se-new-type').click()
		await flush()
		expect(document.getElementById('se-editor').hidden).toBe(false)

		await T().openDetail(TYPE_A)
		await flush()
		expect(document.getElementById('se-editor').hidden).toBe(true)
		expect(document.getElementById('se-detail').hidden).toBe(false)
	})
})

describe('field errors', () => {
	it('maps server field names to the matching inline error', () => {
		const shown = T().showFieldErrors(document.getElementById('se-editor'), {
			publicLabel: 'required',
			amount: 'invalid',
		})
		expect(shown).toBe(true)
		expect(document.getElementById('se-label-error').hidden).toBe(false)
		expect(document.getElementById('se-label-error').textContent).toBe('required')
		expect(document.getElementById('se-amount-error').hidden).toBe(false)
		expect(document.getElementById('se-label').getAttribute('aria-invalid')).toBe('true')
	})

	it('returns false when no field has an error slot so callers show the banner', () => {
		const shown = T().showFieldErrors(document.getElementById('se-editor'), {
			nonexistentField: 'boom',
		})
		expect(shown).toBe(false)
	})

	it('cohort errors land inside the cohort form, not the editor', () => {
		const cohortForm = document.getElementById('se-cohort-form')
		const shown = T().showFieldErrors(cohortForm, { label: 'required' })
		expect(shown).toBe(true)
		expect(document.getElementById('se-cohort-label-error').hidden).toBe(false)
		// The editor's identically-named error element stays untouched.
		expect(document.getElementById('se-label-error').hidden).toBe(true)
	})
})

describe('async race guards', () => {
	it('a slow model fetch cannot overwrite the newer team options', async () => {
		let modelResolve
		const modelPromise = new Promise((res) => { modelResolve = res })
		routeFetch({
			'/api/models': () => modelPromise.then((body) => jsonResponse(body)),
			'/api/teams': { success: true, teams: [{ id: 5, name: 'Team Alpha' }] },
		})
		T().openCreateEditor()

		const scope = document.getElementById('se-scope')
		scope.value = 'model'
		scope.dispatchEvent(new Event('change'))
		scope.value = 'team'
		scope.dispatchEvent(new Event('change'))
		await flush()

		modelResolve({ success: true, models: [{ id: 1, name: 'Stale Model' }] })
		await flush()

		const target = document.getElementById('se-target')
		expect(target.hidden).toBe(false)
		const optionTexts = Array.from(target.options).map((o) => o.textContent)
		expect(optionTexts).toEqual(['Team Alpha'])
	})
})
