// @ts-check
/**
 * Atlas web_api probe: absence API accepts + persists the explicit
 * day-shape columns (AZC-HALF-DAY-SLOTS-BOUNDARIES, migration 1062).
 *
 * Real session login (web surface — app-passwords route through the
 * mobile license gate), real /api/absences round-trip:
 *   POST {type,start_date,end_date,start_day_fraction:0.5,start_day_slot:am}
 *   GET  /api/absences/{id} -> start_day_slot='am', start_day_fraction=0.5
 * Complementary pm leg + camelCase alias leg; rows deleted in finally.
 */
import { test, expect } from '@playwright/test'
import { loginAs, gotoApp } from './helpers/auth.js'

function futureDate(offsetDays) {
	const d = new Date()
	d.setDate(d.getDate() + offsetDays)
	return d.toISOString().slice(0, 10)
}

/**
 * Half-day requests fail closed on non-working days for the employee's
 * contract (VAC_HALF_DAY_NON_WORKING). Iterate weekday candidates and
 * return the first accepted create; fail when every candidate rejects.
 */
async function createHalfDay(page, hdr, slot, extraData, usedDates) {
	let last = null
	for (let i = 30; i <= 120; i++) {
		const date = futureDate(i)
		const dow = new Date(`${date}T12:00:00`).getDay()
		if (dow === 0 || dow === 6 || usedDates.has(date)) continue
		const res = await page.request.post('/apps/arbeitszeitcheck/api/absences', {
			headers: hdr,
			timeout: 30_000,
			data: {
				type: 'vacation',
				start_date: date,
				end_date: date,
				reason: `atlas slot probe ${slot}`,
				...extraData,
			},
		}).catch(() => null)
		// A transport timeout under pool load is environmental, not a feature
		// verdict — the request may still have committed server-side (swept
		// by reason prefix on the next run), so treat it as "try next day".
		if (!res) { last = { success: false, error_code: '__timeout__' }; continue }
		const body = await res.json()
		last = body
		if (body.success) {
			usedDates.add(date)
			return { body, date }
		}
		// Retry on environmental contention (non-working day for the
		// contract, overlap with another spec's row, exhausted yearly
		// allowance). Fail only on feature-regression codes — a rejected
		// well-formed shape payload IS the bug this probe hunts.
		const code = String(body.error_code ?? '')
		const featureBroken = code.startsWith('HALF_DAY_')
			|| /slot|fraction|invalid/i.test(code + ' ' + String(body.error ?? ''))
		if (featureBroken) break
	}
	return { body: last, date: null }
}

/** Delete leftover 'atlas *' probe rows so leaked winners from aborted
 * runs cannot pollute the day-search window (an am+pm pair on a day
 * makes the whole day read as fully covered). Call after login, before
 * probing. */
async function sweepProbeRows(page, hdr) {
	const list = await page.request
		.get('/apps/arbeitszeitcheck/api/absences?limit=200', { headers: hdr })
		.then(r => r.json()).catch(() => null)
	for (const row of (list?.absences ?? list?.data ?? [])) {
		if (String(row?.reason ?? '').startsWith('atlas ') && row?.id) {
			await page.request
				.delete(`/apps/arbeitszeitcheck/api/absences/${row.id}`, { headers: hdr })
				.catch(() => {})
		}
	}
}

async function requestToken(page) {
	return page.evaluate(() => window.OC?.requestToken
		|| document.querySelector('head')?.getAttribute('data-requesttoken') || '')
}

