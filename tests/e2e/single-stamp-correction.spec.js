// @ts-check
/**
 * Single-stamp correction (retro clock-out on a running session) — the real
 * user journey, end to end against a live instance:
 *
 *   1. Employee is clocked in and sees "Forgot to clock out?" on Home.
 *   2. A pending open-session correction request locks stamping and Home
 *      shows the pending card with a withdraw action (not a clock-in button).
 *   3. Withdrawing restores a resumable session — never a fabricated end.
 *
 * Seeding goes through the real API so the UI assertions stay deterministic.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

const CLOCK_STATUS = '/apps/arbeitszeitcheck/api/clock/status'
const CLOCK_IN = '/apps/arbeitszeitcheck/api/clock/in'
const REQUEST_CORRECTION = (id) => `/apps/arbeitszeitcheck/api/time-entries/${id}/request-correction`
const CANCEL_CORRECTION = (id) => `/apps/arbeitszeitcheck/api/time-entries/${id}/cancel-correction`
const BREAK_END = '/apps/arbeitszeitcheck/api/break/end'

async function status(page) {
	// {success: true, status: {status, current_entry, ...}} — unwrap twice.
	const res = await api(page, 'GET', CLOCK_STATUS)
	return res?.status?.status !== undefined ? res.status : (res?.data ?? res)
}

/** Normalize to a clean clocked-in session (or skip when stamping is off). */
async function ensureActiveSession(page) {
	let s = await status(page)

	// Escape hatch first: a stale pending request must never leak into the test.
	if (s.status === 'pending_approval' && s.current_entry?.id) {
		await api(page, 'POST', CANCEL_CORRECTION(s.current_entry.id))
		s = await status(page)
	}
	if (s.status === 'break' || s.status === 'on_break') {
		await apiAllowFailure(page, 'POST', BREAK_END)
		s = await status(page)
	}
	if (s.status === 'paused') {
		// clockIn resumes a paused session (resumePausedEntry on the same route).
		const res = await apiAllowFailure(page, 'POST', CLOCK_IN)
		test.skip(!res.ok, `Resume not possible (${res.status}): ${JSON.stringify(res.json)}`)
		s = await status(page)
	}
	if (s.status === 'clocked_out') {
		const res = await apiAllowFailure(page, 'POST', CLOCK_IN)
		test.skip(!res.ok, `Clock-in not possible (${res.status}): ${JSON.stringify(res.json)}`)
		s = await status(page)
	}
	test.skip(s.status !== 'active' && s.status !== 'clocked_in', `Expected an active session, got ${s.status}`)
	return s
}

