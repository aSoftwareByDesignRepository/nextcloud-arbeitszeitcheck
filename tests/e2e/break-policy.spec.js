import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

/**
 * DISUS organisational break policy — critical flows.
 *
 * Mutates the shared org-level break_policy_json, so this spec is registered
 * in STATEFUL_SPECS (serialized) and must restore an empty policy after every
 * test — an enforced policy left behind would lock the employee auto-break
 * checkbox for every other spec.
 *
 * Read model: persisted state surfaces via the admin GET settings API
 * (`settings.breakPolicy`), the POST echo and the server-rendered
 * `#azc-break-policy-data` script tag on the compliance page — the script
 * tag is the page-load authoritative document.
 */

const COMPLIANCE = { settings_section: 'compliance' }

/** Resets the org break policy to the empty document; returns the POST echo. */
async function clearPolicy(page) {
	return api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
		data: {
			...COMPLIANCE,
			breakPolicyEnforced: false,
			breakPolicyMinBreakMinutes: null,
			breakPolicyTiers: [],
			breakPolicyWindows: [],
			presenceExemptions: [],
		},
	})
}

async function openCompliance(page) {
	await page.goto('/apps/arbeitszeitcheck/admin/settings/compliance')
	await assertArbeitszeitcheckLoaded(page)
	await expect(page.locator('#azc-break-policy-editor')).toBeVisible()
}

/** Server-rendered persisted policy document (black-box read). */
async function persistedPolicy(page) {
	await openCompliance(page)
	const raw = await page.locator('#azc-break-policy-data').textContent()
	return JSON.parse(raw || '{}')
}

/** Click save and wait for the request cycle to finish (busy flag clears). */
async function clickSave(page) {
	const save = page.locator('#admin-settings-save')
	await save.click()
	await expect(save).not.toHaveAttribute('aria-busy', 'true', { timeout: 15000 })
}

test.use({ trace: 'off' }) // manual browser.newContext() calls race Playwright's trace-chunk flush on close (ENOENT *.trace/*.network) — screenshots/video still retained on failure

