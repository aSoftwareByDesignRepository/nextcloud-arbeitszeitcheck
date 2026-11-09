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
