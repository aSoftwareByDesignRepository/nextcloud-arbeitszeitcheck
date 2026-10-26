import { test, expect } from '@playwright/test'
import { login, credsFromEnv, gotoApp } from './helpers/auth.js'

/**
 * Issue #48 — Eintrittsdatum (employment start) must be typeable and the
 * calendar must offer year/month jumps. Employees hired in 2009 cannot
 * wait through ~200 month-step clicks.
 *
 * The spec only edits the DOM field — it never submits the form, so the
 * shared e2e_employee profile is left untouched.
 */
test.describe('Employee edit — employment start date', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	const field = () => '#user-employment-start'

	test('typed date and year jump work on the employee form', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users/e2e_employee')
		await page.waitForSelector(field(), { timeout: 30_000 })

		const input = page.locator(field())

		// 1) The field is a normal editable input, not a readonly picker facade.
		await expect(input).toBeEditable()

		// 2) Typing a short date normalizes to dd.mm.yyyy on commit.
		await input.click()
		await input.fill('1.3.09')
		await input.press('Tab')
		await expect(input).toHaveValue('01.03.2009')
		await expect(input).not.toHaveAttribute('aria-invalid', 'true')

		// 3) Calendar popup exposes month + year selects; jump straight to 2009.
		// (After Tab the focus sits on the toggle, still inside the widget, so
		// the popup may already be open — only click the toggle when it isn't.)
		const hostEl = page.locator('.azc-datepicker-host', { has: input })
		const popup = page.locator('.arbeitszeitcheck-datepicker')
		if ((await popup.count()) === 0) {
			await hostEl.locator('.azc-datepicker-toggle').click()
		}
		await expect(popup).toBeVisible()
		await expect(popup.locator('.arbeitszeitcheck-datepicker__month-select')).toBeVisible()
		const yearSelect = popup.locator('.arbeitszeitcheck-datepicker__year-select')
		await expect(yearSelect).toBeVisible()
		await expect(yearSelect.locator('option[value="2009"]')).toHaveCount(1)

		await yearSelect.selectOption('2009')
		await popup.locator('.arbeitszeitcheck-datepicker__month-select').selectOption('0') // January
		await popup.locator('[data-day="2009-01-15"]').click()
		await expect(input).toHaveValue('15.01.2009')
		await expect(popup).toHaveCount(0)
	})

	test('invalid typed date shows an inline field error', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/users/e2e_employee')
		await page.waitForSelector(field(), { timeout: 30_000 })

		const input = page.locator(field())
		await input.click()
		await input.fill('40.13.2000')
		await input.press('Tab')
		await expect(input).toHaveAttribute('aria-invalid', 'true')
		await expect(page.locator('.field-error', { hasText: /./ })).toBeVisible()

		// Fixing the value clears the error.
		await input.fill('5.6.2009')
		await input.press('Tab')
		await expect(input).toHaveValue('05.06.2009')
		await expect(input).not.toHaveAttribute('aria-invalid', 'true')
	})
})
