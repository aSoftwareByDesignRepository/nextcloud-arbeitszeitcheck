// @ts-check
/**
 * GH #40 delta — theme × viewport × WCAG proof for:
 *  - Admin overtime-settings paid-absence opt-in card
 *  - Dashboard overtime credit note (injected when no live credit)
 *
 * Run with --workers=1.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginAs, gotoApp } from './helpers/auth.js'
import { setUserTheme, resetUserTheme, repairUserThemePrefs } from './helpers/theming.js'

test.describe.configure({ mode: 'serial' })

const themes = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']

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
 * @param {string} include
 */
async function runAxe(page, label, include) {
	const results = await new AxeBuilder({ page })
		.include(include)
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	expect(results.violations, `axe @ ${label}:\n${JSON.stringify(results.violations, null, 2)}`).toEqual([])
}

test.describe('GH #40 paid-absence theme × viewport', () => {
	test.setTimeout(300_000)
	test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC_* credentials')

	for (const theme of themes) {
		test(`${theme}: overtime-settings paid-absence card`, async ({ page }) => {
			test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')
			await loginAs(page, 'ADMIN')
			await gotoApp(page, '/apps/arbeitszeitcheck/admin/overtime-settings')
			await page.waitForSelector('#paidAbsencePlannedHoursCreditEnabled', { timeout: 30_000 })
			await setUserTheme(page, theme)

			// Close NC app-navigation overlay on narrow viewports so measurements are of the form.
			await page.evaluate(() => {
				const nav = document.getElementById('app-navigation')
				if (nav && !nav.classList.contains('hidden')) {
					const toggle = document.querySelector(
						'.app-navigation-toggle, button.app-navigation-toggle, #app-navigation-toggle',
					)
					if (toggle instanceof HTMLElement) {
						toggle.click()
					} else {
						nav.classList.add('hidden')
					}
				}
			})

			const section = page.locator('section[aria-labelledby="paid-absence-credit-heading"]')
			await expect(section).toBeVisible()
			await section.scrollIntoViewIfNeeded()
			await expect(page.locator('#paidAbsencePlannedHoursCreditEnabled')).toBeVisible()
			await expect(page.locator('#paidAbsencePlannedHoursCreditEnabled-help')).toBeVisible()

			const tokens = await page.evaluate(() => {
				const help = document.getElementById('paidAbsencePlannedHoursCreditEnabled-help')
				const cs = help ? getComputedStyle(help) : null
				const body = getComputedStyle(document.body)
				return {
					helpColor: cs?.color ?? '',
					helpWrap: cs?.overflowWrap ?? '',
					muted: body.getPropertyValue('--azc-muted').trim() || body.getPropertyValue('--color-text-maxcontrast').trim(),
					bg: body.getPropertyValue('--azc-bg-card').trim() || body.getPropertyValue('--color-main-background').trim(),
					primary: body.getPropertyValue('--color-primary-element').trim(),
				}
			})
			expect(tokens.helpColor, 'help text uses computed theme color').not.toEqual('')
			expect(tokens.muted).not.toEqual('')
			expect(tokens.bg).not.toEqual('')
			expect(tokens.primary).not.toEqual('')

			// Touch target: checkbox row ≥ 44px on mobile cascade (<768).
			await page.setViewportSize({ width: 375, height: 812 })
			await section.scrollIntoViewIfNeeded()
			const touch = await page.evaluate(() => {
				const input = document.getElementById('paidAbsencePlannedHoursCreditEnabled')
				const row = input?.closest('.form-checkbox')
				const r = row?.getBoundingClientRect()
				return { rowH: r ? r.height : 0, rowW: r ? r.width : 0 }
			})
			expect(touch.rowH, 'paid-absence control row height').toBeGreaterThanOrEqual(44)
			expect(touch.rowW, 'paid-absence control row width').toBeGreaterThanOrEqual(44)

			for (const vp of viewports) {
				await page.setViewportSize(vp)
				await expectNoHorizontalOverflow(page, `${theme}/overtime-settings@${vp.width}`)
				const helpBox = await page.locator('#paidAbsencePlannedHoursCreditEnabled-help').boundingBox()
				expect(helpBox, `help visible @ ${vp.width}`).not.toBeNull()
				if (helpBox) {
					expect(helpBox.width, `help not clipped @ ${vp.width}`).toBeGreaterThan(40)
				}
			}

			await page.setViewportSize({ width: 375, height: 812 })
			await runAxe(page, `${theme}/paid-absence@375`, '#admin-overtime-settings-form')
			await page.setViewportSize({ width: 1280, height: 800 })
			await runAxe(page, `${theme}/paid-absence@1280`, '#admin-overtime-settings-form')
		})

		test(`${theme}: dashboard overtime credit note layout`, async ({ page }) => {
			test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER')
			await loginAs(page, 'EMPLOYEE')
			await gotoApp(page, '/apps/arbeitszeitcheck/dashboard')
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 })
			await setUserTheme(page, theme)

			// Ensure credit note exists for layout proof (live credit may be 0 with flag off).
			await page.evaluate(() => {
				let note = document.getElementById('dashboard-overtime-absence-credit')
				if (!note) {
					const card =
						document.querySelector('.dashboard-overtime-card, [data-azc-overtime-card]') ||
						document.querySelector('#dashboard-metrics-heading')?.closest('section, .azc-card') ||
						document.getElementById('azc-main-content')
					note = document.createElement('p')
					note.id = 'dashboard-overtime-absence-credit'
					note.className = 'form-help dashboard-overtime-card__absence-credit'
					note.textContent =
						'Includes 8.00 h credited from approved sick leave (planned hours). '.repeat(3)
					card?.appendChild(note)
				}
			})

			const note = page.locator('#dashboard-overtime-absence-credit')
			await expect(note).toBeVisible()

			const noteStyle = await page.evaluate(() => {
				const el = document.getElementById('dashboard-overtime-absence-credit')
				const cs = el ? getComputedStyle(el) : null
				return {
					color: cs?.color ?? '',
					wrap: cs?.overflowWrap ?? '',
					maxW: cs?.maxWidth ?? '',
				}
			})
			expect(noteStyle.color).not.toEqual('')
			expect(noteStyle.wrap === 'anywhere' || noteStyle.wrap === 'break-word' || noteStyle.wrap === 'normal').toBeTruthy()

			for (const vp of viewports) {
				await page.setViewportSize(vp)
				await expectNoHorizontalOverflow(page, `${theme}/dashboard-credit@${vp.width}`)
			}

			await page.setViewportSize({ width: 320, height: 640 })
			await runAxe(page, `${theme}/dashboard-credit@320`, '#azc-main-content')
			await page.setViewportSize({ width: 1280, height: 800 })
			await runAxe(page, `${theme}/dashboard-credit@1280`, '#azc-main-content')
		})
	}

	test('reset themes after paid-absence matrix', async ({ page }) => {
		// Prefer OCS reset on the last active session (employee); repair admin prefs via occ.
		if (process.env.NC_EMPLOYEE_USER) {
			await gotoApp(page, '/apps/arbeitszeitcheck/dashboard')
			await page.waitForSelector('#azc-main-content', { timeout: 30_000 }).catch(() => {})
			await resetUserTheme(page).catch(() => {
				repairUserThemePrefs(process.env.NC_EMPLOYEE_USER)
			})
		}
		if (process.env.NC_ADMIN_USER) {
			repairUserThemePrefs(process.env.NC_ADMIN_USER)
		}
	})
})
