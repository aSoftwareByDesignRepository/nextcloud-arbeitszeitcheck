import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')

async function mount(page) {
	await page.setContent(`<!DOCTYPE html>
		<html lang="en"><head><meta charset="utf-8"><title>Time picker</title></head>
		<body>
			<main>
				<label id="start-label" for="entry-start-time-hour">Start</label>
				<div data-azc-timepicker data-allow-empty="0" data-required="1" data-minute-step="5"
					data-label-id="start-label"
					data-hour-label="Start hour" data-minute-label="Start minute"
					data-open-label="Choose start time" data-hours-label="Hours" data-minutes-label="Minutes">
					<input type="hidden" id="entry-start-time" name="startTime" value="09:00">
				</div>
			</main>
		</body></html>`, { waitUntil: 'domcontentloaded' })
	await page.addStyleTag({ content: `
		:root {
			--color-main-background: #ffffff;
			--color-main-text: #222222;
			--color-primary-element: #00679e;
			--color-primary-element-hover: #00507a;
			--color-primary-element-text: #ffffff;
			--color-primary-element-light-hover: #d9e3e8;
			--color-border: #ededed;
		}
	` })
	await page.addStyleTag({ path: path.join(root, 'css/common/timepicker.css') })
	/* Nextcloud core, loaded after the app sheet. Must not wash the numbers. */
	await page.addStyleTag({ content: `
		button:not(.button-vue):hover,
		button:not(.button-vue):focus {
			background-color: var(--color-primary-element-light-hover);
			color: #ffffff;
		}
	` })
	await page.addScriptTag({ path: path.join(root, 'js/common/timepicker-logic.js') })
	await page.addScriptTag({ path: path.join(root, 'js/common/timepicker.js') })
	await expect(page.locator('#entry-start-time-hour')).toHaveValue('09')
}

test('time picker: type a time, pick from the list, and stay accessible', async ({ page }) => {
	await mount(page)
	const hour = page.locator('#entry-start-time-hour')
	await hour.click()
	await hour.press('1')
	await hour.press('4')
	const minute = page.locator('#entry-start-time-minute')
	await minute.press('3')
	await minute.press('0')
	await expect(page.locator('#entry-start-time')).toHaveValue('14:30')

	await page.locator('#entry-start-time-open').click()
	await expect(page.getByRole('dialog', { name: 'Choose start time' })).toBeVisible()
	const selectedHour = page.locator('[data-column="hour"] [data-value="14"]')
	await expect(selectedHour).toHaveCSS('background-color', 'rgb(0, 80, 122)')
	await expect(selectedHour).toHaveCSS('color', 'rgb(255, 255, 255)')
	await page.locator('[data-column="hour"] [data-value="16"]').hover()
	await expect(page.locator('[data-column="hour"] [data-value="16"]')).toHaveCSS('background-color', 'rgb(0, 80, 122)')
	await expect(page.locator('[data-column="hour"] [data-value="16"]')).toHaveCSS('color', 'rgb(255, 255, 255)')
	const openResults = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.analyze()
	expect(openResults.violations, JSON.stringify(openResults.violations, null, 2)).toEqual([])
	await page.locator('[data-column="hour"] [data-value="16"]').click()
	await page.locator('[data-column="minute"] [data-value="45"]').click()
	await expect(page.locator('#entry-start-time')).toHaveValue('16:45')
	await expect(page.getByRole('dialog')).toHaveCount(0)

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
})

test('time picker: narrow screen uses a dialog with Done', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 })
	await mount(page)
	await page.locator('#entry-start-time-open').click()
	const dialog = page.getByRole('dialog', { name: 'Choose start time' })
	await expect(dialog).toBeVisible()
	await expect(page.getByRole('button', { name: 'Done' })).toBeVisible()
	await page.getByRole('button', { name: 'Done' }).click()
	await expect(dialog).toHaveCount(0)
	await expect(page.locator('#entry-start-time-hour')).toBeVisible()
})
