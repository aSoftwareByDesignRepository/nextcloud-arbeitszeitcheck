/**
 * Kraft follow-ups: manager pending time-entry tile focuses Time entries tab.
 */
import { test, expect } from '@playwright/test'
import { loginAs, skipIfMissingCreds } from './helpers/auth.js'

test.describe('Manager pending time entries tile (F3)', () => {
	test.beforeEach(() => {
		skipIfMissingCreds('ADMIN')
	})

	test('tile exists and activates time-entries tab', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await page.goto('/apps/arbeitszeitcheck/manager')
		await page.waitForSelector('.manager-dashboard__stats', { timeout: 30000 })

		const timeTile = page.locator('[data-manager-pending-tab="time-entries"]')
		await expect(timeTile).toBeVisible()
		await expect(timeTile).toHaveAttribute('aria-label', /pending time entr/i)

		await timeTile.click()
		const tab = page.locator('#tab-time-entries')
		await expect(tab).toHaveAttribute('aria-selected', 'true')
		const panel = page.locator('#pending-time-entries-panel')
		await expect(panel).not.toHaveClass(/pending-approvals-panel--hidden/)
	})
})
