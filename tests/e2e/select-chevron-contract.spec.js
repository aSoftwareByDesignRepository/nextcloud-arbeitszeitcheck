// @ts-check
/**
 * Rendered regression proof for the 2026-11-04 farm class-gate sweep
 * (required_field_or_step_undiscoverable / check-select-background-shorthand.py):
 * select-matching CSS rules painted the bare `background:` shorthand, resetting
 * the NC core picker caret (image/position/repeat/size) — the control read as
 * a plain text field in light and a tiled caret grid in dark.
 *
 * Legs (computed style, light AND dark):
 *  1. Correction-modal select `#correction-start-hour` — `select.form-input`
 *     inside `#time-entry-correction-modal`, covered by the fixed
 *     time-entry-correction.css rule.
 *  2. Reports `.report-form` select `#format` — `select.form-select` inside
 *     `.report-form`, covered by the fixed reports.css rule.
 *
 * Theme is flipped via OCS → serialized in STATEFUL_SPECS.
 */
import { test, expect } from '@playwright/test'
import { loginAs } from './helpers/auth.js'
import { apiAllowFailure } from './helpers/api.js'
import { setUserTheme, resetUserTheme } from './helpers/theming.js'

const APP = '/apps/arbeitszeitcheck'
const THEMES = ['light', 'dark']

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

/** Computed chevron probe: core caret = url() image + no-repeat + positioned. */
async function probeChevron(select, theme) {
	const probe = await select.evaluate((node) => {
		const cs = getComputedStyle(node)
		return {
			image: cs.backgroundImage,
			repeat: cs.backgroundRepeat,
			position: cs.backgroundPosition,
		}
	})
	expect(probe.image, `${theme}: chevron background-image missing (bare background: regression)`).toContain('url(')
	expect(probe.repeat, `${theme}: chevron tiles (dark-grid regression)`).toBe('no-repeat')
	expect(probe.position, `${theme}: chevron unpositioned`).not.toBe('0% 0%')
}

test.describe('select chevron contract', () => {
	test.describe.configure({ mode: 'serial' })
	test.afterEach(async ({ page }) => {
		await resetUserTheme(page).catch(() => {})
	})

	test('correction-modal select keeps the picker chevron (light+dark)', async ({ page }) => {
		test.setTimeout(180_000)
		await loginAs(page, 'EMPLOYEE')
		await page.setViewportSize({ width: 1280, height: 900 })

		// Entries paginate server-side (newest first); correction-eligible rows
		// (> edit window) sit on the oldest pages — walk the pager, else seed one.
		const findRequestBtn = async () => {
			const visited = new Set()
			for (let hops = 0; hops < 15; hops += 1) {
				const btn = page.locator('.btn-request-correction:visible').first()
				if ((await btn.count()) > 0) {
					return btn
				}
				const current = await page.locator('#time-entries-table-pager .azc-table-pager__page.is-current').textContent().catch(() => '1')
				visited.add(Number(String(current).trim()))
				const nums = await page.locator('#time-entries-table-pager .azc-table-pager__pages a[data-page]').evaluateAll(
					(links) => links.map((a) => Number(a.getAttribute('data-page'))),
				)
				const nextPage = nums.filter((p) => !visited.has(p)).sort((a, b) => b - a)[0]
				if (nextPage == null) {
					return btn
				}
				await page.locator(`#time-entries-table-pager a[data-page="${nextPage}"]`).click()
				await page.waitForSelector('#azc-main-content', { timeout: 30000 })
			}
			return page.locator('.btn-request-correction:visible').first()
		}

		const openCorrectionModal = async () => {
			await page.goto(`${APP}/time-entries`, { waitUntil: 'domcontentloaded' })
			await page.waitForSelector('#azc-main-content', { timeout: 30000 })
			let requestBtn = await findRequestBtn()
			if ((await requestBtn.count()) === 0) {
				let seeded = false
				let lastErr = ''
				for (let age = 20; age <= 60; age += 1) {
					const date = ymdDaysAgo(age)
					const res = await apiAllowFailure(page, 'POST', `${APP}/api/time-entries`, {
						data: { date, hours: 1.25, description: `E2E chevron seed ${date}` },
					})
					if (res.ok && res.json?.success) {
						seeded = true
						break
					}
					lastErr = res.json?.error || `HTTP ${res.status}`
					const err = String(lastErr).toLowerCase()
					if (!err.includes('overlap') && !err.includes('weekend') && !err.includes('holiday')) {
						continue
					}
				}
				expect(seeded, lastErr || 'could not seed old entry for correction button').toBe(true)
				await page.reload()
				await page.waitForSelector('#azc-main-content', { timeout: 30000 })
				requestBtn = await findRequestBtn()
			}
			await expect(requestBtn).toBeVisible({ timeout: 15000 })
			// Row actions reveal on tr:hover — hover the row before clicking.
			await requestBtn.locator('xpath=ancestor::tr[1]').hover()
			await requestBtn.click()
			const modal = page.locator('#time-entry-correction-modal')
			await expect(modal).toBeVisible({ timeout: 15000 })
			const select = modal.locator('#correction-start-hour')
			await expect(select).toBeVisible()
			return { modal, select }
		}

		for (const theme of THEMES) {
			await page.goto(`${APP}/time-entries`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			const { modal, select } = await openCorrectionModal()
			await probeChevron(select, theme)
			await page.keyboard.press('Escape')
			await expect(modal).toBeHidden({ timeout: 10_000 }).catch(() => {})
		}
	})

	test('reports .report-form select keeps the picker chevron (light+dark)', async ({ page }) => {
		test.setTimeout(120_000)
		// /reports is admin|manager only — employees see a denied state.
		await loginAs(page, 'MANAGER')
		await page.setViewportSize({ width: 1280, height: 900 })

		for (const theme of THEMES) {
			await page.goto(`${APP}/reports`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			await page.goto(`${APP}/reports`, { waitUntil: 'domcontentloaded' })
			const monthly = page.locator('.report-type-card.btn-select-report[data-report="monthly"]').first()
			await expect(monthly).toBeVisible({ timeout: 30000 })
			await monthly.click()
			const select = page.locator('#report-form select#format.form-select')
			await expect(select).toBeVisible({ timeout: 15000 })
			await probeChevron(select, theme)
		}
	})
})
