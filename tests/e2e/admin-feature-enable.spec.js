// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'

const APP = '/apps/arbeitszeitcheck'

// Real click-through of the two feature-enable workflows an admin must be
// able to reach: special leave entitlements and business trips (plus the
// time-account credit postings that depend on the trip workflow being on).
// Idempotent: if a flag is already enabled the test verifies state instead
// of clicking again — the shared E2E instance keeps the flag between runs.
test.describe('admin feature enable workflows', () => {
	test('special leave entitlements enable via confirm dialog', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(`${APP}/admin/special-entitlements`)
		const root = page.locator('#se-admin-root')
		if ((await root.getAttribute('data-enabled')) !== '1') {
			const enable = page.locator('#se-enable')
			await expect(enable).toBeVisible()
			await enable.click()
			await page
				.getByRole('alertdialog')
				.locator('.confirm-dialog__confirm')
				.click()
			await page.waitForLoadState('load')
		}
		await expect(root).toHaveAttribute('data-enabled', '1')
		// Enabled surface must be functional, not just flagged.
		await expect(page.locator('#se-type-list')).toBeVisible()
	})

	test('business trips + credit postings enable in dependency order', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(`${APP}/admin/business-trips`)
		const root = page.locator('#bta-root')
		if ((await root.getAttribute('data-enabled')) !== '1') {
			const enable = page.locator('#bta-enable')
			await expect(enable).toBeVisible()
			await enable.click()
			await page
				.getByRole('alertdialog')
				.locator('.confirm-dialog__confirm')
				.click()
			await page.waitForLoadState('load')
		}
		await expect(root).toHaveAttribute('data-enabled', '1')

		// Credit postings gate on the trip workflow — the button only shows
		// once the feature is on and credit is still off.
		const credit = page.locator('#bta-enable-credit')
		if (await credit.isVisible()) {
			await credit.click()
			await page
				.getByRole('alertdialog')
				.locator('.confirm-dialog__confirm')
				.click()
			await page.waitForLoadState('load')
		}
		// Fully enabled surface: rule-set management section renders.
		await expect(page.locator('#bta-enabled')).toBeVisible()
	})
})
