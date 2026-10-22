/**
 * Kraft F4: audit Offline-Sync filter + badge hooks (a11y smoke).
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginAs, skipIfMissingCreds } from './helpers/auth.js'

test.describe('Audit offline sync filter (F4)', () => {
	test.beforeEach(() => {
		skipIfMissingCreds('ADMIN')
	})

	test('offline sync filter is keyboard reachable and axe-clean on filter card', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await page.goto('/apps/arbeitszeitcheck/admin/audit-log')
		await page.waitForSelector('#offline-sync-filter', { timeout: 30000 })

		const filter = page.locator('#offline-sync-filter')
		await expect(filter).toBeVisible()
		await filter.focus()
		await expect(filter).toBeFocused()
		await filter.check()
		await expect(filter).toBeChecked()

		const results = await new AxeBuilder({ page })
			.include('.audit-log-page__filters')
			.withTags(['wcag2a', 'wcag2aa'])
			.analyze()
		expect(results.violations).toEqual([])
	})
})
