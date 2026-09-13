import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

/** Every My settings section + a primary control that proves the page rendered. */
const SECTIONS = [
	{ id: 'breaks', marker: '#hours-display' },
	{ id: 'notifications', marker: '#notifications-enabled' },
	{ id: 'data-privacy', marker: '#btn-gdpr-delete' },
	{ id: 'about', marker: '#settings-compliance-heading' },
]

test.describe('Employee My settings multipage', () => {
	test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER')

	test('clear hierarchy: one sidebar item + grouped topic chips', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/settings')
		await page.waitForURL(/\/settings\/breaks/)
		await assertArbeitszeitcheckLoaded(page)

		await expect(page.locator('.nav-submenu--settings-sections')).toHaveCount(0)
		await expect(
			page.locator('#app-navigation a[href*="/settings"]').filter({ hasText: /My settings|Meine Einstellungen/i }).first(),
		).toBeVisible()

		const topics = page.locator('#azc-employee-settings-pages')
		await expect(topics.locator('.azc-settings-nav__title')).toBeVisible()
		await expect(topics.locator('.azc-settings-nav__group')).toHaveCount(2)
		await expect(topics.locator('.azc-settings-nav__link')).toHaveCount(SECTIONS.length)
		await expect(topics.locator('.azc-settings-nav__link.is-active')).toHaveAttribute('aria-current', 'page')

		const chipBox = await topics.boundingBox()
		const mainBox = await page.locator('#azc-main-content').boundingBox()
		expect(chipBox && mainBox && chipBox.width).toBeGreaterThan((mainBox?.width ?? 0) * 0.55)

		await expect(page.locator('#auto-break-calculation')).toBeAttached()
		await expect(page.locator('#notifications-enabled')).toHaveCount(0)
		await expect(page.locator('.settings-container a').filter({ hasText: /Cancel|Abbrechen/i })).toHaveCount(0)
		await expect(page.locator('.settings-form__actions a')).toHaveCount(0)

		const crumb = page.locator('.azc-breadcrumb')
		await expect(crumb.locator('a.azc-breadcrumb__link[href*="/settings/breaks"]')).toBeVisible()

		await page.locator('#azc-employee-settings-pages a[href*="/settings/notifications"]').click()
		await page.waitForURL(/\/settings\/notifications/)
		await expect(page.locator('#notifications-enabled')).toBeAttached()
		await expect(page.locator('#auto-break-calculation')).toHaveCount(0)
		await expect(page.locator('#notification-settings-form button[type="submit"]')).toHaveAttribute(
			'aria-label',
			/Save this page|Diese Seite speichern/i,
		)
	})

	test('legacy hash on breaks path forwards to owning section', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/settings/breaks#settings-notifications-heading')
		await page.waitForURL(/\/settings\/notifications#settings-notifications-heading/)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#settings-notifications-heading')).toBeAttached()
	})

	test('breaks page is one job (prefs + model note, no privacy)', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/settings/breaks')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#auto-break-calculation')).toBeVisible()
		await expect(page.locator('#working-time-model-info')).toBeVisible()
		await expect(page.locator('#btn-gdpr-delete')).toHaveCount(0)
		await expect(page.locator('#settings-compliance-heading')).toHaveCount(0)
	})

	for (const section of SECTIONS) {
		test(`works: ${section.id} loads marker`, async ({ page }) => {
			await login(page, credsFromEnv('EMPLOYEE'))
			await page.goto(`/apps/arbeitszeitcheck/settings/${section.id}`)
			await assertArbeitszeitcheckLoaded(page)
			await expect(page.locator(section.marker).first()).toBeAttached()
			await expect(
				page.locator(`#azc-employee-settings-pages a[href*="/settings/${section.id}"]`),
			).toHaveAttribute('aria-current', 'page')
		})

		test(`axe clean: ${section.id}`, async ({ page }) => {
			await login(page, credsFromEnv('EMPLOYEE'))
			await page.goto(`/apps/arbeitszeitcheck/settings/${section.id}`)
			await assertArbeitszeitcheckLoaded(page)
			await expect(page.locator('#azc-employee-settings-pages')).toBeVisible()

			const results = await new AxeBuilder({ page })
				.include('#app-content')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
				.analyze()
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
		})
	}
})
