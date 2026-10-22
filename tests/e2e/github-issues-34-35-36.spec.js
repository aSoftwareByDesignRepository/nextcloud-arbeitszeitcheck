// @ts-check
/**
 * GitHub #34 / #35 / #36 — minute step, Sunday work, hours display (legacy-safe).
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.describe('GitHub #34–#36 time UX (legacy-safe)', () => {
	test('J-GH34: admin Hours & rest exposes minute step; default every minute on create form', async ({ page }) => {
		test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')
		await login(page, credsFromEnv('ADMIN'))

		await page.goto('/apps/arbeitszeitcheck/admin/settings/hours')
		await assertArbeitszeitcheckLoaded(page)
		const step = page.locator('#timePickerMinuteStep')
		await expect(step).toBeVisible()
		await expect(step.locator('option[value="1"]')).toHaveCount(1)
		await expect(step.locator('option[value="5"]')).toHaveCount(1)
		await expect(step.locator('option[value="15"]')).toHaveCount(1)
		/* Fresh installs / unset config → 1 (every minute) */
		await expect(step).toHaveValue('1')

		await page.goto('/apps/arbeitszeitcheck/time-entries/create')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForFunction(() => {
			const cfg = window.ArbeitszeitCheck && window.ArbeitszeitCheck.timeEntryForm
			return cfg && typeof cfg.minuteStep === 'number'
		}, null, { timeout: 15000 })
		const minuteStep = await page.evaluate(() => window.ArbeitszeitCheck.timeEntryForm.minuteStep)
		expect(minuteStep).toBe(1)

		const minuteSelect = page.locator('#entry-start-time-minute')
		await expect(minuteSelect).toBeVisible()
		const values = await minuteSelect.locator('option').evaluateAll((opts) =>
			opts.map((o) => o.value).filter((v) => v !== ''),
		)
		expect(values).toContain('51')
		expect(values.length).toBeGreaterThanOrEqual(60)
		await page.screenshot({ path: 'test-results/azc-gh34-minute-step.png', fullPage: false })
	})

	test('J-GH35: working-time model editor has Sunday work permitted checkbox', async ({ page }) => {
		test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
		await assertArbeitszeitcheckLoaded(page)
		await page.locator('#create-model').click()
		await expect(page.locator('#wtm-model-form')).toBeVisible()
		const sunday = page.locator('#wtm-model-allow-sunday-work')
		await expect(sunday).toBeVisible()
		await expect(sunday).not.toBeChecked()
		await expect(page.locator('#wtm-model-allow-sunday-work-help')).toBeVisible()
		await sunday.check()
		await expect(sunday).toBeChecked()
		await page.screenshot({ path: 'test-results/azc-gh35-sunday-work.png', fullPage: false })
	})

	test('J-GH36: Breaks settings offers hours display decimal vs h/m', async ({ page }) => {
		test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/settings/breaks')
		await assertArbeitszeitcheckLoaded(page)
		const hours = page.locator('#hours-display')
		await expect(hours).toBeVisible()
		await expect(hours.locator('option[value="decimal"]')).toHaveCount(1)
		await expect(hours.locator('option[value="hours_minutes"]')).toHaveCount(1)

		await hours.selectOption('hours_minutes')
		await page.locator('#working-time-settings-form button[type="submit"]').click()
		await page.waitForTimeout(1200)
		await page.reload()
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#hours-display')).toHaveValue('hours_minutes')

		await page.goto('/apps/arbeitszeitcheck/')
		await assertArbeitszeitcheckLoaded(page)
		const mode = await page.evaluate(() => window.ArbeitszeitCheck && window.ArbeitszeitCheck.hoursDisplay)
		expect(mode).toBe('hours_minutes')
		const sample = await page.evaluate(() => {
			const u = window.ArbeitszeitCheckUtils
			return u && typeof u.formatHours === 'function' ? u.formatHours(5.5) : null
		})
		expect(sample).toBe('5h 30')
		await page.screenshot({ path: 'test-results/azc-gh36-hours-display.png', fullPage: false })

		/* Restore legacy default */
		await page.goto('/apps/arbeitszeitcheck/settings/breaks')
		await page.locator('#hours-display').selectOption('decimal')
		await page.locator('#working-time-settings-form button[type="submit"]').click()
		await page.waitForTimeout(800)
	})
})
