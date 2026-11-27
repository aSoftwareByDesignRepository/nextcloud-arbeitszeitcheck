import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api } from './helpers/api.js'

const STATE = 'NW'
const RULE_NAME = 'E2E Annual Company Day'
const RULE_MONTH = 8
const RULE_DAY = 15

async function listStateHolidays(page, year) {
	const res = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/state-holidays?state=${STATE}&year=${year}`)
	expect(res.success).toBe(true)
	return res
}

function ruleInstance(res, ruleId, ymd) {
	return (res.holidays || []).find((h) => h.recurring === true && h.rule_id === ruleId && h.date === ymd)
}

test.describe('Admin recurring holiday rules (AC-B9)', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('create rule → years merge → skip → restore → stop; duplicate rejected', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))

		const startYear = 2098
		const nextYear = startYear + 1
		const ymdStart = `${startYear}-08-15`
		const ymdNext = `${nextYear}-08-15`
		let ruleId = null

		try {
			const created = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/holiday-rules', {
				data: {
					state: STATE,
					name: RULE_NAME,
					kind: 'full',
					scope: 'company',
					anchor: 'date',
					month: RULE_MONTH,
					day: RULE_DAY,
					startYear: startYear,
				},
			})
			expect(created.success).toBe(true)
			expect(created.rule.id).toBeGreaterThan(0)
			ruleId = created.rule.id
			expect(created.rule.rule_key).toBe('d:08-15')
			expect(created.rule.active).toBe(true)

			// Duplicate anchor+scope in the same state → 409 dedup (AC-B7).
			const dup = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/holiday-rules', {
				data: {
					state: STATE,
					name: 'E2E Annual Company Day (duplicate name is no differentiator)',
					kind: 'full',
					scope: 'company',
					anchor: 'date',
					month: RULE_MONTH,
					day: RULE_DAY,
					startYear: startYear,
				},
			})
			expect(dup.success).not.toBe(true)
			expect(dup.error_code ?? dup.error).toBeTruthy()

			// The instance merges into the start year AND the following year
			// (read-time expansion — AC-B1, no persisted row needed).
			const listStart = await listStateHolidays(page, startYear)
			const instStart = ruleInstance(listStart, ruleId, ymdStart)
			expect(instStart, 'rule instance in start year').toBeTruthy()
			expect(instStart.id).toBeNull()
			expect(instStart.name).toBe(RULE_NAME)

			const listNext = await listStateHolidays(page, nextYear)
			expect(ruleInstance(listNext, ruleId, ymdNext), 'rule instance in next year').toBeTruthy()

			// Employee API carries the merged instance (additive wire keys only).
			const cal = await api(page, 'GET', `/apps/arbeitszeitcheck/api/holidays?start=${nextYear}-08-10&end=${nextYear}-08-20`)
			const calInst = (cal.holidays || []).find((h) => h.date === ymdNext && h.rule_id === ruleId)
			expect(calInst, 'employee API sees next-year instance').toBeTruthy()

			// Skip only the next year — AC-B3: instance gone there, start year kept.
			const skipped = await api(page, 'POST', `/apps/arbeitszeitcheck/api/admin/holiday-rules/${ruleId}/skips`, {
				data: { year: nextYear },
			})
			expect(skipped.success).toBe(true)

			const afterSkip = await listStateHolidays(page, nextYear)
			expect(ruleInstance(afterSkip, ruleId, ymdNext), 'skipped year has no instance').toBeUndefined()
			const ruleEntry = (afterSkip.rules || []).find((r) => r.id === ruleId)
			expect(ruleEntry, 'rule still listed for the year').toBeTruthy()
			expect(ruleEntry.skipped_years).toContain(nextYear)

			const listStartAfterSkip = await listStateHolidays(page, startYear)
			expect(ruleInstance(listStartAfterSkip, ruleId, ymdStart), 'start year unaffected by skip').toBeTruthy()

			// Undo the skip — the instance reappears (AC-B3 undo).
			const restored = await api(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/holiday-rules/${ruleId}/skips/${nextYear}`)
			expect(restored.success).toBe(true)
			const afterRestore = await listStateHolidays(page, nextYear)
			expect(ruleInstance(afterRestore, ruleId, ymdNext), 'instance back after restore').toBeTruthy()

			// Stop the series — no instance in any year anymore.
			const stopped = await api(page, 'POST', `/apps/arbeitszeitcheck/api/admin/holiday-rules/${ruleId}/active`, {
				data: { active: false },
			})
			expect(stopped.success).toBe(true)
			expect(stopped.rule.active).toBe(false)

			const listStartStopped = await listStateHolidays(page, startYear)
			expect(ruleInstance(listStartStopped, ruleId, ymdStart), 'stopped rule produces nothing').toBeUndefined()
			const listNextStopped = await listStateHolidays(page, nextYear)
			expect(ruleInstance(listNextStopped, ruleId, ymdNext), 'stopped rule produces nothing (next year)').toBeUndefined()
		} finally {
			if (ruleId !== null) {
				await api(page, 'POST', `/apps/arbeitszeitcheck/api/admin/holiday-rules/${ruleId}/active`, {
					data: { active: false },
				})
			}
		}
	})

	test('admin UI shows the ↻ badge and the year-vs-series native dialog', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))

		const currentYear = new Date().getFullYear()
		const ymd = `${currentYear}-08-15`
		let ruleId = null

		try {
			const created = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/holiday-rules', {
				data: {
					state: STATE,
					name: RULE_NAME,
					kind: 'full',
					scope: 'company',
					anchor: 'date',
					month: RULE_MONTH,
					day: RULE_DAY,
					startYear: currentYear,
				},
			})
			if (created.success !== true) {
				const list = await listStateHolidays(page, currentYear)
				const existing = (list.rules || []).find((r) => r.rule_key === 'd:08-15' && r.scope === 'company')
				test.skip(!existing, 'Rule already covered by a persisted row on this date')
				ruleId = existing.id
			} else {
				ruleId = created.rule.id
			}

			await page.goto('/apps/arbeitszeitcheck/admin/holidays')
			await page.waitForSelector('#holiday-state-select')
			await page.selectOption('#holiday-state-select', STATE)
			await page.selectOption('#holiday-year-select', String(currentYear))

			const row = page.locator(`#holiday-tbody tr[data-rule-id="${ruleId}"]`)
			await expect(row).toBeVisible()
			await expect(row.locator('.admin-holidays-badge--rule')).toBeVisible()

			// Keyboard path (NN-04, a11y): focus the remove action, Enter opens a
			// native <dialog> — no window.confirm anywhere in the flow.
			const removeBtn = row.locator('button[aria-label]')
			await removeBtn.last().focus()
			await page.keyboard.press('Enter')

			const dialog = page.locator('dialog.azc-dialog[open]')
			await expect(dialog).toBeVisible()
			await expect(dialog.getByText('Every year (stop the rule)')).toBeVisible()

			// Escape dismisses the dialog without mutating anything.
			await page.keyboard.press('Escape')
			await expect(dialog).toHaveCount(0)
			const afterEscape = await listStateHolidays(page, currentYear)
			expect(ruleInstance(afterEscape, ruleId, ymd), 'Escape did not mutate').toBeTruthy()

			// Reopen and choose "Only {year}" via keyboard — row turns muted skipped.
			await removeBtn.last().focus()
			await page.keyboard.press('Enter')
			const dialog2 = page.locator('dialog.azc-dialog[open]')
			await expect(dialog2).toBeVisible()
			await dialog2.getByText(`Only ${currentYear}`).focus()
			await page.keyboard.press('Enter')

			await expect(page.locator(`#holiday-tbody tr[data-rule-id="${ruleId}"].admin-holidays-row--skipped`)).toBeVisible()

			// Restore the year via API so the finally cleanup sees a clean state.
			await api(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/holiday-rules/${ruleId}/skips/${currentYear}`)
		} finally {
			if (ruleId !== null) {
				await api(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/holiday-rules/${ruleId}/skips/${currentYear}`).catch(() => {})
				await api(page, 'POST', `/apps/arbeitszeitcheck/api/admin/holiday-rules/${ruleId}/active`, {
					data: { active: false },
				})
			}
		}
	})
})

