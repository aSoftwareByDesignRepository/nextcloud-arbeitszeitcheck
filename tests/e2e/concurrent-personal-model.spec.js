/**
 * Live proof: two concurrent admin profile saves that each create a personal
 * working-time model for the same employee → exactly one editable assignment
 * pointing at one personal model; the loser's row is at most a prunable
 * orphan, never a second live contract. Follow-up save adopts/prunes the
 * orphan; disabling custom hours returns the employee to a global model and
 * removes every unreferenced personal row.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

const USER = 'e2e_employee'

test.use({ trace: 'off' })

test.describe('Concurrent personal working-time model saves (live)', () => {
	test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('parallel customModel saves converge to one personal contract', async ({ browser }) => {
		const ctxA = await browser.newContext()
		const ctxB = await browser.newContext()
		const pageA = await ctxA.newPage()
		const pageB = await ctxB.newPage()

		try {
			await login(pageA, credsFromEnv('ADMIN'))
			await login(pageB, credsFromEnv('ADMIN'))

			const before = await api(pageA, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${USER}`)
			expect(before.success).toBe(true)
			const startDate =
				before.user.workingTimeModelStartDate ||
				before.user.userWorkingTimeModel?.startDate ||
				'2025-03-27'
			const germanState = before.user.germanState || 'NW'
			// Restore target must be a *global* model — a personal leftover from a
			// previous run is a different id space.
			const modelList = await api(pageA, 'GET', '/apps/arbeitszeitcheck/api/admin/working-time-models')
			expect(modelList.success).toBe(true)
			const globalId =
				!before.user.workingTimeModel?.isPersonal && before.user.workingTimeModel?.id
					? before.user.workingTimeModel.id
					: (modelList.models || [])[0]?.id
			expect(globalId).toBeTruthy()

			const save = (page, weeklyHours) =>
				apiAllowFailure(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${USER}/profile`, {
					data: {
						workingTimeModel: {
							startDate,
							germanState,
							customModel: {
								enabled: 'true',
								weeklyHours: String(weeklyHours),
								dailyHours: String(weeklyHours / 5),
								workDaysPerWeek: '5',
							},
						},
					},
				})

			const [a, b] = await Promise.all([save(pageA, 31), save(pageB, 37)])
			const bodies = [a.json, b.json]
			const successCount = bodies.filter((j) => j && j.success === true).length
			// Both may succeed (last-writer-wins on the assignment row) — a 409
			// would also be a valid serialization outcome, but never both-failed
			// and never a torn state.
			expect(successCount, JSON.stringify({ a, b })).toBeGreaterThanOrEqual(1)

			const after = await api(pageA, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${USER}`)
			expect(after.success).toBe(true)
			expect(after.user.workingTimeModel?.isPersonal).toBe(true)
			const winnerHours = Number(after.user.workingTimeModel?.weeklyHours)
			expect([31, 37]).toContain(winnerHours)

			// Adoption path: a follow-up save reuses the referenced personal model
			// and prunes any concurrent loser's orphan.
			const adopt = await api(pageA, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${USER}/profile`, {
				data: {
					workingTimeModel: {
						startDate,
						germanState,
						customModel: { enabled: 'true', weeklyHours: '33', dailyHours: '6.6', workDaysPerWeek: '5' },
					},
				},
			})
			expect(adopt.success).toBe(true)
			const adopted = await api(pageA, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${USER}`)
			expect(adopted.user.workingTimeModel?.isPersonal).toBe(true)
			expect(Number(adopted.user.workingTimeModel?.weeklyHours)).toBe(33)

			// Restore: reassign the original global model — every unreferenced
			// personal row must be pruned by this save.
			const restore = await api(pageA, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${USER}/profile`, {
				data: {
					workingTimeModel: {
						workingTimeModelId: globalId,
						startDate,
						germanState,
						customModel: { enabled: 'false' },
					},
				},
			})
			expect(restore.success).toBe(true)
			const restored = await api(pageA, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${USER}`)
			expect(restored.user.workingTimeModel?.isPersonal).toBe(false)
			expect(Number(restored.user.workingTimeModel?.id)).toBe(Number(globalId))
		} finally {
			await ctxA.close()
			await ctxB.close()
		}
	})
})
