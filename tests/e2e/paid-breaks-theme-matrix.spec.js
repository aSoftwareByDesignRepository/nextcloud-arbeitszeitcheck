import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { USER_THEMES, setUserTheme, resetUserTheme } from './helpers/theming.js'

/**
 * Lens-3 per-theme proof for the paid-break surfaces inside the working-time
 * model editor panel (cap field, impact-preview callout, inline error). The
 * global theme matrix axes the list route only — the editor panel content is
 * only rendered after clicking create/edit, so it needs its own themed pass.
 *
 * Screenshots land under documentation/arbeitszeitcheck/audits/artifacts/
 * (audit evidence must not live in the ship-only app repo).
 */
test.describe.configure({ mode: 'serial' })

const SHOT_DIR = '../../../documentation/arbeitszeitcheck/audits/artifacts/paid-breaks'

test.describe('Paid-break editor surfaces × theme', () => {
	for (const theme of USER_THEMES) {
		test(`working-time-model editor paid-break controls — ${theme}`, async ({ page }) => {
			test.setTimeout(90000)
			await login(page, credsFromEnv('ADMIN'))
			await setUserTheme(page, theme)
			await page.goto('/index.php/apps/arbeitszeitcheck/admin/working-time-models')
			await page.waitForSelector('#create-model', { timeout: 30000 })

			await page.locator('#create-model').dispatchEvent('click')
			const panel = page.locator('#wtm-editor-panel')
			await expect(panel).toBeVisible({ timeout: 10000 })
			await expect(panel.locator('#wtm-model-form')).toBeVisible()

			const flag = panel.locator('#wtm-model-breaks-count-as-work')
			await flag.check()
			const cap = panel.locator('#wtm-model-paid-break-cap')
			await expect(cap).toBeVisible()
			await cap.fill('45')

			const results = await new AxeBuilder({ page })
				.include('#wtm-editor-panel')
				.withTags(['wcag2a', 'wcag2aa'])
				.analyze()
			expect(
				results.violations,
				`axe violations in WTM editor @ ${theme}: ${JSON.stringify(results.violations.map(v => v.id))}`
			).toEqual([])

			await panel.screenshot({ path: `${SHOT_DIR}/paid-breaks-theme-${theme}.png` })
		})
	}

	test.afterAll(async ({ browser }) => {
		const page = await (await browser.newContext({ storageState: undefined })).newPage()
		try {
			await login(page, credsFromEnv('ADMIN'))
			await resetUserTheme(page)
		} finally {
			await page.context().close()
		}
	})
})
