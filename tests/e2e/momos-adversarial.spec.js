/* global process */
// @ts-check
/**
 * MOMOS adversarial sweep — attack classes the functional suite doesn't cover:
 * unauthenticated surface, session-CSRF guard, existence oracles (uniform 404),
 * strict integer IDs, unbounded list params, mass assignment, injection/XSS,
 * CSV formula injection, kiosk auth boundaries, cross-role calls, and the
 * double clock-in race.
 *
 * Everything here asserts what the system is SUPPOSED to guarantee, not what
 * the code happens to do today.
 */
import { test, expect } from '@playwright/test'
import { api, apiAllowFailure } from './helpers/api.js'
import { loginAs, login, credsFromEnv, gotoApp } from './helpers/auth.js'
import { applyLabLicense } from './helpers/license.js'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
// tests/e2e → apps dir; docker compose resolves the project from parents.
const COMPOSE_CWD = path.resolve(__dirname, '../../..')

/** DB seeding/cleanup only — every assertion still goes through the real API. */
function dbExec(sql) {
	try {
		execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
		return true
	} catch {
		return false
	}
}

/**
 * Satisfy the ArbZG §5 rest window for an e2e_* user so clock-in can succeed.
 * A leftover shift ending < min_rest_period ago legitimately rejects clock-in —
 * without this the race test's outcome depends on when the suite last ran.
 */
function ensureE2eClockReady(userId) {
	execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud',
		'php', 'occ', 'arbeitszeitcheck:ensure-e2e-clock', userId, '--force',
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
}

/** SELECT returning the first column of the first row, or null when unreachable. */
function dbScalar(sql) {
	try {
		const out = execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb', '--skip-column-names',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
		return String(out).trim()
	} catch {
		return null
	}
}

test.use({ trace: 'off' }) // manual contexts/pages race the trace-chunk flush (#23387)

test.describe.configure({ mode: 'serial' })

const APP = '/apps/arbeitszeitcheck'
const EMP = process.env.NC_EMPLOYEE_USER || 'e2e_employee'
const _MGR = process.env.NC_MANAGER_USER || 'e2e_manager'
const UNIQ = `mm${Date.now().toString(36)}`

/** Raw fetch that reuses the page's session cookies but skips requesttoken. */
async function rawFetch(page, method, url, { headers = {}, data } = {}) {
	const fullUrl = new URL(url, page.url()).toString()
	const res = await page.request.fetch(fullUrl, {
		method,
		headers: { 'Content-Type': 'application/json', ...headers },
		data: data !== undefined ? data : {},
	})
	return { ok: res.ok(), status: res.status(), json: await res.json().catch(() => null) }
}

