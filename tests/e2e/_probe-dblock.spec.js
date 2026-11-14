/**
 * DbLockGuard stale-row sweep + live-lock blocking — live DB evidence.
 *
 * DBLockingProvider persists lock rows in oc_file_locks; a killed request can
 * leak a row whose CAS keeps blocking every acquirer until the provider TTL
 * elapses. DbLockGuard::acquire() sweeps rows past their ttl and retries once.
 *
 * Legs (working-time-model delete acquires DbLockKeys::workingTimeModelCatalog()
 * = 'azc/wtm/catalog' exclusively):
 *  1. Expired row planted on the catalog key -> delete succeeds (sweep+retry).
 *  2. Live row planted -> delete returns 409 MODEL_CATALOG_BUSY and the live
 *     row is untouched (it may belong to a real holder).
 *
 * Direct oc_file_locks writes are the only way to reproduce a dead-holder row
 * deterministically; all assertions go through the real HTTP API.
 */
import { test, expect } from '@playwright/test'
import { api, apiAllowFailure } from './helpers/api.js'
import { loginAs, gotoApp } from './helpers/auth.js'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const APP = '/apps/arbeitszeitcheck'
const LOCK_KEY = 'azc/wtm/catalog'
// tests/e2e → apps dir; docker compose resolves the project from parents.
const COMPOSE_CWD = path.resolve(__dirname, '../../..')
const UNIQ = `dblock-${Date.now().toString(36)}`

test.use({ trace: 'off' })
test.describe.configure({ mode: 'serial' })

function db(sql) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e', sql,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 }).trim()
}

function plantLock(ttlSecondsFromNow) {
	db(`DELETE FROM oc_file_locks WHERE \`key\` = '${LOCK_KEY}'`)
	db(`INSERT INTO oc_file_locks (\`lock\`, \`key\`, ttl) VALUES (1, '${LOCK_KEY}', UNIX_TIMESTAMP() + ${ttlSecondsFromNow})`)
}

function lockRowCount(expiredOnly = null) {
	// DBLockingProvider leaves released rows behind — assert on ttl class, not
	// row existence. expiredOnly=true counts dead-holder rows; false counts
	// live-holder rows.
	const cond = expiredOnly === null ? '1=1'
		: expiredOnly ? 'ttl < UNIX_TIMESTAMP()' : 'ttl >= UNIX_TIMESTAMP()'
	return Number(db(
		`SELECT COUNT(*) FROM oc_file_locks WHERE \`key\` = '${LOCK_KEY}' AND ${cond}`,
	) || '0')
}

function cleanupLocks() {
	try {
		db(`DELETE FROM oc_file_locks WHERE \`key\` = '${LOCK_KEY}'`)
	} catch {
		/* best-effort teardown */
	}
}

async function createDisposableModel(page, name) {
	const work = { work: true, start: '08:00', end: '16:00', breaks: [] }
	const off = { work: false }
	const created = await api(page, 'POST', `${APP}/api/admin/working-time-models`, {
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
	const id = created?.model?.id ?? created?.id
	expect(id, JSON.stringify(created)).toBeTruthy()
	return id
}

test('DbLockGuard: expired oc_file_locks row swept + retried; live row still blocks', async ({ page }) => {
	await loginAs(page, 'ADMIN')
	await gotoApp(page, `${APP}/admin/settings`)

	// Leg 1 — dead-holder row (ttl in the past): the guard sweeps it and the
	// single retry lets the delete win; the planted row must be gone.
	let modelId = await createDisposableModel(page, `${UNIQ}-stale`)
	plantLock(-3600)
	let del = await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/working-time-models/${modelId}`)
	expect(del.ok || del.json?.success === true, JSON.stringify(del.json)).toBeTruthy()
	expect(lockRowCount(true), 'expired planted row must be swept, not persisted').toBe(0)

	// Leg 2 — live-holder row (ttl in the future): the catalog stays busy, the
	// API fails closed with MODEL_CATALOG_BUSY and the row is NOT touched.
	modelId = await createDisposableModel(page, `${UNIQ}-live`)
	plantLock(3600)
	del = await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/working-time-models/${modelId}`)
	expect(del.status ?? 0).toBe(409)
	expect(del.json?.code).toBe('MODEL_CATALOG_BUSY')
	expect(lockRowCount(false), 'live holder row must remain untouched').toBe(1)

	// Teardown — release the planted live row, then drop the fixture model.
	cleanupLocks()
	del = await apiAllowFailure(page, 'DELETE', `${APP}/api/admin/working-time-models/${modelId}`)
	expect(del.ok || del.json?.success === true, JSON.stringify(del.json)).toBeTruthy()
})
