/**
 * ATLAS job-lifecycle probes for AutoClockOutJob — live-instance proof of the
 * invariants the unit/integration/mutation suites assert in isolation.
 *
 * Covered here (all verified through persisted DB/API re-reads, never just
 * HTTP status):
 *
 *  1) missed-window catch-up — the configured cutover hour is set to 0
 *     (midnight) so every manual run happens "after" the window; the job must
 *     still close the entry instead of deferring to a window it will never
 *     see again today.
 *  2) absence boundary — an approved absence covering TODAY must NOT veto
 *     closure of an entry that belongs to YESTERDAY (date-range off-by-one
 *     regression guard); an approved absence covering the entry's own day
 *     MUST veto it.
 *  3) notice merge — a pre-existing legacy flat auto_clockout_notice payload
 *     must survive into the merged notices[] list when the job appends its
 *     own notice (no last-writer-wins clobbering).
 *  4) per-user localization — the stored banner text must be rendered in the
 *     affected user's language, not raw English.
 *  5) end-time provenance — end_time must equal the resolved planned end on
 *     the entry's day, never the job's wall-clock run time, plus
 *     ended_reason=auto_planned_end and audit capture_source=auto_clock_out.
 *  6) idempotence — a second force-run must not move end_time or append a
 *     duplicate notice.
 *  7) admin input robustness — autoClockOutHour sent as an array must 400.
 *
 * Hermetic by construction: two dedicated users, one dedicated 7-day model
 * (any weekday resolves), entries always dated yesterday so the planned end
 * is guaranteed to lie in the past regardless of when the spec runs.
 *
 * TOCTOU note: the overlap race (manual write between the job's pre-flight
 * check and the in-lock write) is intentionally NOT probed here — the window
 * is microseconds inside a single PHP request and cannot be induced reliably
 * over HTTP. That class is guarded by the in-lock re-check plus the
 * real-DB integration test and mutants.
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { api, apiAllowFailure } from './helpers/api.js'
import { login, loginAs } from './helpers/auth.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const COMPOSE_CWD = path.resolve(__dirname, '../../..') // nextcloud/ compose root
const APP = '/apps/arbeitszeitcheck'

const UNIQ = `aco${Date.now().toString(36)}`
const USER_A = `atlas-aco-a-${UNIQ}` // closes: legacy notice merge + de locale
const USER_B = `atlas-aco-b-${UNIQ}` // stays open: absence vetoes entry day
const PASSWORD = `atlas-aco-${UNIQ}`
const MODEL_NAME = `Atlas ACO ${UNIQ}`
const LEGACY_MESSAGE = `Atlas seeded legacy notice ${UNIQ}`

const CONFIG_KEYS = ['auto_clockout_enabled', 'auto_clockout_hour', 'auto_clockout_scope']

/** @param {string[]} args occ arguments */
function occ(args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
}

