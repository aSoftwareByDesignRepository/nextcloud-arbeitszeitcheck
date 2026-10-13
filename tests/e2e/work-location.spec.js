import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

function addDays(isoDate, days) {
	const d = new Date(`${isoDate}T00:00:00Z`)
	d.setUTCDate(d.getUTCDate() + days)
	return d.toISOString().slice(0, 10)
}

test.use({ trace: 'off' })

/**
 * GH #43 — per-entry work location (office / home office / external appointment).
 *
 * Covers the critical wire paths end-to-end against the live app:
 * allowlist enforcement, create resolution (explicit → default → office),
 * update semantics (absent = unchanged, '' = clear), list badge rendering
 * and the correction round-trip.
 */
test.describe('Work location marking (GH #43)', () => {
	test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER')

	/**
	 * Create an entry via the API, probing dates to dodge existing rows.
	 * recentDays=true stays inside the 14-day editability window; otherwise
	 * scans historical dates (cheaper, no collision with real activity).
	 */
	async function createEntry(page, { workLocation, recentDays = false } = {}) {
		const stamp = Date.now()
		const today = new Date().toISOString().slice(0, 10)
		const base = recentDays ? null : addDays('1994-03-01', stamp % 300)
		for (let i = 0; i < 40; i++) {
			const date = recentDays
				? addDays(today, -(1 + (i % 13))) // stay inside the 14-day editability window
				: addDays(base, i)
			const data = {
				date,
				startTime: `0${8 + (i % 2)}:00`.slice(-5),
				endTime: `1${2 + (i % 3)}:30`.slice(-5),
				description: `e2e work-location ${stamp}`,
			}
			if (workLocation !== undefined) {
				data.workLocation = workLocation
			}
			const res = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', { data })
			if (res.ok && res.json?.success) {
				return res.json
			}
		}
		throw new Error('could not seed a time entry on any probed date')
	}

	test('create form exposes an accessible work-location select', async ({ browser }) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		try {
			await login(page, credsFromEnv('EMPLOYEE'))
			await page.goto('/apps/arbeitszeitcheck/time-entries/create')
			await assertArbeitszeitcheckLoaded(page)

			const select = page.locator('#entry-work-location')
			await expect(select).toBeVisible()
			const values = await select.locator('option').evaluateAll((opts) => opts.map((o) => o.value))
			expect(values).toEqual(['', 'office', 'home', 'external'])
		} finally {
			await context.close()
		}
	})

	test('explicit location persists and renders as a list badge', async ({ browser }) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		try {
			await login(page, credsFromEnv('EMPLOYEE'))
			const created = await createEntry(page, { workLocation: 'home', recentDays: true })
			expect(created.success).toBe(true)
			expect(created.entry?.workLocation ?? created.workLocation).toBe('home')
			const id = created.entry?.id ?? created.id
			expect(id).toBeTruthy()

			// The list renders only the 100 most recent entries (findByUser($uid, 100),
			// newest first) — the badge must be scoped to this entry's row or it can
			// match a badge belonging to a different entry.
			await page.goto('/apps/arbeitszeitcheck/time-entries')
			await assertArbeitszeitcheckLoaded(page)
			const badge = page.locator(`tr[data-entry-id="${id}"] .azc-work-location-badge`)
			await expect(badge).toBeVisible()
			await expect(badge).toContainText(/home|Homeoffice/i)
		} finally {
			await context.close()
		}
	})

	test('invalid location is rejected with a field-level 400', async ({ browser }) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		try {
			await login(page, credsFromEnv('EMPLOYEE'))
			const res = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
				data: {
					date: '1994-01-10',
					startTime: '09:00',
					endTime: '10:00',
					workLocation: 'moon',
				},
			})
			expect(res.ok).toBe(false)
			expect(res.status).toBe(400)
			expect(res.json?.error_code ?? res.json?.error).toBeTruthy()
			// Field-level error map so clients can render inline (WCAG 3.3.1).
			const fields = res.json?.fields ?? {}
			expect(Object.keys(fields)).toContain('workLocation')
		} finally {
			await context.close()
		}
	})

	test('update semantics: absent keeps, "" clears, value sets', async ({ browser }) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		try {
			await login(page, credsFromEnv('EMPLOYEE'))
			const created = await createEntry(page, { workLocation: 'home', recentDays: true })
			const id = created.entry?.id ?? created.id
			expect(id).toBeTruthy()

			// Absent field → unchanged.
			const keep = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/time-entries/${id}`, {
				data: { description: 'e2e keep-location' },
			})
			expect(keep.entry?.workLocation ?? keep.workLocation).toBe('home')

			// Explicit '' → cleared to NULL.
			const cleared = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/time-entries/${id}`, {
				data: { workLocation: '' },
			})
			expect(cleared.entry?.workLocation ?? cleared.workLocation ?? null).toBeNull()

			// Valid value → set.
			const set = await api(page, 'PUT', `/apps/arbeitszeitcheck/api/time-entries/${id}`, {
				data: { workLocation: 'external' },
			})
			expect(set.entry?.workLocation ?? set.workLocation).toBe('external')
		} finally {
			await context.close()
		}
	})

	test('per-user default applies to new entries without explicit location', async ({ browser }) => {
		const context = await browser.newContext()
		const page = await context.newPage()
		try {
			await login(page, credsFromEnv('EMPLOYEE'))
			await api(page, 'POST', '/apps/arbeitszeitcheck/settings', {
				data: { work_location_default: 'home' },
			})
			const created = await createEntry(page, {})
			expect(created.entry?.workLocation ?? created.workLocation).toBe('home')
		} finally {
			try {
				await api(page, 'POST', '/apps/arbeitszeitcheck/settings', {
					data: { work_location_default: '' },
				})
			} catch {
				// best-effort restore
			}
			await context.close()
		}
	})

	test('correction proposes and approval applies a location change', async ({ browser }) => {
		test.skip(!process.env.NC_MANAGER_USER, 'Requires NC_MANAGER_USER')
		const employeeContext = await browser.newContext()
		const managerContext = await browser.newContext()
		const employeePage = await employeeContext.newPage()
		const managerPage = await managerContext.newPage()
		try {
			await login(employeePage, credsFromEnv('EMPLOYEE'))
			const created = await createEntry(employeePage, { workLocation: 'office' })
			const id = created.entry?.id ?? created.id

			const corr = await apiAllowFailure(employeePage, 'POST',
				`/apps/arbeitszeitcheck/api/time-entries/${id}/request-correction`, {
					data: {
						justification: 'Was actually working from home that day.',
						workLocation: 'home',
					},
				})
			expect(corr.ok).toBe(true)
			expect(corr.json?.success).toBe(true)

			await login(managerPage, credsFromEnv('MANAGER'))
			const pending = await api(managerPage, 'GET', '/apps/arbeitszeitcheck/api/manager/pending-time-entry-corrections')
			const mine = (pending.corrections ?? []).find((e) => String(e.id) === String(id))
			expect(mine, 'correction appears in the manager pending queue').toBeTruthy()
			// The proposed mark must be visible in the review payload.
			expect(mine.proposed?.workLocation).toBe('home')
			expect(mine.original?.workLocation).toBe('office')

			const decision = await apiAllowFailure(managerPage, 'POST',
				`/apps/arbeitszeitcheck/api/manager/time-entries/${id}/approve-correction`, {
					data: { comment: 'e2e approved' },
				})
			expect(decision.ok).toBe(true)

			const after = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/time-entries/${id}`)
			expect(after.entry?.workLocation ?? after.workLocation).toBe('home')
		} finally {
			await employeeContext.close()
			await managerContext.close()
		}
	})
})
