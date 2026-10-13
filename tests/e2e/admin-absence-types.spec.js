// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv, gotoApp } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

/**
 * Custom admin-defined absence types ("weitere betriebliche Fehlzeiten").
 *
 * Proves end-to-end against the live stack:
 *  1. Admin page renders + create form is keyboard-reachable and labelled.
 *  2. CRUD via the admin API: create → list → update → deactivate.
 *  3. Validation contract: malformed slug / XSS label / bad color → 400 fields map.
 *  4. Explicit slug collision → 409 (not silent suffixing).
 *  5. Employee surface: created type appears in /api/absences/types and in
 *     the create-form select; deactivated type disappears from creatable list
 *     but the public endpoint still reports it (history must resolve).
 *  6. In-use delete → deactivated (not removed); unused delete → gone.
 *  7. Uniform 404 for missing id (no existence oracle).
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const APP = '/apps/arbeitszeitcheck'
const COMPOSE_CWD = path.resolve(__dirname, '../../..')
const UNIQ = `ct${Date.now().toString(36)}`
const LABEL = `E2E Custom ${UNIQ}`

/** Unwrap the admin API envelope ({success, type|types|mode,...}). */
const typeOf = (j) => j?.type || j
const typesOf = (j) => j?.types || j?.items || (Array.isArray(j) ? j : [])

/** Switch roles mid-test: drop the session cookies, then log in fresh. */
async function relogin(page, role) {
	await page.context().clearCookies()
	await login(page, credsFromEnv(role))
}

/** Next weekday at least `daysAhead` out, ISO Y-m-d (the API's format). */
function futureWeekday(daysAhead) {
	const d = new Date()
	d.setUTCDate(d.getUTCDate() + daysAhead)
	while (d.getUTCDay() === 0 || d.getUTCDay() === 6) d.setUTCDate(d.getUTCDate() + 1)
	return d.toISOString().slice(0, 10)
}

test.use({ trace: 'off' })
test.describe.configure({ mode: 'serial' })

/** Best-effort cleanup of this spec's rows. */
function dbDeleteTypeRemnants() {
	const sql = `DELETE FROM oc_at_custom_absence WHERE label LIKE 'E2E Custom%' OR label LIKE 'E2E Used%' OR label LIKE 'E2E DBG%';
	DELETE FROM oc_at_absences WHERE reason LIKE 'e2e-inuse-%' OR reason LIKE 'e2e-crafted-%' OR reason LIKE 'e2e-dbg%'`
	try {
		execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	} catch { /* best-effort */ }
}

