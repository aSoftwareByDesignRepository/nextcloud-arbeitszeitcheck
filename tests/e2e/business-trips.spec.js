import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

/**
 * Business-trip critical path (Workforce Policy Suite, Phase 5/6):
 * admin rollout → reviewed+active DE preset rule → org assignment →
 * employee draft → preview snapshot token → submit → admin approve with
 * the exact token → frozen CSV export. Plus a BOLA probe: an out-of-scope
 * user must get the canonical 404 on the manager surface.
 *
 * Feature-state aware: the admin leg enables the feature (idempotent) so
 * the spec is green on both fresh upgrades and enabled dev instances.
 */

test.describe('Business trips', () => {
	test.skip(!process.env.NC_EMPLOYEE_USER || !process.env.NC_ADMIN_USER, 'Requires NC_EMPLOYEE_USER + NC_ADMIN_USER')

	// Trip/assignment window far in the future. Start is chosen lazily in
	// test 1: it must dodge stale org-scope assignment stubs that earlier
	// runs leave behind (end can only truncate, never delete — a stub still
	// owns its day for the overlap check).
	const dayMs = 86400000
	const iso = (d) => d.toISOString().slice(0, 10)
	let startDay = new Date(Date.UTC(2100 + Math.floor(Math.random() * 100), 0, 1))
	let start, end
	const tripWindow = () => {
		start = `${iso(startDay)}T07:00:00Z`
		end = `${iso(new Date(startDay.getTime() + dayMs))}T19:00:00Z`
	}
	tripWindow()
	const requestKey = `e2e-trip-${Date.now()}`

	let adminPage, employeePage, tripId, snapshotToken, ruleSetId

	test.beforeAll(async ({ browser }) => {
		adminPage = await (await browser.newContext()).newPage()
		await login(adminPage, credsFromEnv('ADMIN'))
		await adminPage.goto('/apps/arbeitszeitcheck/admin/business-trips')
		await assertArbeitszeitcheckLoaded(adminPage)

		employeePage = await (await browser.newContext()).newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto('/apps/arbeitszeitcheck/business-trips')
	})

	test.afterAll(async () => {
		await adminPage?.close()
		await employeePage?.close()
	})

	test('admin rollout: enable, preset draft, legal review, activate, assign', async () => {
		// Enable is additive + idempotent — safe to call on every run.
		const enabled = await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/business-trips/rollout/enable', {
			data: { acknowledgeRollbackWarning: true },
		})
		expect(enabled.success).toBe(true)

		// Preflight must be observable before anything is active.
		const rollout = await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/business-trips/rollout')
		expect(rollout.flags.business_trips_enabled).toBe(true)

		// Unreviewed drafts must refuse activation (legal gate, spec §10.5).
		const draft = await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/business-trip-rule-presets/DE')
		expect(draft.success).toBe(true)
		ruleSetId = draft.item.id
		const premature = await apiAllowFailure(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trip-rule-sets/${ruleSetId}/activate`)
		expect(premature.status).toBe(409)
		expect(premature.json.error_code).toBe('RULE_REVIEW_REQUIRED')

		// Review is bound to the exact hash — a wrong hash must fail closed.
		const stale = await apiAllowFailure(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trip-rule-sets/${ruleSetId}/mark-reviewed`, {
			data: { configHash: '0'.repeat(64) },
		})
		expect(stale.status).toBe(409)
		expect(stale.json.error_code).toBe('RULE_REVIEW_STALE')

		await api(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trip-rule-sets/${ruleSetId}/mark-reviewed`, {
			data: { configHash: draft.item.configHash },
		})
		const activated = await api(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trip-rule-sets/${ruleSetId}/activate`)
		expect(activated.item.status).toBe('active')

		// Org-scope assignment makes the rule reachable for every employee.
		// Stale org assignments from earlier runs can only be truncated, never
		// deleted — a stub keeps owning its day. So pick a bounded assignment
		// window [from-1d, from+9d] that overlaps no existing org row:
		// rows starting before the window get ended at its edge; rows starting
		// inside push the window past them (open-ended rows push it left).
		const rows = (await api(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/business-trip-rule-assignments')).items ?? []
		const overlaps = (a, from, to) => a.scopeType === 'org' && a.effectiveFrom <= to && (!a.effectiveTo || a.effectiveTo >= from)
		let from = iso(startDay), to = iso(new Date(startDay.getTime() + 9 * dayMs))
		for (let guard = 0; guard < 10; guard++) {
			const hit = rows.find((a) => overlaps(a, from, to))
			if (!hit) break
			if (hit.effectiveFrom < from) {
				const ended = await apiAllowFailure(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trip-rule-assignments/${hit.id}/end`, {
					data: { revision: hit.revision, effectiveTo: iso(new Date(new Date(from).getTime() - dayMs)) },
				})
				expect(ended.status, JSON.stringify(ended.json)).toBe(200)
				hit.effectiveTo = iso(new Date(new Date(from).getTime() - dayMs))
				continue
			}
			// Row starts inside the window — slide the window around it.
			if (hit.effectiveTo) {
				from = iso(new Date(new Date(hit.effectiveTo).getTime() + dayMs))
			} else {
				to = iso(new Date(new Date(hit.effectiveFrom).getTime() - dayMs))
				from = iso(new Date(new Date(to).getTime() - 9 * dayMs))
				continue
			}
			to = iso(new Date(new Date(from).getTime() + 9 * dayMs))
		}
		expect(rows.filter((a) => overlaps(a, from, to))).toEqual([])
		startDay = new Date(new Date(from).getTime() + dayMs)
		tripWindow()
		await api(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/business-trip-rule-assignments', {
			data: { ruleSetId, scopeType: 'org', scopeId: 'org', priority: 0, effectiveFrom: from, effectiveTo: to },
		})
	})

	test('employee: draft → preview token → submit', async () => {
		const created = await api(employeePage, 'POST', '/apps/arbeitszeitcheck/api/business-trips', {
			data: {
				startsAtUtc: start,
				endsAtUtc: end,
				originTimezone: 'Europe/Berlin',
				destinationTimezone: 'Europe/London',
				evaluationTimezone: 'Europe/Berlin',
				purpose: 'E2E contract trip',
				requestKey,
			},
		})
		tripId = created.trip.id
		expect(created.trip.status).toBe('draft')

		// Idempotent replay on the same requestKey must return the same trip.
		const replay = await api(employeePage, 'POST', '/apps/arbeitszeitcheck/api/business-trips', {
			data: {
				startsAtUtc: start,
				endsAtUtc: end,
				originTimezone: 'Europe/Berlin',
				destinationTimezone: 'Europe/London',
				evaluationTimezone: 'Europe/Berlin',
				purpose: 'E2E contract trip',
				requestKey,
			},
		})
		expect(replay.trip.id).toBe(tripId)

		const preview = await api(employeePage, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/preview`)
		snapshotToken = preview.snapshotToken
		expect(snapshotToken).toMatch(/^[0-9a-f]{64}$/)
		expect(preview.days.length).toBeGreaterThan(0)

		const detail = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
		const submitted = await api(employeePage, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/submit`, {
			data: { revision: detail.trip.revision },
		})
		expect(submitted.trip.status).toBe('submitted')

		// Flow-parity: submit recalculates under lock — the persisted fact
		// set must equal the advisory preview it was promised on (same day
		// rows, same payload hash). A drift here is a silent-write bug.
		const persisted = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
		const project = (d) => ({
			localDate: d.localDate,
			ruleSetId: d.ruleSetId,
			presenceMinutes: d.presenceMinutes,
			actualWorkMinutes: d.actualWorkMinutes,
			activeTravelMinutes: d.activeTravelMinutes,
			passiveTravelMinutes: d.passiveTravelMinutes,
			complianceMinutes: d.complianceMinutes,
			accountTargetMinutes: d.accountTargetMinutes,
			accountTopUpMinutes: d.accountTopUpMinutes,
			exportMinutes: d.exportMinutes,
		})
		expect(persisted.days.map(project)).toEqual(preview.days.map(project))
		expect(persisted.snapshotToken).toBe(snapshotToken)

		// A second submit must fail closed — no silent re-transition.
		const again = await apiAllowFailure(employeePage, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/submit`, {
			data: { revision: submitted.trip.revision },
		})
		expect(again.ok).toBe(false)
	})

	test('approve requires the exact frozen snapshot token', async () => {
		const detail = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
		const rev = detail.trip.revision

		// Missing token → 409, wrong token → 409, never a silent merge.
		const missing = await apiAllowFailure(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trips/${tripId}/approve`, {
			data: { revision: rev },
		})
		expect(missing.status).toBe(409)
		const wrong = await apiAllowFailure(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trips/${tripId}/approve`, {
			data: { revision: rev, snapshotToken: 'f'.repeat(64) },
		})
		expect(wrong.status).toBe(409)
		expect(wrong.json.error_code).toBe('TRIP_SNAPSHOT_STALE')

		const approved = await api(adminPage, 'POST', `/apps/arbeitszeitcheck/api/admin/business-trips/${tripId}/approve`, {
			data: { revision: rev, snapshotToken },
		})
		expect(approved.trip.status).toBe('approved')
	})

	test('approved trip exports frozen CSV; foreign manager surface 404s', async () => {
		const res = await employeePage.request.fetch(
			new URL(`/apps/arbeitszeitcheck/api/business-trips/${tripId}/export/csv`, employeePage.url()).toString(),
			{ headers: { requesttoken: await employeePage.evaluate(() => window.OC.requestToken) } },
		)
		expect(res.status()).toBe(200)
		const body = await res.text()
		expect(body.length).toBeGreaterThan(0)

		// BOLA probe: the substitute user is not a manager for the trip —
		// the manager surface must collapse to the canonical 404 payload.
		const sub = credsFromEnv('SUBSTITUTE')
		if (sub) {
			const foreign = await (await employeePage.context().browser().newContext()).newPage()
			await login(foreign, sub)
			await foreign.goto('/apps/arbeitszeitcheck')
			const denied = await apiAllowFailure(foreign, 'GET', `/apps/arbeitszeitcheck/api/manager/business-trips/${tripId}`)
			expect(denied.status).toBe(404)
			expect(denied.json.error).toBe('NOT_FOUND')
			await foreign.close()
		}
	})
})
