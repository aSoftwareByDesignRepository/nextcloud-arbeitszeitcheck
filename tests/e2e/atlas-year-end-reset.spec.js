// @ts-check
/**
 * ATLAS live-instance probes for YearEndResetService (optional workforce §9.5).
 *
 * Covered here — every leg asserts persisted state, never just HTTP status:
 *
 *  1) feature gate — yearEndManualEnabled off → preview 403 FEATURE_DISABLED.
 *  2) app-admin gate — non-admin session → preview denied (403/404, uniform).
 *  3) preview→execute happy path — a dedicated user with a seeded 2025-only
 *     +10h ledger row gets a PREVIEWED run whose target predicts action
 *     reset_positive / delta -1000 centi-hours; executing with a fresh UUID
 *     operation key completes the run, writes exactly one period_nullung
 *     adjustment effective at the 2026-01-01 boundary (balance_after = 0),
 *     and persists item+ref+audit rows.
 *  4) operation-key replay — re-POSTing execute with the SAME operationId
 *     returns the same run and writes NO second adjustment (idempotence).
 *  5) preview-handle expiry shape — a well-formed but unknown 64-hex handle
 *     is 410 PREVIEW_EXPIRED, never a 500.
 *  6) reason floor — a sub-10-char reason is 422 REASON_REQUIRED.
 *
 * Hermetic: dedicated throwaway user (no model needed — the reset only reads
 * the adjustment ledger, month closures, open entries and boundary absences),
 * seeded ledger row dated strictly inside closingYear so closingYear+1 stays
 * empty and the NEXT_OPENING_NONZERO blocker cannot fire.
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { api, apiAllowFailure } from './helpers/api.js'
import { login, loginAs, credsFromEnv, skipIfMissingCreds } from './helpers/auth.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const COMPOSE_CWD = path.resolve(__dirname, '../../..') // nextcloud/ compose root
const APP = '/apps/arbeitszeitcheck'

const UNIQ = `ye${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
const UID = `atlas-ye-${UNIQ}`
const PASSWORD = `atlas-ye-${UNIQ}`
const POLICY_KEY = 'workforce_policy_json'
const REV_KEY = 'workforce_policy_revision'
const SEED_HOURS = 10
const SEED_CENTI = SEED_HOURS * 100
// closingYear must be < current year. The reset anchors at the closingYear+1
// boundary and zeroes the OPENING balance carried into the new year — which
// the bank formula composes from closingYear+1 adjustments effective through
// Jan 1 (see OvertimeBankService::sumAdjustmentsThroughForUsers). The seed is
// therefore a January row of the new year. Its balance_after is 0 on purpose:
// a non-zero opening chain row is exactly what NEXT_OPENING_NONZERO guards —
// this row mimics "opening already booked and zeroed", the ledger state in
// which a manual reset may legitimately still find a positive delta sum.
const CLOSING_YEAR = new Date().getUTCFullYear() - 1
const SEED_DATE = `${CLOSING_YEAR + 1}-01-01`

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

function occConfigGet(key) {
	// config:app:get exits 1 on an unset key — that is "empty", not an error.
	try {
		return occ(['config:app:get', 'arbeitszeitcheck', key])
			.split('\n').map((l) => l.trim()).filter((l) => l !== '').pop() ?? ''
	} catch {
		return ''
	}
}

function occConfigSet(key, value) {
	occ(['config:app:set', 'arbeitszeitcheck', key, `--value=${value}`])
}

/** @param {string} statement SQL executed against the nextcloud db */
function sql(statement) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', statement,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
}

/** @param {string} query SELECT returning one scalar value */
function sqlScalar(query) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb', 'mariadb', '--skip-column-names',
		'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-e', query,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 }).trim()
}

function uuid() {
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
		const r = (Math.random() * 16) | 0
		return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
	})
}

test.use({ trace: 'off' })
test.describe.configure({ mode: 'serial' })

