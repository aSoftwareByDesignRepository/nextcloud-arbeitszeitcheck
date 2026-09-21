/**
 * Live proof: two concurrent manager decisions on one pending absence → exactly one
 * winner in DB; loser gets HTTP 409 already_decided (not last-write-wins / 500).
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure, getRequestToken } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

function addDays(isoDate, days) {
	const d = new Date(`${isoDate}T00:00:00Z`)
	d.setUTCDate(d.getUTCDate() + days)
	return d.toISOString().slice(0, 10)
}

function isWeekend(isoDate) {
	const dow = new Date(`${isoDate}T12:00:00Z`).getUTCDay()
	return dow === 0 || dow === 6
}

test.describe('Concurrent pending absence decision (live)', () => {
	test.skip(
		!hasCreds('EMPLOYEE') || !hasCreds('MANAGER'),
		'Requires NC_EMPLOYEE_USER / NC_MANAGER_USER',
	)

	test('parallel approve+reject → one DB decision and one 409', async ({ browser }) => {
		const employeeContext = await browser.newContext()
		const managerContext = await browser.newContext()
		const employeePage = await employeeContext.newPage()
		const managerPage = await managerContext.newPage()

		let absenceId = null

		try {
			await login(employeePage, credsFromEnv('EMPLOYEE'))
			await employeePage.goto('/apps/arbeitszeitcheck/absences')
			await assertArbeitszeitcheckLoaded(employeePage)
			await getRequestToken(employeePage)

			const stamp = Date.now()
			const futureBase = addDays(new Date().toISOString().slice(0, 10), 120 + (stamp % 60))
			let created = null
			let lastErr = ''
			for (let i = 0; i < 40; i++) {
				let startIso = addDays(futureBase, i * 2)
				while (isWeekend(startIso)) {
					startIso = addDays(startIso, 1)
				}
				const res = await apiAllowFailure(employeePage, 'POST', '/apps/arbeitszeitcheck/api/absences', {
					data: {
						type: 'sick_leave',
						start_date: startIso,
						end_date: startIso,
						reason: `Atlas concurrent absence race ${stamp}`,
					},
				})
				if (res.ok && res.json?.success) {
					created = res.json
					break
				}
				lastErr = res.json?.error || `HTTP ${res.status}`
				const err = String(lastErr).toLowerCase()
				if (!err.includes('overlap') && !err.includes('working day')) {
					throw new Error(lastErr || `Failed to seed absence (${res.status})`)
				}
			}
			expect(created?.success, lastErr || 'no free absence window').toBe(true)
			absenceId = created.absence?.id
			expect(absenceId).toBeTruthy()

			const before = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/absences/${absenceId}`)
			const statusBefore =
				before?.absence?.status ?? before?.data?.status ?? before?.status
			expect(statusBefore).toBe('pending')

			await login(managerPage, credsFromEnv('MANAGER'))
			await managerPage.goto('/apps/arbeitszeitcheck/manager')
			await assertArbeitszeitcheckLoaded(managerPage)
			await getRequestToken(managerPage)

			const approveUrl = `/apps/arbeitszeitcheck/api/manager/absences/${absenceId}/approve`
			const rejectUrl = `/apps/arbeitszeitcheck/api/manager/absences/${absenceId}/reject`

			const [a, b] = await Promise.all([
				apiAllowFailure(managerPage, 'POST', approveUrl, { data: { comment: 'concurrent winner probe' } }),
				apiAllowFailure(managerPage, 'POST', rejectUrl, { data: { comment: 'concurrent loser probe' } }),
			])

			const bodies = [a.json, b.json]
			const successCount = bodies.filter((j) => j && j.success === true).length
			const conflictCount = [a, b].filter(
				(r) => r.status === 409 || r.json?.error_code === 'already_decided',
			).length

			expect(successCount, JSON.stringify({ a, b })).toBe(1)
			expect(conflictCount, JSON.stringify({ a, b })).toBe(1)
			expect([a.status, b.status].filter((s) => s === 200 || s === 409).length).toBe(2)

			const after = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/absences/${absenceId}`)
			const finalStatus =
				after?.absence?.status ?? after?.data?.status ?? after?.status
			expect(['approved', 'rejected']).toContain(finalStatus)
			expect(finalStatus).not.toBe('pending')
		} finally {
			await employeeContext.close()
			await managerContext.close()
		}
	})
})
