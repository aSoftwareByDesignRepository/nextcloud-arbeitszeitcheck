// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv, skipIfMissingCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

/**
 * Hour-mode absence requests (optional workforce §9.1/§9.2).
 *
 * Regression binding for `azc-web-hourly-minutes-control-unreachable`
 * (r82 visual must_fix): #absence-hour-mode-group is emitted hidden and its
 * only unhide path used to be registered inside initVacationHoursField's
 * hours-mode early return — orgs enabling workforcePolicy.hourlyAbsenceTypes
 * WITHOUT the vacation-hours feature (vacation_unit=days) got a permanently
 * dead control. The submit path must also carry duration_mode/
 * duration_minutes + the X-AZC-Absence-Contract: 2 handshake, or the server
 * would silently reinterpret the request as day-mode.
 *
 * Legs (all through the real rendered form):
 *  1. hourly ON + vacation-hours OFF → control reveals, inputs enable,
 *     end date pins to single day, submit persists durationMode='hours' +
 *     durationMinutes — durable postcondition via API re-read.
 *  2. hourly ON + vacation-hours ON → regression: hourly types still reveal
 *     the control; vacation still reveals the hours field.
 *  3. hourly OFF → control stays hidden (no false positive).
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const APP = '/apps/arbeitszeitcheck'
const EMP = process.env.NC_EMPLOYEE_USER || 'e2e_employee'
const UNIQ = `ahm${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
const COMPOSE_CWD = path.resolve(__dirname, '../../..')
const POLICY_KEY = 'workforce_policy_json'
const REV_KEY = 'workforce_policy_revision'
const UNIT_KEY = 'vacation_unit'
const MINUTES = 90

test.use({ trace: 'off' })
test.describe.configure({ mode: 'serial' })

function occ(args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
}

function occConfigGet(key) {
	return occ(['config:app:get', 'arbeitszeitcheck', key])
		.split('\n')
		.map((l) => l.trim())
		.filter((l) => l !== '')
		.pop() ?? ''
}

function occConfigSet(key, value) {
	occ(['config:app:set', 'arbeitszeitcheck', key, `--value=${value}`])
}

/** Merge-mutate the stored workforce policy document, bumping its revision. */
function writePolicy(mutate) {
	const raw = occConfigGet(POLICY_KEY)
	const doc = raw ? JSON.parse(raw) : {}
	mutate(doc)
	occConfigSet(POLICY_KEY, JSON.stringify(doc))
	occConfigSet(REV_KEY, String(Number(occConfigGet(REV_KEY) || '0') + 1))
}

/** Next UTC weekday strictly in the future. */
function futureWeekday(offsetDays) {
	const d = new Date()
	d.setUTCDate(d.getUTCDate() + offsetDays)
	while (d.getUTCDay() === 0 || d.getUTCDay() === 6) {
		d.setUTCDate(d.getUTCDate() + 1)
	}
	return d.toISOString().slice(0, 10)
}

/** First future weekday not covered by any existing employee absence. */
async function freeFutureWeekday(page, startOffset) {
	const existing = await api(page, 'GET', `${APP}/api/absences?limit=500`)
	const rows = existing.absences ?? existing.items ?? []
	const occupied = rows
		.filter((r) => (r.user_id ?? r.userId ?? '') === EMP)
		.map((r) => [r.start_date ?? r.startDate, r.end_date ?? r.endDate])
	for (let off = startOffset; off < startOffset + 120; off++) {
		const iso = futureWeekday(off)
		if (!occupied.some(([s, e]) => s && e && iso >= s && iso <= e)) {
			return iso
		}
	}
	throw new Error('no free weekday found for hourly-mode probe')
}

const toDe = (iso) => iso.split('-').reverse().join('.')

