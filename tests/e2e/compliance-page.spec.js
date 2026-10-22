// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginAs } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.describe('Compliance page is one screen', () => {
	test('employee sees their list and report, without admin tools or subpage links', async ({ page }) => {
		await loginAs(page, 'EMPLOYEE')
		await page.goto('/apps/arbeitszeitcheck/compliance')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#compliance-status-heading')).toBeVisible()
		await expect(page.locator('#compliance-reports-heading')).toBeVisible()
		await expect(page.locator('#violations-table')).toBeVisible()
		await expect(page.locator('.compliance-tabs')).toHaveCount(0)
		await expect(page.locator('.azc-help-more')).toHaveCount(0)
		await expect(page.locator('#btn-run-compliance-check')).toHaveCount(0)
		await expect(page.locator('#app-content')).not.toContainText(/Administrator only|Nur für Administrierende/i)
		await expect(page.locator('#app-content a[href*="/compliance/violations"]')).toHaveCount(0)
		await expect(page.locator('#app-content a[href*="/compliance/reports"]')).toHaveCount(0)

		const results = await new AxeBuilder({ page })
			.include('#app-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('old subpage addresses land on this page', async ({ page }) => {
		await loginAs(page, 'EMPLOYEE')
		// Merged contract: /compliance/violations stays a real page
		// (whole-scope discovery for managers; own list for employees).
		await page.goto('/apps/arbeitszeitcheck/compliance/violations')
		await expect(page.locator('#violations-table')).toBeVisible()

		await page.goto('/apps/arbeitszeitcheck/compliance/reports')
		await expect(page).toHaveURL(/\/apps\/arbeitszeitcheck\/compliance#compliance-status$/)
		await expect(page.locator('#compliance-reports-heading')).toBeVisible()
	})

	test('the report figures stack on a narrow screen without sideways scrolling', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 700 })
		await loginAs(page, 'EMPLOYEE')
		await page.goto('/apps/arbeitszeitcheck/compliance')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('.compliance-status__figure').first()).toBeVisible()
		const overflow = await page.evaluate(() => {
			const content = document.getElementById('app-content')
			if (!content) {
				return 999
			}
			return content.scrollWidth - content.clientWidth
		})
		expect(overflow).toBeLessThanOrEqual(1)
	})

	test('an administrator still sees the manual scan', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await page.goto('/apps/arbeitszeitcheck/compliance')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#btn-run-compliance-check')).toBeVisible()
		await expect(page.locator('#compliance-run-check-help')).toBeVisible()
		await expect(page.locator('#violations-table')).toBeVisible()
		await expect(page.locator('.compliance-tabs')).toHaveCount(0)
	})
})