test.describe('Absence-type paid-gate warning (A-R4)', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test('warning appears only while both credit gates are off', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))

		const before = await api(page, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
		const orig = {
			leaveSollEffectsEnabled: before.leaveSollEffectsEnabled === true,
			paidAbsencePlannedHoursCreditEnabled: before.paidAbsencePlannedHoursCreditEnabled === true,
		}
		let typeId = null

		try {
			const created = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/absence-types', {
				data: {
					code: `custom_e2e_gate_${Date.now()}`,
					label: 'E2E Gate Warning Type',
					sollCredit: true,
					active: true,
				},
			})
			expect(created.data).toBeTruthy()
			typeId = created.data.id

			await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: { leaveSollEffectsEnabled: false, paidAbsencePlannedHoursCreditEnabled: false },
			})

			await page.goto('/apps/arbeitszeitcheck/admin/absence-types')
			await page.waitForSelector('#cat-tbody')

			const warning = page.locator('#cat-paid-gate-warning')
			await expect(warning).toBeVisible()
			await expect(page.locator(`#cat-tbody tr[data-soll-credit="1"]`)).not.toHaveCount(0)

			// Opening either gate silences the warning (OR semantics, AC-A6).
			await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: { leaveSollEffectsEnabled: true },
			})
			await page.reload()
			await page.waitForSelector('#cat-tbody')
			await expect(page.locator('#cat-paid-gate-warning')).toBeHidden()
		} finally {
			await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', { data: orig })
			if (typeId !== null) {
				await api(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/absence-types/${typeId}`)
			}
		}
	})
})
