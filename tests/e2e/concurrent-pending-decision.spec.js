/**
 * Live proof: two concurrent manager decisions on one pending entry → exactly one
 * winner in DB; the loser is serialized — either HTTP 409 already_decided (lost
 * the CAS check after the winner committed) or HTTP 423 locked (lost the
 * per-entity mutation lock before reaching it). Both outcomes prove no
 * last-write-wins. The losing request is then replayed: it must observe the
 * decided state (already_decided/409), proving the lock released and the
 * decision is final.
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

test.use({ trace: 'off' }) // manual browser.newContext() calls race Playwright's trace-chunk flush on close (ENOENT *.trace/*.network) — screenshots/video still retained on failure

test.describe('Concurrent pending time-entry decision (live)', () => {
	test.skip(
		!hasCreds('ADMIN') || !hasCreds('EMPLOYEE') || !hasCreds('MANAGER'),
		'Requires NC_ADMIN_USER, NC_EMPLOYEE_USER, NC_MANAGER_USER',
	)

	test('parallel approve+reject → one DB decision, loser serialized, replay sees already_decided', async ({ browser }) => {
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
			const reason = `Atlas concurrent race ${stamp}`
			const base = addDays('1992-04-01', stamp % 400)
			let created = null
			for (let i = 0; i < 40; i++) {
				const date = addDays(base, i)
				const startH = String(11 + (i % 5)).padStart(2, '0')
				const res = await apiAllowFailure(employeePage, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
					data: {
						date,
						startTime: `${startH}:00`,
						endTime: `${startH}:45`,
						justification: reason,
					},
				})
				if (res.ok) {
					created = res.json
					break
				}
			}
			expect(created, 'seed pending manual create').toBeTruthy()
			entryId =
				created?.data?.id ?? created?.id ?? created?.entry?.id ?? null
			expect(entryId).toBeTruthy()

			const statusBefore = await api(
				employeePage,
				'GET',
				`/apps/arbeitszeitcheck/api/time-entries/${entryId}`,
			)
			const entryStatus =
				statusBefore?.data?.status ?? statusBefore?.entry?.status ?? statusBefore?.status
			expect(entryStatus).toBe('pending_approval')

			await login(managerPage, credsFromEnv('MANAGER'))
			await managerPage.goto('/apps/arbeitszeitcheck/manager')
			await assertArbeitszeitcheckLoaded(managerPage)
			await getRequestToken(managerPage)

			const approveUrl = `/apps/arbeitszeitcheck/api/manager/time-entries/${entryId}/approve-correction`
			const rejectUrl = `/apps/arbeitszeitcheck/api/manager/time-entries/${entryId}/reject-correction`

			const [a, b] = await Promise.all([
				apiAllowFailure(managerPage, 'POST', approveUrl, { data: {} }),
				apiAllowFailure(managerPage, 'POST', rejectUrl, { data: { reason: 'concurrent loser' } }),
			])

			const bodies = [a.json, b.json]
			const successCount = bodies.filter((j) => j && j.success === true).length
			const isLoser = (r) =>
				r.status === 409 ||
				r.status === 423 ||
				r.json?.error_code === 'already_decided' ||
				r.json?.error_code === 'locked'
			const conflictCount = [a, b].filter(isLoser).length

			expect(successCount, JSON.stringify({ a, b })).toBe(1)
			expect(conflictCount, JSON.stringify({ a, b })).toBe(1)
			expect(
				[a.status, b.status].filter((s) => s === 200 || s === 409 || s === 423).length,
			).toBe(2)

			// Replay the losing decision: the lock must be released and the
			// committed decision must reject it — 409/already_decided, never a
			// second success (CAS still guards after lock release).
			const loser = a.json?.success !== true ? { res: a, url: approveUrl } : { res: b, url: rejectUrl }
			const replay = await apiAllowFailure(managerPage, 'POST', loser.url, {
				data: loser.url === rejectUrl ? { reason: 'concurrent loser' } : {},
			})
			expect(
				replay.status === 409 || replay.json?.error_code === 'already_decided',
				JSON.stringify({ a, b, replay }),
			).toBe(true)
			expect(replay.json?.success, JSON.stringify({ a, b, replay })).not.toBe(true)

			const after = await api(
				employeePage,
				'GET',
				`/apps/arbeitszeitcheck/api/time-entries/${entryId}`,
			)
			const finalStatus =
				after?.data?.status ?? after?.entry?.status ?? after?.status
			expect(['completed', 'rejected']).toContain(finalStatus)
			expect(finalStatus).not.toBe('pending_approval')
		} finally {
			if (restoreApproval === false) {
				await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
					data: {
						settings_section: 'time-approvals',
						manualTimeEntriesRequireApproval: false,
					},
				}).catch(() => {})
			}
			await adminContext.close()
			await employeeContext.close()
			await managerContext.close()
		}
	})
})
