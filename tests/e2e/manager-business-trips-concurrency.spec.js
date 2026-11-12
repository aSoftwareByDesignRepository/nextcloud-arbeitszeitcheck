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

test.use({ trace: 'off' })

const MGR_API = '/apps/arbeitszeitcheck/api/manager/business-trips'
const PAGE = '/apps/arbeitszeitcheck/manager/business-trips'

/** Unique far-future window per call — trips persist in the dev DB, so
 * fixed dates collide with prior runs (overlap validator). */
async function createTrip(page, purpose) {
	const base = new Date('2028-01-05T00:00:00Z').getTime() + (Date.now() % 280) * 86400000 + Math.floor(Math.random() * 30) * 86400000
	const start = new Date(base)
	const end = new Date(base + 86400000)
	const iso = (d) => d.toISOString().slice(0, 10)
	const created = await api(page, 'POST', '/apps/arbeitszeitcheck/api/business-trips', {
		data: {
			startsAtUtc: `${iso(start)}T07:00:00Z`,
			endsAtUtc: `${iso(end)}T16:00:00Z`,
			originTimezone: 'Europe/Berlin',
			destinationTimezone: 'Europe/Berlin',
			evaluationTimezone: 'Europe/Berlin',
			purpose,
			requestKey: `e2e-mbt-cas-${Date.now()}-${Math.random().toString(16).slice(2)}`,
		},
	})
	return created.trip.id
}

async function submitTrip(page, tripId) {
	const detail = await api(page, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
	const submitted = await api(page, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/submit`, {
		data: { revision: detail.trip.revision },
	})
	expect(submitted.trip.status).toBe('submitted')
}

async function managerDetail(page, tripId) {
	return api(page, 'GET', `${MGR_API}/${tripId}`)
}

test.describe('Manager business-trip decisions — CAS + scope (Docker dev)', () => {
	test('concurrent approves with the same CAS pair apply at most once', async ({ browser }) => {
		skipIfMissingCreds('EMPLOYEE')
		skipIfMissingCreds('MANAGER')
		const purpose = `E2E CAS approve ${Date.now()}`

		const employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		const tripId = await createTrip(employeePage, purpose)
		await submitTrip(employeePage, tripId)

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

		await employeePage.close()
		await managerPage.close()
	})

	test('non-manager employee cannot read the manager queue or manager detail', async ({ page }) => {
		skipIfMissingCreds('EMPLOYEE')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto(PAGE)
		// The page itself redirects non-managers away; the API is the oracle.
		const purpose = `E2E IDOR ${Date.now()}`
		const tripId = await createTrip(page, purpose)
		await submitTrip(page, tripId)

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
