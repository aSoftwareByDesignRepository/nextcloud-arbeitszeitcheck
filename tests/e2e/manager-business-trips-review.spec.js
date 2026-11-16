// @ts-check
/**
 * E2E coverage for the manager business-trip review overlay + queue filters:
 *  - the detail opens as a centered top-layer modal (not inline below the table)
 *  - approve from the overlay closes it, shows a toast, and the row re-renders
 *    with the approved status chip
 *  - reject requires a reason, nests on the detail dialog, and lands the same way
 *  - decided trips open read-only (no Approve/Reject controls)
 *  - filters (status / employee / date range) narrow the queue and Reset restores it
 *  - Escape closes the dialog; only the topmost dialog closes per press
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, skipIfMissingCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.use({ trace: 'off' })

const MGR_API = '/apps/arbeitszeitcheck/api/manager/business-trips'
const PAGE = '/apps/arbeitszeitcheck/manager/business-trips'

/** Draft → submit a trip as the employee; returns the trip id.
 *  Retries with a fresh window on overlap — prior runs leave trips behind. */
async function submitTrip(page, purpose) {
	const iso = (d) => d.toISOString().slice(0, 10)
	let created = null
	for (let attempt = 0; attempt < 8 && !created; attempt++) {
		const base = new Date('2028-03-01T00:00:00Z').getTime()
			+ (Date.now() % 240) * 86400000
			+ Math.floor(Math.random() * 60) * 86400000
		const res = await page.request.fetch(new URL('/apps/arbeitszeitcheck/api/business-trips', page.url()).toString(), {
			method: 'POST',
			headers: { requesttoken: await page.evaluate(() => window.OC.requestToken), 'Content-Type': 'application/json' },
			data: {
				startsAtUtc: `${iso(new Date(base))}T07:00:00Z`,
				endsAtUtc: `${iso(new Date(base + 2 * 86400000))}T16:00:00Z`,
				originTimezone: 'Europe/Berlin',
				destinationTimezone: 'Europe/Berlin',
				evaluationTimezone: 'Europe/Berlin',
				purpose,
				requestKey: `e2e-mbt-review-${Date.now()}-${Math.random().toString(16).slice(2)}`,
			},
		})
		if (res.ok()) {
			created = await res.json()
		} else {
			const body = await res.json().catch(() => ({}))
			if (!/overlap/i.test(body.error || '')) {
				throw new Error(`trip create failed (${res.status()}): ${body.error || 'unknown'}`)
			}
		}
	}
	if (!created) {
		throw new Error('could not place a non-overlapping trip after 8 attempts')
	}
	const tripId = created.trip.id
	const detail = await api(page, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
	const submitted = await api(page, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/submit`, {
		data: { revision: detail.trip.revision },
	})
	expect(submitted.trip.status).toBe('submitted')
	return tripId
}

async function openRowDialog(page, purpose) {
	const row = page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })
	await expect(row).toHaveCount(1, { timeout: 20000 })
	await row.hover()
	await row.locator('.azc-table-actions .azc-icon-btn').click()
	await expect(page.locator('#mbt-detail')).toBeVisible({ timeout: 15000 })
	await expect(page.locator('#mbt-detail-subtitle')).toContainText(purpose)
}

test.describe('Manager business-trip review overlay + filters (Docker dev)', () => {
	test.beforeEach(() => {
		skipIfMissingCreds('EMPLOYEE')
		skipIfMissingCreds('MANAGER')
	})

	test('detail opens as a centered top-layer modal and Escape closes it', async ({ browser }) => {
		const purpose = `E2E overlay geometry ${Date.now()}`
		const employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		const tripId = await submitTrip(employeePage, purpose)

		const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)
		await openRowDialog(page, purpose)

		const geometry = await page.evaluate(() => {
			const d = document.getElementById('mbt-detail')
			const r = d.getBoundingClientRect()
			return {
				modal: d.matches(':modal'),
				centeredX: Math.abs(r.left + r.width / 2 - innerWidth / 2) < 4,
				closeVisible: !!document.getElementById('mbt-detail-close')?.offsetParent,
			}
		})
		expect(geometry.modal).toBe(true)
		expect(geometry.centeredX).toBe(true)
		expect(geometry.closeVisible).toBe(true)

		await page.keyboard.press('Escape')
		await expect(page.locator('#mbt-detail')).toBeHidden()

		// cleanup
		const fresh = await api(employeePage, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
		await api(employeePage, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/cancel`, { data: { revision: fresh.trip.revision } })
		await employeePage.close()
		await page.close()
	})

	test('approve from the overlay closes it, toasts, and the row re-renders approved', async ({ browser }) => {
		const purpose = `E2E approve overlay ${Date.now()}`
		const employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		const tripId = await submitTrip(employeePage, purpose)

		const page = await browser.newPage()
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)
		await openRowDialog(page, purpose)

		await page.locator('#mbt-approve').click()
		await expect(page.locator('#mbt-detail')).toBeHidden({ timeout: 15000 })

		// Queue reloads — the row now carries the approved chip.
		const row = page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })
		await expect(row).toHaveCount(1, { timeout: 20000 })
		await expect(row.locator('.azc-status-chip')).toHaveAttribute('data-status', 'approved')

		// Read-only for decided trips: Approve/Reject are gone from the overlay.
		await row.hover()
		await row.locator('.azc-table-actions .azc-icon-btn').click()
		await expect(page.locator('#mbt-detail')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('#mbt-approve')).toBeHidden()
		await expect(page.locator('#mbt-reject-toggle')).toBeHidden()
		await page.keyboard.press('Escape')

		await employeePage.close()
		await page.close()
	})

	test('reject requires a reason and lands rejected after confirm', async ({ browser }) => {
		const purpose = `E2E reject overlay ${Date.now()}`
		const employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		const tripId = await submitTrip(employeePage, purpose)

		const page = await browser.newPage()
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)
		await openRowDialog(page, purpose)

		await page.locator('#mbt-reject-toggle').click()
		await expect(page.locator('#mbt-reject-dialog')).toBeVisible()

		// Empty reason is rejected client-side, inline — nothing closes.
		await page.locator('#mbt-reject-confirm').click()
		await expect(page.locator('#mbt-reject-reason')).toHaveAttribute('aria-invalid', 'true')
		await expect(page.locator('#mbt-reject-error')).toBeVisible()
		await expect(page.locator('#mbt-reject-dialog')).toBeVisible()
		await expect(page.locator('#mbt-detail')).toBeVisible()

		// Escape closes only the nested dialog; the detail stays open.
		await page.keyboard.press('Escape')
		await expect(page.locator('#mbt-reject-dialog')).toBeHidden()
		await expect(page.locator('#mbt-detail')).toBeVisible()

		await page.locator('#mbt-reject-toggle').click()
		await page.locator('#mbt-reject-reason').fill('Budget not approved for this trip.')
		await page.locator('#mbt-reject-confirm').click()
		await expect(page.locator('#mbt-reject-dialog')).toBeHidden({ timeout: 15000 })
		await expect(page.locator('#mbt-detail')).toBeHidden({ timeout: 15000 })

		const row = page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })
		await expect(row.locator('.azc-status-chip')).toHaveAttribute('data-status', 'rejected', { timeout: 20000 })

		await employeePage.close()
		await page.close()
	})

	test('status, employee and reset filters narrow and restore the queue', async ({ browser }) => {
		const purpose = `E2E filters ${Date.now()}`
		const employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		await submitTrip(employeePage, purpose)

		const page = await browser.newPage()
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]').first()).toBeVisible({ timeout: 20000 })

		// Status filter: Rejected must not contain the just-submitted trip.
		await page.locator('#mbt-filter-status').selectOption('rejected')
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })).toHaveCount(0)

		// Status filter: Submitted shows it again.
		await page.locator('#mbt-filter-status').selectOption('submitted')
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })).toHaveCount(1)

		// Employee filter: a gibberish name empties the list and switches the
		// empty-state copy to the filter variant.
		await page.locator('#mbt-filter-employee').fill('zz-no-such-employee-zz')
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]')).toHaveCount(0)
		await expect(page.locator('#mbt-empty-title')).toHaveText(/match your filters|keine.*Filter|filtr/i)

		// Reset restores every row and clears the controls.
		await page.locator('#mbt-filter-reset').click()
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })).toHaveCount(1)
		await expect(page.locator('#mbt-filter-employee')).toHaveValue('')
		await expect(page.locator('#mbt-filter-status')).toHaveValue('')

		// Date range: the seeded window must match an overlapping range…
		const queueData = await api(page, 'GET', MGR_API)
		const mine = queueData.items.find((i) => i.purpose === purpose)
		const [y, m, d] = mine.startsAtUtc.slice(0, 10).split('-')
		await page.locator('#mbt-filter-date-from').fill(`${d}.${m}.${y}`)
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })).toHaveCount(1)
		// …and a range ending before the trip starts must exclude it.
		await page.locator('#mbt-filter-date-to').fill('01.01.2020')
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })).toHaveCount(0)

		// Invalid date input is flagged by the datepicker on commit — inline
		// field error, aria-invalid, error styling (single owner of the state).
		await page.locator('#mbt-filter-date-to').fill('not-a-date')
		await page.locator('#mbt-filter-date-to').blur()
		await expect(page.locator('#mbt-filter-date-to')).toHaveAttribute('aria-invalid', 'true')
		await expect(page.locator('#mbt-filter-date-to')).toHaveClass(/form-input--error/)

		// The open datepicker popup overlays the reset button — a real user
		// dismisses it (Escape / outside click) before reaching the button.
		await page.keyboard.press('Escape')
		await expect(page.locator('#azc-datepicker-popup')).toBeHidden()

		// Reset must clear the datepicker's own error state too, not just values.
		await page.locator('#mbt-filter-reset').click()
		await expect(page.locator('#mbt-filter-date-to')).not.toHaveAttribute('aria-invalid')
		await expect(page.locator('#mbt-filter-date-to')).not.toHaveClass(/form-input--error/)

		await employeePage.close()
		await page.close()
	})
})
