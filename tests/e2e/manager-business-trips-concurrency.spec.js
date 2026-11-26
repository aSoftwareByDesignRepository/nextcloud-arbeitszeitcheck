// @ts-check
/**
 * Concurrency + authorization proofs for the manager business-trip queue:
 *  - two simultaneous approve calls carrying the SAME revision+snapshotToken
 *    must be applied at most once (CAS / last-writer-loses at the service)
 *  - a non-manager employee cannot read the manager queue or manager detail
 *    (uniform 403/404 — no existence oracle beyond the queue itself)
 *  - manager detail refuses a trip that is not in 'submitted' state with
 *    the same 404 as a missing id (no state oracle)
 *
 * These hit the real HTTP stack (two parallel fetches → two PHP workers),
 * not mocks: the CAS invariant must hold in the database, not just in
 * application code order.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, skipIfMissingCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'
import { ensureUserRuleCoverage } from './helpers/business-trip-rules.js'

test.use({ trace: 'off' })

const MGR_API = '/apps/arbeitszeitcheck/api/manager/business-trips'
const PAGE = '/apps/arbeitszeitcheck/manager/business-trips'

/** Unique far-future window per attempt — trips persist in the dev DB, so
 * fixed dates collide with prior runs (overlap validator). */
function tripWindowBaseMs(attempt) {
	return new Date('2028-01-05T00:00:00Z').getTime() + (Date.now() % 280) * 86400000 + Math.floor(Math.random() * 30) * 86400000 + attempt * 86400000 * 45
}

/** One attempt; returns the trip id, or null when the window is already
 * taken. */
async function createTripOnce(page, purpose, attempt, base) {
	const iso = (d) => d.toISOString().slice(0, 10)
	const res = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/business-trips', {
		data: {
			startsAtUtc: `${iso(new Date(base))}T07:00:00Z`,
			endsAtUtc: `${iso(new Date(base + 86400000))}T16:00:00Z`,
			originTimezone: 'Europe/Berlin',
			destinationTimezone: 'Europe/Berlin',
			evaluationTimezone: 'Europe/Berlin',
			purpose,
			requestKey: `e2e-mbt-cas-${Date.now()}-${Math.random().toString(16).slice(2)}`,
		},
	})
	if (res.ok) {
		return res.json.trip.id
	}
	if (!/overlap/i.test(res.json?.error || '')) {
		throw new Error(`trip create failed (${res.status}): ${res.json?.error || 'unknown'}`)
	}
	return null
}

async function createTrip(page, purpose) {
	for (let attempt = 0; attempt < 8; attempt++) {
		const tripId = await createTripOnce(page, purpose, attempt, tripWindowBaseMs(attempt))
		if (tripId !== null) {
			return tripId
		}
	}
	throw new Error('could not place a non-overlapping trip after 8 attempts')
}

async function submitTrip(page, tripId) {
	const detail = await api(page, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
	const submitted = await api(page, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/submit`, {
		data: { revision: detail.trip.revision },
	})
	expect(submitted.trip.status).toBe('submitted')
}

/** Draft → submit, retrying the whole pair with a fresh window on
 * overlap — submit re-checks overlap under the user-month locks
 * (BusinessTripService::transition), so a sibling spec can claim the
 * window between our create and submit. An orphan draft from a lost
 * submit race is cancelled before the next attempt — drafts count
 * toward the overlap check. */
async function createAndSubmitTrip(page, adminPage, purpose) {
	const employeeUid = credsFromEnv('EMPLOYEE').username
	for (let attempt = 0; attempt < 8; attempt++) {
		const base = tripWindowBaseMs(attempt)
		if (!(await ensureUserRuleCoverage(adminPage, employeeUid, base - 86400000, base + 2 * 86400000, base + 86400000))) {
			continue
		}
		const tripId = await createTripOnce(page, purpose, attempt, base)
		if (tripId === null) {
			continue
		}
		try {
			await submitTrip(page, tripId)
			return tripId
		} catch (e) {
			if (!/overlap/i.test(e?.message || '')) {
				throw e
			}
		}
		const fresh = await apiAllowFailure(page, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
		if (fresh.ok) {
			await apiAllowFailure(page, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/cancel`, {
				data: { revision: fresh.json.trip.revision },
			})
		}
	}
	throw new Error('could not place a non-overlapping submitted trip after 8 attempts')
}