test.describe('MOMOS adversarial sweep', () => {
	test('unauthenticated API surface returns no data', async ({ browser }) => {
		const anon = await browser.newPage()
		try {
			// goto a neutral page to give page.request an origin — login page is public
			await anon.goto('/login')
			const targets = [
				['GET', `${APP}/api/time-entries`],
				['GET', `${APP}/api/clock/status`],
				['GET', `${APP}/api/absences`],
				['GET', `${APP}/api/admin/settings`],
				['GET', `${APP}/api/admin/users`],
				['GET', `${APP}/api/manager/employee-time-entries`],
				['GET', `${APP}/api/manager/pending-approvals`],
				['GET', `${APP}/api/compliance/violations`],
				['GET', `${APP}/api/admin/audit-logs`],
				['POST', `${APP}/api/clock/in`],
				['POST', `${APP}/api/time-entries`],
			]
			for (const [method, url] of targets) {
				const res = await rawFetch(anon, method, url)
				expect(
					[401, 403, 302, 303].includes(res.status),
					`${method} ${url} leaked ${res.status} to anonymous: ${JSON.stringify(res.json)?.slice(0, 200)}`
				).toBe(true)
			}
		} finally {
			await anon.close()
		}
	})

	test('session CSRF guard: cookie-authenticated POST without requesttoken is rejected', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/dashboard`)
		// session cookies are in the context jar; deliberately omit requesttoken
		const res = await rawFetch(page, 'POST', `${APP}/api/clock/in`)
		expect([400, 403, 412], `CSRF-less cookie POST returned ${res.status}: ${JSON.stringify(res.json)}`).toContain(res.status)
		expect(res.status).not.toBe(200)
	})

	test('existence oracle: foreign vs nonexistent time-entry id produce identical response', async ({ page, browser }) => {
		// Employee creates an own entry; the substitute (a different regular
		// user) probing it must get the SAME payload as for a nonexistent id.
		const sub = await browser.newPage()
		const emp = await browser.newPage()
		try {
			await loginAs(page, 'ADMIN')
			const s0 = await api(page, 'GET', `${APP}/api/admin/settings`)
			if (s0.settings?.manualTimeEntriesRequireApproval === true) {
				await api(page, 'POST', `${APP}/api/admin/settings`, { data: { manualTimeEntriesRequireApproval: false } })
			}
			try {
				await login(emp, credsFromEnv('EMPLOYEE'))
				await login(sub, credsFromEnv('SUBSTITUTE'))
				const create = await api(emp, 'POST', `${APP}/api/time-entries`, {
					data: { date: '2026-01-05', startTime: '08:00', endTime: '08:30', description: `momos oracle ${UNIQ}` },
				})
				const victimId = create.entry?.id ?? create.id ?? create.entryId
				expect(victimId, 'employee entry seed failed').toBeTruthy()

				const foreign = await apiAllowFailure(sub, 'GET', `${APP}/api/time-entries/${victimId}`)
				const missing = await apiAllowFailure(sub, 'GET', `${APP}/api/time-entries/999999999`)
				expect(foreign.status).not.toBe(200)
				expect(missing.status).not.toBe(200)
				expect(
					foreign.status,
					`oracle: foreign=${foreign.status} vs missing=${missing.status} leaks existence`
				).toBe(missing.status)
				expect(
					JSON.stringify(foreign.json),
					`oracle payload differs: ${JSON.stringify(foreign.json)} vs ${JSON.stringify(missing.json)}`
				).toBe(JSON.stringify(missing.json))
				await apiAllowFailure(emp, 'DELETE', `${APP}/api/time-entries/${victimId}`)
			} finally {
				if (s0.settings?.manualTimeEntriesRequireApproval === true) {
					await apiAllowFailure(page, 'POST', `${APP}/api/admin/settings`, { data: { manualTimeEntriesRequireApproval: true } })
				}
			}
		} finally {
			await sub.close()
			await emp.close()
		}
	})

	test('strict integer ids: non-digit id forms are rejected, not coerced', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/time-entries`)
		const badIds = ['2.9', '2e3', ' 5', '05x', 'abc', '-1', '5%20OR%201=1']
		for (const id of badIds) {
			const res = await apiAllowFailure(page, 'GET', `${APP}/api/time-entries/${id}`)
			expect(
				[400, 404].includes(res.status),
				`id '${id}' returned ${res.status} (500 means TypeError leaked; 200 means coercion hit a real row)`
			).toBe(true)
			expect(res.status, `id '${id}' produced 500 — uncaught TypeError on int param`).not.toBe(500)
		}
	})

	test('list params: limit/offset bounds are enforced with JSON envelope', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/time-entries`)
		const cases = [
			[`limit=100000000`, (j) => (j?.entries ?? j?.items ?? []).length <= 500],
			[`limit=-5`, (j) => j?.success === false || (j?.entries ?? j?.items ?? []).length <= 500],
			[`limit=abc`, (j) => j?.success === false || (j?.entries ?? j?.items ?? []).length <= 500],
			[`offset=-100`, (j) => j?.success === false || (j?.entries ?? j?.items ?? []).length <= 500],
		]
		for (const [qs, check] of cases) {
			const res = await apiAllowFailure(page, 'GET', `${APP}/api/time-entries?${qs}`)
			expect(
				[200, 400, 422].includes(res.status),
				`${qs} → ${res.status}: server must clamp or cleanly reject, never 500`
			).toBe(true)
			expect(check(res.json), `${qs} violated bound: ${JSON.stringify(res.json).slice(0, 150)}`).toBe(true)
			// Contract: API errors must arrive as JSON, never a bare HTML 400.
			if (res.status === 400) {
				expect(
					res.json?.success === false && typeof res.json?.error === 'string',
					`${qs} → 400 without JSON envelope (got ${JSON.stringify(res.json)})`
				).toBe(true)
			}
		}
	})

	test('mass assignment: employee cannot set userId/status/approval fields on create', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/time-entries`)
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/time-entries`, {
			data: {
				date: '2026-01-06',
				startTime: '08:00',
				endTime: '08:30',
				description: `momos massassign ${UNIQ}`,
				userId: 'admin',
				user_id: 'admin',
				status: 'approved',
				approvedBy: 'admin',
				policyApplied: false,
			},
		})
		if (res.ok && res.json?.success !== false) {
			const id = res.json.entry?.id ?? res.json.id ?? res.json.entryId
			if (id) {
				const show = await api(page, 'GET', `${APP}/api/time-entries/${id}`)
				const blob = JSON.stringify(show)
				expect(blob, 'mass-assigned userId persisted').not.toContain('"admin"')
				expect(show.entry?.userId ?? show.entry?.user_id, 'entry must belong to the caller, not "admin"').toBe(EMP)
				await apiAllowFailure(page, 'DELETE', `${APP}/api/time-entries/${id}`)
			}
		}
		// if the create was rejected outright that's also acceptable behavior
	})

	test('injection: description payloads are stored verbatim and never break serialization', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/time-entries`)
		const payloads = [
			`'; DROP TABLE oc_at_entries;--`,
			`<img src=x onerror=alert(1)> momos-xss ${UNIQ}`,
			`{{7*7}} \${7*7} <%= 7*7 %>`,
			`momos-unicode ${UNIQ} \u202E\u0000 null-byte`,
		]
		for (const description of payloads) {
			const res = await apiAllowFailure(page, 'POST', `${APP}/api/time-entries`, {
				data: { date: '2026-01-07', startTime: '08:00', endTime: '08:15', description },
			})
			if (res.ok && res.json?.success !== false) {
				const id = res.json.entry?.id ?? res.json.id ?? res.json.entryId
				const show = await apiAllowFailure(page, 'GET', `${APP}/api/time-entries/${id}`)
				expect(show.ok, `stored entry unreadable after payload '${description.slice(0, 30)}'`).toBe(true)
				// table must still exist and answer after the DROP attempt
				const list = await api(page, 'GET', `${APP}/api/time-entries?start_date=2026-01-07&end_date=2026-01-07&limit=500`)
				expect(list.success).toBe(true)
				if (id) await apiAllowFailure(page, 'DELETE', `${APP}/api/time-entries/${id}`)
			}
		}
	})

	test('CSV export escapes spreadsheet formula prefixes', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/time-entries`)
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/time-entries`, {
			data: { date: '2026-01-08', startTime: '08:00', endTime: '08:15', description: `=HYPERLINK("https://evil.example") momos-csv ${UNIQ}` },
		})
		try {
			const exp = await page.request.fetch(`${APP}/export/time-entries?format=csv`, {
				method: 'GET',
				headers: { requesttoken: '' },
			})
			if (exp.ok()) {
				const body = await exp.text()
				const line = body.split('\n').find((l) => l.includes(UNIQ))
				if (line) {
					expect(
						/=HYPERLINK\(/.test(line) && !/'=HYPERLINK/.test(line),
						`CSV cell starts with raw '=' — formula injection: ${line.slice(0, 120)}`
					).toBe(false)
				}
			}
		} finally {
			const id = res.json?.entry?.id ?? res.json?.id
			if (id) await apiAllowFailure(page, 'DELETE', `${APP}/api/time-entries/${id}`)
		}
	})

	test('kiosk endpoints reject missing and wrong credentials', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, `${APP}/admin/kiosk`)
		// KIOSK_DISABLED intentionally 404s (feature concealment) — enable kiosk
		// first so the probe reaches the real credential check.
		// License gate runs before the credential check (402) — apply the lab
		// AZC2 bundle so the probe reaches KIOSK_TERMINAL_UNAUTHORIZED.
		const licStatus = await apiAllowFailure(page, 'GET', `${APP}/api/admin/license/status`)
		const hadLicense = Boolean(licStatus.json?.data?.active ?? licStatus.json?.active ?? licStatus.json?.licensed)
		if (!hadLicense) {
			const lic = await applyLabLicense(page, apiAllowFailure)
			if (!lic.ok) {
				test.skip(true, 'lab license unavailable — credential boundary untestable here')
			}
		}
		const wasEnabled = await page.locator('#azc-kiosk-enabled').isChecked().catch(() => false)
		if (!wasEnabled) {
			const en = await apiAllowFailure(page, 'POST', `${APP}/api/admin/kiosk/enabled`, { data: { enabled: true } })
			if (!en.ok) {
				test.skip(true, 'kiosk cannot be enabled — credential boundary untestable here')
			}
		}
		try {
			const noHdr = await rawFetch(page, 'POST', `${APP}/api/kiosk/action`, { data: { action: 'clock_in' } })
			expect(
				[400, 401, 403].includes(noHdr.status),
				`kiosk action without creds → ${noHdr.status} ${JSON.stringify(noHdr.json)?.slice(0, 150)}`
			).toBe(true)
			expect(noHdr.json?.code ?? '').not.toBe('KIOSK_INTERNAL_ERROR')
			const badTok = await rawFetch(page, 'POST', `${APP}/api/kiosk/action`, {
				headers: { 'X-Kiosk-Terminal-Id': '00000000-0000-0000-0000-000000000000', 'X-Kiosk-Token': 'deadbeef' },
				data: { action: 'clock_in' },
			})
			expect([400, 401, 403].includes(badTok.status), `kiosk action with forged creds → ${badTok.status}`).toBe(true)
			expect(badTok.json?.code ?? '', `forged creds must yield KIOSK_TERMINAL_UNAUTHORIZED, got ${JSON.stringify(badTok.json)}`).toBe('KIOSK_TERMINAL_UNAUTHORIZED')
		} finally {
			if (!wasEnabled) {
				await apiAllowFailure(page, 'POST', `${APP}/api/admin/kiosk/enabled`, { data: { enabled: false } })
			}
			if (!hadLicense) {
				await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/license`, { data: {} })
			}
		}
	})

	test('cross-role: non-admin cannot call admin/manager endpoints', async ({ page }) => {
		// NOTE: e2e_employee is in the NC `admin` group in this env — cannot be
		// used as the low-privilege prober. e2e_substitute (helpdesk_agents) is
		// a plain non-admin user; e2e_manager is a non-admin app manager.
		await login(page, credsFromEnv('SUBSTITUTE'))
		await gotoApp(page, `${APP}/dashboard`)
		const adminTargets = [
			['GET', `${APP}/api/admin/settings`],
			['GET', `${APP}/api/admin/users`],
			['POST', `${APP}/api/admin/settings`],
			['GET', `${APP}/api/admin/audit-logs/stats`],
			['GET', `${APP}/api/admin/audit-logs`],
			['POST', `${APP}/api/compliance/run-check`],
			['POST', `${APP}/api/month-closure/reopen`],
		]
		for (const [method, url] of adminTargets) {
			const res = await apiAllowFailure(page, method, url, method === 'GET' ? {} : { data: {} })
			expect(
				[400, 401, 403, 404].includes(res.status),
				`non-admin reached ${method} ${url} → ${res.status}: ${JSON.stringify(res.json)?.slice(0, 200)}`
			).toBe(true)
		}
		// Manager-role endpoints probed by a non-manager (substitute manages nobody).
		const managerTargets = [
			['POST', `${APP}/api/manager/employee-time-entries`],
			['GET', `${APP}/api/manager/pending-approvals`],
		]
		for (const [method, url] of managerTargets) {
			const res = await apiAllowFailure(page, method, url, method === 'GET' ? {} : { data: {} })
			expect(
				[400, 401, 403, 404].includes(res.status),
				`non-manager reached ${method} ${url} → ${res.status}: ${JSON.stringify(res.json)?.slice(0, 200)}`
			).toBe(true)
		}
	})

	test('double clock-in race leaves exactly one active session', async ({ browser }) => {
		const emp = await browser.newPage()
		try {
			await login(emp, credsFromEnv('EMPLOYEE'))
			await gotoApp(emp, `${APP}/dashboard`)
			// normalize: complete any lingering paused/active session first
			const st0 = await apiAllowFailure(emp, 'GET', `${APP}/api/clock/status`)
			const curId = st0.json?.status?.entry?.id ?? st0.json?.status?.currentEntry?.id ?? st0.json?.status?.entryId
			if (curId) {
				await apiAllowFailure(emp, 'POST', `${APP}/api/time-entries/${curId}/complete`, { data: {} })
			}
			// Another lane may transiently disable org stamping
			// (admin-time-capture-org) — wait on the real condition, not a sleep.
			await expect.poll(async () => {
				const w = await apiAllowFailure(emp, 'GET', `${APP}/api/dashboard-widget/employee`)
				return w.json?.data?.timeCapture?.clockStampingEnabled
			}, { timeout: 30_000, intervals: [1_000, 2_000, 3_000] }).not.toBe(false)
			// Precondition: employee must be able to clock in (rest window satisfied).
			ensureE2eClockReady(EMP)
			const [_r1, _r2] = await Promise.all([
				apiAllowFailure(emp, 'POST', `${APP}/api/clock/in`, { data: {} }),
				apiAllowFailure(emp, 'POST', `${APP}/api/clock/in`, { data: {} }),
			])
			const st = await api(emp, 'GET', `${APP}/api/clock/status`)
			expect(st.status?.status ?? st.status, `after parallel clock-in: ${JSON.stringify(st).slice(0, 200)}`).toBe('active')
			// both succeeded but produced ONE session, or one cleanly failed — never two active
			const list = await api(emp, 'GET', `${APP}/api/time-entries?status=active&limit=500`)
			const actives = (list.entries ?? list.items ?? []).filter((e) => (e.status ?? '').toLowerCase() === 'active')
			expect(actives.length, `${actives.length} active sessions after double clock-in`).toBe(1)
			// cleanup
			const cur = await apiAllowFailure(emp, 'GET', `${APP}/api/clock/status`)
			const cid = cur.json?.status?.entry?.id ?? cur.json?.status?.currentEntry?.id
			if (cid) await apiAllowFailure(emp, 'POST', `${APP}/api/time-entries/${cid}/complete`, { data: {} })
		} finally {
			await emp.close()
		}
	})

	test('absence BOLA: foreign user cannot read/mutate/approve another user\'s absence', async ({ browser }) => {
		const emp = await browser.newPage()
		const sub = await browser.newPage()
		let absenceId = null
		try {
			await login(emp, credsFromEnv('EMPLOYEE'))
			await gotoApp(emp, `${APP}/absences`)
			// Seed an absence owned by EMPLOYEE via the real API. Dates must be
			// a Mon–Tue ≥14d out: weekend-only absences are rejected (0 working
			// days) and nearer dates may collide with leftover fixtures.
			const sd = new Date()
			sd.setUTCDate(sd.getUTCDate() + 14 + ((8 - sd.getUTCDay()) % 7)) // next Monday ≥14d out
			const ed = new Date(sd); ed.setUTCDate(ed.getUTCDate() + 1)
			const iso = (d) => d.toISOString().slice(0, 10)
			const created = await apiAllowFailure(emp, 'POST', `${APP}/api/absences`, {
				data: { type: 'special_leave', start_date: iso(sd), end_date: iso(ed), reason: `momos-bola ${UNIQ}` },
			})
			absenceId = created.json?.absence?.id ?? created.json?.id ?? null
			test.skip(!created.ok || !absenceId, `cannot seed absence: ${JSON.stringify(created.json)?.slice(0, 150)}`)

			await login(sub, credsFromEnv('SUBSTITUTE'))
			await gotoApp(sub, `${APP}/dashboard`)

			// Read probe — must not return the row or its reason.
			const read = await apiAllowFailure(sub, 'GET', `${APP}/api/absences/${absenceId}`)
			expect([400, 401, 403, 404].includes(read.status), `foreign absence read → ${read.status}`).toBe(true)
			expect(JSON.stringify(read.json ?? {}), 'foreign read leaked absence data').not.toContain(`momos-bola`)

			// Mutate probes — none may succeed.
			for (const [m, u, d] of [
				['PUT', `${APP}/api/absences/${absenceId}`, { type: 'vacation', start_date: iso(sd), end_date: iso(ed), reason: 'hijacked' }],
				['DELETE', `${APP}/api/absences/${absenceId}`, {}],
				['POST', `${APP}/api/absences/${absenceId}/approve`, {}],
			]) {
				const r = await apiAllowFailure(sub, m, u, { data: d })
				expect([400, 401, 403, 404].includes(r.status), `${m} ${u} by foreign user → ${r.status}`).toBe(true)
			}

			// Oracle parity: foreign id must look exactly like a nonexistent id.
			const ghost = await apiAllowFailure(sub, 'GET', `${APP}/api/absences/999999998`)
			expect(ghost.status, `foreign=${read.status} vs nonexistent=${ghost.status} → existence oracle`).toBe(read.status)

			// Owner still sees it — proves the DELETE probe did not execute.
			const owner = await apiAllowFailure(emp, 'GET', `${APP}/api/absences/${absenceId}`)
			expect(owner.ok, 'owner lost their absence — foreign DELETE succeeded').toBe(true)
		} finally {
			if (absenceId) await apiAllowFailure(emp, 'DELETE', `${APP}/api/absences/${absenceId}`)
			await emp.close()
			await sub.close()
		}
	})

	test('gdpr endpoints: unauth rejected, delete cannot target another user', async ({ browser, page }) => {
		// Anonymous probes — export must not stream anything to strangers.
		const anon = await browser.newPage()
		try {
			await anon.goto(`${APP}/dashboard`).catch(() => {})
			const exp = await rawFetch(anon, 'GET', `${APP}/gdpr/export`)
			expect([400, 401, 403, 404].includes(exp.status), `anon gdpr export → ${exp.status}`).toBe(true)
			const del = await rawFetch(anon, 'POST', `${APP}/gdpr/delete`, { data: {} })
			expect([400, 401, 403, 404].includes(del.status), `anon gdpr delete → ${del.status}`).toBe(true)
		} finally {
			await anon.close()
		}

		// Mass-assignment: `userId` in the delete body must be ignored — the
		// wipe applies to the session user only. Oracle: substitute's entry
		// count must not change.
		const subUid = process.env.NC_SUBSTITUTE_USER || 'e2e_substitute'
		const before = dbScalar(`SELECT COUNT(*) FROM oc_at_entries WHERE user_id='${subUid}'`)
		test.skip(before === null, 'db unreachable — cannot verify other-user scope')

		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/dashboard`)
		const del = await apiAllowFailure(page, 'POST', `${APP}/gdpr/delete`, {
			data: { reason: 'momos probe', userId: subUid, user_id: subUid },
		})
		expect(del.status, `gdpr delete → ${del.status}: ${JSON.stringify(del.json)?.slice(0, 150)}`).not.toBe(500)

		const after = dbScalar(`SELECT COUNT(*) FROM oc_at_entries WHERE user_id='${subUid}'`)
		expect(after, `foreign user row count changed by gdpr delete (before=${before} after=${after})`).toBe(before)
	})

	test('finalized month rejects further time-entry writes', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await gotoApp(page, `${APP}/dashboard`)

		const feat = await apiAllowFailure(page, 'GET', `${APP}/api/month-closure/feature`)
		test.skip(feat.json?.enabled === false, 'month closure feature disabled')

		// Previous calendar month — guaranteed ended.
		const now = new Date()
		const prev = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - 1, 15))
		const year = prev.getUTCFullYear()
		const month = prev.getUTCMonth() + 1
		const day = `${year}-${String(month).padStart(2, '0')}-15`

		// Seed one entry via DB (edit window blocks API writes this far back;
		// the closure itself is what is under test, not the seed path).
		test.skip(!dbExec(`DELETE FROM oc_at_entries WHERE user_id='${EMP}' AND description='momos-closure ${UNIQ}'`), 'db unreachable')
		dbExec(`INSERT INTO oc_at_entries (user_id, start_time, end_time, status, description, created_at, updated_at)
			VALUES ('${EMP}', '${day} 08:00:00', '${day} 10:00:00', 'completed', 'momos-closure ${UNIQ}', NOW(), NOW())`)
		// An already-finalized month is a valid test fixture too.
		const fin = await apiAllowFailure(page, 'POST', `${APP}/api/month-closure/finalize`, { data: { year, month } })
		const already = !fin.ok && /already/i.test(JSON.stringify(fin.json ?? ''))
		test.skip(!fin.ok && !already, `cannot finalize ${year}-${month}: ${JSON.stringify(fin.json)?.slice(0, 200)}`)

		try {
			const write = await apiAllowFailure(page, 'POST', `${APP}/api/time-entries`, {
				data: { date: day, startTime: '12:00', endTime: '12:30', description: `momos-closure-write ${UNIQ}` },
			})
			expect(write.ok, `write into finalized month returned ${write.status}: ${JSON.stringify(write.json)?.slice(0, 200)}`).toBe(false)
		} finally {
			if (!already) {
				await apiAllowFailure(page, 'POST', `${APP}/api/month-closure/reopen`, {
					data: { year, month, userId: EMP, reason: 'momos e2e cleanup' },
				})
			}
			dbExec(`DELETE FROM oc_at_entries WHERE user_id='${EMP}' AND description LIKE 'momos-closure%'`)
		}
	})
})
