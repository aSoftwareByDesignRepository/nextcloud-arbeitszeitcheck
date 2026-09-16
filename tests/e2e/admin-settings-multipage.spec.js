/* global process */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

/** Every Global settings section + a primary control that proves the page rendered. */
const SECTIONS = [
	{ id: 'access', marker: '#accessMode-help, [name="accessRestrictionEnabled"]' },
	{ id: 'compliance', marker: '#autoComplianceCheck' },
	{ id: 'time-recording', marker: '#clockStampingEnabled, #offlineStampMaxPastHours' },
	{ id: 'time-approvals', marker: '#timeEntryChangesRequireApproval' },
	{ id: 'exports', marker: '#exportMidnightSplitEnabled' },
	{ id: 'outlook-subscription', marker: '#section-outlook-subscription-heading' },
	{ id: 'month-closure', marker: '#monthClosureEnabled' },
	{ id: 'hours', marker: '#maxDailyHours' },
	{ id: 'regional', marker: '#country-de' },
	{ id: 'retention', marker: '#retentionPeriod' },
	{ id: 'projectcheck', marker: '#projectCheckIntegrationEnabled, #section-projectcheck-heading' },
]

test.describe('Admin global settings multipage', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')

	test('clear hierarchy: one sidebar item + grouped topic chips', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/settings')
		await page.waitForURL(/\/admin\/settings\/access/)
		await assertArbeitszeitcheckLoaded(page)

		// No menu-in-menu: sidebar must NOT nest section children.
		await expect(page.locator('.nav-submenu--settings-sections')).toHaveCount(0)
		await expect(
			page.locator('#app-navigation a[href*="/admin/settings"]').filter({ hasText: /Global|Globale/i }).first(),
		).toBeVisible()

		const topics = page.locator('#azc-admin-settings-pages')
		await expect(topics.locator('.azc-settings-nav__title')).toBeVisible()
		await expect(topics.locator('.azc-settings-nav__group')).toHaveCount(4)
		await expect(topics.locator('.azc-settings-nav__link')).toHaveCount(SECTIONS.length)
		await expect(topics.locator('.azc-settings-nav__link.is-active')).toHaveAttribute('aria-current', 'page')

		const chipBox = await topics.boundingBox()
		const mainBox = await page.locator('#azc-main-content').boundingBox()
		expect(chipBox && mainBox && chipBox.width).toBeGreaterThan((mainBox?.width ?? 0) * 0.55)

		await expect(page.locator('#section-access-heading')).toBeAttached()
		await expect(page.locator('#section-compliance-heading')).toHaveCount(0)

		const crumb = page.locator('.azc-breadcrumb')
		await expect(crumb.locator('a.azc-breadcrumb__link[href*="/admin/settings/access"]')).toBeVisible()

		await page.locator('#azc-admin-settings-pages a[href*="/admin/settings/compliance"]').click()
		await page.waitForURL(/\/admin\/settings\/compliance/)
		await expect(page.locator('#section-compliance-heading')).toBeAttached()
		await expect(page.locator('#admin-settings-save')).toHaveAttribute(
			'aria-label',
			/Save this page|Diese Seite speichern/i,
		)
		await expect(page.locator('.azc-admin-settings-form__footer a').filter({ hasText: /Cancel|Abbrechen/i })).toHaveCount(0)
	})

	test('legacy hash on index path forwards to owning section', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/settings/access#section-hours-heading')
		await page.waitForURL(/\/admin\/settings\/hours#section-hours-heading/)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#section-hours-heading')).toBeAttached()
	})

	test('approval legacy hash lands on time-approvals page', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/settings/access#section-time-approval-heading')
		await page.waitForURL(/\/admin\/settings\/time-approvals#section-time-approval-heading/)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#timeEntryChangesRequireApproval')).toBeVisible()
		await expect(page.locator('#clockStampingEnabled')).toHaveCount(0)
	})

	test('time-recording page is methods only (one job)', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/settings/time-recording')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#clockStampingEnabled')).toBeAttached()
		await expect(page.locator('#offlineStampMaxPastHours')).toBeVisible()
		await expect(page.locator('#offlineStampMaxPastHours')).toHaveValue(/^(24|48|72)$/)
		await expect(page.locator('.admin-time-capture__card').first()).toBeVisible()
		await expect(page.locator('#timeEntryChangesRequireApproval')).toHaveCount(0)
	})

	test('access page keeps the current rule obvious while toggling open and restricted modes', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/settings/access')
		await assertArbeitszeitcheckLoaded(page)

		const overview = page.locator('.azc-access-overview')
		await expect(overview).toBeVisible()
		await expect(overview.locator('.azc-access-overview__item')).toHaveCount(4)

		const modeSummary = page.locator('#azcAccessModeSummary')
		await expect(modeSummary).toContainText(/Open|Restricted|Offen|Beschränkt/i)

		await page.locator('input[name="accessRestrictionEnabled"][value="1"]').check()
		await expect(modeSummary).toContainText(/Restricted|Beschränkt/i)
		await expect(page.locator('[data-azc-access-allowlists]')).toHaveCount(2)
		await expect(page.locator('[data-azc-access-allowlists]').first()).toBeVisible()
		await expect(page.locator('[data-azc-access-summary-panel]')).toHaveCount(2)
		await expect(page.locator('[data-azc-access-summary-panel]').first()).toBeVisible()

		await page.locator('input[name="accessRestrictionEnabled"][value="0"]').check()
		await expect(modeSummary).toContainText(/Open|Offen/i)
		await expect(page.locator('[data-azc-access-allowlists]').first()).toBeHidden()
		await expect(page.locator('[data-azc-access-summary-panel]').first()).toBeHidden()
	})

	for (const section of SECTIONS) {
		test(`works: ${section.id} loads marker + scoped save payload`, async ({ page }) => {
			await login(page, credsFromEnv('ADMIN'))
			await page.goto(`/apps/arbeitszeitcheck/admin/settings/${section.id}`)
			await assertArbeitszeitcheckLoaded(page)
			await expect(page.locator('#admin-settings-form')).toBeVisible()
			await expect(page.locator(`#admin-settings-form [name="settings_section"]`)).toHaveValue(section.id)
			await expect(page.locator(section.marker).first()).toBeAttached()
			if (section.id === 'projectcheck') {
				const switchOrMissing = page.locator(
					'#projectCheckIntegrationEnabled, #azc-projectcheck-app-required',
				)
				await expect(switchOrMissing.first()).toBeAttached()
				const sw = page.locator('#projectCheckIntegrationEnabled')
				if ((await sw.count()) > 0) {
					await expect(sw).toHaveAttribute('role', 'switch')
					const box = await sw.evaluate((el) => {
						const label = el.closest('.azc-switch-field')
						return label ? label.getBoundingClientRect().height : 0
					})
					expect(box).toBeGreaterThanOrEqual(44)
				}
			}

			// Active chip matches this section; foreign section markers stay absent when possible.
			await expect(
				page.locator(`#azc-admin-settings-pages a[href*="/admin/settings/${section.id}"]`),
			).toHaveAttribute('aria-current', 'page')

			let saveBody = ''
			await page.route('**/api/admin/settings**', async (route) => {
				const req = route.request()
				if (req.method() === 'POST') {
					saveBody = req.postData() || ''
					await route.fulfill({
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify({ success: true, message: 'ok' }),
					})
					return
				}
				await route.continue()
			})

			// Outlook subscription uses dedicated create/rotate APIs — no sticky Save footer.
			const save = page.locator('#admin-settings-save')
			if ((await save.count()) === 0) {
				expect(section.id).toBe('outlook-subscription')
				return
			}

			await save.click()
			await expect
				.poll(() => {
					try {
						const parsed = JSON.parse(saveBody)
						return String(parsed.settings_section || '') === section.id
					} catch {
						return (
							saveBody.includes(`settings_section=${section.id}`) ||
							saveBody.includes(`"settings_section":"${section.id}"`)
						)
					}
				})
				.toBeTruthy()
		})

		test(`axe clean: ${section.id}`, async ({ page }) => {
			await login(page, credsFromEnv('ADMIN'))
			await page.goto(`/apps/arbeitszeitcheck/admin/settings/${section.id}`)
			await assertArbeitszeitcheckLoaded(page)
			await expect(page.locator('#admin-settings-form')).toBeVisible()

			const results = await new AxeBuilder({ page })
				.include('#app-content')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
				.analyze()
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
		})
	}
})
