/**
 * FA-1: tolerant h:mm tier parsing + field-level errors (spec §9.1, US-401).
 */
import { describe, it, expect, beforeAll, beforeEach, afterAll, vi } from 'vitest'

const IDS = [
	'bta-set-list', 'bta-sets-status', 'bta-sets-empty', 'bta-new-set',
	'bta-builder', 'bta-builder-form', 'bta-builder-title', 'bta-builder-error',
	'bta-builder-error-text', 'bta-builder-close', 'bta-set-id', 'bta-code',
	'bta-name', 'bta-jurisdiction', 'bta-valid-from', 'bta-valid-to',
	'bta-day-basis', 'bta-kind-first', 'bta-kind-middle', 'bta-kind-last',
	'bta-kind-single', 'bta-threshold-rows', 'bta-threshold-error',
	'bta-add-threshold', 'bta-operation', 'bta-operation-hint', 'bta-cap',
	'bta-f-work', 'bta-f-active', 'bta-f-passive', 'bta-f-during',
	'bta-round-mode', 'bta-round-inc', 'bta-req-classification',
	'bta-active-released', 'bta-passive-released', 'bta-eval-tz',
	'bta-preview-basis', 'bta-preview-status', 'bta-preview-rows', 'bta-save',
	'bta-activate', 'bta-new-assignment', 'bta-assignment-form',
	'bta-assignment-cancel', 'bta-a-ruleset', 'bta-a-scope', 'bta-a-target',
	'bta-a-user-wrap', 'bta-a-user', 'bta-a-from', 'bta-a-to', 'bta-a-priority',
	'bta-assign-error', 'bta-assign-error-text', 'bta-assign-status',
	'bta-assignment-list', 'bta-assign-empty', 'bta-profile-form',
	'bta-profile-error', 'bta-profile-error-text', 'bta-profile-status',
	'bta-p-user', 'bta-p-minutes', 'bta-p-from', 'bta-rollout-status',
	'bta-rollout-checks', 'bta-enable', 'bta-enable-credit', 'bta-disable-legacy',
	'bta-presets', 'bta-threshold-formats',
]

const SELECT_IDS = new Set([
	'bta-jurisdiction', 'bta-day-basis', 'bta-kind-first', 'bta-kind-middle',
	'bta-kind-last', 'bta-kind-single', 'bta-operation', 'bta-round-mode',
	'bta-eval-tz', 'bta-a-ruleset', 'bta-a-scope',
])
const CHECKBOX_IDS = new Set([
	'bta-req-classification', 'bta-active-released', 'bta-passive-released',
])
const FORM_IDS = new Set(['bta-builder-form', 'bta-assignment-form', 'bta-profile-form'])
const HIDDEN_IDS = new Set(['bta-set-id', 'bta-builder'])

function buildDom() {
	document.body.innerHTML = ''
	const root = document.createElement('div')
	root.id = 'bta-root'
	root.dataset.enabled = '1'
	root.dataset.ruleSetsUrl = '/api/rule-sets'
	root.dataset.ruleSetCreateUrl = '/api/rule-sets'
	root.dataset.ruleSetUpdateTemplate = '/api/rule-sets/__ID__'
	root.dataset.ruleSetActivateTemplate = '/api/rule-sets/__ID__/activate'
	root.dataset.markReviewedTemplate = '/api/rule-sets/__ID__/mark-reviewed'
	root.dataset.presetCreateTemplate = '/api/presets/__ID__/create'
	root.dataset.previewUrl = '/api/preview'
	root.dataset.assignmentsUrl = '/api/assignments'
	root.dataset.modelsUrl = '/api/models'
	root.dataset.teamsUrl = '/api/teams'
	root.dataset.cohortsUrl = '/api/cohorts'
	root.dataset.usersUrl = '/api/users'
	root.dataset.rolloutUrl = '/api/rollout'
	root.dataset.presetsUrl = '/api/presets'
	root.dataset.creditEnableUrl = '/api/credit/enable'
	root.dataset.disableLegacyUrl = '/api/disable-legacy'
	document.body.appendChild(root)

	IDS.forEach((id) => {
		let el
		if (FORM_IDS.has(id)) {
			el = document.createElement('form')
		} else if (SELECT_IDS.has(id)) {
			el = document.createElement('select')
			el.appendChild(new Option('x', 'x'))
		} else if (id === 'bta-threshold-rows') {
			el = document.createElement('tbody')
		} else {
			el = document.createElement('input')
			if (CHECKBOX_IDS.has(id)) el.type = 'checkbox'
			else if (HIDDEN_IDS.has(id)) el.type = 'hidden'
			else el.type = 'text'
		}
		el.id = id
		if (id === 'bta-preview-basis') el.value = '480'
		root.appendChild(el)
	})
}

let fetchCalls = []

beforeAll(async () => {
	vi.resetModules()
	if (!Element.prototype.scrollIntoView) {
		Element.prototype.scrollIntoView = () => {}
	}
	buildDom()
	fetchCalls = []
	window.AzcApi = {
		fetch: async (url, options = {}) => {
			fetchCalls.push({ url, options })
			return { ok: true, status: 200, data: { items: [], models: [], teams: [] } }
		},
		isApiSuccess: () => true,
	}
	await import('./admin-business-trips.js')
	// let init async loads settle
	await new Promise((r) => setTimeout(r, 0))
})

