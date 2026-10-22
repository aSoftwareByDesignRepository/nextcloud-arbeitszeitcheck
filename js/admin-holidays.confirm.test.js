/**
 * @vitest-environment jsdom
 *
 * Per-surface open → cancel (no mutate) → confirm for admin-holidays.js
 * confirmDialog call sites (~334 country, ~476 region, ~991 remove).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

function shellDom() {
	document.body.innerHTML = `
		<header id="header"></header>
		<nav id="app-navigation"></nav>
		<main id="azc-main-content" tabindex="-1">
			<input type="radio" name="holidayCountry" id="holiday-country-de" value="DE" checked />
			<input type="radio" name="holidayCountry" id="holiday-country-at" value="AT" />
			<select id="holiday-state-select" data-last-value="NW">
				<option value="NW" selected>NW</option>
				<option value="AT-W">AT-W</option>
			</select>
			<select id="holiday-default-state" data-last-value="NW">
				<option value="NW" selected>NW</option>
				<option value="AT-W">AT-W</option>
			</select>
			<script type="application/json" id="azc-holidays-region-data">${JSON.stringify({
				defaultRegionByCountry: { DE: 'NW', AT: 'AT-W', CH: 'CH-ZH' },
				regionsByCountry: {
					DE: [{ code: 'NW', label: 'NW' }, { code: 'BY', label: 'BY' }],
					AT: [{ code: 'AT-W', label: 'Wien' }],
					CH: [{ code: 'CH-ZH', label: 'Zurich' }],
				},
			})}</script>
		</main>`
}

async function loadStack() {
	vi.resetModules()
	shellDom()
	window.OC = { generateUrl: (u) => u, requestToken: 'test' }
	window.t = (_app, s) => s
	window.ArbeitszeitCheck = { holidaysUiStrings: {} }
	window.ArbeitszeitCheckMessaging = {
		showError: vi.fn(),
		showSuccess: vi.fn(),
		announceAssertive: vi.fn(),
	}
	await import('./common/utils.js')
	await import('./common/components.js')
	window.AzcComponents = window.ArbeitszeitCheckComponents
	vi.stubGlobal('fetch', vi.fn(async () => ({
		ok: true,
		json: async () => ({ success: true, holidays: [] }),
	})))
	await import('./admin-holidays.js')
	return window.__ArbeitszeitCheckAdminHolidaysTestables
}

afterEach(() => {
	document.body.innerHTML = ''
	vi.unstubAllGlobals()
	delete window.__ArbeitszeitCheckAdminHolidaysTestables
	delete window.ArbeitszeitCheckUtils
	delete window.ArbeitszeitCheckComponents
	delete window.AzcComponents
	delete window.ArbeitszeitCheckMessaging
	delete window.ArbeitszeitCheck
})

describe('admin-holidays confirmDialog call-sites', () => {
	/** @type {typeof window.__ArbeitszeitCheckAdminHolidaysTestables} */
	let t

	beforeEach(async () => {
		t = await loadStack()
		t.setPersistedCountry('DE')
	})

	it('region cross-country: cancel reverts select; confirm keeps AT-W', async () => {
		const select = document.getElementById('holiday-state-select')
		select.value = 'AT-W'
		const pending = t.handleRegionChange(select)
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy())
		document.querySelector('.confirm-dialog__cancel').click()
		await pending
		expect(select.value).toBe('NW')
		expect(select.getAttribute('data-last-value')).toBe('NW')

		select.value = 'AT-W'
		const pending2 = t.handleRegionChange(select)
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy())
		document.querySelector('.confirm-dialog__confirm').click()
		await pending2
		expect(select.value).toBe('AT-W')
		expect(select.getAttribute('data-last-value')).toBe('AT-W')
	})

	it('remove holiday: cancel skips DELETE; confirm DELETEs', async () => {
		const fetchMock = /** @type {ReturnType<typeof vi.fn>} */ (globalThis.fetch)
		fetchMock.mockClear()
		const item = { id: 501, name: 'Atlas Day', scope: 'company', date: '2026-06-01' }
		const row = document.createElement('tr')

		const cancelPending = t.confirmRemoveHoliday(item, row, '01.06.2026')
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy())
		document.querySelector('.confirm-dialog__cancel').click()
		await expect(cancelPending).resolves.toBe(false)
		expect(fetchMock.mock.calls.some((c) => String(c[1]?.method || '').toUpperCase() === 'DELETE')).toBe(false)

		const confirmPending = t.confirmRemoveHoliday(item, row, '01.06.2026')
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy())
		document.querySelector('.confirm-dialog__confirm').click()
		await expect(confirmPending).resolves.toBe(true)
		await vi.waitFor(() => {
			expect(fetchMock.mock.calls.some((c) => String(c[1]?.method || '').toUpperCase() === 'DELETE')).toBe(true)
		})
	})

	it('country change: cancel keeps DE radio; confirm accepts AT (no persist on cancel)', async () => {
		const at = document.getElementById('holiday-country-at')
		const de = document.getElementById('holiday-country-de')
		at.checked = true
		de.checked = false

		const pending = t.handleCountryChange(at)
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__cancel')).toBeTruthy())
		document.querySelector('.confirm-dialog__cancel').click()
		await pending
		expect(de.checked).toBe(true)
		expect(at.checked).toBe(false)

		at.checked = true
		de.checked = false
		const fetchMock = /** @type {ReturnType<typeof vi.fn>} */ (globalThis.fetch)
		fetchMock.mockImplementation(async (url, init) => {
			if (init && String(init.method || '').toUpperCase() === 'POST') {
				return { ok: true, json: async () => ({ success: true }) }
			}
			return { ok: true, json: async () => ({ success: true, holidays: [] }) }
		})
		const pending2 = t.handleCountryChange(at)
		await vi.waitFor(() => expect(document.querySelector('.confirm-dialog__confirm')).toBeTruthy())
		document.querySelector('.confirm-dialog__confirm').click()
		await pending2
		expect(at.checked).toBe(true)
	})
})
