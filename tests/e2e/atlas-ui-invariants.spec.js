// @ts-check
/**
 * Atlas UI invariants — shared-contract coverage for ArbeitszeitCheck web.
 *
 * Wires nextcloud/apps/_shared/e2e/atlas-ui-invariants.js onto representative
 * employee surfaces. Classes covered:
 *  - a11y_dom          icon-only names, <24px targets, focusable-in-aria-hidden
 *                      on /dashboard /time-entries /absences /settings(+breaks)
 *  - stored_xss        time-entry `description` free-text payloads minted via
 *                      POST /api/time-entries must render escaped on /time-entries
 *  - mutation_freshness API-created entry appears on /time-entries without a
 *                      manual refresh; after DELETE it is gone
 *  - double_submit     rapid double submit on the create form must not escape
 *                      a second POST /api/time-entries (disabled in-flight)
 *  - form_loss_on_5xx  injected 500 keeps the note field + renders an error toast
 *  - n_plus_one        /time-entries fires no per-row data fetches
 *  - console_errors + raw i18n keys on all swept surfaces
 *
 * State: creates/deletes completed entries for the shared employee on
 * yesterday-and-older late-evening slots — must run in the serialized
 * STATEFUL_SPECS lane, never in the parallel pool.
 */
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { login, credsFromEnv } from './helpers/auth.js'
import { gotoApp } from './helpers/auth-guard.js'
import { api, apiAllowFailure } from './helpers/api.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const require = createRequire(path.join(__dirname, 'package.json'))
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertFormSurvivesFailure,
	assertSurfaceFresh,
	countApiRequests,
	trackConsoleErrors,
} = require('../../../_shared/e2e/atlas-ui-invariants.js')

const APP = '/apps/arbeitszeitcheck'
const CONTENT = '#app-content'
const STAMP = `azcinv-${Date.now()}`
const JUSTIFICATION = 'atlas invariants probe entry' // mode-independent: ≥10 chars, ignored when approval off

/** Completed-entry slot on a past day (never today: avoids live clock sessions). */
function slotFor(daysAgo, startTime = '22:30', endTime = '23:10') {
	const d = new Date()
	d.setUTCDate(d.getUTCDate() - daysAgo)
	return { date: d.toISOString().slice(0, 10), startTime, endTime }
}

/** Create one completed entry via the real API; returns id or null on reject. */
async function createEntry(page, slot, description) {
	const res = await apiAllowFailure(page, 'POST', `${APP}/api/time-entries`, {
		data: { ...slot, description, justification: JUSTIFICATION },
	})
	if (!res.ok) return null
	const body = res.json || {}
	return body.entry?.id ?? body.id ?? body.timeEntry?.id ?? null
}

/** Delete every entry whose description carries this run's stamp (both routes). */
async function cleanupStamped(page) {
	for (const daysAgo of [1, 2, 3, 4, 5, 6, 7]) {
		const { date } = slotFor(daysAgo)
		const list = await apiAllowFailure(page, 'GET', `${APP}/api/time-entries?start_date=${date}&end_date=${date}&limit=500`)
		for (const e of list.json?.entries ?? list.json?.items ?? []) {
			if (String(e.description ?? '').includes(STAMP)) {
				await apiAllowFailure(page, 'DELETE', `${APP}/api/time-entries/${e.id}`, { data: {} })
				await apiAllowFailure(page, 'DELETE', `${APP}/time-entries/${e.id}`, { data: {} })
			}
		}
	}
}