afterAll(() => {
	delete window.AzcApi
	delete window.__ArbeitszeitCheckBtaTestables
})

const t = () => window.__ArbeitszeitCheckBtaTestables

function openNewBuilder() {
	document.getElementById('bta-new-set').click()
}

function tierInputs() {
	return [...document.querySelectorAll('#bta-threshold-rows input.bta-hm')]
}

describe('parseHm normalization (spec §9.1)', () => {
	it.each([
		['0:00', 0], ['8:00', 480], ['10:00', 600], ['24:00', 1440],
		['1:30', 90], ['8:0', 480], ['7:5', 425],
		['8.30', 510], ['8,30', 510], ['8h30', 510], ['8H30', 510],
		['480', 480], ['90', null], ['100', 100], ['1440', 1440],
		['1441', null], ['8', 480], ['24', 1440], ['25', null],
		['8,5', null], ['8.5', null], ['8:61', null], ['abc', null],
		['', null], [null, null], [undefined, null],
		['8: 00', 480], [' 8:00 ', 480], ['8:00 ', 480],
		['8​:00', 480], ['10.00', 600], ['0', 0],
		['100:00', null], ['8:000', null], ['8,500', null], ['-30', null],
	])('%j → %s', (input, expected) => {
		expect(t().parseHm(input)).toBe(expected)
	})
})

describe('readConfig tier field errors', () => {
	beforeEach(() => {
		fetchCalls = []
		openNewBuilder()
	})

	it('marks the offending row input with aria-invalid + describedby, fills #bta-threshold-error', () => {
		const [f1, g1, f2, g2] = tierInputs()
		f2.value = 'abc'
		const { error, firstInvalid } = t().readConfig()
		expect(error).toContain('2')
		expect(firstInvalid).toBe(f2)
		expect(f2.getAttribute('aria-invalid')).toBe('true')
		expect(f2.getAttribute('aria-describedby')).toContain('bta-threshold-error')
		expect(g2.getAttribute('aria-invalid')).toBeNull()
		const errEl = document.getElementById('bta-threshold-error')
		expect(errEl.hidden).toBe(false)
		expect(errEl.textContent).toContain('2')
	})

	it('accepts normalized formats and produces integer minutes', () => {
		const [f1, g1, f2, g2] = tierInputs()
		f1.value = '0'
		g1.value = '0'
		f2.value = '480'
		g2.value = '8,30'
		const { config, error } = t().readConfig()
		expect(error).toBeUndefined()
		expect(config.thresholds).toEqual([
			{ fromMinutes: 0, targetMinutes: 0 },
			{ fromMinutes: 480, targetMinutes: 510 },
		])
		expect(document.getElementById('bta-threshold-error').hidden).toBe(true)
	})

	it('clears a previously-invalid field once fixed', () => {
		const [f1] = tierInputs()
		f1.value = 'nope'
		t().readConfig()
		expect(f1.getAttribute('aria-invalid')).toBe('true')
		f1.value = '0:00'
		const { error } = t().readConfig()
		expect(error).toBeUndefined()
		expect(f1.getAttribute('aria-invalid')).toBeNull()
	})

	it('flags a non-monotonic tier row', () => {
		const [f1, g1, f2, g2] = tierInputs()
		f1.value = '0:00'; g1.value = '0:00'
		f2.value = '9:00'; g2.value = '8:00'
		document.getElementById('bta-add-threshold').click()
		const extra = tierInputs()
		extra[4].value = '9:00'
		extra[5].value = '8:00'
		const { error, firstInvalid } = t().readConfig()
		expect(error).toBe('Tiers must be strictly increasing.')
		expect(firstInvalid.getAttribute('aria-invalid')).toBe('true')
	})

	it('flags a first tier that does not start at 0:00', () => {
		const [f1, g1, f2, g2] = tierInputs()
		f1.value = '1:00'; g1.value = '0:00'
		f2.value = '9:00'; g2.value = '8:00'
		const { error, firstInvalid } = t().readConfig()
		expect(error).toBe('The first tier must start at 0:00.')
		expect(firstInvalid).toBe(f1)
	})
})

describe('input affordances', () => {
	beforeEach(() => {
		openNewBuilder()
	})

	it('uses decimal inputMode so touch keyboards offer a separator', () => {
		tierInputs().forEach((i) => expect(i.inputMode).toBe('decimal'))
	})

	it('auto-formats recognized input back to canonical h:mm on change', () => {
		const [f1] = tierInputs()
		f1.value = '8.30'
		f1.dispatchEvent(new Event('change', { bubbles: true }))
		expect(f1.value).toBe('8:30')
	})

	it('auto-formats on blur as well', () => {
		const [, g1] = tierInputs()
		g1.value = '480'
		g1.dispatchEvent(new Event('blur'))
		expect(g1.value).toBe('8:00')
	})
})

describe('submit gate', () => {
	it('blocks the request and focuses the invalid field on a bad row', async () => {
		openNewBuilder()
		document.getElementById('bta-name').value = 'Test'
		document.getElementById('bta-code').value = 'TST'
		const [f1] = tierInputs()
		f1.value = 'abc'
		const callsBefore = fetchCalls.length
		const form = document.getElementById('bta-builder-form')
		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		await new Promise((r) => setTimeout(r, 0))
		expect(fetchCalls.length).toBe(callsBefore)
		expect(document.getElementById('bta-builder-error').hidden).toBe(false)
		expect(document.activeElement).toBe(f1)
	})
})
