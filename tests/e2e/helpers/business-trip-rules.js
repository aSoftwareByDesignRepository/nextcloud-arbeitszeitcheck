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
