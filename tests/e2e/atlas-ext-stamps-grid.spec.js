// @ts-check
/* global process */
/**
 * Permanent probe — azc-extstamps-dashboard-grid-squeeze.
 *
 * The conditional "Suggested stamps" card (#azc-ext-stamps) renders inside
 * the named-area .azc-dashboard-overview__grid only while a pending suite
 * draft stamp exists. With no placement of its own it auto-placed into a
 * ~50px implicit track at >=768px and painted word-per-line (visual delta
 * 2026-10-18, live-proven 50x1026px @1280). The fix gives every unnamed
 * grid child `grid-column: 1 / -1`; this spec fails if the card ever lands
 * in an implicit track again — rendered geometry, not DOM presence.
 *
 * Seed is a direct oc_at_ext_stamp row because proposing is server-side
 * only (ExternalStampDraftFacade); every assertion is on the real page.
 *
 * State: inserts/deletes one pending row for the shared e2e_employee — the
 * card is visible on that fixture's dashboard for the duration, so this
 * spec is registered in STATEFUL_SPECS (serialized), never the pool.
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv, hasCreds, gotoApp } from './helpers/auth.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
// tests/e2e → apps dir; docker compose resolves the project from parents.
const COMPOSE_CWD = path.resolve(__dirname, '../../..')
const PROOFS = path.resolve(
	__dirname,
	'../../../../../.cursor/atlas-farm-v3/artifacts/arbeitszeitcheck/proofs',
)
const APP = '/apps/arbeitszeitcheck'
const EMP = process.env.NC_EMPLOYEE_USER || 'e2e_employee'
const REF = `azcgrid-${process.pid}-${Date.now().toString(36)}`

/** DB seeding/cleanup only — every assertion still goes through the page. */
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

test.describe('Ext-stamps dashboard grid placement', () => {
	test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_* creds')
	// Reachability probe + mop up rows a crashed run left behind.
	test.skip(
		!dbExec("DELETE FROM oc_at_ext_stamp WHERE source_ref LIKE 'azcgrid-%'"),
		'dev DB unreachable (docker compose mariadb)',
	)

	test('populated card spans the overview grid at every desktop band', async ({ page }) => {
		const startTs = Math.floor(Date.now() / 1000) - 4 * 3600
		expect(dbExec(
			`INSERT INTO oc_at_ext_stamp
				(user_id, kind, start_ts, end_ts, source_app, source_ref, status, created_at)
				VALUES ('${EMP}', 'segment', ${startTs}, ${startTs + 3600},
				'tourcheck', '${REF}', 'pending', NOW())`,
		), 'seed insert failed').toBe(true)
		expect(
			dbScalar(`SELECT COUNT(*) FROM oc_at_ext_stamp WHERE source_ref='${REF}'`),
			'seeded stamp did not persist',
		).toBe('1')

		try {
			await page.setViewportSize({ width: 1280, height: 900 })
			await login(page, credsFromEnv('EMPLOYEE'))
			await gotoApp(page, `${APP}/dashboard`)
			const card = page.locator('#azc-ext-stamps')
			await card.waitFor({ state: 'visible', timeout: 30_000 })

			/** Rendered geometry of the card inside its parent grid. */
			const measure = () => page.evaluate(() => {
				const el = document.getElementById('azc-ext-stamps')
				const grid = el ? el.parentElement : null
				const er = el.getBoundingClientRect()
				const gr = grid.getBoundingClientRect()
				const cs = getComputedStyle(el)
				return {
					inGrid: grid.classList.contains('azc-dashboard-overview__grid'),
					cardW: er.width,
					gridW: gr.width,
					colEnd: cs.gridColumnEnd,
					infoW: el.querySelector('.azc-ext-stamps__info')
						?.getBoundingClientRect().width ?? 0,
				}
			})
			const assertFullSpan = async (label) => {
				await page.waitForTimeout(250) // let the media query reflow settle
				const m = await measure()
				expect(m.inGrid, `${label}: card left the overview grid`).toBe(true)
				expect(
					m.cardW,
					`${label}: card w=${Math.round(m.cardW)}px must span grid ` +
						`w=${Math.round(m.gridW)}px (gridColumnEnd=${m.colEnd}) — ` +
						'~50px means it fell back into an implicit track',
				).toBeGreaterThanOrEqual(m.gridW - 8)
				expect(
					m.infoW,
					`${label}: .azc-ext-stamps__info collapsed to ${m.infoW}px`,
				).toBeGreaterThan(40)
			}

			await assertFullSpan('1280px')

			fs.mkdirSync(PROOFS, { recursive: true })
			await card.scrollIntoViewIfNeeded()
			await page.waitForTimeout(150)
			await page.screenshot({
				path: path.join(PROOFS, 'azc-extstamps-grid-span-1280.png'),
				fullPage: false,
			})

			await page.setViewportSize({ width: 900, height: 900 })
			await assertFullSpan('900px')

			await page.setViewportSize({ width: 700, height: 900 })
			await assertFullSpan('700px')
		} finally {
			dbExec(`DELETE FROM oc_at_ext_stamp WHERE source_ref='${REF}'`)
		}
	})
})
