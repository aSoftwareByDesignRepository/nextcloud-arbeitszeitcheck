/**
 * Slice A/B UX: compact help, fields-first form, 5-min steps, Saldo PDF.
 * Live Docker journeys when NC_EMPLOYEE_* works; fixture a11y always runs.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import path from 'path'
import { fileURLToPath } from 'url'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const fixtureUrl = 'file://' + path.resolve(__dirname, 'fixtures/lebenswelt-ux-slice-ab.html')

test.describe('Slice A/B Lebenswelt UX (fixture a11y)', () => {
	test('form fields precede summary; PDF control; axe clean', async ({ page }) => {
		await page.goto(fixtureUrl)
		const primary = page.locator('.time-entry-form__primary')
		const summary = page.locator('#time-summary')
		const primaryBox = await primary.boundingBox()
		const summaryBox = await summary.boundingBox()
		expect(primaryBox.y).toBeLessThan(summaryBox.y)
		await expect(page.locator('#entry-start-time-type')).toBeVisible()
		await expect(page.locator('#dashboard-overtime-balance-pdf')).toHaveAttribute('href', /balance-pdf/)

		const results = await new AxeBuilder({ page })
			.include('#azc-main-content')
			.withTags(['wcag2a', 'wcag2aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
})

test.describe('Slice A/B Lebenswelt UX (live)', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')
		await login(page, credsFromEnv('EMPLOYEE'))
	})

	test('manual create: fields before summary, 5-min minutes, free type', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/time-entries/create')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#time-entry-form', { timeout: 30000 })

		const primary = page.locator('.time-entry-form__primary')
		const summary = page.locator('#time-summary')
		await expect(primary).toBeVisible()
		await expect(summary).toBeVisible()

		const primaryBox = await primary.boundingBox()
		const summaryBox = await summary.boundingBox()
		expect(primaryBox.y).toBeLessThan(summaryBox.y)

		const minuteStep = await page.evaluate(() => {
			const cfg = window.ArbeitszeitCheck?.timeEntryForm
			return cfg && typeof cfg.minuteStep === 'number' ? cfg.minuteStep : 5
		})
		const minuteValues = await page.locator('#entry-start-time-minute option').evaluateAll(
			(opts) => opts.map((o) => o.value).filter(Boolean)
		)
		expect(minuteValues).toContain('00')
		if (minuteStep === 1) {
			expect(minuteValues).toContain('01')
			expect(minuteValues).toContain('05')
		} else {
			expect(minuteValues).toContain(String(minuteStep).padStart(2, '0'))
			expect(minuteValues).not.toContain('01')
		}

		await page.locator('#entry-start-time-type').fill('08:07')
		await page.locator('#entry-start-time-type').blur()
		await expect(page.locator('#entry-start-time')).toHaveValue('08:07')
		await expect(page.locator('#entry-start-time-minute')).toHaveValue('07')

		const results = await new AxeBuilder({ page })
			.include('#time-entry-form')
			.withTags(['wcag2a', 'wcag2aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('dashboard Saldo PDF control is present and reachable', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/dashboard')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#dashboard-overtime-heading', { timeout: 30000 })
		const pdf = page.locator('#dashboard-overtime-balance-pdf')
		await expect(pdf).toBeVisible()
		await expect(pdf).toHaveAttribute('href', /balance-pdf/)

		const results = await new AxeBuilder({ page })
			.include('.dashboard-overtime-card')
			.withTags(['wcag2a', 'wcag2aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
})
