/**
 * Live proof of the split-day entry CAS (spec §9.7, AC-5):
 *
 *   1. A completed entry with an applied split context is seeded via the DB
 *      (fixture injection — the model config is irrelevant to the CAS path).
 *   2. Employee submits a correction carrying splitOverride.baseEntryRevision
 *      equal to the entry's real content revision → accepted, pending.
 *   3. The correction basis rotates (end_time changes — the competing write
 *      that landed while the proposal was pending).
 *   4. Manager approves → MUST fail 409 SPLIT_OVERRIDE_STALE and MUST NOT
 *      apply the override (no write: status stays pending_approval).
 *
 * Also pinned live: a well-formed but wrong revision at submit → 409, a
 * malformed token → 400. The seeded entry records the 10:00–13:30 break in
 * the break columns so force_continuous stays ArbZG-legal.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { execFileSync } from 'node:child_process'

const APP = '/apps/arbeitszeitcheck'

function sql(query) {
	return execFileSync('docker', [
		'exec', '-i', 'nextcloud-mariadb',
		'mariadb', '-unextcloud', '-pnextcloud_password', 'nextcloud',
		'-N', '-B', '-e', query,
	]).toString().trim()
}

/** Unique seed day per run — probe a day with no existing rows for this user. */
function seedDay(uid, stamp) {
	for (let i = 0; i < 30; i++) {
		const d = new Date('2026-11-02T00:00:00Z') // Mon, before DST edge cases
		d.setUTCDate(d.getUTCDate() + ((stamp + i) % 22))
		const day = d.toISOString().slice(0, 10)
		if (Number(sql(`SELECT COUNT(*) FROM oc_at_entries WHERE user_id='${uid}' AND start_time LIKE '${day} %'`)) === 0) {
			return day
		}
	}
	throw new Error('no free seed day found')
}

function splitContext(day) {
	return JSON.stringify({
		v: 1,
		model_id: 1,
		schedule_revision: 'e2e',
		timezone: 'Europe/Berlin',
		day: { blocks: [['06:00', '10:00'], ['13:30', '16:30']] },
		split: { automatic: true, cutoff: '16:00', break_cover: true },
		overrides: { decision: 'auto', worked: [] },
		result: {
			state: 'applied', split: true, reason: 'clock_out_after_cutoff',
			excluded: [[`${day}T10:00:00+01:00`, `${day}T13:30:00+01:00`]],
			segments: [
				[`${day}T06:00:00+01:00`, `${day}T10:00:00+01:00`],
				[`${day}T13:30:00+01:00`, `${day}T16:30:00+01:00`],
			],
			break_handling: 'covered_by_interruption', engine: 1,
		},
	})
}

