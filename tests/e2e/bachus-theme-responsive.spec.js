// @ts-check
/**
 * Theme × viewport proof for Bachus simplify surfaces (dashboard glance,
 * calendar day panel, mobile nav tokens) — WCAG 2.1 AA + no overflow.
 * Run with --workers=1.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginAs, gotoApp } from './helpers/auth.js'
import { setUserTheme, resetUserTheme, USER_THEMES } from './helpers/theming.js'

test.describe.configure({ mode: 'serial' })

const viewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 414, height: 896 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1280, height: 800 },
]

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content.azc-app')
		const main = document.getElementById('azc-main-content')
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			main: main ? main.scrollWidth - main.clientWidth : 0,
		}
	})
	expect(overflow.doc, `document overflow @ ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.app, `#app-content overflow @ ${label}`).toBeLessThanOrEqual(1)
	expect(overflow.main, `main overflow @ ${label}`).toBeLessThanOrEqual(1)
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function runAxeApp(page, label) {
	const results = await new AxeBuilder({ page })
		.include('#content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(results.violations, `axe @ ${label}:\n${JSON.stringify(results.violations, null, 2)}`).toEqual([])
}

test.describe('Bachus theme × viewport: dashboard + calendar', () => {
	test.setTimeout(240_000)
	test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

	for (const theme of ['light', 'dark', 'dark-highcontrast']) {
		test(`${theme}: dashboard glance hierarchy + overflow`, async ({ page }) => {
			await loginAs(page, 'EMPLOYEE')
			await gotoApp(page, '/apps/arbeitszeitcheck/dashboard')
			await page.waitForSelector('#dashboard-metrics-heading, .azc-dashboard-metrics-panel', { timeout: 30_000 })
			await setUserTheme(page, theme)

			const tokens = await page.evaluate(() => {
				const b = getComputedStyle(document.body)
				return {
					text: b.getPropertyValue('--azc-text').trim(),
					bg: b.getPropertyValue('--azc-bg-card').trim(),
					primary: b.getPropertyValue('--color-primary-element').trim(),
					touch: b.getPropertyValue('--azc-touch').trim(),
				}
			})
			expect(tokens.text).not.toEqual('')
			expect(tokens.bg).not.toEqual('')
			expect(tokens.primary).not.toEqual('')
			expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44).toBeTruthy()

			const panel = page.locator('.azc-dashboard-metrics-panel')
			await expect(panel).toBeVisible()
			const more = panel.locator('details.azc-dashboard-metrics-more')
			if (await more.count()) {
				await more.locator('summary').focus()
				await expect(more.locator('summary')).toBeFocused()
			}

			for (const vp of viewports) {
				await page.setViewportSize(vp)
				await expectNoHorizontalOverflow(page, `${theme}/dashboard@${vp.width}`)
			}

			await page.setViewportSize({ width: 375, height: 812 })
			await runAxeApp(page, `${theme}/dashboard@375`)
			await page.setViewportSize({ width: 1280, height: 800 })
			await runAxeApp(page, `${theme}/dashboard@1280`)
		})

		test(`${theme}: calendar day panel tokens + overflow`, async ({ page }) => {
			await loginAs(page, 'EMPLOYEE')
			await gotoApp(page, '/apps/arbeitszeitcheck/calendar')
			await page.waitForSelector('.calendar-day[data-date], .calendar-day[tabindex="0"]', { timeout: 30_000 })
			await setUserTheme(page, theme)

			const day = page.locator('.calendar-day[data-date], .calendar-day[tabindex="0"]').first()
			await day.click()
			await expect(page.locator('#day-details-panel')).toBeVisible()
			const primary = page.locator('.day-details-actions__link--primary, .day-details-actions a.azc-btn--primary').first()
			await expect(primary).toBeVisible()
			const box = await primary.boundingBox()
			expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)

			for (const vp of [320, 375, 768, 1024, 1280]) {
				await page.setViewportSize({ width: vp, height: 800 })
				await expectNoHorizontalOverflow(page, `${theme}/calendar@${vp}`)
			}

			await page.setViewportSize({ width: 320, height: 640 })
			await runAxeApp(page, `${theme}/calendar-day@320`)
		})
	}

	test('reset employee theme after Bachus matrix', async ({ page }) => {
		await loginAs(page, 'EMPLOYEE')
		await gotoApp(page, '/apps/arbeitszeitcheck/dashboard')
		await page.waitForSelector('#azc-main-content', { timeout: 30_000 })
		await resetUserTheme(page)
		expect(USER_THEMES.length).toBeGreaterThan(0)
	})
})
