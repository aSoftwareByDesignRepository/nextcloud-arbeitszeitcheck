/**
 * Live hammer: Saldo PDF UserRateLimit(30/60) must eventually return HTTP 429.
 * Proves the attribute is wired through the Nextcloud middleware, not only reflection.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { getRequestToken } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.describe('Rate-limit hammer (live)', () => {
	test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

	test('overtime balance PDF returns 429 after UserRateLimit window', async ({ page }) => {
		test.setTimeout(180_000)
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/dashboard')
		await assertArbeitszeitcheckLoaded(page)
		await getRequestToken(page)

		const url = '/apps/arbeitszeitcheck/api/overtime/balance-pdf'
		let saw429 = false
		const statuses = []

		// Limit is 30/60s — send a burst past the ceiling.
		for (let i = 0; i < 40; i++) {
			const res = await page.request.get(url)
			statuses.push(res.status())
			if (res.status() === 429) {
				saw429 = true
				break
			}
		}

		expect(saw429, `expected 429 within 40 PDF requests; statuses=${statuses.join(',')}`).toBe(true)
	})
})
