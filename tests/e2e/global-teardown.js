// @ts-check
/**
 * Playwright globalTeardown — durable e2e user language restore.
 *
 * locale-rendering-overflow-rawkey.spec.js mutates the shared Nextcloud user
 * language (`occ user:setting <uid> core lang`) for every supported locale.
 * On SIGINT / timeout / crash mid-run, afterAll never runs and the e2e users
 * are left at e.g. pt_BR — poisoning every sibling spec that asserts EN copy
 * (observed: 10-test suite failure on 2026-09-30).
 *
 * globalTeardown still runs on interrupted/aborted runs (graceful SIGINT),
 * so this is the last line of defence: unconditionally reset `core/lang`
 * for every configured e2e user. The spec also restores per test in
 * `finally`, so a merely-failing test cannot leak either.
 */
import { execFileSync } from 'child_process'
import { existsSync, readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

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

function resetLang(userId) {
	try {
		execFileSync('docker', [
			'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ',
			'user:setting', userId, 'core', 'lang', 'en',
		], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 })
	} catch {
		try {
			execFileSync('docker', [
				'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ',
				'user:setting', '--delete', userId, 'core', 'lang',
			], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 })
		} catch {
			/* best-effort: user may not exist in this environment */
		}
	}
}

export default async function globalTeardown() {
	loadEnv()
	const users = [
		process.env.NC_ADMIN_USER,
		process.env.NC_EMPLOYEE_USER,
		process.env.NC_MANAGER_USER,
		process.env.NC_SUBSTITUTE_USER,
		process.env.NC_BOLA_ATTACKER_USER,
	].filter((u) => typeof u === 'string' && u.length > 0)
	for (const userId of new Set(users)) {
		resetLang(userId)
	}
	if (users.length > 0) {
		// eslint-disable-next-line no-console
		console.log(`[e2e globalTeardown] core/lang reset to 'en' for: ${[...new Set(users)].join(', ')}`)
	}
}