test.describe('Split-day entry CAS — live race proof', () => {
	test.skip(
		!hasCreds('ADMIN') || !hasCreds('EMPLOYEE') || !hasCreds('MANAGER'),
		'Requires NC_ADMIN_USER, NC_EMPLOYEE_USER, NC_MANAGER_USER',
	)

	test('approval-time stale baseEntryRevision → 409, no write applied', async ({ browser }) => {
		const employeePage = await (await browser.newContext()).newPage()
		const managerPage = await (await browser.newContext()).newPage()
		const adminPage = await (await browser.newContext()).newPage()
		let restoreApproval = false
		let entryId = null

		try {
			// 1. Require approval so the correction lands as a pending proposal.
			await login(adminPage, credsFromEnv('ADMIN'))
			const before = await api(adminPage, 'GET', `${APP}/api/admin/settings`)
			restoreApproval = before.settings?.manualTimeEntriesRequireApproval === true
			await api(adminPage, 'POST', `${APP}/api/admin/settings`, {
				data: { settings_section: 'time-approvals', manualTimeEntriesRequireApproval: true },
			})

			await login(employeePage, credsFromEnv('EMPLOYEE'))
			const uid = credsFromEnv('EMPLOYEE').username
			const day = seedDay(uid, Date.now())
			const ctx = splitContext(day)

			// Seed a completed split-context entry; recorded break columns keep a
			// force_continuous proposal ArbZG-legal (45 min already satisfied).
			sql(
				`INSERT INTO oc_at_entries (user_id, start_time, end_time, break_start_time, break_end_time, status, work_context_json, excluded_work_seconds, created_at, updated_at)
				 VALUES ('${uid}', '${day} 06:00:00', '${day} 16:30:00', '${day} 10:00:00', '${day} 13:30:00', 'completed', '${ctx.replace(/'/g, "''")}', 12600, NOW(), NOW())`
			)
			entryId = Number(
				sql(`SELECT id FROM oc_at_entries WHERE user_id='${uid}' AND start_time='${day} 06:00:00' ORDER BY id DESC LIMIT 1`)
			)
			expect(entryId).toBeGreaterThan(0)

			// 2. Read the entry: summary must expose revision + applied accounting.
			const show = await api(employeePage, 'GET', `${APP}/api/time-entries/${entryId}`)
			const entry = show?.data ?? show?.entry ?? show
			expect(entry?.accounting?.state).toBe('applied')
			const revision = entry.revision
			expect(revision).toMatch(/^[0-9a-f]{64}$/)

			// 3a. Well-formed but WRONG token → 409 SPLIT_OVERRIDE_STALE at submit.
			const stale = await apiAllowFailure(employeePage, 'POST', `${APP}/api/time-entries/${entryId}/request-correction`, {
				data: {
					justification: 'Atlas CAS probe — stale token must 409.',
					splitOverride: { decision: 'force_continuous', baseEntryRevision: 'a'.repeat(64) },
				},
			})
			expect(stale.status, JSON.stringify(stale.json)).toBe(409)
			expect(stale.json?.error_code).toBe('SPLIT_OVERRIDE_STALE')

			// 3b. Malformed token → 400 SPLIT_OVERRIDE_INVALID.
			const malformed = await apiAllowFailure(employeePage, 'POST', `${APP}/api/time-entries/${entryId}/request-correction`, {
				data: {
					justification: 'Atlas CAS probe — malformed token must 400.',
					splitOverride: { decision: 'force_continuous', baseEntryRevision: 'not-a-token' },
				},
			})
			expect(malformed.status, JSON.stringify(malformed.json)).toBe(400)

			// 3c. Real token → accepted, pending.
			const submitted = await apiAllowFailure(employeePage, 'POST', `${APP}/api/time-entries/${entryId}/request-correction`, {
				data: {
					justification: 'Atlas CAS probe — correct token accepted.',
					splitOverride: { decision: 'force_continuous', baseEntryRevision: revision },
				},
			})
			expect(submitted.status, JSON.stringify(submitted.json)).toBe(200)
			expect(submitted.json?.success).toBe(true)

			// 4. The competing write: basis rotates while the proposal is pending.
			sql(`UPDATE oc_at_entries SET end_time = '${day} 16:35:00', updated_at = NOW() WHERE id = ${entryId}`)

			// 5. Approval → 409 SPLIT_OVERRIDE_STALE, and the override is NOT applied.
			await login(managerPage, credsFromEnv('MANAGER'))
			const approve = await apiAllowFailure(
				managerPage, 'POST',
				`${APP}/api/manager/time-entries/${entryId}/approve-correction`,
				{ data: {} },
			)
			expect(approve.status, JSON.stringify(approve.json)).toBe(409)
			expect(approve.json?.error_code).toBe('SPLIT_OVERRIDE_STALE')

			const stored = sql(
				`SELECT CONCAT(status, '|', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(work_context_json,'$.overrides.decision')),'NULL')) FROM oc_at_entries WHERE id = ${entryId}`
			)
			expect(stored).toBe('pending_approval|auto')
		} finally {
			if (entryId) {
				sql(`DELETE FROM oc_at_entries WHERE id = ${entryId}`)
			}
			if (restoreApproval === false) {
				await api(adminPage, 'POST', `${APP}/api/admin/settings`, {
					data: { settings_section: 'time-approvals', manualTimeEntriesRequireApproval: false },
				}).catch(() => {})
			}
		}
	})

	test('month-closure review listing (spec §9.6) — live API', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		const uid = credsFromEnv('EMPLOYEE').username
		const day = seedDay(uid, Date.now() + 7)
		const month = Number(day.slice(5, 7))
		const year = Number(day.slice(0, 4))
		const ctx = splitContext(day).replace('"state":"applied"', '"state":"review"').replace('"reason":"clock_out_after_cutoff"', '"reason":"override_review"')
		let entryId = null

		try {
			sql(
				`INSERT INTO oc_at_entries (user_id, start_time, end_time, break_start_time, break_end_time, status, work_context_json, excluded_work_seconds, created_at, updated_at)
				 VALUES ('${uid}', '${day} 06:00:00', '${day} 16:30:00', '${day} 10:00:00', '${day} 13:30:00', 'completed', '${ctx.replace(/'/g, "''")}', 12600, NOW(), NOW())`
			)
			entryId = Number(
				sql(`SELECT id FROM oc_at_entries WHERE user_id='${uid}' AND start_time='${day} 06:00:00' ORDER BY id DESC LIMIT 1`)
			)
			expect(entryId).toBeGreaterThan(0)

			const res = await api(page, 'GET', `${APP}/api/month-closure/status?year=${year}&month=${month}`)
			const body = res?.data ?? res
			expect(body?.success).toBe(true)
			const listed = (body?.reviewEntries ?? []).filter((e) => e.id === entryId)
			expect(listed, JSON.stringify(body?.reviewEntries)).toHaveLength(1)
			expect(listed[0].date).toBe(day)
			expect(listed[0].reason).toBe('override_review')
			expect(typeof body?.reviewWarning).toBe('string')
			expect(body.reviewWarning.length).toBeGreaterThan(0)
			// §9.6: review entries warn but never block the month.
			expect(body?.reviewWarning).not.toBe('')
		} finally {
			if (entryId) {
				sql(`DELETE FROM oc_at_entries WHERE id = ${entryId}`)
			}
		}
	})
})
