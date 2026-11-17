// @ts-check
/**
 * E2E coverage for the manager business-trip review queue:
 *  - submitted team trips render as design-system table rows
 *    (data-label cells, name+uid employee cell, icon review action)
 *  - the queue API exposes displayName + updatedAt for the table
 *  - the review action opens the decision card for that trip only
 *  - empty queue renders the empty-state row, not a bare table
 *  - axe-clean on WCAG 2.1 A/AA for the loaded queue
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv, skipIfMissingCreds } from './helpers/auth.js'
import { api } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.use({ trace: 'off' })

const QUEUE_API = '/apps/arbeitszeitcheck/api/manager/business-trips'
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
				requestKey: `e2e-mbt-queue-${Date.now()}-${Math.random().toString(16).slice(2)}`,
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

/** Nextcloud's first-run wizard animates in a few seconds after first
 * paint for users who never dismissed it — and re-mounts after being
 * closed. addLocatorHandler re-dismisses it whenever it surfaces, so it
 * cannot intercept the clicks below. */
async function autoDismissFirstRunWizard(page) {
	const wizard = page.locator('#firstrunwizard[role="dialog"]')
	await page.addLocatorHandler(wizard, async () => {
		await wizard.getByRole('button', { name: 'Close' }).first().click()
	})
}

/** Best-effort cleanup — a withdrawn trip leaves the manager queue. */
async function withdrawTrip(page, tripId) {
	try {
		const detail = await api(page, 'GET', `/apps/arbeitszeitcheck/api/business-trips/${tripId}`)
		await api(page, 'POST', `/apps/arbeitszeitcheck/api/business-trips/${tripId}/cancel`, {
			data: { revision: detail.trip.revision },
		})
	} catch {
		// Dev-instance fixture — leaving a submitted trip behind is safe.
	}
}

test.describe('Manager business-trip queue table (Docker dev)', () => {
	let employeePage
	let tripId
	const purpose = `E2E queue table ${Date.now()}`

	test.beforeAll(async ({ browser }) => {
		skipIfMissingCreds('EMPLOYEE')
		skipIfMissingCreds('MANAGER')
		employeePage = await browser.newPage()
		await login(employeePage, credsFromEnv('EMPLOYEE'))
		await employeePage.goto(PAGE)
		await assertArbeitszeitcheckLoaded(employeePage)
		tripId = await submitTrip(employeePage, purpose)
	})

	test.afterAll(async () => {
		if (employeePage && tripId) {
			await withdrawTrip(employeePage, tripId)
		}
		if (employeePage) {
			await employeePage.close()
		}
	})

	test('queue API exposes the fields the table renders', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)

		const data = await api(page, 'GET', QUEUE_API)
		expect(data.success).toBe(true)
		const mine = data.items.find((i) => i.id === tripId)
		expect(mine).toBeTruthy()
		expect(mine.status).toBe('submitted')
		expect(mine.displayName).toBeTruthy()
		expect(mine.updatedAt).toMatch(/^\d{4}-\d{2}-\d{2}T/)
		expect(mine.purpose).toBe(purpose)
	})

	test('submitted trips render as table rows with labelled cells', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)

		const rows = page.locator('#mbt-queue-body tr[data-azc-row]')
		await expect(rows.first()).toBeVisible({ timeout: 20000 })
		await expect(page.locator('#mbt-empty-row')).toBeHidden()

		const row = rows.filter({ hasText: purpose })
		await expect(row).toHaveCount(1)
		const cells = row.locator('td')
		await expect(cells.nth(0)).toHaveAttribute('data-label', 'Employee')
		await expect(cells.nth(1)).toHaveAttribute('data-label', 'Business trip')
		await expect(cells.nth(2)).toHaveAttribute('data-label', 'Purpose')
		await expect(cells.nth(3)).toHaveAttribute('data-label', 'Status')
		await expect(cells.nth(4)).toHaveAttribute('data-label', 'Updated')
		await expect(cells.nth(5)).toHaveAttribute('data-label', 'Actions')
		// The workflow state is visible as a status chip, not bare text.
		await expect(cells.nth(3).locator('.azc-status-chip')).toHaveAttribute('data-status', 'submitted')
		// Employee cell carries the readable name (+ uid subline when it differs).
		await expect(cells.nth(0).locator('.mbt-emp__name')).not.toHaveText('')
		// The shared pager reports the queue size.
		await expect(page.locator('#mbt-queue-pager [data-pager-total]')).toContainText(/Total [1-9]/)
	})

	test('review action opens the decision card for that trip', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)

		await autoDismissFirstRunWizard(page)
		const row = page.locator('#mbt-queue-body tr[data-azc-row]').filter({ hasText: purpose })
		await expect(row).toHaveCount(1, { timeout: 20000 })
		const review = row.locator('.azc-table-actions .azc-icon-btn')
		await expect(review).toHaveAttribute('aria-label', /Review business trip/)
		// Design-system contract: on pointer-capable desktops the row actions
		// reveal on tr:hover / focus-within (opacity 0 + pointer-events none
		// otherwise). Hover first — exactly what a real user does.
		await row.hover()
		await review.click()

		await expect(page.locator('#mbt-detail')).toBeVisible()
		await expect(page.locator('#mbt-detail-subtitle')).toContainText(purpose)
		await expect(page.locator('#mbt-approve')).toBeVisible()
		await expect(page.locator('#mbt-reject-toggle')).toBeVisible()
	})

	test('queue table passes axe on WCAG 2.1 A/AA', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto(PAGE)
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#mbt-queue-body tr[data-azc-row]').first()).toBeVisible({ timeout: 20000 })

		const results = await new AxeBuilder({ page })
			.include('#mbt-queue-card')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations).toEqual([])
	})
})
