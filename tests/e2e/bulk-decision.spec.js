/**
 * Live proof: POST /api/manager/bulk-decision (suite appendix) —
 * atomic approve across mixed pending items, uniform 404 for foreign ids,
 * 409 when an item was already decided. Covers AC-01/02/03.
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

/** Create one pending sick-leave absence for the logged-in employee. */
async function createPendingAbsence(employeePage, stamp, salt) {
	const futureBase = addDays(new Date().toISOString().slice(0, 10), 130 + (stamp % 50) + salt * 60)
	for (let i = 0; i < 60; i++) {
		let startIso = addDays(futureBase, i * 2)
		while (isWeekend(startIso)) {
			startIso = addDays(startIso, 1)
		}
		const res = await apiAllowFailure(employeePage, 'POST', '/apps/arbeitszeitcheck/api/absences', {
			data: {
				type: 'sick_leave',
				start_date: startIso,
				end_date: startIso,
				reason: `Bulk decision e2e ${stamp}-${salt}`,
			},
		})
		if (res.ok && res.json?.success && res.json?.absence?.id) {
			return Number(res.json.absence.id)
		}
	}
	throw new Error('could not create pending absence for bulk-decision spec')
}

async function absenceStatus(employeePage, id) {
	const res = await apiAllowFailure(employeePage, 'GET', `/apps/arbeitszeitcheck/api/absences/${id}`)
	return res.json?.absence?.status ?? null
}

test.use({ trace: 'off' })

test.describe('Manager bulk decision (suite appendix)', () => {
	test.skip(
		!hasCreds('EMPLOYEE') || !hasCreds('MANAGER'),
		'Requires NC_EMPLOYEE_USER / NC_MANAGER_USER',
	)

	test('bulk approve over two absences commits both; foreign id → 404; decided → 409', async ({ browser }) => {
		const employeeContext = await browser.newContext()
		const managerContext = await browser.newContext()
		const employeePage = await employeeContext.newPage()
		const managerPage = await managerContext.newPage()
		const stamp = Date.now()
		const ids = []

		try {
			await login(employeePage, credsFromEnv('EMPLOYEE'))
			await employeePage.goto('/apps/arbeitszeitcheck/absences')
			await assertArbeitszeitcheckLoaded(employeePage)
			await getRequestToken(employeePage)

			await login(managerPage, credsFromEnv('MANAGER'))
			await managerPage.goto('/apps/arbeitszeitcheck/manager')
			await assertArbeitszeitcheckLoaded(managerPage)
			await getRequestToken(managerPage)

			ids.push(await createPendingAbsence(employeePage, stamp, 0))
			ids.push(await createPendingAbsence(employeePage, stamp, 1))

			// AC-1: atomic approve across both pending absences.
			const res = await api(managerPage, 'POST', '/apps/arbeitszeitcheck/api/manager/bulk-decision', {
				data: {
					action: 'approve',
					items: ids.map((id) => ({ type: 'absence', id })),
					comment: 'bulk e2e',
				},
			})
			expect(res.success).toBe(true)
			expect(res.applied).toBe(2)
			for (const id of ids) {
				expect(await absenceStatus(employeePage, id)).toBe('approved')
			}

			// AC-2: replaying the decided ids → 409 already_decided, no mutation.
			const replay = await apiAllowFailure(managerPage, 'POST', '/apps/arbeitszeitcheck/api/manager/bulk-decision', {
				data: { action: 'approve', items: ids.map((id) => ({ type: 'absence', id })) },
			})
			expect(replay.status).toBe(409)
			expect(replay.json?.error_code).toBe('already_decided')

			// AC-3: a foreign/nonexistent id → uniform 404, no mutation.
			const foreign = await apiAllowFailure(managerPage, 'POST', '/apps/arbeitszeitcheck/api/manager/bulk-decision', {
				data: { action: 'approve', items: [{ type: 'absence', id: 99999999 }] },
			})
			expect(foreign.status).toBe(404)

			// Payload contract: unknown type rejected at the boundary.
			const badType = await apiAllowFailure(managerPage, 'POST', '/apps/arbeitszeitcheck/api/manager/bulk-decision', {
				data: { action: 'approve', items: [{ type: 'on_call_shift', id: 1 }] },
			})
			expect(badType.status).toBe(400)
		} finally {
			await employeeContext.close().catch(() => {})
			await managerContext.close().catch(() => {})
		}
	})
})
