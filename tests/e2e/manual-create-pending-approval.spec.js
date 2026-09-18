/**
 * Four-eyes manual create: manager pending card + reject must not complete hours.
 *
 * Proves the customer-reported empty-card bug and the Absolute No-Go where
 * reject(manual_create) previously flipped the row to completed.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

function addDays(isoDate, days) {
	const d = new Date(`${isoDate}T00:00:00Z`)
	d.setUTCDate(d.getUTCDate() + days)
	return d.toISOString().slice(0, 10)
}

test.describe('Manual create pending approval (manager)', () => {
	test.skip(
		!hasCreds('ADMIN') || !hasCreds('EMPLOYEE') || !hasCreds('MANAGER'),
		'Requires NC_ADMIN_USER, NC_EMPLOYEE_USER, NC_MANAGER_USER',
	)

	test('pending card shows summary fields; reject marks rejected not completed', async ({ browser }) => {
		const adminContext = await browser.newContext()
		const employeeContext = await browser.newContext()
		const managerContext = await browser.newContext()
		const adminPage = await adminContext.newPage()
		const employeePage = await employeeContext.newPage()
		const managerPage = await managerContext.newPage()

		let restoreApproval = false
		let entryId = null

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
			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: { manualTimeEntryEnabled: true },
			})

			await login(employeePage, credsFromEnv('EMPLOYEE'))

			const stamp = Date.now()
			const reason = `Atlas four-eyes reject proof ${stamp}`
			const base = addDays('1991-03-01', stamp % 500)
			let created = null
			let lastErr = ''
			for (let i = 0; i < 50; i++) {
				const date = addDays(base, i)
				const startH = String(10 + (i % 6)).padStart(2, '0')
				const res = await apiAllowFailure(employeePage, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
					data: {
						date,
						startTime: `${startH}:00`,
						endTime: `${startH}:45`,
						justification: reason,
						description: `atlas-manual-${stamp}`,
					},
				})
				if (res.ok && res.json?.success) {
					created = res.json
					break
				}
				lastErr = res.json?.error || `HTTP ${res.status}`
				const err = String(lastErr).toLowerCase()
				if (!err.includes('overlap') && !err.includes('month')) {
					// keep retrying on overlap; fail fast on other hard errors after a few tries
					if (i > 5 && !err.includes('overlap')) {
						throw new Error(lastErr)
					}
				}
			}
			expect(created?.success, lastErr || 'create failed').toBe(true)
			expect(created.entry?.status).toBe('pending_approval')
			entryId = created.entry?.id
			expect(entryId).toBeTruthy()

			await login(managerPage, credsFromEnv('MANAGER'))

			const pending = await api(
				managerPage,
				'GET',
				'/apps/arbeitszeitcheck/api/manager/pending-approvals?type=time_entry',
			)
			expect(pending.success).toBe(true)
			const row = (pending.pendingApprovals || []).find((item) => Number(item.id) === Number(entryId))
			expect(row, 'pending list must include the new manual entry').toBeTruthy()
			expect(row.type).toBe('time_entry')
			expect(row.summary?.requestType).toBe('manual_create')
			expect(row.summary?.justification).toBe(reason)
			expect(String(row.summary?.date || '')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(String(row.summary?.startTime || '')).toMatch(/^\d{1,2}:\d{2}$/)
			expect(String(row.summary?.endTime || '')).toMatch(/^\d{1,2}:\d{2}$/)

			await managerPage.goto('/apps/arbeitszeitcheck/manager')
			await assertArbeitszeitcheckLoaded(managerPage)

			const timeTab = managerPage.locator('#tab-time-entries')
			await expect(timeTab).toBeVisible({ timeout: 20000 })
			await timeTab.click()

			const card = managerPage.locator(`.pending-approval-card[data-time-entry-id="${entryId}"]`)
			await expect(card).toBeVisible({ timeout: 20000 })
			await expect(card).toHaveAttribute('data-request-type', 'manual_create')
			await expect(card).toContainText(/New manual time entry|Neuer manueller Zeiteintrag/i)
			await expect(card).not.toContainText(/manager-correction-diff/)
			await expect(card.locator('.pending-approval-card__reason')).toContainText(reason)
			await expect(card.locator('.pending-approval-card__facts')).toBeVisible()
			await expect(card.locator('.pending-approval-card__meta')).not.toHaveText(/^$/)

			await card.locator('.btn-reject-time-entry').click()
			const rejectModal = managerPage.getByRole('dialog', { name: /Reject Request|Ablehnen|Rechazar/i })
			await expect(rejectModal).toBeVisible()
			await rejectModal.locator('.btn-reject-modal-confirm').click()

			await expect(card).toHaveCount(0, { timeout: 15000 })

			const empView = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/time-entries/${entryId}`)
			expect(empView.success).toBe(true)
			expect(empView.entry?.status).toBe('rejected')
			expect(empView.entry?.status).not.toBe('completed')

			const pendingAfter = await api(
				managerPage,
				'GET',
				'/apps/arbeitszeitcheck/api/manager/pending-approvals?type=time_entry',
			)
			const stillPending = (pendingAfter.pendingApprovals || []).some(
				(item) => Number(item.id) === Number(entryId),
			)
			expect(stillPending).toBe(false)
		} finally {
			try {
				await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
					data: {
						settings_section: 'time-approvals',
						manualTimeEntriesRequireApproval: restoreApproval,
					},
				})
			} catch {
				// best-effort
			}
			await adminContext.close()
			await employeeContext.close()
			await managerContext.close()
		}
	})

	test('manager approve completes manual create', async ({ browser }) => {
		const adminContext = await browser.newContext()
		const employeeContext = await browser.newContext()
		const managerContext = await browser.newContext()
		const adminPage = await adminContext.newPage()
		const employeePage = await employeeContext.newPage()
		const managerPage = await managerContext.newPage()

		let restoreApproval = false

		try {
			await login(adminPage, credsFromEnv('ADMIN'))
			const before = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
			restoreApproval = before.settings?.manualTimeEntriesRequireApproval === true
			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: {
					settings_section: 'time-approvals',
					manualTimeEntriesRequireApproval: true,
				},
			})
			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: { manualTimeEntryEnabled: true },
			})

			await login(employeePage, credsFromEnv('EMPLOYEE'))
			const stamp = Date.now()
			const reason = `Atlas four-eyes approve proof ${stamp}`
			const base = addDays('1992-07-01', stamp % 500)
			let created = null
			for (let i = 0; i < 50; i++) {
				const date = addDays(base, i)
				const startH = String(11 + (i % 5)).padStart(2, '0')
				const res = await apiAllowFailure(employeePage, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
					data: {
						date,
						startTime: `${startH}:15`,
						endTime: `${startH}:50`,
						justification: reason,
					},
				})
				if (res.ok && res.json?.success) {
					created = res.json
					break
				}
			}
			expect(created?.success).toBe(true)
			expect(created.entry?.status).toBe('pending_approval')
			const entryId = created.entry.id

			await login(managerPage, credsFromEnv('MANAGER'))
			const approved = await api(
				managerPage,
				'POST',
				`/apps/arbeitszeitcheck/api/manager/time-entries/${entryId}/approve-correction`,
				{ data: { comment: 'Looks good' } },
			)
			expect(approved.success).toBe(true)

			const empView = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/time-entries/${entryId}`)
			expect(empView.success).toBe(true)
			expect(empView.entry?.status).toBe('completed')
			expect(empView.entry?.approvedByUserId || empView.entry?.approvedBy).toBeTruthy()
		} finally {
			try {
				await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
					data: {
						settings_section: 'time-approvals',
						manualTimeEntriesRequireApproval: restoreApproval,
					},
				})
			} catch {
				// best-effort
			}
			await adminContext.close()
			await employeeContext.close()
			await managerContext.close()
		}
	})
})