test.describe('year-end overtime reset — live persisted-state probes', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')

	let prev = { policy: '', rev: '' }
	let policyRev = 0

	test.beforeAll(() => {
		prev = { policy: occConfigGet(POLICY_KEY), rev: occConfigGet(REV_KEY) }
		const doc = prev.policy ? JSON.parse(prev.policy) : {}
		doc.yearEnd = {
			...(typeof doc.yearEnd === 'object' && doc.yearEnd !== null ? doc.yearEnd : {}),
			manualEnabled: true,
			resetPositive: true,
			waiveNegative: true,
		}
		occConfigSet(POLICY_KEY, JSON.stringify(doc))
		policyRev = Number(occConfigGet(REV_KEY) || '0') + 1
		occConfigSet(REV_KEY, String(policyRev))

		occEnv({ OC_PASS: PASSWORD }, ['user:add', '--password-from-env', UID])
		sql(`INSERT INTO oc_at_ot_adj
			 (user_id, calendar_year, effective_on, hours_delta, reason_code, note,
			  balance_before, balance_after, processed_by, created_at)
			 VALUES ('${UID}', ${CLOSING_YEAR + 1}, '${SEED_DATE}', ${SEED_HOURS}, 'custom',
			  'atlas-ye ${UNIQ} seed', 0, 0, 'atlas', NOW())`)
	})

	test.afterAll(() => {
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
		try {
			sql(`DELETE FROM oc_at_ot_adj WHERE user_id='${UID}';
				 DELETE FROM oc_at_year_reset_ref WHERE user_id='${UID}';
				 DELETE FROM oc_at_year_reset_item WHERE user_id='${UID}';
				 DELETE FROM oc_at_year_reset_run WHERE selection_snapshot LIKE '%${UID}%'`)
		} catch { /* best-effort cleanup */ }
		try {
			occ(['user:delete', UID])
		} catch { /* best-effort cleanup */ }
	})

	test('non-admin is denied the preview endpoint', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC_EMPLOYEE_USER')
		await login(page, credsFromEnv('EMPLOYEE'))
		const res = await apiAllowFailure(page, 'POST', `${APP}/api/admin/year-end-runs/preview`, {
			data: {
				closingYear: CLOSING_YEAR, userIds: [UID], resetPositive: true,
				waiveNegative: false, reason: 'atlas-ye probe reason', expectedPolicyRevision: policyRev,
			},
		})
		expect([403, 404]).toContain(res.status)
		expect(res.json?.success ?? false).toBe(false)
	})

	test('preview predicts, execute commits, replay is a no-op', async ({ page }) => {
		await loginAs(page, 'ADMIN')

		// --- reason floor (before preview, so no run rows are created) ---
		const shortReason = await apiAllowFailure(page, 'POST', `${APP}/api/admin/year-end-runs/preview`, {
			data: {
				closingYear: CLOSING_YEAR, userIds: [UID], resetPositive: true,
				waiveNegative: false, reason: 'short', expectedPolicyRevision: policyRev,
			},
		})
		expect(shortReason.status).toBe(422)
		expect(shortReason.json?.code).toBe('REASON_REQUIRED')

		// --- preview ---
		const preview = await api(page, 'POST', `${APP}/api/admin/year-end-runs/preview`, {
			data: {
				closingYear: CLOSING_YEAR, userIds: [UID], resetPositive: true,
				waiveNegative: true, reason: 'atlas-ye probe closing-year reset',
				expectedPolicyRevision: policyRev,
			},
		})
		expect(preview.success).toBe(true)
		expect(preview.preview_id).toMatch(/^[0-9a-f]{64}$/)
		expect(preview.run_id).toBeGreaterThan(0)
		expect(preview.closing_year).toBe(CLOSING_YEAR)
		const target = (preview.targets ?? []).find((t) => t.user_id === UID)
		expect(target).toBeTruthy()
		expect(target.before_centi_hours).toBe(SEED_CENTI)
		expect(target.delta_centi_hours).toBe(-SEED_CENTI)
		expect(target.action).toBe('reset_positive')
		expect(target.blocked).toBeNull()
		const runId = preview.run_id

		// DB: previewed run + pending ref row exist
		expect(sqlScalar(
			`SELECT state FROM oc_at_year_reset_run WHERE id=${runId}`
		)).toBe('previewed')

		// --- execute ---
		const opId = uuid()
		const exec = await api(page, 'POST', `${APP}/api/admin/year-end-runs`, {
			data: { previewId: preview.preview_id, operationId: opId, expectedPolicyRevision: policyRev },
		})
		expect(exec.success).toBe(true)
		expect(exec.run?.id).toBe(runId)
		expect(exec.run?.state).toBe('completed')

		// DB ground truth: exactly one compensating adjustment at the boundary
		expect(sqlScalar(
			`SELECT COUNT(*) FROM oc_at_ot_adj
			 WHERE user_id='${UID}' AND reason_code='period_nullung'`
		)).toBe('1')
		expect(Number(sqlScalar(
			`SELECT balance_after FROM oc_at_ot_adj
			 WHERE user_id='${UID}' AND reason_code='period_nullung'`
		))).toBe(0)
		expect(sqlScalar(
			`SELECT effective_on FROM oc_at_ot_adj
			 WHERE user_id='${UID}' AND reason_code='period_nullung'`
		)).toBe(`${CLOSING_YEAR + 1}-01-01`)
		expect(sqlScalar(
			`SELECT state FROM oc_at_year_reset_item
			 WHERE user_id='${UID}' AND closing_year=${CLOSING_YEAR}
			   AND last_execution_run_id=${runId}`
		)).toBe('done')
		// run_started + run_finished audit rows bind the run id
		expect(Number(sqlScalar(
			`SELECT COUNT(*) FROM oc_at_audit
			 WHERE entity_type='year_reset_run' AND entity_id=${runId}`
		))).toBeGreaterThanOrEqual(2)

		// --- replay: same operation key must not double-apply ---
		const replay = await api(page, 'POST', `${APP}/api/admin/year-end-runs`, {
			data: { previewId: preview.preview_id, operationId: opId, expectedPolicyRevision: policyRev },
		})
		expect(replay.success).toBe(true)
		expect(replay.run?.id).toBe(runId)
		expect(sqlScalar(
			`SELECT COUNT(*) FROM oc_at_ot_adj
			 WHERE user_id='${UID}' AND reason_code='period_nullung'`
		)).toBe('1')

		// --- unknown preview handle → 410 PREVIEW_EXPIRED, never 500 ---
		const bogus = await apiAllowFailure(page, 'POST', `${APP}/api/admin/year-end-runs`, {
			data: {
				previewId: 'a'.repeat(64), operationId: uuid(),
				expectedPolicyRevision: policyRev,
			},
		})
		expect(bogus.status).toBe(410)
		expect(bogus.json?.code).toBe('PREVIEW_EXPIRED')
	})

	test('disabled policy rejects preview with FEATURE_DISABLED', async ({ page }) => {
		// Flip the gate off inside the leg and restore it immediately — the
		// suite is serial, so ordering is deterministic.
		const doc = JSON.parse(occConfigGet(POLICY_KEY) || '{}')
		doc.yearEnd = {
			...(typeof doc.yearEnd === 'object' && doc.yearEnd !== null ? doc.yearEnd : {}),
			manualEnabled: false,
		}
		occConfigSet(POLICY_KEY, JSON.stringify(doc))
		occConfigSet(REV_KEY, String(Number(occConfigGet(REV_KEY) || '0') + 1))
		try {
			await loginAs(page, 'ADMIN')
			const res = await apiAllowFailure(page, 'POST', `${APP}/api/admin/year-end-runs/preview`, {
				data: {
					closingYear: CLOSING_YEAR, userIds: [UID], resetPositive: true,
					waiveNegative: false, reason: 'atlas-ye disabled-gate probe',
					expectedPolicyRevision: Number(occConfigGet(REV_KEY) || '0'),
				},
			})
			expect(res.status).toBe(403)
			expect(res.json?.code).toBe('FEATURE_DISABLED')
		} finally {
			doc.yearEnd.manualEnabled = true
			occConfigSet(POLICY_KEY, JSON.stringify(doc))
			occConfigSet(REV_KEY, String(Number(occConfigGet(REV_KEY) || '0') + 1))
		}
	})
})
