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
			data: {
				type: 'vacation',
				start_date: date,
				end_date: date,
				reason: `atlas slot probe ${slot}`,
				...extraData,
			},
		})
		const body = await res.json()
		last = body
		if (body.success) {
			usedDates.add(date)
			return { body, date }
		}
		const retryable = body.error_code === 'VAC_HALF_DAY_NON_WORKING'
			|| /overlap/i.test(String(body.error ?? ''))
		if (!retryable) break
	}
	return { body: last, date: null }
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
})