test.describe('Organisational break policy', () => {
	test.skip(!hasCreds('ADMIN'), 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test.afterEach(async ({ page }) => {
		try {
			await clearPolicy(page)
		} catch {
			/* session may already be gone — best effort */
		}
	})

	test('editor renders, rows add/remove, policy persists', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await clearPolicy(page)
		await openCompliance(page)

		// Empty state before rows exist.
		await expect(page.locator('[data-bp-empty="tiers"]')).toBeVisible()
		await expect(page.locator('[data-bp-list="tiers"] .azc-policy-row')).toHaveCount(0)

		// Add a tier: >6h → at least 45 min (tighter than the DE statutory 30).
		await page.locator('[data-bp-add="tiers"]').click()
		const tierRow = page.locator('[data-bp-list="tiers"] .azc-policy-row').first()
		await expect(tierRow).toBeVisible()
		await tierRow.locator('[data-bp-key="afterHours"]').fill('6')
		await tierRow.locator('[data-bp-key="breakMinutes"]').fill('45')

		// Live preview narrates the configured rule.
		const preview = page.locator('#azc-policy-preview')
		await expect(preview).toBeVisible()
		await expect(preview).toContainText(/45/)

		// A second empty row is removable (editor must not deadlock on partial rows).
		await page.locator('[data-bp-add="tiers"]').click()
		await expect(page.locator('[data-bp-list="tiers"] .azc-policy-row')).toHaveCount(2)
		await page.locator('[data-bp-list="tiers"] .azc-policy-row').nth(1).locator('[data-bp-remove]').click()
		await expect(page.locator('[data-bp-list="tiers"] .azc-policy-row')).toHaveCount(1)

		await page.locator('#breakPolicyEnforced').check()
		await clickSave(page)

		// Reload renders the saved row from server state (the data script is
		// the authoritative persisted document, not optimistic UI).
		const policy = await persistedPolicy(page)
		expect(policy.tiers).toEqual([{ afterHours: 6, breakMinutes: 45 }])
		expect(policy.enforced).toBe(true)

		const persistedRow = page.locator('[data-bp-list="tiers"] .azc-policy-row').first()
		await expect(persistedRow.locator('[data-bp-key="afterHours"]')).toHaveValue('6')
		await expect(persistedRow.locator('[data-bp-key="breakMinutes"]')).toHaveValue('45')
		await expect(page.locator('#breakPolicyEnforced')).toBeChecked()
	})

	test('shadowed policy requires the explicit confirm gate', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await clearPolicy(page)
		await openCompliance(page)

		// Tier >6h → 30 min equals the DE statute: tightening nothing, so the
		// save must bounce with CONFIRM_REQUIRED before persisting anything.
		await page.locator('[data-bp-add="tiers"]').click()
		const row = page.locator('[data-bp-list="tiers"] .azc-policy-row').first()
		await row.locator('[data-bp-key="afterHours"]').fill('6')
		await row.locator('[data-bp-key="breakMinutes"]').fill('30')
		await page.locator('#breakPolicyEnforced').check()
		await clickSave(page)

		const confirmBox = page.locator('#azc-policy-confirm')
		await expect(confirmBox).toBeVisible({ timeout: 15000 })
		await expect(confirmBox).toHaveAttribute('role', 'alert')
		await expect(page.locator('#azc-policy-confirm-warnings li').first()).toBeVisible()

		// Nothing persisted while the gate is open.
		const rejected = await persistedPolicy(page)
		expect(rejected.tiers ?? []).toEqual([])
		expect(rejected.enforced).toBeFalsy()

		// Confirm + resave persists.
		await page.locator('[data-bp-add="tiers"]').click()
		const row2 = page.locator('[data-bp-list="tiers"] .azc-policy-row').first()
		await row2.locator('[data-bp-key="afterHours"]').fill('6')
		await row2.locator('[data-bp-key="breakMinutes"]').fill('30')
		await page.locator('#breakPolicyEnforced').check()
		await clickSave(page)
		await expect(page.locator('#azc-policy-confirm')).toBeVisible({ timeout: 15000 })
		await page.locator('#breakPolicyConfirm').check()
		await clickSave(page)
		await expect(page.locator('#azc-policy-confirm')).toBeHidden()

		const saved = await persistedPolicy(page)
		expect(saved.tiers).toEqual([{ afterHours: 6, breakMinutes: 30 }])
		expect(saved.enforced).toBe(true)
	})

	test('invalid payloads return field-level validation errors', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await clearPolicy(page)

		// Tier missing breakMinutes → VALIDATION on that row's field key.
		const bad = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
			data: {
				...COMPLIANCE,
				breakPolicyTiers: [{ afterHours: 6 }],
			},
		})
		expect(bad.ok).toBe(false)
		expect(bad.status).toBe(400)
		expect(bad.json?.code).toBe('VALIDATION')
		expect(Object.keys(bad.json?.fields ?? {}).some((k) => k.startsWith('breakPolicyTiers'))).toBe(true)

		// Overlapping windows must be rejected.
		const overlap = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
			data: {
				...COMPLIANCE,
				breakPolicyWindows: [
					{ start: '12:00', end: '13:00', minutes: 30, mode: 'placement' },
					{ start: '12:30', end: '14:00', minutes: 30, mode: 'placement' },
				],
			},
		})
		expect(overlap.ok).toBe(false)
		expect(overlap.json?.code).toBe('VALIDATION')
		expect(Object.keys(overlap.json?.fields ?? {}).some((k) => k.startsWith('breakPolicyWindows'))).toBe(true)

		// Rejected writes never touched state.
		const policy = await persistedPolicy(page)
		expect(policy.tiers ?? []).toEqual([])
		expect(policy.windows ?? []).toEqual([])
	})

	test('employee sees locked auto-break and the applied rules summary', async ({ page, browser }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')
		await login(page, credsFromEnv('ADMIN'))
		await clearPolicy(page)
		const res = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
			data: {
				...COMPLIANCE,
				breakPolicyEnforced: true,
				breakPolicyTiers: [{ afterHours: 6, breakMinutes: 45 }],
			},
		})
		expect(res.settings?.breakPolicy?.enforced).toBe(true)

		const employeeContext = await browser.newContext()
		const employeePage = await employeeContext.newPage()
		try {
			await login(employeePage, credsFromEnv('EMPLOYEE'))
			await employeePage.goto('/apps/arbeitszeitcheck/settings/breaks')
			await assertArbeitszeitcheckLoaded(employeePage)

			const checkbox = employeePage.locator('#auto-break-calculation')
			await expect(checkbox).toBeVisible()
			await expect(checkbox).toBeDisabled()
			await expect(checkbox).toBeChecked()
			await expect(employeePage.locator('#auto-break-calculation-enforced')).toBeVisible()

			// Effective policy summary lists the tightened rule.
			const summary = employeePage.locator('.azc-break-policy-summary')
			await expect(summary).toBeVisible()
			await expect(summary).toContainText(/45/)
		} finally {
			await employeeContext.close()
		}
	})

	test('working-time model presence windows: editor round-trip + overlap rejection', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
		await assertArbeitszeitcheckLoaded(page)

		const name = `Presence E2E ${Date.now() % 100000}`
		await page.locator('#create-model').click()
		await expect(page.locator('#wtm-editor-panel')).toBeVisible()

		// The presence fieldset renders inside the break-rules section.
		const fieldset = page.locator('.wtm-presence-windows').first()
		await expect(fieldset).toBeVisible()
		await expect(fieldset.locator('.wtm-presence-empty')).toBeVisible()

		await page.locator('#wtm-model-name').fill(name)
		await fieldset.locator('[data-action="add-presence-window"]').click()
		const row = fieldset.locator('[data-presence-row]').first()
		await expect(row).toBeVisible()
		await row.locator('.wtm-presence-weekday').selectOption('1')
		await row.locator('.wtm-presence-start').fill('08:00')
		await row.locator('.wtm-presence-end').fill('12:00')

		await page.locator('#wtm-model-submit').click()

		// Model persisted with the presence window in break_rules.
		let modelId = null
		await expect.poll(async () => {
			const list = await api(page, 'GET', '/apps/arbeitszeitcheck/api/admin/working-time-models')
			const model = (list.models ?? []).find((m) => m.name === name)
			modelId = model ? Number(model.id) : null
			return modelId
		}, { timeout: 15000 }).not.toBeNull()

		try {
			const show = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/working-time-models/${modelId}`)
			const rawRules = show.model?.breakRules ?? show.model?.break_rules ?? '{}'
			const rules = typeof rawRules === 'string' ? JSON.parse(rawRules || '{}') : rawRules
			expect(rules.presence_windows).toEqual([{ weekday: 1, start: '08:00', end: '12:00' }])

			// Edit view re-renders the saved row.
			await page.goto('/apps/arbeitszeitcheck/admin/working-time-models')
			await assertArbeitszeitcheckLoaded(page)
			// Row actions sit in a hover-reveal panel (pointer-events:none until tr:hover).
			const editBtn = page.locator(`[data-action="edit-model"][data-model-id="${modelId}"]`).first()
			await editBtn.locator('xpath=ancestor::tr[1]').hover()
			await editBtn.click()
			const editRow = page.locator('.wtm-presence-windows [data-presence-row]').first()
			await expect(editRow).toBeVisible()
			await expect(editRow.locator('.wtm-presence-start')).toHaveValue('08:00')
			await expect(editRow.locator('.wtm-presence-end')).toHaveValue('12:00')

			// Same-weekday overlap is rejected at the write boundary.
			const overlap = await apiAllowFailure(page, 'PUT', `/apps/arbeitszeitcheck/api/admin/working-time-models/${modelId}`, {
				data: {
					breakRules: {
						presence_windows: [
							{ weekday: 1, start: '08:00', end: '12:00' },
							{ weekday: 1, start: '11:00', end: '14:00' },
						],
					},
				},
			})
			expect(overlap.ok).toBe(false)
		} finally {
			await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/working-time-models/${modelId}`)
		}
	})

	test('presence exemption and break windows round-trip via API', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await clearPolicy(page)
		const res = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/settings', {
			data: {
				...COMPLIANCE,
				presenceExemptions: [{ start: '2026-12-24', end: '2027-01-06' }],
				breakPolicyWindows: [{ start: '12:00', end: '13:00', minutes: 30, mode: 'required' }],
			},
		})
		expect(res.success).toBe(true)
		expect(res.settings?.breakPolicy?.presence_exemptions).toEqual([{ start: '2026-12-24', end: '2027-01-06' }])

		const policy = await persistedPolicy(page)
		expect(policy.presence_exemptions).toEqual([{ start: '2026-12-24', end: '2027-01-06' }])
		expect(policy.windows).toEqual([{ start: '12:00', end: '13:00', minutes: 30, mode: 'required' }])
	})
})
