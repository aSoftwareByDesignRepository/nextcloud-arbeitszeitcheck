// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

/**
 * Emitted-URL contract: every apiUrl/substitutionApi template the server puts
 * into the page must carry the `/apps/arbeitszeitcheck/` segment.
 *
 * Regression class azc-1.8.0: linkTo() on a non-existent route path silently
 * emitted WEBROOT/arbeitszeitcheck/… (no /apps/), so the UI called
 * POST /arbeitszeitcheck/api/… → 404 in production while e2e passed because
 * specs built URLs manually instead of consuming the emitted config.
 */

const APP_URL_SHAPE = /\/apps\/arbeitszeitcheck\//

for (const [pagePath, configKey] of [
	['/apps/arbeitszeitcheck/time-entries', 'apiUrl'],
	['/apps/arbeitszeitcheck/absences', 'apiUrl'],
	['/apps/arbeitszeitcheck/substitution-requests', 'substitutionApi'],
]) {
	test(`emitted ${configKey} URLs on ${pagePath} carry /apps/ segment`, async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto(pagePath)
		await assertArbeitszeitcheckLoaded(page)

		const entries = await page.evaluate(
			(key) => {
				const out = []
				const walk = (v, keyPath) => {
					if (typeof v === 'string' && v.includes('arbeitszeitcheck')) {
						out.push({ keyPath, url: v })
					} else if (v && typeof v === 'object') {
						for (const [k, child] of Object.entries(v)) {
							walk(child, keyPath ? keyPath + '.' + k : k)
						}
					}
				}
				walk((window.ArbeitszeitCheck || {})[key] || {}, '')
				return out
			},
			configKey,
		)

		expect(entries.length, `no emitted ${configKey} URLs found — config missing or renamed`).toBeGreaterThan(0)

		for (const { keyPath, url } of entries) {
			expect(
				url,
				`${configKey}.${keyPath} = ${JSON.stringify(url)} is missing the /apps/ segment ` +
				`(azc-1.8.0 regression shape: /arbeitszeitcheck/api/… → 404)`,
			).toMatch(APP_URL_SHAPE)
			// Sentinels must survive as complete literal __X__ segments —
			// never URL-encoded or truncated by requirement validation.
			expect(url, `${configKey}.${keyPath} URL-encoded`).not.toMatch(/%[0-9A-Fa-f]{2}/)
			const withoutSentinels = url.replace(/__[A-Z0-9_]+__/g, '')
			expect(
				withoutSentinels,
				`${configKey}.${keyPath} has an incomplete sentinel segment: ${JSON.stringify(url)}`,
			).not.toMatch(/__/)
		}
	})
}
