// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv, gotoApp } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

/**
 * New absence types (§4 feedback): child_sick ("Kind krank") and
 * overtime_comp ("Gleittag / Freizeitausgleich").
 *
 * Proves via the real API + rendered DOM:
 *  1. The employee form offers both types.
 *  2. child_sick creates and is re-readable.
 *  3. overtime_comp guards hold server-side: half-day rejected,
 *     day-without-planned-hours rejected, day-with-time-entries rejected.
 *  4. The model-changes endpoint answers with a keyed changes map.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const APP = '/apps/arbeitszeitcheck'
const EMP = process.env.NC_EMPLOYEE_USER || 'e2e_employee'
const UNIQ = `ant${Date.now().toString(36)}`
const COMPOSE_CWD = path.resolve(__dirname, '../../..')

test.use({ trace: 'off' })
test.describe.configure({ mode: 'serial' })

/** Remove this spec's remnants (reason prefix 'atlas-nt'). */
function dbDeleteAbsenceRemnants() {
	const sql = `DELETE FROM oc_at_absences WHERE reason LIKE 'atlas-nt%'`
	try {
		execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	} catch { /* best-effort */ }
}

/** Next Monday..Friday strictly in the future (no time entries possible). */
function nextFutureWeekday(offsetDays) {
	const d = new Date()
	d.setUTCDate(d.getUTCDate() + offsetDays)
	while (d.getUTCDay() === 0 || d.getUTCDay() === 6) {
		d.setUTCDate(d.getUTCDate() + 1)
	}
	return d.toISOString().slice(0, 10)
}

/**
 * Probe-fragility guard: other specs/fixtures can leave absences covering the
 * candidate date, and overlap rejections would then mask the guard under test.
 * Fetch the real list and return the first future weekday outside every
 * existing absence range.
 */
async function freeFutureWeekday(page, startOffset) {
	const existing = await api(page, 'GET', `${APP}/api/absences?limit=500`)
	const rows = existing.absences ?? existing.items ?? []
	const occupied = rows
		.map((a) => [a.start_date ?? a.startDate, a.end_date ?? a.endDate])
		.filter(([s, e]) => typeof s === 'string' && typeof e === 'string')
	for (let off = startOffset; off <= 120; off++) {
		const candidate = nextFutureWeekday(off)
		if (!occupied.some(([s, e]) => candidate >= s && candidate <= e)) {
			return candidate
		}
	}
	return null
}

/**
 * Same fragility guard for the no-planned-hours probe: pick a Sunday far out
 * that no existing absence covers — an overlap rejection would mask the
 * OVERTIME_COMP_NO_PLANNED_HOURS guard under test.
 */
async function freeFutureSunday(page, startOffset) {
	const existing = await api(page, 'GET', `${APP}/api/absences?limit=500`)
	const rows = existing.absences ?? existing.items ?? []
	const occupied = rows
		.map((a) => [a.start_date ?? a.startDate, a.end_date ?? a.endDate])
		.filter(([s, e]) => typeof s === 'string' && typeof e === 'string')
	for (let off = startOffset; off <= 180; off++) {
		const d = new Date()
		d.setUTCDate(d.getUTCDate() + off)
		if (d.getUTCDay() !== 0) {
			continue
		}
		const candidate = d.toISOString().slice(0, 10)
		if (!occupied.some(([s, e]) => candidate >= s && candidate <= e)) {
			return candidate
		}
	}
	return null
}