test.describe('Custom absence types (admin-defined)', () => {
	test.skip(!process.env.NC_ADMIN_USER || !process.env.NC_EMPLOYEE_PASS, 'e2e creds required')

	test.beforeAll(() => { dbDeleteTypeRemnants() })
	test.afterAll(() => { dbDeleteTypeRemnants() })

	let createdId = null
	let createdCode = null

	test('admin page renders with labelled create form', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)

		// Page chrome
		await expect(page.locator('body')).toContainText(/absence type|Abwesenheitstyp/i)

		// Editor is JS-rendered behind the create toggle (same pattern as
		// working-time-models admin page).
		await page.click('#cat-create')
		const labelInput = page.locator('#cat-label')
		await expect(labelInput).toBeVisible({ timeout: 15000 })
		await expect(page.locator('label[for="cat-label"]')).toHaveCount(1)
		// Field-level error slot + required marker contract (WCAG 3.3.1/3.3.2).
		await expect(page.locator('.cat-field-error[data-field="label"]')).toHaveCount(1)
		await expect(page.locator('#cat-soll-credit')).toBeVisible()
		await expect(page.locator('#cat-max-days')).toBeVisible()
	})

	test('CRUD via admin API: create → list → read', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)

		const created = await api(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: {
				label: LABEL,
				labelMap: { en: `E2E Outing ${UNIQ}`, de: `E2E Ausflug ${UNIQ}` },
				sollCredit: true,
				maxDays: '3',
				color: 'success',
				icon: 'users',
			},
		})
		const t0 = typeOf(created)
		expect(t0.id).toBeTruthy()
		createdId = t0.id
		createdCode = t0.code
		expect(createdCode).toMatch(/^custom_[a-z0-9_]+$/)
		expect(t0.active).toBe(true)
		expect(t0.maxDays).toBe(3)

		const list = await api(page, 'GET', `${APP}/api/admin/absence-types`)
		expect(typesOf(list).some((r) => r.id === createdId)).toBe(true)

		const read = await api(page, 'GET', `${APP}/api/admin/absence-types/${createdId}`)
		expect(typeOf(read).code).toBe(createdCode)
	})

	test('update + deactivate via admin API', async ({ page }) => {
		test.skip(!createdId, 'depends on previous test')
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)

		const updated = await api(page, 'PUT', `${APP}/api/admin/absence-types/${createdId}`, {
			data: { active: 'false', maxDays: '5' },
		})
		expect(typeOf(updated).active).toBe(false)
		expect(typeOf(updated).maxDays).toBe(5)
	})

	test('validation rejects malformed payloads with field errors', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)

		for (const [data, field] of [
			[{ label: '<script>x</script>' }, 'label'],
			[{ label: 'X', slug: '-bad_slug-' }, 'code'],
			[{ label: 'X', color: 'hotpink' }, 'color'],
			[{ label: 'X', icon: 'skull' }, 'icon'],
			[{ label: 'X', maxDays: 'abc' }, 'maxDays'],
			[{ label: 'X', labelMap: { 'not a locale': 'x' } }, 'labelMap'],
		]) {
			const res = await apiAllowFailure(page, 'POST', `${APP}/api/admin/absence-types`, { data })
			expect(res.status, `${JSON.stringify(data)} must 400`).toBe(400)
			const fields = res.json?.fields || res.json?.errors || {}
			expect(fields[field], `field error for ${field}`).toBeTruthy()
		}
	})

	test('explicit slug collision returns 409, generated slug suffixes', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)

		const slug = `e2e_collision_${UNIQ}`
		const first = await api(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: { label: `E2E Used collision ${UNIQ} a`, slug },
		})
		const clash = await apiAllowFailure(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: { label: `E2E Used collision ${UNIQ} b`, slug },
		})
		expect(clash.status).toBe(409)

		// same label twice without explicit slug → auto-suffix, not 409
		const g1 = await api(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: { label: `E2E Used genslug ${UNIQ}` },
		})
		const g2 = await api(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: { label: `E2E Used genslug ${UNIQ}` },
		})
		expect(typeOf(g1).code).not.toBe(typeOf(g2).code)
	})

	test('missing id returns uniform 404 (no existence oracle)', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)

		for (const method of ['GET', 'PUT', 'DELETE']) {
			const res = await apiAllowFailure(page, method, `${APP}/api/admin/absence-types/999999999`, {
				data: method !== 'GET' ? { label: 'x' } : undefined,
			})
			expect(res.status, `${method} missing id must 404`).toBe(404)
		}
	})

	test('employee surface: created type appears in types API + form select', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)
		const t = await api(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: { label: `E2E Used visible ${UNIQ}`, sollCredit: 'false' },
		})

		await relogin(page, 'EMPLOYEE')
		await gotoApp(page, `${APP}/absences`)

		// Public endpoint must list it with a resolved label.
		const types = await api(page, 'GET', `${APP}/api/absences/types`)
		const rows = typesOf(types)
		const entry = rows.find((r) => r.key === typeOf(t).code || r.code === typeOf(t).code)
		expect(entry, 'custom type must be exposed to employees').toBeTruthy()
		expect(entry.label).toBeTruthy()

		// And the create form must offer it as an option.
		await gotoApp(page, `${APP}/absences/create`)
		await page.waitForSelector('#absence-type', { timeout: 30000 })
		const options = await page.evaluate(() =>
			Array.from(document.querySelectorAll('#absence-type option')).map((o) => o.value),
		)
		expect(options).toContain(typeOf(t).code)

		// Deactivate → disappears from creatable set, still listed as valid type.
		await relogin(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/absence-types`)
		await api(page, 'PUT', `${APP}/api/admin/absence-types/${typeOf(t).id}`, { data: { active: 'false' } })

		await relogin(page, 'EMPLOYEE')
		await gotoApp(page, `${APP}/absences/create`)
		await page.waitForSelector('#absence-type', { timeout: 30000 })
		const optionsAfter = await page.evaluate(() =>
			Array.from(document.querySelectorAll('#absence-type option')).map((o) => o.value),
		)
		expect(optionsAfter).not.toContain(typeOf(t).code)

		// Crafted request must still be rejected server-side — a missing
		// select option is not a security boundary.
		const day = futureWeekday(21)
		const crafted = await apiAllowFailure(page, 'POST', `${APP}/api/absences`, {
			data: { type: typeOf(t).code, start_date: day, end_date: day, reason: `e2e-crafted-${UNIQ}` },
		})
		expect(crafted.ok, 'deactivated type must reject crafted create').toBe(false)
		expect(String(crafted.json?.error || '')).toMatch(/no longer available|not.*available|nicht.*verf.gbar/i)
	})

	test('in-use delete deactivates; unused delete removes', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await gotoApp(page, `${APP}/admin/absence-types`)

		// Unused → hard delete
		const unused = await api(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: { label: `E2E Used unused ${UNIQ}` },
		})
		const del = await api(page, 'DELETE', `${APP}/api/admin/absence-types/${typeOf(unused).id}`)
		expect(del.mode).toBe('deleted')
		const gone = await apiAllowFailure(page, 'GET', `${APP}/api/admin/absence-types/${typeOf(unused).id}`)
		expect(gone.status).toBe(404)

		// Used → deactivate (insert absence via employee API)
		const used = await api(page, 'POST', `${APP}/api/admin/absence-types`, {
			data: { label: `E2E Used inuse ${UNIQ}` },
		})
		await relogin(page, 'EMPLOYEE')
		await gotoApp(page, `${APP}/absences`)
		const day2 = futureWeekday(14)
		const created = await api(page, 'POST', `${APP}/api/absences`, {
			data: { type: typeOf(used).code, start_date: day2, end_date: day2, reason: `e2e-inuse-${UNIQ}` },
		})
		expect(created, 'absence with custom type must create').toBeTruthy()

		await relogin(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/absence-types`)
		const del2 = await api(page, 'DELETE', `${APP}/api/admin/absence-types/${typeOf(used).id}`)
		expect(del2.mode, 'in-use type must deactivate, not delete').toBe('deactivated')
		expect(del2.absenceCount).toBeGreaterThan(0)
	})
})