test.describe('single-stamp correction — pending open session', () => {
	test.beforeEach(() => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')
	})

	test('pending request blocks stamping, dashboard offers withdraw, cancel restores session', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		const s = await ensureActiveSession(page)
		const entryId = s.current_entry?.id
		expect(entryId, 'active session must expose current_entry.id').toBeTruthy()

		// The retro-clock-out affordance is visible on the running session.
		await page.goto('/apps/arbeitszeitcheck/')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })
		await expect(page.locator('#dashboard-retro-clockout')).toBeVisible()

		// Employee files the retro clock-out request (real endpoint).
		const endIso = new Date().toISOString()
		const req = await apiAllowFailure(page, 'POST', REQUEST_CORRECTION(entryId), {
			data: {
				justification: 'Vergessen auszustempeln — retro clock-out requested in e2e.',
				endTime: endIso,
				// The seeded session may carry a sub-legal (<15 min) pause-break
				// from the resume cycle; proposing its removal mirrors what an
				// employee would do in the dialog (such breaks never count).
				breaks: [],
			},
		})
		// A designed refusal must still be a 4xx with an error_code — surface it
		// loudly in the report instead of silently skipping.
		expect(
			req.ok,
			`request-correction refused (${req.status}): ${JSON.stringify(req.json)}`,
		).toBe(true)

		// Server truth: status flips to pending_approval with the open entry.
		const pending = await status(page)
		expect(pending.status).toBe('pending_approval')

		// Conflicting stamping is blocked while the request is pending.
		const blockedOut = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/out')
		expect(blockedOut.ok).toBe(false)
		expect(blockedOut.json?.error_code).toBe('open_session_correction_pending')

		// Dashboard: pending card, withdraw action, and NO clock-in button.
		await page.goto('/apps/arbeitszeitcheck/')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })
		await expect(page.locator('#dashboard-withdraw-correction')).toBeVisible()
		await expect(page.locator('#btn-clock-in')).toHaveCount(0)
		await expect(page.locator('#dashboard-retro-clockout')).toHaveCount(0)

		// Withdraw via the real dashboard control → session resumable again.
		page.on('dialog', (d) => void d.accept())
		await page.locator('#dashboard-withdraw-correction').click()
		await page.waitForLoadState('load')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		const after = await status(page)
		expect(['active', 'paused']).toContain(after.status)
		expect(after.current_entry?.endTime ?? null).toBeNull()
	})

	test('manager approves an open-session request — entry completes at proposed end', async ({ page }) => {
		test.skip(!hasCreds('MANAGER'), 'Requires NC_MANAGER_USER / NC_MANAGER_PASS')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		const s = await ensureActiveSession(page)
		const entryId = s.current_entry?.id
		expect(entryId).toBeTruthy()

		const endIso = new Date().toISOString()
		const req = await apiAllowFailure(page, 'POST', REQUEST_CORRECTION(entryId), {
			data: {
				justification: 'e2e manager approve round trip — forgot to clock out',
				endTime: endIso,
				breaks: [],
			},
		})
		expect(req.ok, `request failed: ${JSON.stringify(req.json)}`).toBe(true)

		// Manager sees the open-session card with the adjust-time input — in a
		// separate context so the employee session stays alive for the re-check.
		const mgrContext = await page.context().browser().newContext()
		const mgrPage = await mgrContext.newPage()
		try {
			await login(mgrPage, credsFromEnv('MANAGER'))
			await mgrPage.goto('/apps/arbeitszeitcheck/manager')
			await mgrPage.waitForSelector('#azc-main-content', { timeout: 30000 })
			// The card renders inside a collapsible section — assert the markup
			// (open-session label + adjust input) regardless of collapse state.
			const card = mgrPage.locator(`.pending-approval-card[data-time-entry-id="${entryId}"]`)
			await expect(card).toBeAttached({ timeout: 15000 })
			await expect(card).toContainText('Retro clock-out')
			await expect(card.locator('.azc-adjust-end-input')).toBeAttached()

			// Approve without adjustment via the manager session's API — same
			// endpoint the card's Approve button calls.
			const approve = await apiAllowFailure(
				mgrPage,
				'POST',
				`/apps/arbeitszeitcheck/api/manager/time-entries/${entryId}/approve-correction`,
			)
			expect(approve.ok, `approve failed: ${JSON.stringify(approve.json)}`).toBe(true)
		} finally {
			await mgrContext.close()
		}

		// Server truth (employee session still live): session completed at the
		// proposed end, not left open.
		await page.goto('/apps/arbeitszeitcheck/')
		const after = await status(page)
		expect(after.status).toBe('clocked_out')

		const entry = await api(page, 'GET', `/apps/arbeitszeitcheck/api/time-entries/${entryId}`)
		const entryData = entry?.entry ?? entry?.data ?? entry
		expect(entryData.status).toBe('completed')
		expect(entryData.endTime).toBeTruthy()
		// Instant equality: the approved end must equal the proposed instant
		// regardless of the ISO offset the client sent (Z vs +HH:mm).
		expect(Math.abs(new Date(entryData.endTime).getTime() - new Date(endIso).getTime())).toBeLessThan(120000)
		expect(new Date(entryData.endTime).getTime()).toBeGreaterThan(new Date(entryData.startTime).getTime())
	})

	test('request-correction rejects a missing end time on an open session (NN-2)', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		const s = await ensureActiveSession(page)
		const entryId = s.current_entry?.id
		expect(entryId).toBeTruthy()

		// A proposal lacking endTime but carrying another field must be refused
		// as proposal_requires_end — an empty payload is proposed_change_required
		// first, so startTime is the probe field here.
		const res = await apiAllowFailure(page, 'POST', REQUEST_CORRECTION(entryId), {
			data: {
				justification: 'start-only proposal without a clock-out — must be refused',
				startTime: new Date(Date.now() - 2 * 3600 * 1000).toISOString(),
			},
		})
		expect(res.ok).toBe(false)
		expect(res.status).toBe(400)
		expect(res.json?.error_code).toBe('proposal_requires_end')
	})

	test('request-correction rejects a future end time on an open session', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		const s = await ensureActiveSession(page)
		const entryId = s.current_entry?.id
		expect(entryId).toBeTruthy()

		const future = new Date(Date.now() + 24 * 3600 * 1000).toISOString()
		const res = await apiAllowFailure(page, 'POST', REQUEST_CORRECTION(entryId), {
			data: { justification: 'end in the future — must be refused', endTime: future },
		})
		expect(res.ok).toBe(false)
		expect(res.status).toBe(400)
		expect(res.json?.error_code).toBe('end_in_future')
	})

	test('direct retro-clock-out with a Z-offset endTime persists the instant, not the foreign wall', async ({ page }) => {
		// naive_datetime_wall_vs_offset_instant regression probe: the direct
		// retro-clock-out route must persist the instant's STORAGE-zone wall —
		// a '...Z' input carrying its foreign wall verbatim used to rehydrate
		// shifted by the offset (end_time BEFORE start_time). Objects are
		// instants; naive columns hold storage walls.
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/')
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		const s = await ensureActiveSession(page)
		const entryId = s.current_entry?.id
		expect(entryId).toBeTruthy()

		// Explicit UTC offset — the killer asymmetry from the incident report.
		// "Now" stays > start of the just-clocked-in session while the server
		// evaluates it as not-in-the-future by request latency.
		const endIso = new Date().toISOString()
		const res = await apiAllowFailure(
			page,
			'POST',
			`/apps/arbeitszeitcheck/api/time-entries/${entryId}/retro-clock-out`,
			{ data: { endTime: endIso, justification: 'e2e naive-datetime probe — Z offset' } },
		)
		expect(
			res.ok,
			`retro-clock-out refused (${res.status}): ${JSON.stringify(res.json)}`,
		).toBe(true)

		const after = await status(page)
		expect(after.status).toBe('clocked_out')

		const entry = await api(page, 'GET', `/apps/arbeitszeitcheck/api/time-entries/${entryId}`)
		const entryData = entry?.entry ?? entry?.data ?? entry
		expect(entryData.status).toBe('completed')
		expect(entryData.endTime).toBeTruthy()
		// Instant equality — not non-null, not wall equality: the persisted
		// instant must equal the instant the client sent regardless of offset.
		expect(
			Math.abs(new Date(entryData.endTime).getTime() - new Date(endIso).getTime()),
		).toBeLessThan(5000)
		expect(new Date(entryData.endTime).getTime()).toBeGreaterThan(
			new Date(entryData.startTime).getTime(),
		)
	})
})