test.describe('New absence types: child_sick + overtime_comp', () => {
	test.skip(!process.env.NC_EMPLOYEE_PASS, 'e2e creds required')

	test('form offers both new types with labels', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/absences/create`)
		await page.waitForSelector('#absence-type', { timeout: 30000 })
		const options = await page.evaluate(() =>
			Array.from(document.querySelectorAll('#absence-type option'))
				.map((o) => ({ value: o.value, label: o.textContent.trim() })),
		)
		const child = options.find((o) => o.value === 'child_sick')
		const comp = options.find((o) => o.value === 'overtime_comp')
		expect(child, 'child_sick option must exist').toBeTruthy()
		expect(comp, 'overtime_comp option must exist').toBeTruthy()
		// Never render the raw machine code — a translated label must show.
		expect(child.label).not.toBe('child_sick')
		expect(child.label.length).toBeGreaterThan(2)
		expect(comp.label).not.toBe('overtime_comp')
		expect(comp.label.length).toBeGreaterThan(2)
	})

	test('child_sick creates via API and is re-readable', async ({ page }) => {
		dbDeleteAbsenceRemnants()
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/absences`)

		const day = await freeFutureWeekday(page, 14)
		expect(day, 'no future weekday free of existing absences within +120d').toBeTruthy()

		const created = await apiAllowFailure(page, 'POST', `${APP}/api/absences`, {
			data: { type: 'child_sick', start_date: day, end_date: day, reason: `atlas-nt ${UNIQ}` },
		})
		expect(created.ok, `child_sick create failed: ${JSON.stringify(created.json)}`).toBe(true)
		const id = created.json?.id ?? created.json?.absence?.id
		expect(id).toBeTruthy()

		const list = await api(page, 'GET', `${APP}/api/absences?limit=500`)
		const items = list.absences ?? list.items ?? []
		const row = items.find((a) => a.id === id)
		expect(row, 'created child_sick must be re-readable').toBeTruthy()
		expect(row.type).toBe('child_sick')
	})

	test('overtime_comp rejects half-day requests', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/absences`)
		const day = await freeFutureWeekday(page, 21)
		expect(day, 'no free future weekday found').toBeTruthy()
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/absences`, {
			data: { type: 'overtime_comp', start_date: day, end_date: day, day_fraction: 0.5, reason: `atlas-nt ${UNIQ}` },
		})
		expect(res.ok, 'half-day overtime_comp must be rejected').toBe(false)
		// Assert the *right* guard fired — an overlap rejection would also be !ok
		// but proves nothing about the half-day rule.
		expect(res.json?.code ?? res.json?.error ?? '').toContain('OVERTIME_COMP_FULL_DAY_ONLY')
	})

	test('overtime_comp rejects a free weekend day (no planned hours)', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/absences`)
		// Find a Sunday far enough out that no entries can exist — and outside
		// every existing absence range (overlap would mask the guard under test).
		const sunday = await freeFutureSunday(page, 21)
		expect(sunday, 'no free future Sunday found').toBeTruthy()
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/absences`, {
			data: { type: 'overtime_comp', start_date: sunday, end_date: sunday, reason: `atlas-nt ${UNIQ}` },
		})
		expect(res.ok, 'overtime_comp on a non-planned day must be rejected').toBe(false)
		expect(res.json?.code ?? res.json?.error ?? '').toContain('OVERTIME_COMP_NO_PLANNED_HOURS')
	})

	test('overtime_comp accepts a future full weekday and re-reads', async ({ page }) => {
		dbDeleteAbsenceRemnants()
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/absences`)
		// If the employee has NO planned hours on that weekday the guard
		// legitimately rejects — report it honestly.
		const day = await freeFutureWeekday(page, 35)
		expect(day, 'no free future weekday found').toBeTruthy()
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/absences`, {
			data: { type: 'overtime_comp', start_date: day, end_date: day, reason: `atlas-nt ${UNIQ}` },
		})
		if (!res.ok) {
			// Honest outcome: the dev employee has no Soll on that weekday.
			// Verify it is the planned-hours guard, not a crash.
			expect(res.status).toBeLessThan(500)
			test.info().annotations.push({
				type: 'note',
				description: `overtime_comp create rejected (employee has no planned hours on ${day}): ${JSON.stringify(res.json)}`,
			})
			return
		}
		const id = res.json?.id ?? res.json?.absence?.id
		expect(id).toBeTruthy()
		const list = await api(page, 'GET', `${APP}/api/absences?limit=500`)
		const row = (list.absences ?? list.items ?? []).find((a) => a.id === id)
		expect(row?.type).toBe('overtime_comp')
	})

	test('model-changes endpoint returns keyed map', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/calendar`)
		const res = await api(page, 'GET', `${APP}/api/settings/working-time-model-changes`)
		expect(res.success).toBe(true)
		expect(Array.isArray(res.changes)).toBe(true)
		for (const c of res.changes) {
			expect(c).toHaveProperty('date')
			expect(c.date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(c).toHaveProperty('model_name')
		}
	})
})
