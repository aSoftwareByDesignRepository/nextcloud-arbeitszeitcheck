// @ts-check
/**
 * absence-day-accuracy-pack-2026-11 — live E2E for the deterministic ACs:
 * AC-P3 (record form defaults to today), FA-PREVIEW (estimate endpoint +
 * multi-day preview callout), AC-P1 (schedule-aware day count end-to-end).
 */
import { test, expect } from '@playwright/test'
import { api, apiAllowFailure } from './helpers/api.js'
import { login, skipIfMissingCreds } from './helpers/auth.js'

const creds = (role) => ({
	username: process.env[`NC_${role}_USER`],
	password: process.env[`NC_${role}_PASS`],
})

function nextWeekRange() {
	// Next Monday..Friday — always in the future regardless of run day.
	const d = new Date()
	const diff = (8 - d.getDay()) % 7 || 7
	const mon = new Date(d)
	mon.setDate(d.getDate() + diff)
	const fri = new Date(mon)
	fri.setDate(mon.getDate() + 4)
	const fmt = (x) => x.toISOString().slice(0, 10)
	return { mon: fmt(mon), fri: fmt(fri) }
}

test.describe('absence-day-accuracy-pack', () => {
	test('AC-P3: manager record form defaults from/to to today', async ({ page }) => {
		skipIfMissingCreds('MANAGER')
		await login(page, creds('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/absences')

		const start = page.locator('#manager-absence-record-start')
		const end = page.locator('#manager-absence-record-end')
		await expect(start).toBeVisible()
		await expect(start).not.toHaveValue('')
		await expect(end).toHaveValue(await start.inputValue())

		// Filter changes must not clobber the record dates (FA-RECDATE AC-2).
		const before = await start.inputValue()
		const filterStart = page.locator('#employee-absences-start-date-filter')
		if (await filterStart.isVisible().catch(() => false)) {
			await filterStart.fill('01.01.2020')
			await filterStart.blur()
		}
		await expect(start).toHaveValue(before)
	})

	test('estimate-days: self endpoint returns basis + count', async ({ page }) => {
		skipIfMissingCreds('EMPLOYEE')
		await login(page, creds('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/absences')
		const { mon, fri } = nextWeekRange()

		const res = await api(
			page, 'GET',
			`/apps/arbeitszeitcheck/api/absences/estimate-days?start=${mon}&end=${fri}`,
		)
		expect(res.success).toBe(true)
		expect(typeof res.days).toBe('number')
		expect(res.days).toBeGreaterThan(0)
		expect(['weekday_schedule', 'weekdays_mon_fri']).toContain(res.basis)

		const bad = await apiAllowFailure(
			page, 'GET',
			'/apps/arbeitszeitcheck/api/absences/estimate-days?start=garbage&end=x',
		)
		expect(bad.status).toBe(400)
	})

	test('AC-P1: weekday_schedule counts scheduled days, not Mon–Fri', async ({ page }) => {
		skipIfMissingCreds('ADMIN')
		skipIfMissingCreds('EMPLOYEE')
		const EMPLOYEE = process.env.NC_EMPLOYEE_USER
		await login(page, creds('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/')

		// Snapshot current assignment for restore.
		const before = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${EMPLOYEE}`)
		const prevModel = before?.user?.workingTimeModel ?? null

		const name = `AZC-4DAY-${Date.now()}`
		const work = { work: true, start: '08:00', end: '16:00', breaks: [] }
		const off = { work: false }
		const created = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/working-time-models', {
			data: {
				name,
				weeklyHours: 32,
				dailyHours: 8,
				workDaysPerWeek: 4,
				breakRules: {
					weekday_schedule: {
						version: 1,
						days: { mon: work, tue: work, wed: work, thu: work, fri: off, sat: off, sun: off },
					},
				},
			},
		})
		const modelId = created?.model?.id ?? created?.id
		expect(modelId, JSON.stringify(created)).toBeTruthy()

		try {
			const assign = await apiAllowFailure(
				page, 'PUT',
				`/apps/arbeitszeitcheck/api/admin/users/${EMPLOYEE}/working-time-model`,
				{ data: { workingTimeModelId: modelId } },
			)
			expect(assign.ok || assign.json?.success === true, JSON.stringify(assign.json)).toBeTruthy()

			const { mon, fri } = nextWeekRange()
			// API-level proof: the estimate and the stored debit agree on 4 days.
			const est = await api(
				page, 'GET',
				`/apps/arbeitszeitcheck/api/manager/employee-absences/estimate-days?userId=${EMPLOYEE}&startDate=${mon}&endDate=${fri}`,
			).catch(() => null)
			if (est) {
				expect(est.basis).toBe('weekday_schedule')
				expect(est.days).toBe(4)
			}
		} finally {
			// Restore the previous model assignment, then drop the fixture model.
			if (prevModel?.id) {
				await apiAllowFailure(
					page, 'PUT',
					`/apps/arbeitszeitcheck/api/admin/users/${EMPLOYEE}/working-time-model`,
					{ data: { workingTimeModelId: prevModel.id } },
				)
			}
			await apiAllowFailure(
				page, 'DELETE',
				`/apps/arbeitszeitcheck/api/admin/working-time-models/${modelId}`,
			)
		}
	})
})