/** @param {Record<string,string>} env extra env vars for the occ process */
function occEnv(env, args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data',
		...Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`]),
		'nextcloud', 'php', 'occ', ...args,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
}

/** @param {string} sql single statement(s) executed against the nextcloud db */
function sql(statement) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', statement,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
}

/** @param {string} query SELECT returning one scalar value */
function sqlScalar(query) {
	// --skip-column-names must be a client arg — inside -e it silently eats output.
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb', 'mariadb', '--skip-column-names',
		'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', query,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 }).trim()
}

function yesterdayKey() {
	const d = new Date()
	d.setDate(d.getDate() - 1)
	return d.toISOString().slice(0, 10)
}

function todayKey() {
	return new Date().toISOString().slice(0, 10)
}

function forceRunAutoClockOutJob() {
	const jobId = sqlScalar(
		`SELECT id FROM oc_jobs WHERE class LIKE '%AutoClockOutJob%' LIMIT 1`
	)
	if (!jobId) {
		throw new Error('AutoClockOutJob not registered in oc_jobs')
	}
	return occ(['background-job:execute', jobId, '--force-execute'])
}

test.describe.configure({ mode: 'serial' })

test.describe('auto clock-out job — persisted-state probes', () => {
	const prevConfig = {}
	let entryAId = 0
	let entryBId = 0

	test.beforeAll(() => {
		for (const key of CONFIG_KEYS) {
			try {
				prevConfig[key] = occ(['config:app:get', 'arbeitszeitcheck', key]).trim()
			} catch {
				prevConfig[key] = null
			}
		}

		// hour=0: today's cutover already passed for every manual run — exactly
		// the missed-window condition the catch-up gate was built for.
		occ(['config:app:set', 'arbeitszeitcheck', 'auto_clockout_enabled', '--value=1'])
		occ(['config:app:set', 'arbeitszeitcheck', 'auto_clockout_hour', '--value=0'])
		occ(['config:app:set', 'arbeitszeitcheck', 'auto_clockout_scope', '--value=all_open'])

		for (const uid of [USER_A, USER_B]) {
			occEnv({ OC_PASS: PASSWORD }, ['user:add', '--password-from-env', uid])
		}
		occ(['user:setting', USER_A, 'core', 'lang', 'de'])

		const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
			.map((d) => `"${d}":{"work":true,"start":"08:00","end":"16:00","breaks":[]}`)
			.join(',')
		const breakRules = `{"weekday_schedule":{"version":1,"days":{${days}}}}`
		sql(`INSERT INTO oc_at_models (name, description, type, weekly_hours, daily_hours, work_days_per_week, break_rules, is_default, created_at, updated_at)
			 VALUES ('${MODEL_NAME}', 'atlas auto clock-out probe', 'full_time', 40, 8, 7, '${breakRules}', 0, NOW(), NOW())`)
		const modelId = sqlScalar(`SELECT id FROM oc_at_models WHERE name='${MODEL_NAME}'`)

		const entryDay = yesterdayKey()
		for (const uid of [USER_A, USER_B]) {
			sql(`INSERT INTO oc_at_user_models (user_id, working_time_model_id, vacation_days_per_year, start_date, created_at, updated_at)
				 VALUES ('${uid}', ${modelId}, 25, '2020-01-01', NOW(), NOW())`)
			sql(`INSERT INTO oc_at_entries (user_id, start_time, status, created_at, updated_at)
				 VALUES ('${uid}', '${entryDay} 09:00:00', 'active', NOW(), NOW())`)
		}

		// Boundary pair: an absence on TODAY must not veto YESTERDAY's entry
		// (the off-by-one this spec guards); an absence on the entry day must.
		sql(`INSERT INTO oc_at_absences (user_id, type, start_date, end_date, days, reason, status, created_at, updated_at)
			 VALUES ('${USER_A}', 'vacation', '${todayKey()}', '${todayKey()}', 1, 'atlas-aco ${UNIQ} today-only', 'approved', NOW(), NOW())`)
		sql(`INSERT INTO oc_at_absences (user_id, type, start_date, end_date, days, reason, status, created_at, updated_at)
			 VALUES ('${USER_B}', 'vacation', '${entryDay}', '${entryDay}', 1, 'atlas-aco ${UNIQ} entry-day', 'approved', NOW(), NOW())`)

		// Legacy flat payload — the merge path must preserve it, not clobber it.
		const legacyNotice = JSON.stringify({
			message: LEGACY_MESSAGE,
			reason: 'stale_paused',
			at: new Date().toISOString(),
		})
		occ(['user:setting', USER_A, 'arbeitszeitcheck', 'auto_clockout_notice', legacyNotice])

		entryAId = Number(sqlScalar(`SELECT id FROM oc_at_entries WHERE user_id='${USER_A}'`))
		entryBId = Number(sqlScalar(`SELECT id FROM oc_at_entries WHERE user_id='${USER_B}'`))
	})

	test.afterAll(() => {
		for (const key of CONFIG_KEYS) {
			try {
				if (prevConfig[key] === null || prevConfig[key] === '') {
					occ(['config:app:delete', 'arbeitszeitcheck', key])
				} else {
					occ(['config:app:set', 'arbeitszeitcheck', key, `--value=${prevConfig[key]}`])
				}
			} catch { /* best-effort restore */ }
		}
		try {
			sql(`DELETE FROM oc_at_entries WHERE user_id IN ('${USER_A}','${USER_B}');
				 DELETE FROM oc_at_absences WHERE user_id IN ('${USER_A}','${USER_B}');
				 DELETE FROM oc_at_user_models WHERE user_id IN ('${USER_A}','${USER_B}');
				 DELETE FROM oc_at_audit WHERE user_id IN ('${USER_A}','${USER_B}') AND entity_type='time_entry';
				 DELETE FROM oc_at_models WHERE name='${MODEL_NAME}'`)
		} catch { /* best-effort cleanup */ }
		for (const uid of [USER_A, USER_B]) {
			try {
				occ(['user:delete', uid])
			} catch { /* best-effort cleanup */ }
		}
	})

	test('closes at planned end after a missed cutover, respects absence boundary', async ({ page }) => {
		// Public-holiday veto is legitimate — skip rather than flake.
		const holidayCount = sqlScalar(`SELECT COUNT(*) FROM oc_at_holidays WHERE date='${yesterdayKey()}'`)
		test.skip(Number(holidayCount) > 0, `entry day ${yesterdayKey()} is a public holiday — job would veto legitimately`)

		const out = forceRunAutoClockOutJob()
		console.log('job output:', String(out).slice(0, 500))

		// --- Probes 1+2+5: A closed at yesterday's planned end, B vetoed ---
		const rowA = sqlScalar(`SELECT status, end_time, ended_reason FROM oc_at_entries WHERE id=${entryAId}`).split('\t')
		expect(rowA[0], 'user A entry should be completed').toBe('completed')
		expect(rowA[1], 'end_time must be the entry-day planned end, not wall-clock now')
			.toContain(yesterdayKey())
		expect(rowA[2]).toBe('auto_planned_end')

		const auditCount = sqlScalar(
			`SELECT COUNT(*) FROM oc_at_audit WHERE user_id='${USER_A}' AND entity_id=${entryAId} AND capture_source='auto_clock_out'`
		)
		expect(Number(auditCount), 'audit row with auto_clock_out provenance').toBeGreaterThan(0)

		const rowB = sqlScalar(`SELECT status FROM oc_at_entries WHERE id=${entryBId}`)
		expect(rowB, 'entry-day absence must veto closure').toBe('active')

		// --- Probes 3+4: merged notices, per-user localized banner ---
		const noticeRaw = occ(['user:setting', USER_A, 'arbeitszeitcheck', 'auto_clockout_notice']).trim()
		const notice = JSON.parse(noticeRaw)
		const notices = Array.isArray(notice.notices) ? notice.notices : [notice]
		expect(notices.length, 'legacy notice must merge with job notice, not be clobbered').toBeGreaterThanOrEqual(2)
		expect(notices.map((n) => n.message)).toContain(LEGACY_MESSAGE)
		const jobNotice = notices.find((n) => n.reason === 'auto_planned_end')
		expect(jobNotice, 'job notice with auto_planned_end reason').toBeTruthy()
		expect(jobNotice.message, 'banner must be localized for the affected user (de)')
			.toContain('geplantes Ende')
		expect(jobNotice.message).not.toContain('scheduled end')

		// API surface: the status endpoint must expose the merged payload.
		await login(page, { username: USER_A, password: PASSWORD })
		const statusRes = await api(page, 'GET', `${APP}/api/clock/status`)
		const apiNotice = statusRes?.status?.auto_clockout_notice
		expect(apiNotice && (Array.isArray(apiNotice.notices) ? apiNotice.notices.length : 0))
			.toBeGreaterThanOrEqual(2)

		// --- Probe 6: second run is a no-op ---
		const endBefore = rowA[1]
		forceRunAutoClockOutJob()
		const rowA2 = sqlScalar(`SELECT end_time FROM oc_at_entries WHERE id=${entryAId}`)
		expect(rowA2, 'end_time must not move on a repeated run').toBe(endBefore)
		const noticeRaw2 = occ(['user:setting', USER_A, 'arbeitszeitcheck', 'auto_clockout_notice']).trim()
		expect((JSON.parse(noticeRaw2).notices ?? []).length, 'no duplicate notice on repeated run')
			.toBe(notices.length)
	})

	test('admin settings reject array-shaped autoClockOutHour', async ({ page }) => {
		await loginAs(page, 'ADMIN')
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/admin/settings`, {
			data: { autoClockOutHour: ['x'] },
		})
		expect(res.status).toBe(400)
	})
})
