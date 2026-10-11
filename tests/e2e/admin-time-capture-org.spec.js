/* global process */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.use({ trace: 'off' }) // manual browser.newContext() calls race Playwright's trace-chunk flush on close (ENOENT *.trace/*.network) — screenshots/video still retained on failure

test.describe('Organisation time recording methods', () => {
	test.skip(!process.env.NC_ADMIN_USER || !process.env.NC_EMPLOYEE_USER, 'Requires NC_ADMIN_USER and NC_EMPLOYEE_USER')

	test('disabling org stamping hides punch clock and blocks clock-in API', async ({ browser }) => {
		const adminContext = await browser.newContext()
		const employeeContext = await browser.newContext()
		const adminPage = await adminContext.newPage()
		const employeePage = await employeeContext.newPage()
		// Hoisted so `finally` can restore even when the test failed mid-toggle —
		// a leaked clockStampingEnabled=false poisons every downstream clock-in.
		let restoreClock = true
		let restoreManual = true
		let toggled = false
		const restore = async () => {
			if (!toggled) return
			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: {
					clockStampingEnabled: restoreClock,
					manualTimeEntryEnabled: restoreManual,
				},
			})
			toggled = false
		}

		try {
			await login(adminPage, credsFromEnv('ADMIN'))

			const before = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
			expect(before.success).toBe(true)
			restoreClock = before.settings?.clockStampingEnabled !== false
			restoreManual = before.settings?.manualTimeEntryEnabled !== false

			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: {
					clockStampingEnabled: false,
					manualTimeEntryEnabled: true,
				},
			})
			toggled = true

			const after = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
			expect(after.settings?.clockStampingEnabled).toBe(false)
			expect(after.settings?.manualTimeEntryEnabled).toBe(true)

			await login(employeePage, credsFromEnv('EMPLOYEE'))

			// Another lane may legitimately hold an active session for this
			// shared employee fixture (e.g. the momos double-clock-in race).
			// Completing it right before the clock-in assert ensures we reach
			// the stamping-disabled check instead of a 400 already_clocked_in.
			const completeActive = async () => {
				const st = await apiAllowFailure(employeePage, 'GET', '/apps/arbeitszeitcheck/api/clock/status')
				const id = st.json?.status?.entry?.id ?? st.json?.status?.currentEntry?.id ?? st.json?.status?.entryId
				if (id) {
					await apiAllowFailure(employeePage, 'POST', `/apps/arbeitszeitcheck/api/time-entries/${id}/complete`, { data: {} })
				}
			}

			const widget = await api(employeePage, 'GET', '/apps/arbeitszeitcheck/api/dashboard-widget/employee')
			expect(widget.success).toBe(true)
			expect(widget.data?.timeCapture?.clockStampingEnabled).toBe(false)
			expect(widget.data?.timeCapture?.manualTimeEntryEnabled).toBe(true)

			// Bounded retry: a concurrent lane may have just opened a session
			// after our normalize; complete it once more and retry rather than
			// mistaking already_clocked_in for the disabled-stamping path.
			let clockIn = null
			for (let attempt = 0; attempt < 2; attempt++) {
				await completeActive()
				clockIn = await apiAllowFailure(employeePage, 'POST', '/apps/arbeitszeitcheck/api/clock/in', {
					data: {},
				})
				if (clockIn.json?.error_code !== 'already_clocked_in') break
			}
			expect(clockIn.ok, `clockIn payload: ${JSON.stringify(clockIn.json)}`).toBe(false)
			expect(clockIn.status, `clockIn payload: ${JSON.stringify(clockIn.json)}`).toBe(403)
			expect(clockIn.json?.error_code).toBe('clock_stamping_disabled')

			await employeePage.goto('/apps/arbeitszeitcheck/dashboard')
			await assertArbeitszeitcheckLoaded(employeePage)
			await expect(employeePage.locator('#btn-clock-in')).toHaveCount(0)

			await restore()
		} finally {
			// Restore must survive assertion failures — leaked
			// clockStampingEnabled=false poisons every downstream clock-in spec.
			await restore().catch(() => {})
			await adminContext.close()
			await employeeContext.close()
		}
	})
})
