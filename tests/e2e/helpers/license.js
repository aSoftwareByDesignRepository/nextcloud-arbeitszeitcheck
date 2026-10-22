/**
 * Lab-license helper for E2E proofs that need a terminal/seat-capable AZC2
 * license on the dev container.
 *
 * The container's trust anchor is env-driven (AZC_VENDOR_PUBLIC_KEY_B64 +
 * AZC_ALLOW_VENDOR_KEY_OVERRIDE): it may be the deterministic test-seed key
 * (the checked-in sbdlicenseops golden fixture verifies) or the production ops
 * key. Try the golden fixture first; on INVALID_SIGNATURE fall back to a
 * freshly generated 'atlas-e2e' customer license via ~/ops/azc — private ops
 * tooling, never committed to this repo.
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import { homedir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

/**
 * @param {import('@playwright/test').Page} page admin-authenticated page
 * @param {(page: any, m: string, u: string, o?: any) => Promise<{ok: boolean, status: number, json: any}>} apiAllowFailure
 * @param {string} appBase
 * @returns {Promise<{ok: boolean, via: string, res: any}>}
 */
export async function applyLabLicense(page, apiAllowFailure, appBase = '/apps/arbeitszeitcheck') {
	const fixture = path.resolve(__dirname, '../../../../sbdlicenseops/tests/fixtures/license_azc2_bundle_golden.json')
	const goldenKey = JSON.parse(fs.readFileSync(fixture, 'utf8')).wireKey
	const first = await apiAllowFailure(page, 'POST', `${appBase}/api/admin/license`, { data: { licenseKey: goldenKey } })
	if (first.ok) {
		return { ok: true, via: 'golden-fixture', res: first }
	}
	const err = first.json?.error || ''
	if (err !== 'INVALID_SIGNATURE') {
		return { ok: false, via: 'golden-fixture', res: first }
	}
	// Container trusts the production vendor key — mint a terminal-capable
	// 'atlas-e2e' license with the local ops signer (never committed).
	const gen = path.join(homedir(), 'ops/azc/generate-license.php')
	const out = execFileSync('php', [
		gen, '--customer', 'atlas-e2e', '--mobile', '5', '--terminal', '2', '--until', '2027-12-31',
	], { encoding: 'utf8', timeout: 30_000 })
	const wireKey = out.trim().split('\n').map((l) => l.trim()).filter((l) => l.startsWith('AZC2.')).pop()
	const second = await apiAllowFailure(page, 'POST', `${appBase}/api/admin/license`, { data: { licenseKey: wireKey } })
	return { ok: second.ok, via: 'ops-generated', res: second }
}
