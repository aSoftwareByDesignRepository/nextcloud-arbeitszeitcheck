// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertDesktopTableMetrics, collectDesktopTableMetrics } from './helpers/table-layout.js'

/** Wide desktop — matches issue #12 reports (Firefox on Windows, full browser width). */
const WIDE_DESKTOP = { width: 1920, height: 900 }

/** Narrower laptop — horizontal scroll must still expose actions. */
const LAPTOP = { width: 1440, height: 900 }

test.describe('Desktop table width (#12)', () => {
	test('Admin → Employees: wide shell and visible row actions', async ({ page }) => {
		test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS in tests/e2e/.env')

		await page.setViewportSize(WIDE_DESKTOP)
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/users')
		await page.waitForSelector('#users-table', { timeout: 30000 })

		await expect(page.locator('#app-content-wrapper')).toHaveClass(/azc-shell--wide/)
		assertDesktopTableMetrics(expect, await collectDesktopTableMetrics(page, '#users-table'))

		const lastActionInRow = page.locator('#users-table tbody tr').first().locator('.azc-table-actions .azc-btn, .azc-table-actions .btn').last()
		await expect(lastActionInRow).toBeVisible()
		await expect(lastActionInRow).toBeEnabled()
	})

	test('Time entries: wide shell and actions visible on wide desktop', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

		await page.setViewportSize(WIDE_DESKTOP)
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/time-entries')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		await expect(page.locator('#app-content-wrapper')).toHaveClass(/azc-shell--wide/)

		const table = page.locator('#time-entries-table')
		if (await table.count() > 0) {
			assertDesktopTableMetrics(expect, await collectDesktopTableMetrics(page, '#time-entries-table'))
		} else {
			const widths = await page.evaluate(() => {
				const shell = document.getElementById('app-content-wrapper')
				const list = document.querySelector('.time-entries-page__list')
				if (!shell || !list) {
					return null
				}
				const shellRect = shell.getBoundingClientRect()
				const listRect = list.getBoundingClientRect()
				return { listUsesShell: listRect.width >= shellRect.width * 0.88 }
			})
			expect(widths).not.toBeNull()
			expect(widths.listUsesShell).toBe(true)
		}
	})

	test('Time entries: horizontal scroll exposes actions on laptop width', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

		await page.setViewportSize(LAPTOP)
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/time-entries')
		await page.waitForSelector('#time-entries-table', { timeout: 30000 })

		const metrics = await collectDesktopTableMetrics(page, '#time-entries-table')
		assertDesktopTableMetrics(expect, metrics, { requireFullyVisibleActions: false })
		expect(metrics.needsHorizontalScroll, 'overflow-x scroll region when columns exceed width').toBe(true)
	})

	test('Manager → Employee time entries: no narrow page cap', async ({ page }) => {
		test.skip(!process.env.NC_MANAGER_USER, 'Requires NC_MANAGER_USER / NC_MANAGER_PASS')

		await page.setViewportSize(WIDE_DESKTOP)
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/time-entries')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		await expect(page.locator('#app-content-wrapper')).toHaveClass(/azc-shell--wide/)

		const scopeMetrics = await page.evaluate(() => {
			const scope = document.querySelector('.manager-scope-page')
			const shell = document.getElementById('app-content-wrapper')
			if (!scope || !shell) {
				return null
			}
			const scopeStyle = window.getComputedStyle(scope)
			const scopeRect = scope.getBoundingClientRect()
			const shellRect = shell.getBoundingClientRect()
			const maxWidthPx = parseFloat(scopeStyle.maxWidth)
			return {
				usesShellWidth: scopeRect.width >= shellRect.width * 0.88,
				maxWidthNotCapped: Number.isNaN(maxWidthPx) || maxWidthPx >= shellRect.width * 0.88,
			}
		})
		expect(scopeMetrics).not.toBeNull()
		expect(scopeMetrics.usesShellWidth).toBe(true)
		expect(scopeMetrics.maxWidthNotCapped).toBe(true)

		// Results table is injected after "Show" — scope width is the #12 fix for manager pages.
		const showBtn = page.locator('#employee-time-entries-submit')
		if (await showBtn.isVisible()) {
			await showBtn.click()
			await page.waitForFunction(() => {
				const container = document.querySelector('.manager-scope-page__results .table-container')
				return container && container.getBoundingClientRect().width > 200
			}, { timeout: 15000 })
			const resultsTable = page.locator('.manager-scope-page__results .table-container table')
			if (await resultsTable.count() > 0) {
				assertDesktopTableMetrics(
					expect,
					await collectDesktopTableMetrics(page, '.manager-scope-page__results .table-container table'),
				)
			}
		}
	})
})
