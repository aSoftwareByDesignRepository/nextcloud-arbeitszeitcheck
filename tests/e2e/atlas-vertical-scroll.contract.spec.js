// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall admin license page must scroll to seat table end.
 * Guards CSS Overflow L3 unpaired overflow-x:clip truncating the seats block.
 */
import { createRequire } from 'module'
import { test } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'

const require = createRequire(import.meta.url)
const { assertAtlasVerticalScrollReachable } = require('../../../_shared/e2e/atlas-vertical-scroll-contract.js')

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('admin license page scrolls to seat table end', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 640 })
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/license', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#azc-license-page, .azc-access-denied', { timeout: 45_000 })
		test.skip((await page.locator('.azc-access-denied').count()) > 0, 'License access denied')
		await page.waitForSelector('#azc-license-page', { timeout: 30_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#azc-seat-count, .azc-license-seats-table, #azc-license-clear, #azc-license-page',
			bottomSlopPx: 12,
		})
	})

	test('admin license stays reachable at phone height', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 667 })
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/license', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#azc-license-page, .azc-access-denied', { timeout: 45_000 })
		test.skip((await page.locator('.azc-access-denied').count()) > 0, 'License access denied')
		await page.waitForSelector('#azc-license-page', { timeout: 30_000 })

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#azc-seat-count, .azc-license-seats-table, #azc-license-clear, #azc-license-page',
			bottomSlopPx: 16,
		})
	})
})
