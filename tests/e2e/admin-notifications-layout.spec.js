// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

/**
 * Admin settings IA: Notifications is alerts-only; vacation and overtime live elsewhere.
 */
test.describe('Admin policy settings IA', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('notifications page is alerts-only and aligns labels', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 })
		await login(page, credsFromEnv('ADMIN'))

		const response = await page.goto('/apps/arbeitszeitcheck/admin/notifications')
		expect(response, 'document response').not.toBeNull()
		expect(response.status(), 'notifications must not 500').toBe(200)

		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#azc-main-content')).toBeVisible()
		await expect(page.locator('#admin-notifications-form')).toBeVisible()
		await expect(page.locator('#hr-notifications-heading')).toBeVisible()
		await expect(page.locator('#overtime-trafficlight-heading')).toBeVisible()
		await expect(page.locator('#block-clock-reminders-heading')).toBeVisible()
		await expect(page.locator('#premium-surcharges-heading')).toHaveCount(0)
		await expect(page.locator('#overtime-bank-heading')).toHaveCount(0)
		await expect(page.locator('#vacation-unit-heading')).toHaveCount(0)

		const metrics = await page.evaluate(() => {
			const body = document.body
			const nav = document.getElementById('app-navigation')
			const navLink = document.querySelector('#app-navigation .nav-menu a, #app-navigation .nav-submenu a')
			const label = document.querySelector('#admin-notifications-form .form-checkbox label, #admin-notifications-form .form-checkbox .form-label')
			const guestCss = [...document.styleSheets].some((s) => (s.href || '').includes('guest.css'))
			const policySidebar = [...document.querySelectorAll('#admin-subnav a')].some((a) =>
				/Policy settings|Richtlinieneinstellungen/i.test(a.textContent || '')
			)
			const overtimeChip = !!document.querySelector('#azc-policy-pages a[href*="/admin/overtime-settings"]')
			const vacationChip = !!document.querySelector('#azc-policy-pages a[href*="/admin/vacation-rules"]')
			const entitlementChip = !!document.querySelector('#azc-policy-pages a[href*="/admin/vacation-layers"]')
			return {
				bodyId: body.id,
				guestCss,
				navTextAlign: nav ? getComputedStyle(nav).textAlign : null,
				navLinkTextAlign: navLink ? getComputedStyle(navLink).textAlign : null,
				labelTextAlign: label ? getComputedStyle(label).textAlign : null,
				policySidebar,
				overtimeChip,
				vacationChip,
				entitlementChip,
			}
		})

		expect(metrics.bodyId).toBe('body-user')
		expect(metrics.guestCss).toBe(false)
		expect(['left', 'start']).toContain(metrics.navTextAlign)
		expect(['left', 'start']).toContain(metrics.navLinkTextAlign)
		expect(['left', 'start']).toContain(metrics.labelTextAlign)
		expect(metrics.policySidebar).toBe(true)
		expect(metrics.overtimeChip).toBe(true)
		expect(metrics.vacationChip).toBe(true)
		expect(metrics.entitlementChip).toBe(true)
	})

	test('overtime settings hosts bank and premiums', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 })
		await login(page, credsFromEnv('ADMIN'))
		const response = await page.goto('/apps/arbeitszeitcheck/admin/overtime-settings')
		expect(response.status(), 'overtime settings must not 500').toBe(200)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#admin-overtime-settings-form')).toBeVisible()
		await expect(page.locator('#overtime-bank-heading')).toBeVisible()
		await expect(page.locator('#paid-absence-credit-heading')).toBeVisible()
		await expect(page.locator('#paidAbsencePlannedHoursCreditEnabled')).toBeVisible()
		await expect(page.locator('#paidAbsencePlannedHoursCreditEnabled-help')).toBeVisible()
		await expect(page.locator('#premium-surcharges-heading')).toBeVisible()

		const enable = page.locator('#premiumSurchargesEnabled')
		await enable.scrollIntoViewIfNeeded()
		if (!(await enable.isChecked())) {
			await enable.check()
		}
		await expect(page.locator('#premium-categories-table')).toBeVisible()

		const metrics = await page.evaluate(() => {
			const name = document.querySelector('#premium-cat-ot-name')
			const rate = document.querySelector('#premium-cat-ot-rate')
			const suffix = document.querySelector('#premium-categories-table .premium-cat-rate-suffix')
			const wrap = document.querySelector('#premium-categories-table .premium-cat-rate-wrap')
			const toggle = document.querySelector('#premium-cat-ot-on')
			if (!name || !rate || !suffix || !wrap || !toggle) {
				return { ok: false }
			}
			const nameR = name.getBoundingClientRect()
			const rateR = rate.getBoundingClientRect()
			const suffixR = suffix.getBoundingClientRect()
			const toggleR = toggle.getBoundingClientRect()
			return {
				ok: true,
				nameAlign: getComputedStyle(name).textAlign,
				wrapDisplay: getComputedStyle(wrap).display,
				nameLeftOfRate: nameR.right <= rateR.left + 2,
				toggleLeftOfName: toggleR.right <= nameR.left + 8,
				suffixBesideRate: Math.abs(suffixR.top - rateR.top) < 24 && suffixR.left >= rateR.right - 4,
				rateWidth: rateR.width,
			}
		})
		expect(metrics.ok).toBe(true)
		expect(['left', 'start']).toContain(metrics.nameAlign)
		expect(metrics.wrapDisplay).toContain('flex')
		expect(metrics.nameLeftOfRate).toBe(true)
		expect(metrics.toggleLeftOfName).toBe(true)
		expect(metrics.suffixBesideRate).toBe(true)
		expect(metrics.rateWidth).toBeLessThan(120)

		// Bachus A2: Simple chip applies OT+Sun+Night; Saturday stays off; templates stay collapsed.
		await expect(page.locator('#premium-mode-simple')).toBeVisible()
		await expect(page.locator('#premium-template-picker')).toBeHidden()
		await page.locator('#premium-mode-simple').click()
		await expect(page.locator('#premium-mode-simple')).toHaveAttribute('aria-checked', 'true')
		await expect(page.locator('#premium-cat-ot-on')).toBeChecked()
		await expect(page.locator('#premium-cat-sun-on')).toBeChecked()
		await expect(page.locator('#premium-cat-night-on')).toBeChecked()
		await expect(page.locator('#premium-cat-sat-on')).not.toBeChecked()
		await expect(page.locator('#premium-cat-ot-rate')).toHaveValue('50')
		await expect(page.locator('#premium-cat-sun-rate')).toHaveValue('100')
		await expect(page.locator('#premium-example')).toBeVisible()

		await page.locator('#premium-mode-template').click()
		await expect(page.locator('#premium-template-picker')).toBeVisible()
		await page.locator('#premium-template-picker [data-premium-preset="at"]').click()
		await expect(page.locator('#premium-cat-sat-on')).toBeChecked()

		const axe = await new AxeBuilder({ page })
			.include('#premium-surcharges-section')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.analyze()
		expect(axe.violations, JSON.stringify(axe.violations, null, 2)).toEqual([])
	})

	test('vacation rules page hosts year/unit without notification controls', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 })
		await login(page, credsFromEnv('ADMIN'))
		const response = await page.goto('/apps/arbeitszeitcheck/admin/vacation-rules')
		expect(response.status(), 'vacation rules must not 500').toBe(200)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#admin-vacation-policy-form')).toBeVisible()
		await expect(page.locator('#section-absences-heading')).toBeAttached()
		await expect(page.locator('#vacation-unit-heading')).toBeVisible()
		await expect(page.locator('#vacationProrationMethod')).toBeVisible()
		await expect(page.locator('#hrNotificationsEnabled')).toHaveCount(0)
		await expect(page.locator('#layer-l0')).toHaveCount(0)

		const anniversary = page.locator('#vacationYearMode-anniversary')
		await anniversary.scrollIntoViewIfNeeded()
		await anniversary.click()
		await expect(page.locator('#vacationCarryoverExpiryDay-wrap')).toBeHidden()
	})

	test('vacation entitlement page hosts layers without rules form', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 })
		await login(page, credsFromEnv('ADMIN'))
		const response = await page.goto('/apps/arbeitszeitcheck/admin/vacation-layers')
		expect(response.status(), 'vacation layers must not 500').toBe(200)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#admin-vacation-policy-form')).toHaveCount(0)
		await expect(page.locator('#layer-l0')).toBeVisible()
		await expect(page.locator('#layer-sim')).toBeVisible()
		await expect(page.locator('#vacation-unit-heading')).toHaveCount(0)

		const results = await new AxeBuilder({ page })
			.include('#azc-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('policy hierarchy: one sidebar item, grouped chips, no jump nav, axe clean', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 })
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/notifications')
		await assertArbeitszeitcheckLoaded(page)
		await page.locator('#admin-notifications-form').waitFor({ state: 'visible', timeout: 30000 })

		await expect(page.locator('.azc-jump-nav')).toHaveCount(0)
		await expect(
			page.locator('#app-navigation a').filter({ hasText: /Policy settings|Richtlinieneinstellungen/i }).first(),
		).toBeVisible()
		await expect(page.locator('#app-navigation a').filter({ hasText: /^Notifications$|^Benachrichtigungen$/i })).toHaveCount(0)

		const topics = page.locator('#azc-policy-pages')
		await expect(topics.locator('.azc-settings-nav__title')).toBeVisible()
		await expect(topics.locator('.azc-settings-nav__group')).toHaveCount(3)
		await expect(topics.locator('.azc-settings-nav__link')).toHaveCount(6)
		await expect(topics.locator('.azc-settings-nav__link[aria-current="page"]')).toHaveCount(1)

		const crumb = page.locator('.azc-breadcrumb')
		await expect(crumb.locator('a.azc-breadcrumb__link[href*="/admin/vacation-rules"]')).toBeVisible()

		const results = await new AxeBuilder({ page })
			.include('#azc-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('policy chip bar marks active page and reaches siblings on mobile', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 })
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/notifications')
		await assertArbeitszeitcheckLoaded(page)

		const chips = page.locator('#azc-policy-pages .azc-settings-nav__link')
		await expect(chips).toHaveCount(6)
		const active = page.locator('#azc-policy-pages .azc-settings-nav__link[aria-current="page"]')
		await expect(active).toHaveCount(1)
		await expect(active).toBeVisible()

		const overtimeChip = page.locator('#azc-policy-pages a[href*="/admin/overtime-settings"]')
		await overtimeChip.click()
		await expect(page).toHaveURL(/\/admin\/overtime-settings/)
		await expect(page.locator('#admin-overtime-settings-form')).toBeVisible()
		await expect(page.locator('#azc-policy-pages .azc-settings-nav__link[aria-current="page"]')).toHaveAttribute(
			'href',
			/\/admin\/overtime-settings/,
		)

		const metrics = await page.evaluate(() => {
			const link = document.querySelector('#azc-policy-pages .azc-settings-nav__link[aria-current="page"]')
			if (!link) return { ok: false }
			const r = link.getBoundingClientRect()
			const cs = getComputedStyle(link)
			return {
				ok: true,
				height: r.height,
				width: r.width,
				color: cs.color,
			}
		})
		expect(metrics.ok).toBe(true)
		expect(metrics.height).toBeGreaterThanOrEqual(44)
		expect(metrics.width).toBeGreaterThanOrEqual(44)
	})

	test('legacy notifications#overtime-bank-heading forwards to overtime settings', async ({ page }) => {
		await page.setViewportSize({ width: 1400, height: 900 })
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/notifications#overtime-bank-heading')
		await page.waitForURL(/\/admin\/overtime-settings#overtime-bank-heading/, { timeout: 30000 })
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#overtime-bank-heading')).toBeVisible()
	})
})
