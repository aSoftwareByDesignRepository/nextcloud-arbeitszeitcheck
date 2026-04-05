import { expect } from '@playwright/test'

/**
 * Set app config via occ, if provided.
 *
 * In Docker setups, run tests using a wrapper that calls occ before Playwright.
 * This helper is only here to document the expectation in code and to keep tests explicit.
 */
export async function assertArbeitszeitcheckLoaded(page) {
  await page.waitForURL(/\/apps\/arbeitszeitcheck(\/|$)/)
  await expect(page.locator('body')).toBeVisible()
}

