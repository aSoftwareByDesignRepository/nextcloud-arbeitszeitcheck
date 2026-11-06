/**
 * @vitest-environment jsdom
 *
 * Rollout card contract: the readiness preflight renders as a guided
 * checklist — open steps (with their inline fix) under "To do", finished
 * steps under "Done", evidence under "Good to know" — and the cutover
 * button only unlocks once every gate is green.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchMock = vi.fn()

function stageDom() {
	document.body.innerHTML = `
		<section id="bta-rollout"></section>
		<div id="bta-root"
			data-enabled="0"
			data-rollout-url="/rollout"
			data-enable-url="/enable"
			data-credit-enable-url="/credit"
			data-disable-legacy-url="/disable-legacy"
			data-manager-absences-url="/manager/absences"
			data-manager-trips-url="/manager/business-trips"></div>
		<p id="bta-rollout-status"></p>
		<div id="bta-rollout-todo-group" hidden><h3 id="bta-rollout-todo-title"></h3><ul id="bta-rollout-todo"></ul></div>
		<div id="bta-rollout-done-group" hidden><h3 id="bta-rollout-done-title"></h3><ul id="bta-rollout-done"></ul></div>
		<div id="bta-rollout-info-group" hidden><h3 id="bta-rollout-info-title"></h3><ul id="bta-rollout-info"></ul></div>
		<div class="form-actions" id="bta-cutover" hidden><button id="bta-disable-legacy" type="button"></button><p id="bta-cutover-help"></p></div>
		<button id="bta-enable" type="button" hidden></button>
		<button id="bta-enable-credit" type="button" hidden></button>
	`
}

function rolloutPayload({ preflight = {}, flags = {} } = {}) {
	return {
		success: true,
		preflight: {
			pendingLegacyTripAbsences: 0,
			submittedTripsUndecided: 0,
			activeRuleSets: 0,
			effectiveAssignments: 0,
			orgScopeCoverage: false,
			ready: false,
			...preflight,
		},
		flags: {
			business_trips_enabled: false,
			business_trip_credit_enabled: false,
			legacy_trip_absence_create_enabled: true,
			...flags,
		},
	}
}

function stubRollout(payload) {
	fetchMock.mockImplementation(async (url, options) => {
		const method = (options && options.method) || 'GET'
		if (url === '/rollout' && method === 'GET') {
			return { ok: true, status: 200, data: payload }
		}
		if (url === '/disable-legacy' && method === 'POST') {
			return {
				ok: true,
				status: 200,
				data: {
					success: true,
					preflight: payload.preflight,
					legacy_trip_absence_create_enabled: false,
					business_trips_enabled: true,
				},
			}
		}
		return { ok: true, status: 200, data: { success: true, items: [] } }
	})
}

const texts = (selector) =>
	Array.from(document.querySelectorAll(selector)).map((li) => li.querySelector('.bta-step__text').textContent.trim())

beforeEach(() => {
	fetchMock.mockReset()
	stageDom()
	window.t = (app, text) => text
	window.AzcCatalog = { render: () => '' }
	window.AzcApi = {
		fetch: fetchMock,
		isApiSuccess: (res) => !!(res && res.data && res.data.success === true),
	}
	window.AzcComponents = { confirmDialog: vi.fn(async () => true) }
})

describe('rollout checklist', () => {
	it('splits open steps, done steps and info into separate groups', async () => {
		stubRollout(rolloutPayload())
		await import('./admin-business-trips.js?split=1')
		await vi.waitFor(() => expect(document.querySelectorAll('#bta-rollout-todo li').length).toBeGreaterThan(0))

		expect(document.getElementById('bta-rollout-todo-group').hidden).toBe(false)
		expect(document.getElementById('bta-rollout-done-group').hidden).toBe(false)
		expect(document.getElementById('bta-rollout-info-group').hidden).toBe(false)

		expect(texts('#bta-rollout-done li')).toEqual(['No pending legacy business-trip requests'])
		expect(texts('#bta-rollout-todo li')).toEqual([
			'Turn on the business-trip feature',
			'Create, review and activate a rule setEnable business trips first.',
			'Assign a rule setEnable business trips first.',
			'Turn on time-account credit postingsEnable business trips first.',
		])
		expect(document.getElementById('bta-rollout-status').textContent).toBe('1 of 5 steps done.')
	})

	it('seats the enable button inside its step row while the feature is off', async () => {
		stubRollout(rolloutPayload())
		await import('./admin-business-trips.js?seat=1')
		await vi.waitFor(() => expect(document.querySelector('#bta-rollout-todo li .bta-step__action')).not.toBeNull())

		const row = document.querySelector('#bta-rollout-todo li')
		expect(row.querySelector('#bta-enable')).not.toBeNull()
		expect(document.getElementById('bta-enable').hidden).toBe(false)
	})

	it('marks prerequisite-blocked steps and keeps their notes', async () => {
		stubRollout(rolloutPayload())
		await import('./admin-business-trips.js?blocked=1')
		await vi.waitFor(() => expect(document.querySelectorAll('#bta-rollout-todo li').length).toBeGreaterThan(0))

		const blocked = document.querySelectorAll('#bta-rollout-todo li.bta-step--blocked')
		expect(blocked.length).toBe(3)
		blocked.forEach((li) => {
			expect(li.querySelector('.bta-step__note').textContent).toBe('Enable business trips first.')
			expect(li.querySelector('.bta-step__action')).toBeNull()
		})
	})

	it('links open gates to where they get fixed once the feature is on', async () => {
		stubRollout(rolloutPayload({
			preflight: { pendingLegacyTripAbsences: 2 },
			flags: { business_trips_enabled: true },
		}))
		await import('./admin-business-trips.js?links=1')
		await vi.waitFor(() => expect(document.querySelectorAll('#bta-rollout-todo li').length).toBeGreaterThan(0))

		const todoText = texts('#bta-rollout-todo li')
		expect(todoText[0]).toBe('Decide pending legacy business-trip requests (2)')

		const links = Array.from(document.querySelectorAll('#bta-rollout-todo a.azc-btn'))
		expect(links.map((a) => a.getAttribute('href'))).toEqual([
			'/manager/absences',
			'#bta-sets',
		])

		const creditRow = Array.from(document.querySelectorAll('#bta-rollout-todo li'))
			.find((li) => li.textContent.includes('credit postings'))
		expect(creditRow.querySelector('#bta-enable-credit')).not.toBeNull()
		expect(document.getElementById('bta-enable-credit').hidden).toBe(false)

		const assignRow = Array.from(document.querySelectorAll('#bta-rollout-todo li'))
			.find((li) => li.classList.contains('bta-step--blocked'))
		expect(assignRow.querySelector('.bta-step__note').textContent).toBe('Activate a rule set first.')
	})

	it('unlocks the cutover only when every gate is green and explains the wait', async () => {
		stubRollout(rolloutPayload({
			preflight: { activeRuleSets: 1, effectiveAssignments: 1, ready: true },
			flags: { business_trips_enabled: true },
		}))
		await import('./admin-business-trips.js?cutover=1')
		await vi.waitFor(() => expect(document.querySelectorAll('#bta-rollout-done li').length).toBeGreaterThan(0))

		const cutover = document.getElementById('bta-cutover')
		expect(cutover.hidden).toBe(false)
		expect(document.getElementById('bta-disable-legacy').disabled).toBe(false)
		expect(document.getElementById('bta-cutover-help').textContent)
			.toBe('Existing legacy requests stay readable and editable forever — this only stops creating new ones.')
		expect(document.getElementById('bta-rollout-status').textContent)
			.toBe('All required steps are done — you can now stop new legacy requests.')
	})

	it('keeps the cutover disabled with a pointer while steps are open', async () => {
		stubRollout(rolloutPayload())
		await import('./admin-business-trips.js?blockedcut=1')
		await vi.waitFor(() => expect(document.querySelectorAll('#bta-rollout-todo li').length).toBeGreaterThan(0))

		expect(document.getElementById('bta-disable-legacy').disabled).toBe(true)
		expect(document.getElementById('bta-cutover-help').textContent)
			.toBe('Finish the open steps above — then you can stop new legacy requests.')
	})

	it('links undecided submitted trips to the manager review page', async () => {
		stubRollout(rolloutPayload({
			preflight: { submittedTripsUndecided: 4 },
			flags: { business_trips_enabled: true },
		}))
		await import('./admin-business-trips.js?info=1')
		await vi.waitFor(() => expect(document.querySelectorAll('#bta-rollout-info li').length).toBeGreaterThan(0))

		const infoLinks = Array.from(document.querySelectorAll('#bta-rollout-info a.azc-btn'))
		expect(infoLinks.map((a) => a.getAttribute('href'))).toContain('/manager/business-trips')
		expect(texts('#bta-rollout-info li')[0]).toBe('Submitted trips awaiting a decision: 4')
	})

	it('hides the whole card after the cutover completes the rollout', async () => {
		stubRollout(rolloutPayload({
			preflight: { pendingLegacyTripAbsences: 1, activeRuleSets: 1, effectiveAssignments: 1, ready: true },
			flags: { business_trips_enabled: true, business_trip_credit_enabled: true },
		}))
		await import('./admin-business-trips.js?done=1')
		await vi.waitFor(() => expect(document.getElementById('bta-disable-legacy').disabled).toBe(false))
		expect(document.getElementById('bta-rollout').hidden).toBe(false)

		document.getElementById('bta-disable-legacy').click()
		await vi.waitFor(() => expect(document.getElementById('bta-rollout').hidden).toBe(true))
	})

	it('shows the card again when a loaded rollout is not yet complete', async () => {
		stubRollout(rolloutPayload({
			flags: { business_trips_enabled: true, business_trip_credit_enabled: true },
		}))
		await import('./admin-business-trips.js?visible=1')
		await vi.waitFor(() => expect(document.querySelectorAll('#bta-rollout-todo li').length).toBeGreaterThan(0))
		expect(document.getElementById('bta-rollout').hidden).toBe(false)
	})
})
