/* global process */
// shared-state-safe: read-only GET page; the only mutation route is
// page.route-intercepted and fulfilled locally.
//
// Regression (nested_form_dropped_by_parser): the work-locations partial is
// included inside the shell's outer #admin-settings-form, so its own <form>
// elements were dropped by the HTML parser — the first inner form never
// reached the DOM, admin-work-locations.js crashed binding listeners to
// null, and the section stayed stuck on "Checking migration readiness…".
// These asserts fail on the old markup: the group element is absent, a
// nested <form> exists inside #admin-settings-form, and the migration
// request never fires.
import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.describe('Admin work-locations settings', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER')

	test('section groups render, no nested form, JS init completes', async ({ page }) => {
		const pageErrors = []
		page.on('pageerror', (err) => pageErrors.push(String(err)))

		await login(page, credsFromEnv('ADMIN'))

		const migrationProbe = page.waitForResponse(
			(res) => res.url().includes('/api/admin/work-location-migration') && res.status() === 200,
			{ timeout: 20000 },
		)
		await page.goto('/apps/arbeitszeitcheck/admin/settings/work-locations')
		await assertArbeitszeitcheckLoaded(page)

		// Both groups must be in the DOM (a dropped nested form left this id absent).
		await expect(page.locator('#work-location-create-form')).toBeAttached()
		await expect(page.locator('#work-location-policy-form')).toBeAttached()

		// No <form> may live inside the outer settings form.
		await expect(page.locator('#admin-settings-form form')).toHaveCount(0)

		// Create/policy controls carry data-field, not name — they must not
		// merge into the outer form's save payload.
		await expect(page.locator('#admin-settings-form [name="label"]')).toHaveCount(0)
		await expect(page.locator('#admin-settings-form [name="priority"]')).toHaveCount(0)

		// Init reached Promise.all: the migration fetch must actually fire
		// (the null-addEventListener crash aborted before it).
		await migrationProbe

		expect(pageErrors).toEqual([])
	})

	test('NC mega-form keeps every section + save button inside the form', async ({ page }) => {
		const pageErrors = []
		page.on('pageerror', (err) => pageErrors.push(String(err)))

		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/settings/admin/arbeitszeitcheck')

		// The stray inner </form> used to close #admin-settings-form early,
		// ejecting every later section and the sticky Save button.
		await expect(page.locator('#admin-settings-form')).toBeAttached()
		await expect(page.locator('#admin-settings-form form')).toHaveCount(0)
		await expect(page.locator('#admin-settings-form .azc-work-location-admin')).toBeAttached()
		await expect(page.locator('#admin-settings-form #admin-settings-save')).toBeAttached()

		expect(pageErrors).toEqual([])
	})

	test('create control submits via click and Enter', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/settings/work-locations')
		await assertArbeitszeitcheckLoaded(page)

		const bodies = []
		await page.route('**/api/admin/work-locations', async (route) => {
			const req = route.request()
			if (req.method() === 'POST') {
				bodies.push(JSON.parse(req.postData() || '{}'))
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ success: true, item: { label: 'x', code: 'x', category: 'office', active: true } }),
				})
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ success: true, items: [] }),
			})
		})

		const group = page.locator('#work-location-create-form')
		await group.locator('#work-location-label').fill('HQ')
		await group.locator('#work-location-code').fill('hq')
		await group.locator('[data-submit]').click()
		await expect.poll(() => bodies.length).toBe(1)
		expect(bodies[0]).toMatchObject({ label: 'HQ', code: 'hq' })

		await group.locator('#work-location-label').fill('Branch')
		await group.locator('#work-location-code').fill('branch')
		await group.locator('#work-location-code').press('Enter')
		await expect.poll(() => bodies.length).toBe(2)
		expect(bodies[1]).toMatchObject({ label: 'Branch', code: 'branch' })
	})
})
