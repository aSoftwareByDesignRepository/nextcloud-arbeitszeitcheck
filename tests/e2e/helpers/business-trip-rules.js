// @ts-check
/** Shared rule-set coverage for business-trip e2e specs.
 *
 *  Ambient rule assignments cannot be trusted: each one pins a specific
 *  rule-set VERSION, and every lifecycle spec activation retires the
 *  previous version — the rows stay effective but resolve to dead sets,
 *  so submits fail "No business-trip rule set applies on <date>".
 *  Provision a bounded USER-scope assignment (highest scope precedence)
 *  on the set that is active right now. The overlap guard only checks
 *  same-user rows: stale ones starting before the window get truncated
 *  at its edge; ones starting inside the window make the call fail so
 *  the caller's retry shifts the date (past any ≤4d stub).
 *
 *  @param {import('@playwright/test').Page} adminPage admin session
 *  @param {string} employeeUid trip owner's uid (user scope target)
 *  @param {number} fromMs desired window start (UTC ms)
 *  @param {number} toMs desired window end (UTC ms)
 *  @param {number} minToMs last trip day — the shrunk window may never end before it
 *  @returns {Promise<boolean>} false when coverage cannot be placed. */
import { api, apiAllowFailure } from './api.js'

const ADMIN_BT = '/apps/arbeitszeitcheck/api/admin'

export async function ensureUserRuleCoverage(adminPage, employeeUid, fromMs, toMs, minToMs) {
	const iso = (ms) => new Date(ms).toISOString().slice(0, 10)
	const sets = (await api(adminPage, 'GET', `${ADMIN_BT}/business-trip-rule-sets`)).items ?? []
	let active = sets.find((s) => s.status === 'active')
	if (!active) {
		const draft = await api(adminPage, 'POST', `${ADMIN_BT}/business-trip-rule-presets/DE`)
		await api(adminPage, 'POST', `${ADMIN_BT}/business-trip-rule-sets/${draft.item.id}/mark-reviewed`, {
			data: { configHash: draft.item.configHash },
		})
		active = (await api(adminPage, 'POST', `${ADMIN_BT}/business-trip-rule-sets/${draft.item.id}/activate`)).item
	}
	const rows = (await api(adminPage, 'GET', `${ADMIN_BT}/business-trip-rule-assignments`)).items ?? []
	let from = fromMs
	let to = toMs
	for (let guard = 0; guard < 10; guard++) {
		const hit = rows.find((a) => a.scopeType === 'user' && a.scopeId === employeeUid
			&& a.effectiveFrom <= iso(to) && (!a.effectiveTo || a.effectiveTo >= iso(from)))
		if (!hit) {
			break
		}
		if (hit.effectiveFrom < iso(from)) {
			// Row starts before the window — truncate it at the window edge.
			const ended = await apiAllowFailure(adminPage, 'POST', `${ADMIN_BT}/business-trip-rule-assignments/${hit.id}/end`, {
				data: { revision: hit.revision, effectiveTo: iso(from - 86400000) },
			})
			if (!ended.ok) {
				return false
			}
			hit.effectiveTo = iso(from - 86400000)
			hit.revision += 1
			continue
		}
		// Row starts inside the window. If it only clips the tail, shrink the
		// window ahead of it while it still covers the last trip day; otherwise
		// bail — the caller's retry shifts the date past any stub.
		if (hit.effectiveFrom > iso(minToMs)) {
			to = new Date(`${hit.effectiveFrom}T00:00:00Z`).getTime() - 86400000
			continue
		}
		return false
	}
	const res = await apiAllowFailure(adminPage, 'POST', `${ADMIN_BT}/business-trip-rule-assignments`, {
		data: {
			ruleSetId: active.id,
			scopeType: 'user',
			scopeId: employeeUid,
			priority: 0,
			effectiveFrom: iso(from),
			effectiveTo: iso(to),
		},
	})
	return res.ok
}

/** Statuses the overlap validator counts (BusinessTripMapper::findOverlapping). */
const TRIP_BLOCKING_STATUSES = new Set(['draft', 'submitted', 'approved'])

/** Deterministic non-overlapping trip-window picker for the shared dev
 *  employee. Past runs leave draft/submitted/approved trips behind and
 *  random candidate dates saturate as the calendar fills (20261126: 71%
 *  of the 2028 window blocked → the old 8-random-attempts loop failed).
 *  Reads the employee's own trip index once, marks the blocking
 *  intervals, and returns every free base day inside a far-future span;
 *  callers iterate from a random start offset so sibling specs still
 *  spread across days.
 *
 *  @param {import('@playwright/test').Page} page employee session
 *  @param {{anchor?: string, spanDays?: number, tripDays?: number}} [opts]
 *          anchor: ISO date of the first candidate base day;
 *          tripDays: day offset between trip start (base 07:00Z) and end
 *          (base+tripDays 16:00Z) — matches the caller's create payload.
 *  @returns {Promise<number[]>} free base-day timestamps (UTC ms), ascending */
export async function freeTripWindowBases(page, { anchor = '2028-03-01T00:00:00Z', spanDays = 730, tripDays = 2 } = {}) {
	const blocking = []
	for (let offset = 0; offset < 2000; offset += 200) {
		const res = await api(page, 'GET', `/apps/arbeitszeitcheck/api/business-trips?limit=200&offset=${offset}`)
		for (const t of res.items ?? []) {
			if (TRIP_BLOCKING_STATUSES.has(t.status)) {
				blocking.push([Date.parse(t.startsAtUtc), Date.parse(t.endsAtUtc)])
			}
		}
		if ((res.items ?? []).length < 200) {
			break
		}
	}
	const anchorMs = new Date(anchor).getTime()
	const bases = []
	for (let d = 0; d <= spanDays; d++) {
		const base = anchorMs + d * 86400000
		const start = base + 7 * 3600000
		const end = base + tripDays * 86400000 + 16 * 3600000
		if (!blocking.some(([s, e]) => start <= e && end >= s)) {
			bases.push(base)
		}
	}
	return bases
}
