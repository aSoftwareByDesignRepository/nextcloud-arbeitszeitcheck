/**
 * Slice C — stamp-path geteilte Arbeitszeit vs overnight Ruhezeit (live API).
 *
 * Uses server_now from clock status so calendar days match storage TZ.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure, getRequestToken } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

function pad2(n) {
	return String(n).padStart(2, '0')
}

/** @param {string} serverNowIso-ish */
function parseServerParts(serverNow) {
	// Expect "YYYY-MM-DD HH:MM:SS" or ISO with T
	const m = String(serverNow).match(
		/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/
	)
	if (!m) {
		throw new Error('Unexpected server_now: ' + serverNow)
	}
	return {
		y: Number(m[1]),
		mo: Number(m[2]),
		d: Number(m[3]),
		h: Number(m[4]),
		mi: Number(m[5]),
		date: `${m[1]}-${m[2]}-${m[3]}`,
	}
}

function shiftCalendarDay(ymd, deltaDays) {
	const [y, mo, d] = ymd.split('-').map(Number)
	const dt = new Date(Date.UTC(y, mo - 1, d))
	dt.setUTCDate(dt.getUTCDate() + deltaDays)
	return `${dt.getUTCFullYear()}-${pad2(dt.getUTCMonth() + 1)}-${pad2(dt.getUTCDate())}`
}

async function ensureClockedOut(page) {
	let statusRes = await api(page, 'GET', '/apps/arbeitszeitcheck/api/clock/status')
	for (let i = 0; i < 4; i += 1) {
		const st = statusRes.status?.status
		if (st !== 'active' && st !== 'break') {
			return statusRes
		}
		if (st === 'break') {
			await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/end-break')
		} else {
			await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/out')
		}
		statusRes = await api(page, 'GET', '/apps/arbeitszeitcheck/api/clock/status')
	}
	return statusRes
}

test.describe('Slice C stamp-path rest (live)', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')
		await login(page, credsFromEnv('EMPLOYEE'))
	})

	test('same-calendar-day morning block then clock-in is allowed', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/dashboard')
		await assertArbeitszeitcheckLoaded(page)
		await getRequestToken(page)

		let statusRes = await ensureClockedOut(page)
		const serverNow = statusRes.status?.server_now
		expect(serverNow).toBeTruthy()
		const parts = parseServerParts(serverNow)

		// Need at least ~4h into the day so 08:00–11:00 fits before "now".
		test.skip(parts.h < 12, 'Needs afternoon wall clock so a morning block can end before now')

		const created = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
			data: {
				date: parts.date,
				startTime: '08:00',
				endTime: '11:00',
			},
		})
		test.skip(
			!created.ok,
			'Could not seed morning entry (manual entry disabled, approval, or compliance): ' +
				JSON.stringify(created.json)
		)
		const morningId =
			created.json?.data?.id ?? created.json?.id ?? created.json?.entry?.id ?? null

		try {
			const clockIn = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/in')
			expect(
				clockIn.ok && clockIn.json?.success === true,
				'Same-day split must not raise rest_period_required: ' + JSON.stringify(clockIn.json)
			).toBeTruthy()
			expect(clockIn.json?.error_code).not.toBe('rest_period_required')

			await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/out')
		} finally {
			if (morningId) {
				await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/time-entries/${morningId}`)
			}
		}
	})

	test('overnight block ending recently still blocks clock-in', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/dashboard')
		await assertArbeitszeitcheckLoaded(page)
		await getRequestToken(page)

		let statusRes = await ensureClockedOut(page)
		const parts = parseServerParts(statusRes.status?.server_now)
		// Short overnight 22:00→06:00 stays under daily max and keeps Ruhezeit active until 17:00.
		// Outside that window, deterministic coverage is PHPUnit (ComplianceService + ArbzgRestMidnightMutation).
		test.skip(
			parts.h < 7 || parts.h >= 17,
			'Live overnight rest-block needs wall clock 07:00–16:59 (else PHPUnit gates apply)'
		)

		const yesterday = shiftCalendarDay(parts.date, -1)
		const created = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
			data: {
				date: yesterday,
				startTime: '22:00',
				endTime: '06:00',
			},
		})
		test.skip(
			!created.ok,
			'Could not seed overnight entry: ' + JSON.stringify(created.json)
		)
		const overnightId =
			created.json?.data?.id ?? created.json?.id ?? created.json?.entry?.id ?? null
		const status = created.json?.data?.status ?? created.json?.entry?.status ?? created.json?.status
		test.skip(
			status && status !== 'completed' && status !== 'COMPLETED',
			'Overnight seed not completed (approval pending?): ' + String(status)
		)

		// The rest gate anchors on the LATEST completed end_time, and a same-day
		// start+end pair qualifies for the intraday split-shift exemption — any
		// stamp left by an earlier spec/test (incl. this file's own test 1) would
		// legitimately allow clock-in and make the assertion meaningless. Delete
		// every completed row ending after the seeded overnight end so the
		// overnight block becomes the anchor.
		const overnightEnd =
			created.json?.data?.endTime ?? created.json?.entry?.endTime ?? null
		test.skip(!overnightEnd, 'Overnight seed response lacks endTime: ' + JSON.stringify(created.json))
		const todayList = await api(
			page,
			'GET',
			`/apps/arbeitszeitcheck/api/time-entries?start_date=${parts.date}&end_date=${parts.date}&limit=200`
		)
		const rows = todayList.entries || todayList.data || []
		for (const row of rows) {
			const rid = Number(row.id)
			const rowEnd = row.endTime || row.end_time || null
			if (
				rid !== Number(overnightId)
				&& String(row.status).toLowerCase() === 'completed'
				&& rowEnd
				&& String(rowEnd) > String(overnightEnd)
			) {
				const del = await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/time-entries/${rid}`)
				if (!del.ok || del.json?.success === false) {
					test.skip(
						true,
						`Could not remove fresher same-day entry ${rid} (intraday anchor): ` +
							JSON.stringify(del.json)
					)
				}
			}
		}

		try {
			const clockIn = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/in')
			expect(clockIn.ok).toBe(false)
			expect(clockIn.json?.error_code).toBe('rest_period_required')
		} finally {
			if (overnightId) {
				await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/time-entries/${overnightId}`)
			}
		}
	})
})