test.describe('Absence day-shape slot payloads (migration 1062)', () => {
	test('start_day_fraction + start_day_slot accepted and persisted (am/pm/camel)', async ({ page }) => {
		await loginAs(page, 'EMPLOYEE')
		await gotoApp(page, '/apps/arbeitszeitcheck/absences')
		const rt = await requestToken(page)
		expect(rt, 'no requesttoken on page').toBeTruthy()
		const hdr = { requesttoken: rt, 'Accept': 'application/json' }
		const created = []
		await sweepProbeRows(page, hdr)
		const usedDates = new Set()
		try {
			// am half-day, snake_case wire names
			const am = await createHalfDay(page, hdr, 'am', { start_day_fraction: '0.5', start_day_slot: 'am' }, usedDates)
			expect(am.body?.success, `am create failed: ${JSON.stringify(am.body)}`).toBe(true)
			const amId = am.body.absence?.id ?? am.body.id
			expect(amId).toBeTruthy()
			created.push(amId)

			const amRead = await page.request.get(`/apps/arbeitszeitcheck/api/absences/${amId}`, { headers: hdr })
			const amRow = (await amRead.json()).absence ?? {}
			expect(String(amRow.startDaySlot), 'am slot not persisted').toBe('am')
			expect(Number(amRow.startDayFraction), 'am fraction not persisted').toBeCloseTo(0.5, 2)
			expect(amRow.endDaySlot == null, 'end slot should be null on single-day am half').toBe(true)

			// pm half-day on a different working day (pairing complement)
			const pm = await createHalfDay(page, hdr, 'pm', { start_day_fraction: '0.5', start_day_slot: 'pm' }, usedDates)
			expect(pm.body?.success, `pm create failed: ${JSON.stringify(pm.body)}`).toBe(true)
			const pmId = pm.body.absence?.id ?? pm.body.id
			expect(pmId).toBeTruthy()
			created.push(pmId)
			const pmRow = (await (await page.request.get(
				`/apps/arbeitszeitcheck/api/absences/${pmId}`, { headers: hdr },
			)).json()).absence ?? {}
			expect(String(pmRow.startDaySlot)).toBe('pm')
			expect(Number(pmRow.startDayFraction)).toBeCloseTo(0.5, 2)

			// camelCase JSON aliases (mobile clients)
			const cam = await createHalfDay(page, hdr, 'camel', { startDaySlot: 'pm', startDayFraction: '0.5' }, usedDates)
			expect(cam.body?.success, `camel create failed: ${JSON.stringify(cam.body)}`).toBe(true)
			const camId = cam.body.absence?.id ?? cam.body.id
			created.push(camId)
			const camRow = (await (await page.request.get(
				`/apps/arbeitszeitcheck/api/absences/${camId}`, { headers: hdr },
			)).json()).absence ?? {}
			expect(String(camRow.startDaySlot)).toBe('pm')
			expect(Number(camRow.startDayFraction)).toBeCloseTo(0.5, 2)
		} finally {
			for (const id of created) {
				await page.request.delete(`/apps/arbeitszeitcheck/api/absences/${id}`, { headers: hdr }).catch(() => {})
			}
		}
	})

	/**
	 * Concurrency soak (NN-02 / QA open question). Same-account creates are
	 * serialized by a non-blocking user mutation lock: parallel posts see
	 * error_code 'locked' on the loser — there is NO race window into the
	 * DB. The honest invariants under fire:
	 *   - 'locked' is a transient, retryable refusal (retry wins cleanly).
	 *   - am + pm may coexist: a locked/complementary retry lands BOTH halves.
	 *   - am + am may NEVER coexist: at most one winner, always — the loser
	 *     fails 'locked' and then fails 'overlap' on retry (slot taken).
	 */
	test('parallel same-day halves: lock serializes, complementary pairs persist, same-slot never doubles', async ({ page }) => {
		test.setTimeout(240_000)
		await loginAs(page, 'EMPLOYEE')
		await gotoApp(page, '/apps/arbeitszeitcheck/absences')
		const rt = await requestToken(page)
		expect(rt, 'no requesttoken on page').toBeTruthy()
		const hdr = { requesttoken: rt, 'Accept': 'application/json' }
		const created = []
		await sweepProbeRows(page, hdr)

		const post = async (date, slot) => {
			const raw = await page.request.post('/apps/arbeitszeitcheck/api/absences', {
				headers: hdr,
				timeout: 30_000,
				data: {
					type: 'vacation',
					start_date: date,
					end_date: date,
					reason: `atlas concurrent ${slot}`,
					start_day_fraction: '0.5',
					start_day_slot: slot,
				},
			}).then(r => r.json()).catch(() => null)
			let out = raw
			if (out === null) {
				// Transport timeout under pool load — the POST may still have
				// committed. Verify via list so a committed winner is tracked
				// (an unowned win would leak rows AND spoof a same-slot
				// violation that never happened at the API level).
				const list = await page.request
					.get(`/apps/arbeitszeitcheck/api/absences?limit=200`, { headers: hdr, timeout: 30_000 })
					.then(r => r.json()).catch(() => null)
				const hit = (list?.absences ?? list?.data ?? []).find(r =>
					String(r?.reason ?? '') === `atlas concurrent ${slot}`
					&& String(r?.start_date ?? '').slice(0, 10) === date)
				out = hit
					? { success: true, absence: hit, id: hit.id }
					: { success: false, error_code: '__timeout__' }
			}
			// Track every winner immediately — an unowned win would leak rows.
			const b = out
			if (b?.success) {
				const id = b.absence?.id ?? b.id
				if (id) created.push(id)
			}
			return b
		}
		const locked = b => !b?.success
			&& ['locked', '__timeout__'].includes(String(b?.error_code ?? ''))
		const overlap = b => !b?.success && /overlap/i.test(String(b?.error ?? ''))
		// A feature-level rejection of a well-formed half payload IS the bug
		// under test — fail fast instead of scanning the whole window.
		const featureBroken = b => {
			if (b?.success) return false
			const code = String(b?.error_code ?? '')
			return code.startsWith('HALF_DAY_')
				|| /slot|fraction|invalid/i.test(code + ' ' + String(b?.error ?? ''))
		}

		try {
			// --- Complementary am+pm on one day: after the dust settles BOTH
			// halves must exist (in parallel or via retry of the locked leg).
			const retryLocked = async (date, slot, body) => {
				let b = body
				for (let k = 0; k < 3 && locked(b); k++) {
					// No fixed sleep: the POST round-trip is the backoff.
					b = await post(date, slot)
				}
				return b
			}
			let compDone = false
			let compLast = null
			for (let i = 30; i <= 150 && !compDone; i++) {
				const date = futureDate(i)
				const dow = new Date(`${date}T12:00:00`).getDay()
				if (dow === 0 || dow === 6) continue
				let [am, pm] = await Promise.all([post(date, 'am'), post(date, 'pm')])
				am = await retryLocked(date, 'am', am)
				pm = await retryLocked(date, 'pm', pm)
				compLast = [am, pm]
				if (am?.success && pm?.success) {
					compDone = true
				}
				if (featureBroken(am) || featureBroken(pm)) break
			}
			expect(compDone, `complementary am+pm pair never landed — last: ${JSON.stringify(compLast)}`).toBe(true)

			// --- Same-slot am+am on one day: NEVER two winners. A 'locked'
			// loser must discover the taken slot on retry → overlap.
			let sameSlotViolations = 0
			let sameDone = false
			let sameLast = null
			for (let i = 30; i <= 150 && !sameDone; i++) {
				const date = futureDate(i)
				const dow = new Date(`${date}T12:00:00`).getDay()
				if (dow === 0 || dow === 6) continue
				let [a1, a2] = await Promise.all([post(date, 'am'), post(date, 'am')])
				const winsNow = [a1, a2].filter(b => b?.success).length
				if (winsNow === 2) sameSlotViolations++
				if (locked(a1)) a1 = await post(date, 'am')
				if (locked(a2)) a2 = await post(date, 'am')
				sameLast = [a1, a2]
				const winsAfter = [a1, a2].filter(b => b?.success).length
				if (winsAfter === 2) sameSlotViolations++
				if (winsAfter === 1 && (overlap(a1) || overlap(a2))) {
					sameDone = true // canonical outcome: one wins, loser overlaps
				} else if (winsNow > 0 || winsAfter > 0) {
					sameDone = true // a winner exists; outcome recorded via violations
				}
				if (featureBroken(a1) || featureBroken(a2)) break
			}
			expect(sameDone, `no same-slot race ever resolved — last: ${JSON.stringify(sameLast)}`).toBe(true)
			expect(sameSlotViolations, 'two same-slot halves coexisted — overlap predicate breached').toBe(0)
		} finally {
			for (const id of created) {
				await page.request.delete(`/apps/arbeitszeitcheck/api/absences/${id}`, { headers: hdr }).catch(() => {})
			}
		}
	})
})
