import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

test.describe('Admin employees — atomic profile save', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('PUT profile saves all sections without partial failure', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))

		const userId = 'e2e_employee'
		const body = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${userId}`)
		expect(body.success).toBe(true)
		const user = body.user
		expect(user).toBeTruthy()

		const startDate = user.workingTimeModelStartDate || user.userWorkingTimeModel?.startDate || '2025-03-27'
		const policy = user.vacationPolicy || {}
		const vacationMode = policy.vacationMode || 'inherit'
		const inheritLowerLayers = vacationMode === 'inherit' || !!policy.inheritLowerLayers
		const manualDays = inheritLowerLayers ? null : (policy.manualDays ?? 30)
		const policyId = policy.id ?? null
		const policyEffectiveFrom = policy.effectiveFrom || startDate
		const loadedStart = startDate
		const effectiveFrom =
			policyId && policyEffectiveFrom && loadedStart && startDate === loadedStart
				? policyEffectiveFrom
				: startDate

		const payload = {
			workingTimeModel: {
				workingTimeModelId: user.workingTimeModel?.id ?? user.userWorkingTimeModel?.workingTimeModelId ?? 1,
				vacationDaysPerYear: user.vacationDaysPerYear ?? 28,
				vacationCarryoverDays: user.vacationCarryoverDays ?? 0,
				vacationCarryoverYear: user.vacationCarryoverYear ?? new Date().getFullYear(),
				startDate,
				endDate: user.workingTimeModelEndDate || user.userWorkingTimeModel?.endDate || null,
				germanState: user.germanState || 'NW',
			},
			vacationPolicy: {
				policyId,
				vacationMode,
				inheritLowerLayers,
				manualDays,
				tariffRuleSetId: policy.tariffRuleSetId ?? null,
				overrideReason: policy.overrideReason ?? '',
				effectiveFrom,
				effectiveTo: user.workingTimeModelEndDate || user.userWorkingTimeModel?.endDate || null,
			},
			timeCapture: (() => {
				const preferences = user.timeCapture?.preferences || user.timeCapture || {}
				return {
					clockStampingEnabled: preferences.clockStampingEnabled !== false,
					manualTimeEntryEnabled: preferences.manualTimeEntryEnabled !== false,
				}
			})(),
			overtime: {
				trackingFrom: user.overtimeTrackingFrom || null,
				openingBalance: {
					year: user.overtimeOpeningBalanceYear ?? new Date().getFullYear(),
					hours: String(user.overtimeOpeningBalanceHours ?? 0),
				},
			},
		}

		const result = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${userId}/profile`, {
			data: payload,
		})
		expect(result.success).toBe(true)
		expect(result.userWorkingTimeModel !== undefined || result.policyId !== undefined).toBeTruthy()
	})

	test('carryover-only profile update persists without model change', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))

		const userId = 'e2e_employee'
		const body = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${userId}`)
		expect(body.success).toBe(true)
		const user = body.user
		expect(user).toBeTruthy()

		const startDate = user.workingTimeModelStartDate || user.userWorkingTimeModel?.startDate || '2025-03-27'
		const year = user.vacationCarryoverYear ?? new Date().getFullYear()
		const marker = 3.25

		const payload = {
			workingTimeModel: {
				workingTimeModelId: user.workingTimeModel?.id ?? user.userWorkingTimeModel?.workingTimeModelId ?? 1,
				vacationDaysPerYear: user.vacationDaysPerYear ?? 28,
				vacationCarryoverDays: marker,
				vacationCarryoverYear: year,
				startDate,
				endDate: user.workingTimeModelEndDate || user.userWorkingTimeModel?.endDate || null,
				germanState: user.germanState || 'NW',
			},
			vacationPolicy: {
				policyId: user.vacationPolicy?.id ?? null,
				vacationMode: user.vacationPolicy?.vacationMode || 'inherit',
				inheritLowerLayers: true,
				manualDays: null,
				effectiveFrom: startDate,
				effectiveTo: null,
			},
			timeCapture: {
				clockStampingEnabled: true,
				manualTimeEntryEnabled: true,
			},
			overtime: {
				trackingFrom: user.overtimeTrackingFrom || null,
				openingBalance: {
					year,
					hours: String(user.overtimeOpeningBalanceHours ?? 0),
				},
			},
		}

		const put = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${userId}/profile`, {
			data: payload,
		})
		expect(put.success).toBe(true)

		const reloaded = await api(
			page,
			'GET',
			`/apps/arbeitszeitcheck/api/admin/users/${userId}?carryoverYear=${year}`,
		)
		expect(reloaded.success).toBe(true)
		expect(Number(reloaded.user.vacationCarryoverDays)).toBe(marker)
		expect(Number(reloaded.user.vacationCarryoverYear)).toBe(year)

		const otYear = year - 1
		const otMarker = 6.5
		const otPut = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${userId}/profile`, {
			data: {
				...payload,
				workingTimeModel: {
					...payload.workingTimeModel,
					vacationCarryoverDays: marker,
				},
				overtime: {
					trackingFrom: user.overtimeTrackingFrom || null,
					openingBalance: { year: otYear, hours: String(otMarker) },
				},
			},
		})
		expect(otPut.success).toBe(true)
		expect(Number(otPut.overtimeOpeningBalanceYear)).toBe(otYear)
		expect(Number(otPut.overtimeOpeningBalanceHours)).toBe(otMarker)

		const otReloaded = await api(
			page,
			'GET',
			`/apps/arbeitszeitcheck/api/admin/users/${userId}?overtimeOpeningBalanceYear=${otYear}`,
		)
		expect(otReloaded.success).toBe(true)
		expect(Number(otReloaded.user.overtimeOpeningBalanceYear)).toBe(otYear)
		expect(Number(otReloaded.user.overtimeOpeningBalanceHours)).toBe(otMarker)
	})

	test('individual contract hours: personal model round-trip', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))

		const userId = 'e2e_employee'
		const before = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${userId}`)
		expect(before.success).toBe(true)
		const startDate =
			before.user.workingTimeModelStartDate ||
			before.user.userWorkingTimeModel?.startDate ||
			'2025-03-27'

		// Restore target must be a *global* model — the user may still carry a
		// personal one from an earlier run, which is a different id space.
		const modelList = await api(page, 'GET', '/apps/arbeitszeitcheck/api/admin/working-time-models')
		expect(modelList.success).toBe(true)
		const globalId =
			!before.user.workingTimeModel?.isPersonal && before.user.workingTimeModel?.id
				? before.user.workingTimeModel.id
				: (modelList.models || [])[0]?.id
		expect(globalId).toBeTruthy()

		const schedule = {
			version: 1,
			days: {
				mon: { work: true, start: '08:00', end: '15:00', breaks: [{ start: '12:00', end: '12:30', paid: false }] },
				tue: { work: true, start: '08:00', end: '15:00', breaks: [{ start: '12:00', end: '12:30', paid: false }] },
				wed: { work: true, start: '08:00', end: '15:00', breaks: [{ start: '12:00', end: '12:30', paid: false }] },
				thu: { work: true, start: '08:00', end: '15:00', breaks: [{ start: '12:00', end: '12:30', paid: false }] },
				fri: { work: true, start: '08:00', end: '13:00', breaks: [] },
				sat: { work: false },
				sun: { work: false },
			},
		}

		// Enable custom contract hours — must create+assign a personal model.
		const enable = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${userId}/profile`, {
			data: {
				workingTimeModel: {
					startDate,
					germanState: before.user.germanState || 'NW',
					customModel: {
						enabled: 'true',
						weeklyHours: 30,
						dailyHours: 6,
						workDaysPerWeek: 5,
						weekdaySchedule: schedule,
					},
				},
			},
		})
		expect(enable.success).toBe(true)

		const after = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${userId}`)
		expect(after.success).toBe(true)
		expect(after.user.workingTimeModel?.isPersonal).toBe(true)
		const personalId = after.user.workingTimeModel.id
		expect(personalId).not.toBe(globalId)
		expect(after.user.workingTimeModel.weekdaySchedule?.days?.mon?.work).toBe(true)

		// Isolation: the personal model must not leak into the org model list.
		const models = await api(page, 'GET', '/apps/arbeitszeitcheck/api/admin/working-time-models')
		expect(models.success).toBe(true)
		const listedIds = (models.models || []).map((m) => m.id)
		expect(listedIds).not.toContain(personalId)

		// And the global model CRUD must 404 on the personal id (no oracle).
		const direct = await apiAllowFailure(page, 'GET', `/apps/arbeitszeitcheck/api/admin/working-time-models/${personalId}`)
		expect(direct.status).toBe(404)
		expect(direct.json?.success).toBe(false)

		// Toggle custom hours off — the employee returns to a global model and
		// the now-unreferenced personal model is pruned.
		const disable = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${userId}/profile`, {
			data: {
				workingTimeModel: {
					workingTimeModelId: globalId,
					startDate,
					germanState: before.user.germanState || 'NW',
					customModel: { enabled: 'false' },
				},
			},
		})
		expect(disable.success).toBe(true)

		const restored = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${userId}`)
		expect(restored.success).toBe(true)
		expect(restored.user.workingTimeModel?.isPersonal).toBe(false)
		expect(Number(restored.user.workingTimeModel?.id)).toBe(Number(globalId))
	})
})
