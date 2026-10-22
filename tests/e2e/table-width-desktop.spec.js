// @ts-check
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api } from './helpers/api.js'
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

	test('Time entries: row actions reachable on laptop width', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

		await page.setViewportSize(LAPTOP)
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/time-entries')
		await page.waitForSelector('#time-entries-table', { timeout: 30000 })

		// Issue #12 contract: actions reachable at laptop width — either fully
		// visible or via overflow-x scroll. The shared-footer layout now fits
		// the columns at 1440, so scroll is optional, not required.
		const metrics = await collectDesktopTableMetrics(page, '#time-entries-table')
		assertDesktopTableMetrics(expect, metrics, { requireFullyVisibleActions: false })
	})

	/**
	 * Manager → People overview at 1280 (azc-vis-people-actions-column-clipped-1280):
	 * the Details column is the rows' only interactive control — it must render
	 * fully inside the card at the critic-repro width, not behind overflow-x.
	 */
	test('Manager → People overview: actions column visible at 1280', async ({ page }) => {
		test.skip(!process.env.NC_MANAGER_USER, 'Requires NC_MANAGER_USER / NC_MANAGER_PASS')

		await page.setViewportSize({ width: 1280, height: 900 })
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		await expect(page.locator('#app-content-wrapper')).toHaveClass(/azc-shell--wide/)
		await expect(page.locator('#manager-people-body tr').first()).toBeVisible({ timeout: 30000 })

		assertDesktopTableMetrics(
			expect,
			await collectDesktopTableMetrics(page, '.manager-scope-page__results-table'),
		)
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
		// Seed a completed entry inside the default range first: the docker seed only
		// backdates stale sessions (rest-period probe) and ambient data ages out of the
		// 30-day window, which would leave the results wrap permanently hidden.
		const seedEmployee = process.env.NC_EMPLOYEE_USER || 'e2e_employee'
		const day = new Date(Date.now() - 86400000)
		const seedDate = `${String(day.getDate()).padStart(2, '0')}.${String(day.getMonth() + 1).padStart(2, '0')}.${day.getFullYear()}`
		try {
			await api(page, 'POST', '/apps/arbeitszeitcheck/api/manager/employee-time-entries', {
				data: {
					userId: seedEmployee,
					date: seedDate,
					startTime: '08:00',
					endTime: '12:00',
					reason: 'E2E table-width seed entry',
				},
			})
		} catch (err) {
			// Seed via API may legitimately fail (approval workflows, overlap) — the
			// empty-state path below still verifies scope width without row data.
			console.warn(`table-width seed skipped: ${err}`)
		}

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
