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

		// The entries table paginates server-side (newest first): rows outside the
		// current page render with `hidden`, so only query *visible* buttons.
		// Correction-eligible entries (> edit window) sit on the oldest pages —
		// jump to the last page before concluding none exists.
		const visibleRequestBtn = () => page.locator('.btn-request-correction:visible').first()
		// Walk the pager oldest→newest until a visible button appears.
		const findAcrossPages = async () => {
			const visited = new Set()
			for (let hops = 0; hops < 15; hops += 1) {
				const btn = visibleRequestBtn()
				if ((await btn.count()) > 0) {
					return btn
				}
				const current = await page.locator('#time-entries-table-pager .azc-table-pager__page.is-current').textContent().catch(() => '1')
				visited.add(Number(String(current).trim()))
				const nums = await page.locator('#time-entries-table-pager .azc-table-pager__pages a[data-page]').evaluateAll(
					(links) => links.map((a) => Number(a.getAttribute('data-page'))),
				)
				// Highest page number first (oldest entries); skip visited pages.
				const nextPage = nums.filter((p) => !visited.has(p)).sort((a, b) => b - a)[0]
				if (nextPage == null) {
					return btn
				}
				await page.locator(`#time-entries-table-pager a[data-page="${nextPage}"]`).click()
				await page.waitForSelector('#azc-main-content', { timeout: 30000 })
			}
			return visibleRequestBtn()
		}

		let requestBtn = await findAcrossPages()
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
			// Seeded entry is old → sits past page 1 after reload; scan the pager.
			await page.reload()
			await page.waitForSelector('#azc-main-content', { timeout: 30000 })
			requestBtn = await findAcrossPages()
		}

		await expect(requestBtn).toBeVisible({ timeout: 15000 })
		// Row actions reveal on tr:hover at >=769px (pointer-events:none panel);
		// Playwright hit-tests without moving the cursor, so hover the row first.
		await requestBtn.locator('xpath=ancestor::tr[1]').hover()
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
