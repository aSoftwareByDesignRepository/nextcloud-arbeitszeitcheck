// @ts-check
/**
 * Bachus A3: live Working time models — inline editor (no create/edit modal).
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.describe('Working time models inline editor', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')

	test('J-WTM1: New opens inline panel, not a modal', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#create-model')).toBeVisible()

		await page.locator('#create-model').click()
		const panel = page.locator('#wtm-editor-panel')
		await expect(panel).toBeVisible()
		await expect(panel).toHaveAttribute('data-mode', 'create')
		await expect(page.locator('#wtm-model-form')).toBeVisible()
		await expect(page.locator('#wtm-model-type')).toHaveValue('full_time')
		await expect(page.locator('#wtm-model-description')).toBeVisible()
		await expect(page.locator('.wtm-editor-desc')).toHaveCount(0)
		await expect(page.locator('.wtm-weekday-schedule')).toBeVisible()
		await expect(page.locator('#create-model-modal')).toHaveCount(0)
		await expect(page.locator('#edit-model-modal')).toHaveCount(0)

		await page.locator('[data-action="close-editor"]').click()
		await expect(panel).toBeHidden()
		await expect(page.locator('#wtm-model-form')).toHaveCount(0)
		await expect(page.locator('#create-model')).toBeFocused()
	})

	test('J-WTM2: Cancel stays on list (no dashboard navigation)', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
		await assertArbeitszeitcheckLoaded(page)
		await page.locator('#create-model').click()
		await expect(page.locator('#wtm-editor-panel')).toBeVisible()
		await page.locator('[data-action="close-editor"]').click()
		await expect(page).toHaveURL(/\/admin\/working-time-models/)
		await expect(page.locator('#models-table')).toBeVisible()
	})

	test('J-WTM4: on_demand locks contract fields to zero (spec: readonly zeros, human label)', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
		await assertArbeitszeitcheckLoaded(page)
		await page.locator('#create-model').click()
		await expect(page.locator('#wtm-model-form')).toBeVisible()

		// The type option carries the human label, not a bare "0.0 h".
		const option = page.locator('#wtm-model-type option[value="on_demand"]')
		await expect(option).toHaveCount(1)
		await expect(option).toHaveText(/On demand/i)

		await page.locator('#wtm-model-type').selectOption('on_demand')
		for (const name of ['weeklyHours', 'dailyHours', 'workDaysPerWeek']) {
			const field = page.locator(`#wtm-model-form [name="${name}"]`)
			await expect(field).toHaveValue('0')
			await expect(field).toHaveAttribute('readonly', '')
			await expect(field).toHaveAttribute('aria-readonly', 'true')
		}
		// Plain-language explanation replaces the raw zero semantics.
		await expect(page.locator('#wtm-model-type-help')).toBeVisible()
		await expect(page.locator('#wtm-model-type-help')).not.toBeEmpty()

		// Switching back clears the on-demand explanation.
		await page.locator('#wtm-model-type').selectOption('full_time')
		await expect(page.locator('#wtm-model-type-help')).toBeHidden()
	})

	test('J-WTM3: axe clean on open create panel', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
		await assertArbeitszeitcheckLoaded(page)
		await page.locator('#create-model').click()
		await expect(page.locator('#wtm-model-form')).toBeVisible()

		const results = await new AxeBuilder({ page })
			.include('#wtm-editor-panel')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
})
