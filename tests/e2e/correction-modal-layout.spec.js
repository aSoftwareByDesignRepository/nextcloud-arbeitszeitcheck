// @ts-check
/**
 * Employee "Request correction" modal must keep form content inside the dialog
 * (regression: CSS selector pairing shoved the body into a horizontal flex row).
 *
 * Seeds a completed entry older than the edit window so the real
 * "Request correction" control appears (same path as production).
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { apiAllowFailure } from './helpers/api.js'

function ymdDaysAgo(days) {
	const d = new Date()
	d.setDate(d.getDate() - days)
	// Prefer a weekday to avoid weekend rejection on some profiles.
	while (d.getDay() === 0 || d.getDay() === 6) {
		d.setDate(d.getDate() - 1)
	}
	const pad = (n) => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

test.describe('time entry correction modal layout', () => {
	test.beforeEach(() => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')
	})

	test('correction form fits inside modal (no horizontal shift/clip)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/time-entries')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		let requestBtn = page.locator('.btn-request-correction').first()
		if ((await requestBtn.count()) === 0) {
			let seeded = false
			let lastErr = ''
			for (let age = 20; age <= 60; age += 1) {
				const date = ymdDaysAgo(age)
				const res = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
					data: {
						date,
						hours: 1.25,
						description: `E2E correction layout seed ${date}`,
					},
				})
				if (res.ok && res.json?.success) {
					seeded = true
					break
				}
				lastErr = res.json?.error || `HTTP ${res.status}`
				const err = String(lastErr).toLowerCase()
				if (!err.includes('overlap') && !err.includes('weekend') && !err.includes('holiday')) {
					// Keep trying other ages for soft calendar conflicts.
					continue
				}
			}
			expect(seeded, lastErr || 'could not seed old entry for correction button').toBe(true)
			await page.reload()
			await page.waitForSelector('#azc-main-content', { timeout: 30000 })
			requestBtn = page.locator('.btn-request-correction').first()
		}

		await expect(requestBtn).toBeVisible({ timeout: 15000 })
		await requestBtn.click()

		const modal = page.locator('#time-entry-correction-modal')
		await expect(modal).toBeVisible({ timeout: 15000 })
		await expect(modal.locator('#time-entry-correction-form')).toBeVisible()
		await expect(modal.locator('#correction-date')).toBeVisible()
		await expect(modal.locator('#correction-start-hour')).toBeVisible()
		await expect(modal.locator('#correction-justification')).toBeVisible()

		const geometry = await page.evaluate(() => {
			const modalEl = document.getElementById('time-entry-correction-modal')
			const body = modalEl && modalEl.querySelector('.modal-body')
			const formEl = document.getElementById('time-entry-correction-form')
			const dateInput = document.getElementById('correction-date')
			const snapshot = modalEl && modalEl.querySelector('.correction-dialog__block--current')
			if (!modalEl || !body || !formEl || !dateInput) {
				return { ok: false, reason: 'missing nodes' }
			}
			const m = modalEl.getBoundingClientRect()
			const b = body.getBoundingClientRect()
			const f = formEl.getBoundingClientRect()
			const d = dateInput.getBoundingClientRect()
			const s = snapshot ? snapshot.getBoundingClientRect() : null
			const style = window.getComputedStyle(modalEl)
			return {
				ok: true,
				modalFlexDirection: style.flexDirection,
				modalFlexWrap: style.flexWrap,
				bodyLeftOffset: b.left - m.left,
				formWidthRatio: f.width / Math.max(m.width, 1),
				dateFullyInside:
					d.left >= m.left - 1
					&& d.right <= m.right + 1
					&& d.top >= m.top - 1,
				formFullyInside:
					f.left >= m.left - 2
					&& f.right <= m.right + 2,
				snapshotNotShifted: s ? (s.left - m.left) < 48 : true,
			}
		})

		expect(geometry.ok, geometry.reason || 'geometry').toBe(true)
		expect(geometry.modalFlexDirection).toBe('column')
		expect(geometry.modalFlexWrap === 'nowrap' || geometry.modalFlexWrap === '').toBeTruthy()
		expect(geometry.bodyLeftOffset).toBeLessThan(48)
		expect(geometry.formWidthRatio).toBeGreaterThan(0.7)
		expect(geometry.dateFullyInside).toBe(true)
		expect(geometry.formFullyInside).toBe(true)
		expect(geometry.snapshotNotShifted).toBe(true)

		await page.screenshot({
			path: 'test-results/correction-modal-layout.png',
			fullPage: false,
		})
	})
})
