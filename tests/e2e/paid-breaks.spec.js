// @ts-check
/**
 * Paid breaks (TVöD §6 Abs. 1 S. 2) — end-to-end flow.
 *
 * Covers the whole feature in one stateful pass:
 *   admin creates a paid-break model → assigns it to the shared employee →
 *   employee records a day with a break → allocator snapshots the paid
 *   portion → API payload, employee list UI and NH-03 impact preview all
 *   surface it.
 *
 * Mutates shared state (employee working-time-model assignment, org
 * manual-entry settings, creates/deletes a model + entry), so this spec is
 * registered in STATEFUL_SPECS (serialized) and restores everything in
 * `finally`.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

function addDays(isoDate, days) {
	const d = new Date(`${isoDate}T00:00:00Z`)
	d.setUTCDate(d.getUTCDate() + days)
	return d.toISOString().slice(0, 10)
}

test.use({ trace: 'off' })

test.describe('Paid breaks (TVöD §6)', () => {
	test.skip(!process.env.NC_ADMIN_USER || !process.env.NC_EMPLOYEE_USER, 'Requires NC_ADMIN_USER and NC_EMPLOYEE_USER')

	test('admin→assignment→entry→snapshot→display→impact preview', async ({ browser }) => {
		// Two browser contexts, a 40-day free-date scan and an axe sweep —
		// generous budget for a loaded shared instance.
		test.setTimeout(180_000)
		const adminContext = await browser.newContext()
		const employeeContext = await browser.newContext()
		const adminPage = await adminContext.newPage()
		const employeePage = await employeeContext.newPage()

		let modelId = null
		let entryId = null
		let restoreApproval = null
		let restoreManualEntry = null
		let origAssignment = null

		const employeeUid = credsFromEnv('EMPLOYEE').username

		try {
			await login(adminPage, credsFromEnv('ADMIN'))

			// -- Settings: manual entries enabled, no approval round-trip ----
			const settingsBefore = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
			restoreApproval = settingsBefore.settings?.manualTimeEntriesRequireApproval === true
			restoreManualEntry = settingsBefore.settings?.manualTimeEntryEnabled === true
			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: { settings_section: 'time-approvals', manualTimeEntriesRequireApproval: false },
			})
			await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
				data: { settings_section: 'time-recording', manualTimeEntryEnabled: true },
			})

			// -- 1) Model with paid-break flag + 45min cap -------------------
			const stamp = Date.now()
			const model = await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/working-time-models', {
				data: {
					name: `E2E Paid Break ${stamp}`,
					description: 'E2E paid-break verification',
					type: 'full_time',
					weeklyHours: 40,
					dailyHours: 8,
					workDaysPerWeek: 5,
					breakRules: { breaks_count_as_work: true, paid_break_cap_minutes: 45 },
				},
			})
			expect(model.success).toBe(true)
			modelId = model.model?.id
			expect(modelId).toBeTruthy()

			// -- 2) Assign to the shared employee (capture for restore) ------
			// GET user returns vacationDaysPerYear in *admin days* — the same
			// unit applyWorkingTimeModel expects, so it round-trips safely.
			const userBefore = await api(adminPage, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${employeeUid}`)
			const origModel = userBefore.user?.userWorkingTimeModel ?? null
			if (origModel?.workingTimeModelId) {
				origAssignment = {
					workingTimeModelId: origModel.workingTimeModelId,
					startDate: userBefore.user?.workingTimeModelStartDate ?? null,
					endDate: userBefore.user?.workingTimeModelEndDate ?? null,
					vacationDaysPerYear: userBefore.user?.vacationDaysPerYear ?? null,
					germanState: userBefore.user?.germanState ?? null,
				}
			}

			await api(adminPage, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${employeeUid}/working-time-model`, {
				data: { workingTimeModelId: modelId, startDate: '1993-01-01' },
			})

			// Impact baseline: the 1993 window covers the employee's seeded
			// history — assert the delta, not an absolute count.
			const impactBefore = await api(adminPage, 'GET', `/apps/arbeitszeitcheck/api/admin/working-time-models/${modelId}/paid-break-impact`)
			expect(impactBefore.success).toBe(true)
			const baselineEntries = Number(impactBefore.data?.affected_entry_count ?? 0)

			// -- 3) Employee records a day: 9h wall, 1h break ----------------
			await login(employeePage, credsFromEnv('EMPLOYEE'))
			const base = addDays('1993-03-01', stamp % 250)
			let created = null
			for (let i = 0; i < 40 && !created; i++) {
				const date = addDays(base, i)
				const res = await apiAllowFailure(employeePage, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
					data: {
						date,
						startTime: '08:00',
						endTime: '17:00',
						breaks: [{ start: '12:00', end: '13:00' }],
						justification: 'E2E paid-break verification entry.',
					},
				})
				if (res.ok && res.json?.success) {
					created = { date, json: res.json }
				}
			}
			expect(created, 'employee could not create the verification entry').toBeTruthy()
			entryId = created.json.entry?.id
			expect(entryId).toBeTruthy()
			const workDate = created.date

			// -- 4) Snapshot: 8h net + min(60min pool, 45min cap) = 8.75h ----
			const list = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/time-entries?start_date=${workDate}&end_date=${workDate}`)
			expect(list.success).toBe(true)
			const row = (list.entries || list.data?.entries || []).find((e) => Number(e.id) === Number(entryId))
			expect(row, 'created entry not found in list payload').toBeTruthy()
			expect(Number(row.paidBreakSeconds)).toBe(2700)
			expect(Number(row.paidBreakMinutes)).toBe(45)
			expect(Number(row.paidDurationHours)).toBeCloseTo(8.75, 2)

			// -- 5) Employee UI: paid primary + net/paid-break detail --------
			// Assertions stay numeric: a concurrent spec's locale sweep can
			// flip the shared user's language mid-run — numbers are stable.
			await employeePage.goto(`/apps/arbeitszeitcheck/time-entries?start_date=${workDate}&end_date=${workDate}`)
			await assertArbeitszeitcheckLoaded(employeePage)
			const paidCell = employeePage.locator('td[data-label]', { hasText: '8.75' }).first()
			await expect(paidCell).toBeVisible()
			await expect(paidCell).toContainText('8.00')
			await expect(paidCell).toContainText('45')

			// -- 6) NH-03: impact preview in the model editor ----------------
			const impact = await api(adminPage, 'GET', `/apps/arbeitszeitcheck/api/admin/working-time-models/${modelId}/paid-break-impact`)
			expect(impact.success).toBe(true)
			expect(impact.data.affected_user_count).toBe(1)
			expect(impact.data.affected_entry_count).toBe(baselineEntries + 1)

			await adminPage.goto('/apps/arbeitszeitcheck/admin/working-time-models')
			await assertArbeitszeitcheckLoaded(adminPage)
			// dispatchEvent: under load a sibling <td> intermittently overlaps
			// the button's click point and real clicks spin forever.
			const editButton = adminPage.locator(`tr[data-model-id="${modelId}"] [data-action="edit-model"]`)
			await editButton.scrollIntoViewIfNeeded()
			await editButton.dispatchEvent('click')
			const panel = adminPage.locator('#wtm-editor-panel')
			await expect(panel).toBeVisible()
			const capInput = panel.locator('#wtm-model-paid-break-cap')
			await expect(capInput).toHaveValue('45')
			await capInput.fill('30')
			const impactBox = panel.locator('#wtm-model-paid-break-impact')
			await expect(impactBox).toBeVisible({ timeout: 5000 })
			// Numeric needle — the label is localized and a concurrent spec's
			// locale sweep can flip the shared user's language mid-run. The
			// precise user/entry counts are asserted on the API above.
			await expect(panel.locator('#wtm-model-paid-break-impact-text'))
				.toContainText(String(baselineEntries + 1), { timeout: 5000 })

			// -- 7) axe on the paid-break editor region ----------------------
			const axe = await new AxeBuilder({ page: adminPage })
				.include('#wtm-editor-panel')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
				.analyze()
			expect(axe.violations, JSON.stringify(axe.violations, null, 2)).toEqual([])
		} finally {
			// Cleanup order matters: entry first, then the assignment restore
			// (which re-enqueues a policy recompute), then the model delete.
			// Three guards: (1) a non-2xx response counts as failure (apiAllow-
			// Failure does not throw), (2) a dead page retries through a fresh
			// login — contexts are already closing on test timeout, (3) every
			// step is isolated and LOGGED so a failed restore can never skip
			// the remaining restores nor vanish silently.
			const withPage = async (page, role, step) => {
				const run = async (p) => {
					const res = await step(p)
					if (res && typeof res === 'object' && res.ok === false) {
						throw new Error(`cleanup call failed (HTTP ${res.status})`)
					}
					return res
				}
				try {
					return await run(page)
				} catch (first) {
					const ctx = await browser.newContext()
					try {
						const fresh = await ctx.newPage()
						await login(fresh, credsFromEnv(role))
						return await run(fresh)
					} finally {
						await ctx.close()
					}
				}
			}
			const attempt = async (label, step) => {
				try {
					await step()
				} catch (e) {
					// eslint-disable-next-line no-console
					console.warn(`[paid-breaks cleanup] ${label} failed: ${e}`)
				}
			}
			try {
				if (entryId) {
					await attempt('delete entry', () => withPage(employeePage, 'EMPLOYEE', (p) => apiAllowFailure(p, 'DELETE', `/apps/arbeitszeitcheck/api/time-entries/${entryId}`)))
				}
				if (origAssignment) {
					const restore = {
						workingTimeModelId: origAssignment.workingTimeModelId,
						startDate: origAssignment.startDate,
						endDate: origAssignment.endDate,
						vacationDaysPerYear: origAssignment.vacationDaysPerYear,
						germanState: origAssignment.germanState,
					}
					await attempt('restore assignment', () => withPage(adminPage, 'ADMIN', (p) => apiAllowFailure(p, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${employeeUid}/working-time-model`, { data: restore })))
				} else {
					await attempt('clear assignment', () => withPage(adminPage, 'ADMIN', (p) => apiAllowFailure(p, 'PUT', `/apps/arbeitszeitcheck/api/admin/users/${employeeUid}/working-time-model`, { data: { workingTimeModelId: '' } })))
				}
				if (modelId) {
					await attempt('delete model', () => withPage(adminPage, 'ADMIN', (p) => apiAllowFailure(p, 'DELETE', `/apps/arbeitszeitcheck/api/admin/working-time-models/${modelId}`)))
				}
				if (restoreApproval !== null) {
					await attempt('restore approval flag', () => withPage(adminPage, 'ADMIN', (p) => apiAllowFailure(p, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
						data: { settings_section: 'time-approvals', manualTimeEntriesRequireApproval: restoreApproval },
					})))
				}
				if (restoreManualEntry !== null) {
					await attempt('restore manual-entry flag', () => withPage(adminPage, 'ADMIN', (p) => apiAllowFailure(p, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
						data: { settings_section: 'time-recording', manualTimeEntryEnabled: restoreManualEntry },
					})))
				}
			} finally {
				await adminContext.close()
				await employeeContext.close()
			}
		}
	})
})
