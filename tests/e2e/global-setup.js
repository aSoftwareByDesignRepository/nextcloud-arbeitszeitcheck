// @ts-check
/**
 * Playwright globalSetup — normalize the shared Nextcloud e2e environment.
 *
 * The e2e users are shared fixtures on a shared dev instance: sibling agents
 * and other apps' suites rotate their passwords between runs, so a suite that
 * trusts "the .env password still matches" bounces at /login mid-run
 * (observed repeatedly 2026-10). This setup re-syncs every configured fixture
 * password to the .env SSOT before the first browser opens, preconditions
 * brute-force testing mode (~230 real UI logins/run otherwise trip the
 * 10-attempt throttle into "Too many requests"), and resets any leaked
 * core/lang left by a crashed locale run.
 *
 * All steps are idempotent and best-effort: a non-Docker target just skips
 * the occ calls (the tests themselves would fail at login, which is the
 * honest signal).
 */
import { execFileSync } from 'child_process'
import { existsSync, readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { acquireInstanceLock } from './instance-lock.js'

const configDir = dirname(fileURLToPath(import.meta.url))
const nextcloudRoot = resolve(configDir, '../../..')

/** Load tests/e2e/.env without overriding real env vars (mirrors config). */
function loadEnv() {
	const envFile = resolve(configDir, '.env')
	if (!existsSync(envFile)) {
		return
	}
	for (const line of readFileSync(envFile, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (!trimmed || trimmed.startsWith('#')) {
			continue
		}
		const eq = trimmed.indexOf('=')
		if (eq <= 0) {
			continue
		}
		const key = trimmed.slice(0, eq).trim()
		let value = trimmed.slice(eq + 1).trim()
		if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
			value = value.slice(1, -1)
		}
		if (process.env[key] === undefined) {
			process.env[key] = value
		}
	}
}

function occ(args, env = {}) {
	const envArgs = []
	for (const [k, v] of Object.entries(env)) {
		envArgs.push('-e', `${k}=${v}`)
	}
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', ...envArgs,
		'nextcloud', 'php', 'occ', ...args,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000, stdio: ['ignore', 'pipe', 'pipe'] })
}

function dockerReachable() {
	try {
		occ(['status', '--output=json'])
		return true
	} catch {
		return false
	}
}

/** NC testing mode: protection code path stays live, sleeps + throw ceiling are bypassed. */
function preconditionBruteForce() {
	occ(['config:system:set', 'auth.bruteforce.protection.testing', '--value=true', '--type=boolean'])
	occ(['config:system:set', 'auth.bruteforce.max-attempts', '--value=100000', '--type=integer'])
}

/** Re-sync a fixture password to the .env SSOT; missing users warn, don't fail. */
function syncPassword(userId, password) {
	try {
		occ(['user:resetpassword', '--password-from-env', userId], { OC_PASS: password })
		return true
	} catch {
		return false
	}
}

/** Undo leaked core/lang from a crashed locale run (same list as globalTeardown). */
function resetLang(userId) {
	try {
		occ(['user:setting', userId, 'core', 'lang', 'en'])
	} catch {
		try {
			occ(['user:setting', '--delete', userId, 'core', 'lang'])
		} catch {
			/* user may not exist */
		}
	}
}

/**
 * Warn when another Playwright run is live on this shared instance — a
 * sibling agent's suite mutating the same config keys/users produces
 * "stale read" failures that look like product bugs (observed 2026-10-17:
 * a sibling settings sweep overwrote hr_notification_recipients between a
 * POST and its GET re-read). Warn only: detection can't be perfect and the
 * run may still succeed.
 */
function detectSiblingRuns() {
	try {
		const out = execFileSync('ps', ['-eo', 'pid=,ppid=,args='], { encoding: 'utf8', timeout: 10_000 })
		const ppidOf = new Map()
		const rows = []
		for (const line of out.split('\n')) {
			const m = line.trim().match(/^(\d+)\s+(\d+)\s+(.*)$/)
			if (m) {
				ppidOf.set(Number(m[1]), Number(m[2]))
				rows.push({ pid: Number(m[1]), args: m[3] })
			}
		}
		// Exclude this runner's whole ancestor chain (sh -c, npm exec, etc.).
		const ancestors = new Set([process.pid])
		for (let p = process.ppid; p > 1 && ppidOf.has(p); p = ppidOf.get(p)) {
			ancestors.add(p)
		}
		return rows
			.filter((r) => r.args.includes('playwright test') && !r.args.includes('pgrep') && !ancestors.has(r.pid))
			.map((r) => `pid ${r.pid}: ${r.args.slice(0, 120)}`)
	} catch {
		return []
	}
}

export default async function globalSetup() {
	loadEnv()

	const siblings = detectSiblingRuns()
	if (siblings.length > 0) {
		// eslint-disable-next-line no-console
		console.warn(`[e2e globalSetup] WARNING: ${siblings.length} other playwright run(s) detected on this shared instance — config/user mutations may race:\n${siblings.map((s) => `  ${s.trim()}`).join('\n')}`)
	}

	// Serialize cooperating runs on the shared instance — STATEFUL_SPECS
	// serializes within one run; the lease serializes between runs (including
	// sibling agents invoking the same globalSetup in this tree).
	acquireInstanceLock()

	if (!dockerReachable()) {
		// eslint-disable-next-line no-console
		console.log('[e2e globalSetup] Nextcloud container not reachable — skipping env normalization (non-Docker target)')
		return
	}

	preconditionBruteForce()

	const pairs = [
		['NC_ADMIN_USER', 'NC_ADMIN_PASS'],
		['NC_EMPLOYEE_USER', 'NC_EMPLOYEE_PASS'],
		['NC_MANAGER_USER', 'NC_MANAGER_PASS'],
		['NC_SUBSTITUTE_USER', 'NC_SUBSTITUTE_PASS'],
		['NC_BOLA_ATTACKER_USER', 'NC_BOLA_ATTACKER_PASS'],
	]
	const synced = []
	const failed = []
	for (const [userKey, passKey] of pairs) {
		const user = process.env[userKey]
		const pass = process.env[passKey]
		if (!user || !pass) {
			continue
		}
		;(syncPassword(user, pass) ? synced : failed).push(user)
	}
	if (failed.length > 0) {
		// eslint-disable-next-line no-console
		console.warn(`[e2e globalSetup] password sync failed (user missing?): ${failed.join(', ')}`)
	}

	for (const userId of synced) {
		resetLang(userId)
	}
	// eslint-disable-next-line no-console
	console.log(`[e2e globalSetup] normalized: brute-force testing mode, passwords + lang synced for: ${synced.join(', ') || 'none'}`)
}
