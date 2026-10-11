// @ts-check
/**
 * UI-master pass probe: the two pages touched by the a11y-smoke fixes
 * (audit-log tabindex, kiosk badge + search width) — overflow and
 * focusable scroll regions at the full audit breakpoint set.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

const PAGES = [
	'/apps/arbeitszeitcheck/admin/audit-log',
	'/apps/arbeitszeitcheck/admin/kiosk',
]
const VIEWPORTS = [320, 375, 414, 768, 1024, 1280]

test.describe('UI-master probe (touched pages)', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')

	for (const path of PAGES) {
		test(`${path}: no horizontal overflow, scroll regions keyboard-focusable`, async ({ page }) => {
			await login(page, credsFromEnv('ADMIN'))
			for (const w of VIEWPORTS) {
				await page.setViewportSize({ width: w, height: 800 })
				await page.goto(path)
				await assertArbeitszeitcheckLoaded(page)
				const overflow = await page.evaluate(() => ({
					doc: document.documentElement.scrollWidth - document.documentElement.clientWidth,
					main: (document.getElementById('azc-main-content')?.scrollWidth ?? 0)
						- (document.getElementById('azc-main-content')?.clientWidth ?? 0),
				}))
				expect(overflow.doc, `doc overflow ${path} @${w}`).toBeLessThanOrEqual(1)
				expect(overflow.main, `main overflow ${path} @${w}`).toBeLessThanOrEqual(1)
			}
			// Any scrollable table region on the page must be in tab order.
			// (kiosk has no table region — audit-log does.)
			const bad = await page
				.locator('[role="region"] .vgt-table, .table-container[role="region"]:not([tabindex="0"]), .azc-table-wrap[role="region"]:not([tabindex="0"])')
				.count()
			expect(bad, `unfocusable scroll regions ${path}`).toBe(0)
		})
	}
})