test.describe('ArbeitszeitCheck UI invariants (atlas-ui-invariants)', () => {
	test.skip(!process.env.NC_EMPLOYEE_PASS, 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await login(page, credsFromEnv('EMPLOYEE'))
	})

	for (const [label, url] of [
		['dashboard', `${APP}/dashboard`],
		['time-entries', `${APP}/time-entries`],
		['absences', `${APP}/absences`],
		['settings', `${APP}/settings`],
		['settings-breaks', `${APP}/settings/breaks`],
	]) {
		test(`a11y-dom sweep /${label}`, async ({ page }) => {
			await gotoApp(page, url)
			await page.locator(CONTENT).waitFor({ state: 'visible', timeout: 30_000 })
			// WCAG 2.5.8 equivalent-target exception: sub-24px radios/checkboxes
			// are legal only when a wrapping <label> gives the ≥24px target.
			const unlabeled = await page.evaluate(() => {
				const bad = []
				document.querySelectorAll('#app-content input[type="radio"], #app-content input[type="checkbox"]').forEach((inp) => {
					const lbl = inp.closest('label')
						|| (inp.id ? document.querySelector(`label[for="${CSS.escape(inp.id)}"]`) : null)
					if (!lbl) {
						bad.push(`no label: ${inp.id || inp.outerHTML.slice(0, 60)}`)
						return
					}
					const r = lbl.getBoundingClientRect()
					const cs = getComputedStyle(lbl)
					if (cs.display === 'none' || cs.visibility === 'hidden') return
					if (r.width < 24 || r.height < 24) bad.push(`label ${Math.round(r.width)}x${Math.round(r.height)} < 24px: ${inp.id}`)
				})
				return bad
			})
			expect(unlabeled, `radio/checkbox without a ≥24px label target on /${label}:\n${unlabeled.join('\n')}`).toEqual([])
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				sizeAllow: 'input[type="radio"], input[type="checkbox"]',
			})
			// The "not announced" heuristic fires on every *callout*/error-class
			// element, but WCAG 4.1.3 status-message semantics only apply to
			// dynamically-inserted messages. Static content that is already
			// properly marked — role="note" callouts, <details> disclosures,
			// severity .badge pills — is legitimately silent. Verify the mark
			// exists before filtering: an unmarked new alert still fails.
			const properlyMarkedStatic = new Set(await page.evaluate(() => {
				const out = []
				document
					.querySelectorAll('#app-content [class*="error"], #app-content [class*="Error"], #app-content [class*="alert"], #app-content [class*="Alert"], #app-content [class*="toast"], #app-content [class*="Toast"], #app-content [class*="callout"], #app-content [class*="Callout"]')
					.forEach((el) => {
						const isStaticMarked = el.matches('details')
							|| el.closest('details')
							|| el.getAttribute('role') === 'note'
							|| el.closest('[role="note"]')
							|| /\bbadge\b|badge--/.test(String(el.className))
						if (isStaticMarked) out.push(String(el.className).slice(0, 60))
					})
				return out
			}))
			const real = findings.filter((f) => {
				const m = f.match(/^error\/toast element not announced \(no role\/aria-live\): (.+)$/)
				if (!m) return true
				return !properlyMarkedStatic.has(m[1])
			})
			expect(real, `a11y-dom findings on /${label}:\n${real.join('\n')}`).toEqual([])
		})

		test(`console errors + raw i18n keys /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page)
			await gotoApp(page, url)
			await page.locator(CONTENT).waitFor({ state: 'visible', timeout: 30_000 })
			assertNoConsoleErrors(errs)
			await assertNoRawI18nKeys(page, { content: CONTENT })
		})
	}

	test('n+1: /time-entries fires no per-row fetches and stays bounded', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await gotoApp(page, `${APP}/time-entries`)
			await page.locator(CONTENT).waitFor({ state: 'visible', timeout: 30_000 })
			await page.waitForLoadState('networkidle').catch(() => {})
		}, '/apps/arbeitszeitcheck/api/')
		const perRow = hits.filter((h) => /\/api\/time-entries\/\d+/.test(h.url))
		expect(
			perRow,
			`per-row time-entry fetches (N+1): ${perRow.map((h) => `${h.method} ${h.url}`).join(' | ')}`,
		).toEqual([])
		expect(
			hits.length,
			`/time-entries fired ${hits.length} app API requests: ${hits.map((h) => `${h.method} ${h.url}`).join(' | ')}`,
		).toBeLessThanOrEqual(12)
	})

	test('stored-xss: entry description payloads render escaped on /time-entries', async ({ page }) => {
		const created = []
		try {
			for (let i = 0; i < 3; i++) {
				const payload = ATLAS_XSS_PAYLOADS[i]
				const id = await createEntry(page, slotFor(i + 1), `${STAMP} ${payload}`)
				if (id !== null) created.push(id)
			}
			expect(created.length, 'no probe entry persisted — cannot assert render escaping').toBeGreaterThan(0)
			await gotoApp(page, `${APP}/time-entries`)
			await page.locator(CONTENT).waitFor({ state: 'visible', timeout: 30_000 })
			await assertNoInjection(page)
		} finally {
			await cleanupStamped(page)
		}
	})

	test('mutation freshness: API entry appears, then disappears, without manual reload', async ({ page }) => {
		const desc = `${STAMP}-fresh`
		let id = null
		try {
			await assertSurfaceFresh(page, {
				content: CONTENT,
				mutate: async () => {
					id = await createEntry(page, slotFor(4), desc)
					expect(id, 'POST /api/time-entries did not persist').not.toBeNull()
				},
				visit: () => gotoApp(page, `${APP}/time-entries`),
				expect: { present: desc },
			})
			await api(page, 'DELETE', `${APP}/api/time-entries/${id}`, { data: {} })
			id = null
			await gotoApp(page, `${APP}/time-entries`)
			await expect(page.locator(CONTENT)).not.toContainText(desc)
		} finally {
			await cleanupStamped(page)
		}
	})

	test('double-submit: create form fires at most one POST /api/time-entries', async ({ page }) => {
		await gotoApp(page, `${APP}/time-entries/create`)
		const form = page.locator('#time-entry-form')
		await form.waitFor({ state: 'visible', timeout: 30_000 })
		const { date } = slotFor(5)
		const [dd, mm, yyyy] = date.split('-').reverse()
		// #entry-date is readonly (datepicker-managed) — set programmatically.
		await page.evaluate((d) => {
			const el = document.getElementById('entry-date')
			el.value = d
			el.dispatchEvent(new Event('input', { bubbles: true }))
			el.dispatchEvent(new Event('change', { bubbles: true }))
		}, `${dd}.${mm}.${yyyy}`)
		await page.locator('#entry-start-time-hour').selectOption('22')
		await page.locator('#entry-start-time-minute').selectOption('30')
		await page.locator('#entry-end-time-hour').selectOption('23')
		await page.locator('#entry-end-time-minute').selectOption('10')
		await page.locator('#entry-description').fill(`${STAMP}-dbl`)
		const submit = form.locator('button[type="submit"]').first()
		try {
			// dispatchEvent bypasses actionability: fires even after the button
			// disables in-flight — proves the app's own submit guard.
			await assertNoDuplicateSubmit(page, {
				mutatingUrl: '/apps/arbeitszeitcheck/api/time-entries',
				method: 'POST',
				submit: () => submit.dispatchEvent('click').catch(() => {}),
			})
		} finally {
			await cleanupStamped(page)
		}
	})

	test('form-loss-on-5xx: note field survives an injected POST 500 with error toast', async ({ page }) => {
		await gotoApp(page, `${APP}/time-entries/create`)
		const form = page.locator('#time-entry-form')
		await form.waitFor({ state: 'visible', timeout: 30_000 })
		await page.locator('#entry-start-time-hour').selectOption('22')
		await page.locator('#entry-start-time-minute').selectOption('30')
		await page.locator('#entry-end-time-hour').selectOption('23')
		await page.locator('#entry-end-time-minute').selectOption('10')
		const submit = form.locator('button[type="submit"]').first()
		await assertFormSurvivesFailure(page, {
			failUrl: '/apps/arbeitszeitcheck/api/time-entries',
			method: 'POST',
			fields: { '#entry-description': `${STAMP}-survives` },
			submit: () => submit.click(),
			errorSel: '.toast--error',
		})
	})
})
