// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv } from './helpers/auth.js'
import { apiAllowFailure, getRequestToken } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.describe('Compliance gate smoke (Docker dev)', () => {
	test.beforeEach(async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
	})

	test('time entry create page: shell, cards, fieldsets', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/time-entries/create')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		await expect(page.locator('#app-content.azc-app--time-entries')).toBeVisible()
		await expect(page.locator('.azc-page-header')).toBeVisible()
		await expect(page.locator('.time-entry-request-form')).toBeVisible()
		await expect(page.locator('.time-entry-form-fieldset').first()).toBeVisible()
		await expect(page.locator('.time-entry-form__actions .azc-btn').first()).toBeVisible()
		await expect(page.locator('#time-summary')).toBeVisible()
		await expect(page.locator('.time-summary-title')).toContainText(/Day Summary|Tageszusammenfassung/i)
		await expect(page.locator('#compliance-status')).not.toBeEmpty()

		const calloutIcon = page.locator('.azc-callout__icon .azc-icon:visible').first()
		if ((await calloutIcon.count()) > 0) {
			const box = await calloutIcon.boundingBox()
			expect(box).not.toBeNull()
			expect(box.width).toBeLessThanOrEqual(24)
			expect(box.height).toBeLessThanOrEqual(24)
		}
		const labelIcon = page.locator('.form-label-icon .azc-icon').first()
		if ((await labelIcon.count()) > 0) {
			const box = await labelIcon.boundingBox()
			expect(box).not.toBeNull()
			expect(box.width).toBeLessThanOrEqual(20)
			expect(box.height).toBeLessThanOrEqual(20)
		}
	})

	test('time entry create page: live day summary updates after time selection', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/time-entries/create')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#time-entry-form', { timeout: 30000 })

		await page.locator('#entry-start-time-hour').selectOption('08')
		await page.locator('#entry-start-time-minute').selectOption('00')
		await page.locator('#entry-end-time-hour').selectOption('16')
		await page.locator('#entry-end-time-minute').selectOption('00')

		await expect(page.locator('#summary-working-hours')).not.toHaveText('0.00')
		await expect(page.locator('#compliance-status')).not.toContainText(/Please enter both start and end time|Bitte.*Start.*End/i)
	})

	test('time entry create page: axe clean with visible day summary', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/time-entries/create')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#time-entry-form', { timeout: 30000 })

		const results = await new AxeBuilder({ page })
			.include('#app-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('absences list: filter panel and apply control', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/absences')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		await expect(page.locator('#app-content.azc-app--absences')).toBeVisible()
		await page.locator('#btn-filter').click()
		const filterPanel = page.locator('#filter-section.absences-page__filter')
		await expect(filterPanel).toBeVisible()
		await expect(page.locator('#btn-apply-filter')).toBeVisible()

		const calloutIcon = page.locator('.azc-callout__icon .azc-icon:visible').first()
		if ((await calloutIcon.count()) > 0) {
			const box = await calloutIcon.boundingBox()
			expect(box).not.toBeNull()
			expect(box.width).toBeLessThanOrEqual(24)
			expect(box.height).toBeLessThanOrEqual(24)
		}
	})

	test('absence create page: callout icons stay compact', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/absences/create')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#absence-form', { timeout: 30000 })

	const calloutIcon = page.locator('.azc-callout__icon .azc-icon:visible').first()
		await expect(calloutIcon).toBeVisible()
		const box = await calloutIcon.boundingBox()
		expect(box).not.toBeNull()
		expect(box.width).toBeLessThanOrEqual(24)
		expect(box.height).toBeLessThanOrEqual(24)
	})

	test('toast icons stay compact outside app shell', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/absences')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#azc-main-content', { timeout: 30000 })

		await page.evaluate(() => {
			if (window.ArbeitszeitCheckComponents && typeof window.ArbeitszeitCheckComponents.showToast === 'function') {
				window.ArbeitszeitCheckComponents.showToast({ type: 'info', message: 'Icon size probe' })
			}
		})

		const toastIcon = page.locator('.toast-icon .azc-icon').first()
		await expect(toastIcon).toBeVisible({ timeout: 5000 })
		const box = await toastIcon.boundingBox()
		expect(box).not.toBeNull()
		expect(box.width).toBeLessThanOrEqual(24)
		expect(box.height).toBeLessThanOrEqual(24)
	})

	test('API blocks 7h completed entry without mandatory break', async ({ page, browser }) => {
		// Pending four-eyes creates skip completed-entry compliance — turn approval off for this probe.
		const adminPage = await browser.newPage()
		await login(adminPage, credsFromEnv('ADMIN'))
		const before = await apiAllowFailure(adminPage, 'GET', '/apps/arbeitszeitcheck/api/admin/settings')
		const restoreApproval = before.json?.settings?.manualTimeEntriesRequireApproval === true
		await apiAllowFailure(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
			data: {
				settings_section: 'time-approvals',
				manualTimeEntriesRequireApproval: false,
			},
		})

		try {
			// Auto-break would insert the §4 break and allow the entry — turn it off for this probe.
			await page.goto('/apps/arbeitszeitcheck/settings/breaks')
			await assertArbeitszeitcheckLoaded(page)
			await getRequestToken(page)
			await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/settings', {
				data: { auto_break_calculation: false },
			})

			await page.goto('/apps/arbeitszeitcheck/time-entries')
			await assertArbeitszeitcheckLoaded(page)
			await getRequestToken(page)

			// Shared Docker DB retains prior seed rows — pick a free day in a rare window.
			const stamp = Date.now()
			const base = `2099-03-${String(1 + (stamp % 20)).padStart(2, '0')}`
			let blocked = { status: 0, json: {} }
			for (let i = 0; i < 25; i++) {
				const d = new Date(`${base}T00:00:00Z`)
				d.setUTCDate(d.getUTCDate() + i)
				const date = d.toISOString().slice(0, 10)
				blocked = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
					data: {
						date,
						startTime: '08:00',
						endTime: '15:00',
					},
				})
				const err = String(blocked.json?.error || '').toLowerCase()
				if (blocked.json?.error_code === 'compliance_blocked' || !err.includes('overlap')) {
					break
				}
			}

			expect(blocked.status).toBe(400)
			expect(blocked.json?.success).toBe(false)
			expect(blocked.json?.error_code).toBe('compliance_blocked')
			expect(String(blocked.json?.error || '')).toMatch(/30.minute|30 Minuten|Pflichtpause/i)
		} finally {
			if (restoreApproval) {
				await apiAllowFailure(adminPage, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
					data: {
						settings_section: 'time-approvals',
						manualTimeEntriesRequireApproval: true,
					},
				})
			}
			await adminPage.close()
		}
	})

	test('API allows exactly six hours without a break (ArbZG §4 mehr als sechs Stunden)', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/time-entries')
		await assertArbeitszeitcheckLoaded(page)
		await getRequestToken(page)

		const allowed = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
			data: {
				date: '2026-06-05',
				startTime: '08:00',
				endTime: '14:00',
				justification: 'Compliance gate smoke justification for 6h entry.',
			},
		})

		if (allowed.ok) {
			expect(allowed.json?.success).toBe(true)
			const id = allowed.json?.data?.id ?? allowed.json?.id ?? allowed.json?.entry?.id
			if (id) {
				await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/time-entries/${id}`)
			}
			return
		}

		// Auto-break off is not required here: 6.00 h must not be compliance_blocked.
		expect(allowed.json?.error_code).not.toBe('compliance_blocked')
	})

	test('time entry create: 08:00–14:00 stays compliant without a break', async ({ page }) => {
		await page.goto('/apps/arbeitszeitcheck/time-entries/create')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#time-entry-form', { timeout: 30000 })

		await page.locator('#entry-start-time-hour').selectOption('08')
		await page.locator('#entry-start-time-minute').selectOption('00')
		await page.locator('#entry-end-time-hour').selectOption('14')
		await page.locator('#entry-end-time-minute').selectOption('00')

		await expect(page.locator('#summary-working-hours')).toHaveText('6.00')
		const status = page.locator('#compliance-status')
		await expect(status).not.toContainText(/30 minutes break required|30 Minuten Pause erforderlich/i)
	})
})
