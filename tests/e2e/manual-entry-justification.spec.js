import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.describe('Manual time entry justification (four-eyes)', () => {
	test.skip(!process.env.NC_ADMIN_USER || !process.env.NC_EMPLOYEE_USER, 'Requires NC_ADMIN_USER and NC_EMPLOYEE_USER')

	test('create form shows justification when approval is required and submits pending entry', async ({ browser }) => {
		const adminContext = await browser.newContext()
		const employeeContext = await browser.newContext()
		const adminPage = await adminContext.newPage()
		const employeePage = await employeeContext.newPage()

		let restoreApproval = false
		try {
			await login(adminPage, credsFromEnv('ADMIN'))

			const before = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
			expect(before.success).toBe(true)
			restoreApproval = before.settings?.manualTimeEntriesRequireApproval === true

			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: {
					settings_section: 'time-approvals',
					manualTimeEntriesRequireApproval: true,
				},
			})
			// Ensure manual create stays enabled (separate settings page).
			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: {
					manualTimeEntryEnabled: true,
				},
			})

			const after = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
			expect(after.settings?.manualTimeEntriesRequireApproval).toBe(true)

			await login(employeePage, credsFromEnv('EMPLOYEE'))
			await employeePage.goto('/apps/arbeitszeitcheck/time-entries/create')
			await assertArbeitszeitcheckLoaded(employeePage)

			const justification = employeePage.locator('#entry-justification')
			await expect(justification).toBeVisible()
			await expect(justification).toHaveAttribute('aria-required', 'true')
			await expect(employeePage.locator('.time-entries-page__workflow-callout')).toContainText(/approval/i)

			// Client-side gate: save without justification must not leave the page blank of errors.
			await employeePage.locator('#submit-button').click()
			await expect(justification).toHaveAttribute('aria-invalid', 'true')

			const reason = 'Forgot to clock out after the client visit.'
			await justification.fill(reason)
			await expect(employeePage.locator('#entry-justification-count')).toContainText(/10\+|submit/i)

			// Prefer API submit for the persistence proof (datepicker is readonly; form
			// times collide with prior E2E/fixture rows). UI proof above already covers
			// the missing-field bug; API proves justification is accepted end-to-end.
			const stamp = Date.now()
			const startH = String(22 + (stamp % 2)).padStart(2, '0')
			const startM = String((stamp % 12) * 5).padStart(2, '0')
			const endM = String(Math.min(55, parseInt(startM, 10) + 25)).padStart(2, '0')
			const isoDate = new Date().toISOString().slice(0, 10)
			const create = await api(employeePage, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
				data: {
					date: isoDate,
					startTime: `${startH}:${startM}`,
					endTime: `${startH}:${endM}`,
					justification: reason,
				},
			})
			expect(create.success).toBe(true)
			expect(String(create.message || '')).toMatch(/approval/i)
			expect(['pending_approval', 'completed']).toContain(create.entry?.status)
		} finally {
			try {
				await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
					data: {
						settings_section: 'time-approvals',
						manualTimeEntriesRequireApproval: restoreApproval,
					},
				})
			} catch {
				// best-effort restore
			}
			await adminContext.close()
			await employeeContext.close()
		}
	})

	test('create form hides justification when approval is off', async ({ browser }) => {
		const adminContext = await browser.newContext()
		const employeeContext = await browser.newContext()
		const adminPage = await adminContext.newPage()
		const employeePage = await employeeContext.newPage()

		let restoreApproval = false
		try {
			await login(adminPage, credsFromEnv('ADMIN'))
			const before = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
			restoreApproval = before.settings?.manualTimeEntriesRequireApproval === true

			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: {
					settings_section: 'time-approvals',
					manualTimeEntriesRequireApproval: false,
				},
			})

			await login(employeePage, credsFromEnv('EMPLOYEE'))
			await employeePage.goto('/apps/arbeitszeitcheck/time-entries/create')
			await assertArbeitszeitcheckLoaded(employeePage)
			await expect(employeePage.locator('#entry-justification')).toHaveCount(0)
		} finally {
			try {
				await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
					data: {
						settings_section: 'time-approvals',
						manualTimeEntriesRequireApproval: restoreApproval,
					},
				})
			} catch {
				// best-effort restore
			}
			await adminContext.close()
			await employeeContext.close()
		}
	})
})