test.describe('Hour-mode absence form (workforce policy §9.2)', () => {
	test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER')

	let prev = { unit: '', policy: '', rev: '' }

	test.beforeAll(() => {
		prev = {
			unit: occConfigGet(UNIT_KEY),
			policy: occConfigGet(POLICY_KEY),
			rev: occConfigGet(REV_KEY),
		}
	})

	test.afterAll(() => {
		if (prev.unit === '') {
			occ(['config:app:delete', 'arbeitszeitcheck', UNIT_KEY])
		} else {
			occConfigSet(UNIT_KEY, prev.unit)
		}
		if (prev.policy === '') {
			occ(['config:app:delete', 'arbeitszeitcheck', POLICY_KEY])
		} else {
			occConfigSet(POLICY_KEY, prev.policy)
		}
		if (prev.rev === '') {
			occ(['config:app:delete', 'arbeitszeitcheck', REV_KEY])
		} else {
			occConfigSet(REV_KEY, prev.rev)
		}
		const sql = `DELETE FROM oc_at_absences WHERE reason LIKE 'atlas-hm%'`
		try {
			execFileSync('docker', [
				'compose', 'exec', '-T', 'mariadb', 'mariadb',
				'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', sql,
			], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
		} catch { /* best-effort */ }
	})

	test('hourly on + vacation-hours off: minutes control reveals, submits and persists', async ({ page }) => {
		writePolicy((doc) => { doc.hourlyAbsenceTypes = ['unpaid_leave', 'overtime_comp'] })
		occConfigSet(UNIT_KEY, 'days')

		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto(`${APP}/absences/create`)

		// Prove the exact bug environment: vacation-hours OFF (days unit) —
		// the hours group stays hidden and carries data-hours-mode="0".
		const hoursGroup = page.locator('#absence-duration-hours-group')
		await expect(hoursGroup).toHaveAttribute('data-hours-mode', '0')
		await expect(hoursGroup).toBeHidden()

		const hourGroup = page.locator('#absence-hour-mode-group')
		const minutesInput = page.locator('#absence-duration-minutes')
		const modeInput = page.locator('#absence-duration-mode')
		await expect(hourGroup).toBeHidden()
		await expect(minutesInput).toBeDisabled()
		await expect(modeInput).toBeDisabled()

		// Selecting a policy-enabled hourly type must reveal the control even
		// with vacation hours disabled — this was the dead path.
		await page.locator('#absence-type').selectOption('unpaid_leave')
		await expect(hourGroup).toBeVisible()
		await expect(minutesInput).toBeEnabled()
		await expect(modeInput).toBeEnabled()

		// Single-day pin: filling start forces end to the same day.
		const day = await freeFutureWeekday(page, 14)
		const start = page.locator('#absence-start-date')
		const end = page.locator('#absence-end-date')
		await start.fill(toDe(day))
		await start.dispatchEvent('change')
		await expect(end).toHaveValue(toDe(day))

		await minutesInput.fill(String(MINUTES))
		await page.locator('#absence-reason').fill(`atlas-hm ${UNIQ} hourly`)

		await page.locator('#absence-form button[type="submit"]').click()
		// End-anchored: the create URL /absences/create already contains
		// /absences — an unanchored pattern resolves instantly while the submit
		// fetch is still in flight (observed 2026-11-25: list re-read raced
		// the write and saw no row). Race navigation against the form error so
		// a server-side rejection fails fast with its message, not a timeout.
		const formError = page.locator('#absence-form-error')
		const navigation = page.waitForURL(
			/\/apps\/arbeitszeitcheck\/absences(\?|#|$)/, { timeout: 30_000 },
		)
		await Promise.race([
			navigation,
			formError.waitFor({ state: 'visible' }),
		])
		if (await formError.isVisible().catch(() => false)) {
			throw new Error(`hourly submit rejected: ${await formError.textContent()}`)
		}
		await navigation

		// Durable postcondition: re-read the persisted row — never trust the
		// redirect alone. durationMode/durationMinutes must be the stored
		// truth, not a silent day-mode reinterpretation.
		const list = await api(page, 'GET', `${APP}/api/absences?limit=500`)
		const rows = list.absences ?? list.items ?? []
		const created = rows.find((r) => String(r.reason ?? '').includes(`atlas-hm ${UNIQ}`))
		expect(created, 'hourly absence must persist via the rendered form').toBeTruthy()
		expect(created.type).toBe('unpaid_leave')
		expect(created.durationMode ?? created.duration_mode).toBe('hours')
		expect(Number(created.durationMinutes ?? created.duration_minutes)).toBe(MINUTES)
		expect(created.start_date ?? created.startDate).toBe(day)
		expect(created.end_date ?? created.endDate).toBe(day)

		// Cleanup: owner-delete the pending row (SQL sweep in afterAll is the
		// belt-and-suspenders fallback).
		const id = created.id
		if (id) {
			await apiAllowFailure(page, 'DELETE', `${APP}/api/absences/${id}`)
		}
	})

	test('hourly on + vacation-hours on: regression — both controls still reach', async ({ page }) => {
		writePolicy((doc) => { doc.hourlyAbsenceTypes = ['unpaid_leave', 'overtime_comp'] })
		occConfigSet(UNIT_KEY, 'hours')

		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto(`${APP}/absences/create`)

		const hoursGroup = page.locator('#absence-duration-hours-group')
		await expect(hoursGroup).toHaveAttribute('data-hours-mode', '1')

		// Vacation still reveals the vacation-hours field.
		await page.locator('#absence-type').selectOption('vacation')
		await expect(hoursGroup).toBeVisible()
		await expect(page.locator('#absence-duration-hours')).toBeEnabled()

		// Switching to an hourly type hides the vacation-hours field and
		// reveals the minutes control — the previously working combo.
		await page.locator('#absence-type').selectOption('unpaid_leave')
		await expect(hoursGroup).toBeHidden()
		await expect(page.locator('#absence-hour-mode-group')).toBeVisible()
		await expect(page.locator('#absence-duration-minutes')).toBeEnabled()
	})

	test('hourly off: control stays hidden for built-in types', async ({ page }) => {
		writePolicy((doc) => { doc.hourlyAbsenceTypes = [] })
		occConfigSet(UNIT_KEY, 'days')

		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto(`${APP}/absences/create`)

		await page.locator('#absence-type').selectOption('unpaid_leave')
		await expect(page.locator('#absence-hour-mode-group')).toBeHidden()
		await expect(page.locator('#absence-duration-minutes')).toBeDisabled()
	})
})
