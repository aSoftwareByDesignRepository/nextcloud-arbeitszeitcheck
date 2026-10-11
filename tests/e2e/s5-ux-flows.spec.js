// @ts-check
/**
 * S5 "Feature B" flows — live coverage.
 *
 * Mutates shared state (e2e_employee dismissed_hints, a throwaway working
 * time model), so this spec is registered in STATEFUL_SPECS (serialized).
 * Every mutation is restored/cleaned inside the test.
 *
 *  - B-2: dismissible hint persists server-side across page loads
 *  - B-3: "Download PDF" yields a real PDF with a safe filename
 *  - B-4: manager team CSV exports (overtime + absences) are scoped + BOM'd
 *  - B-6: UE helper converts units → weekly hours and stores the label
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

const HINT_ID = 'time-entries-export-hint'

test.describe('S5 Feature B flows', () => {
	test('B-2: export hint dismisses, persists across reload, restores', async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/time-entries')
		await assertArbeitszeitcheckLoaded(page)

		const body = page.locator(`#${HINT_ID}`)
		const showBtn = page.locator(`[data-hint-show="${HINT_ID}"]`)
		const waitPersist = () =>
			page.waitForResponse((r) => r.url().includes('/arbeitszeitcheck/settings') && r.request().method() === 'POST')

		// Idempotent start: if a previous run left it dismissed, re-show it first.
		if (!(await body.isVisible())) {
			await expect(showBtn).toBeVisible()
			const p0 = waitPersist()
			await showBtn.click()
			await p0
			await expect(body).toBeVisible()
		}

		const p1 = waitPersist()
		await page.locator(`[data-hint-dismiss="${HINT_ID}"]`).click()
		await p1
		await expect(body).toBeHidden()
		await expect(showBtn).toBeVisible()

		// Persistence is server-side: a fresh page load must keep it hidden.
		await page.reload()
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator(`#${HINT_ID}`)).toBeHidden()

		// Restore via the UI show-link so other specs see the default state.
		const p2 = waitPersist()
		await page.locator(`[data-hint-show="${HINT_ID}"]`).click()
		await p2
		await expect(page.locator(`#${HINT_ID}`)).toBeVisible()
	})

	test('B-3: PDF export button downloads a valid PDF', async ({ page }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER')
		await login(page, credsFromEnv('EMPLOYEE'))
		await page.goto('/apps/arbeitszeitcheck/time-entries')
		await assertArbeitszeitcheckLoaded(page)

		// Record the URL instead of opening a tab — deterministic in headless.
		await page.evaluate(() => {
			// @ts-ignore test hook
			window.__openedUrls = []
			// @ts-ignore test hook
			window.open = (url) => { window.__openedUrls.push(String(url)); return null }
		})
		await page.locator('#btn-export-pdf').click()

		const urls = await page.evaluate(() => window.__openedUrls)
		expect(urls.length).toBe(1)
		expect(urls[0]).toContain('export.pdf')

		const res = await page.request.get(urls[0])
		expect(res.status(), `GET ${urls[0]}`).toBe(200)
		const bytes = await res.body()
		expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
		const disposition = res.headers()['content-disposition'] || ''
		expect(disposition).toMatch(/attachment/i)
		expect(disposition).toMatch(/\.pdf/i)
	})

	test('B-4: manager team exports return scoped, BOM-marked CSV', async ({ page }) => {
		test.skip(!hasCreds('MANAGER'), 'Requires NC_MANAGER_USER')
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager')
		await assertArbeitszeitcheckLoaded(page)

		const overtimeHref = await page.locator('#team-overtime-export').getAttribute('href')
		const absencesHref = await page.locator('#team-absences-export').getAttribute('href')
		expect(overtimeHref).toContain('team-overtime-export')
		expect(absencesHref).toContain('team-absences-export')

		for (const href of [overtimeHref, absencesHref]) {
			const res = await page.request.get(href)
			expect(res.ok()).toBeTruthy()
			const body = (await res.body()).toString('utf8')
			// UTF-8 BOM for Excel, then the header row.
			expect(body.charCodeAt(0)).toBe(0xFEFF)
			expect(body).toContain('user_id;display_name;')
			const disposition = res.headers()['content-disposition'] || ''
			expect(disposition).toMatch(/\.csv/i)
		}
	})

	test('B-6: UE helper converts units to hours and stores the label', async ({ page }) => {
		test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_USER')
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
		await assertArbeitszeitcheckLoaded(page)

		const name = `UE-Helper-E2E-${Date.now()}`
		await page.locator('#create-model').click()
		await expect(page.locator('#wtm-model-form')).toBeVisible()
		await page.locator('#wtm-model-name').fill(name)

		await page.locator('.wtm-ue-count').fill('27')
		await page.locator('.wtm-ue-minutes').fill('45')
		await page.locator('[data-action="apply-ue"]').click()

		// 27 x 45 min = 20.25 h weekly — filled in, label shown.
		await expect(page.locator('#wtm-model-weekly-hours')).toHaveValue('20.25')
		await expect(page.locator('.wtm-ue-result')).toContainText('20.25')

		await page.locator('#wtm-model-submit').click()
		await expect(page.locator('#wtm-editor-panel')).toBeHidden({ timeout: 15000 })

		// Verify server-side: model exists with the ue_label in break_rules.
		const list = await api(page, 'GET', '/apps/arbeitszeitcheck/api/admin/working-time-models')
		const models = list.models || list.data || []
		const created = models.find((m) => m.name === name)
		expect(created, 'created model in list').toBeTruthy()

		try {
			const detail = await api(page, 'GET',
				`/apps/arbeitszeitcheck/api/admin/working-time-models/${created.id}`)
			const rules = (detail.model || detail).breakRules || (detail.model || detail).break_rules || {}
			expect(rules.ue_label).toBe('27 UE à 45 min')
		} finally {
			await apiAllowFailure(page, 'DELETE',
				`/apps/arbeitszeitcheck/api/admin/working-time-models/${created.id}`)
		}
	})
})