async function managerDetail(page, tripId) {
	return api(page, 'GET', `${MGR_API}/${tripId}`)
}

test.describe('Manager business-trip decisions — CAS + scope (Docker dev)', () => {
	test('concurrent approves with the same CAS pair apply at most once', async ({ browser }) => {
		skipIfMissingCreds('EMPLOYEE')
		skipIfMissingCreds('MANAGER')
		skipIfMissingCreds('ADMIN')
		const purpose = `E2E CAS approve ${Date.now()}`

		const adminPage = await browser.newPage()
		await login(adminPage, credsFromEnv('ADMIN'))

		const employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		const tripId = await createAndSubmitTrip(employeePage, adminPage, purpose)

		const managerPage = await browser.newPage()
		await login(managerPage, credsFromEnv('MANAGER'))
		await managerPage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(managerPage)

		const detail = await managerDetail(managerPage, tripId)
		const casPair = { revision: detail.trip.revision, snapshotToken: detail.snapshotToken }
		expect(casPair.snapshotToken).toBeTruthy()

		const [r1, r2] = await Promise.all([
			apiAllowFailure(managerPage, 'POST', `${MGR_API}/${tripId}/approve`, { data: casPair }),
			apiAllowFailure(managerPage, 'POST', `${MGR_API}/${tripId}/approve`, { data: casPair }),
		])

		const successes = [r1, r2].filter((r) => r.ok)
		expect(successes.length, `both approves succeeded: ${JSON.stringify([r1.status, r2.status])}`).toBe(1)
		const loser = [r1, r2].find((r) => !r.ok)
		expect(loser.status).toBe(409)

		const after = await managerDetail(managerPage, tripId)
		expect(after.trip.status).toBe('approved')

		// A third decide attempt (any path) must keep failing — the trip is decided.
		const again = await apiAllowFailure(managerPage, 'POST', `${MGR_API}/${tripId}/approve`, { data: casPair })
		expect(again.ok).toBe(false)

		await adminPage.close()
		await employeePage.close()
		await managerPage.close()
	})

	test('non-manager employee cannot read the manager queue or manager detail', async ({ page }) => {
		skipIfMissingCreds('EMPLOYEE')
		skipIfMissingCreds('ADMIN')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto(PAGE)
		const adminPage = await page.context().browser().newPage()
		await login(adminPage, credsFromEnv('ADMIN'))
		// The page itself redirects non-managers away; the API is the oracle.
		const purpose = `E2E IDOR ${Date.now()}`
		const tripId = await createAndSubmitTrip(page, adminPage, purpose)
		await adminPage.close()

		const queue = await apiAllowFailure(page, 'GET', MGR_API)
		expect([403, 404]).toContain(queue.status)

		const detail = await apiAllowFailure(page, 'GET', `${MGR_API}/${tripId}`)
		expect([403, 404]).toContain(detail.status)

		// Even the employee's OWN submitted trip is not reachable via the
		// manager surface — scope, not ownership, decides.
		expect(detail.json?.trip).toBeUndefined()

		// cleanup: withdraw so the queue stays clean for other specs
		const fresh = await api(page, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
		await api(page, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/cancel`, {
			data: { revision: fresh.trip.revision },
		})
	})

	test('manager detail hides non-submitted trips with the same 404 as missing ids', async ({ browser }) => {
		skipIfMissingCreds('EMPLOYEE')
		skipIfMissingCreds('MANAGER')
		const employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		const draftId = await createTrip(employeePage, `E2E draft oracle ${Date.now()}`)

		const managerPage = await browser.newPage()
		await login(managerPage, credsFromEnv('MANAGER'))
		await managerPage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(managerPage)

		const draft = await apiAllowFailure(managerPage, 'GET', `${MGR_API}/${draftId}`)
		const missing = await apiAllowFailure(managerPage, 'GET', `${MGR_API}/99999999`)
		expect(draft.status).toBe(404)
		expect(missing.status).toBe(404)
		// Identical shape — a draft must not leak a different error than a
		// nonexistent id (existence oracle / AC-GLOBAL-15).
		expect(Object.keys(draft.json || {}).sort()).toEqual(Object.keys(missing.json || {}).sort())

		await employeePage.close()
		await managerPage.close()
	})
})
