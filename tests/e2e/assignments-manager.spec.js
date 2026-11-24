import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

/**
 * Effective-dated work schedule periods (admin user detail).
 *
 * Uses ONLY future-dated rows on the shared e2e_employee fixture: future
 * windows are deletable, so every created row is removed again and no
 * sealed/current state is touched. Windows sit in 2099-06+ — after the shared fixture's last assignment
 * row (2099-03-01..2099-04-30) so nothing existing is ever touched.
 */
test.describe('Admin user — effective-dated assignment manager', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	const userId = 'e2e_employee'
	const apiBase = `/apps/arbeitszeitcheck/api/admin/users/${userId}/working-time-assignments`

	async function deleteRow(page, id) {
		if (!id) return
		await apiAllowFailure(page, 'DELETE', `${apiBase}/${id}`)
	}

	async function rowsFor(page) {
		const body = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${userId}`)
		return body.user?.workingTimeModelAssignments || []
	}

	async function firstModelId(page) {
		const body = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/users/${userId}`)
		const models = body.user?.availableWorkingTimeModels || []
		const pick = models.find(m => m.assignable !== false && m.superseded !== true) || models[0]
		expect(pick, 'an assignable model must exist for the fixture user').toBeTruthy()
		return pick.id
	}

	test('dialog shows live consequence preview and saves a future period', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(`/apps/arbeitszeitcheck/admin/users/${userId}`)

		const addBtn = page.locator('#assignment-add-btn')
		await expect(addBtn).toBeVisible({ timeout: 30000 })

		// Defensive cleanup: a previous failed attempt may have left the row.
		for (const row of await rowsFor(page)) {
			if (row.start_date >= '2099-05-01') await deleteRow(page, row.id)
		}

		await addBtn.click()

		const dialog = page.locator('#assignment-dialog')
		await expect(dialog).toHaveAttribute('open', '', { timeout: 10000 })

		const modelSel = dialog.locator('#assignment-model')
		const firstModelValue = await modelSel.locator('option').first().getAttribute('value')
		await modelSel.selectOption(firstModelValue)

		await dialog.locator('#assignment-start').fill('2099-06-01')
		const preview = dialog.locator('#assignment-dialog-preview')
		await expect(preview).toBeVisible()
		await expect(preview).toContainText('2099')

		// Teacher line present on create (auto-end semantics explained).
		await expect(dialog.locator('#assignment-dialog-teacher')).toBeVisible()

		// "More options" progressive disclosure keeps the primary path small.
		await dialog.locator('#assignment-more summary').click()
		await expect(dialog.locator('#assignment-end')).toBeVisible()
		await dialog.locator('#assignment-end').fill('2099-06-30')

		await dialog.locator('#assignment-dialog-save').click()
		await expect(dialog).not.toBeVisible({ timeout: 15000 })

		// Row persisted and listed via API; cleanup after.
		const created = (await rowsFor(page)).find(a => a.start_date === '2099-06-01')
		expect(created, 'created assignment row must be listed via API').toBeTruthy()
		expect(created.end_date).toBe('2099-06-30')
		await deleteRow(page, created.id)
		const after = await rowsFor(page)
		expect(after.find(a => a.id === created.id)).toBeUndefined()
	})

	test('engulfing an existing window shows inline overlap + conflict list', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		const modelId = await firstModelId(page)

		const seeded = await api(page, 'POST', apiBase, {
			data: { modelId, startDate: '2099-07-01' },
		})
		expect(seeded.success).toBe(true)
		const seededId = seeded.assignment.id

		try {
			await page.goto(`/apps/arbeitszeitcheck/admin/users/${userId}`)
			const addBtn = page.locator('#assignment-add-btn')
			await expect(addBtn).toBeVisible({ timeout: 30000 })
			await addBtn.click()

			const dialog = page.locator('#assignment-dialog')
			await dialog.locator('#assignment-start').fill('2099-06-15')
			await dialog.locator('#assignment-more summary').click()
			await dialog.locator('#assignment-end').fill('2099-08-15')
			await dialog.locator('#assignment-dialog-save').click()

			// Inline error + named conflict with an Edit escape hatch — no dead end.
			const err = dialog.locator('#assignment-dialog-error')
			await expect(err).toBeVisible({ timeout: 15000 })
			const conflicts = dialog.locator('#assignment-dialog-conflicts')
			await expect(conflicts).toBeVisible()
			await expect(conflicts).toContainText('2099')
			await expect(conflicts.locator('[data-conflict-edit]')).toHaveCount(1)

			await dialog.locator('#assignment-dialog-cancel').click()
		} finally {
			await deleteRow(page, seededId)
		}
	})

	test('concurrent identical creates serialize — exactly one row survives', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		const modelId = await firstModelId(page)

		// Two parallel POSTs with identical windows: the per-user mutation
		// lock must serialize them; exactly one may win, the loser must 409
		// — and the DB must hold exactly one row (ground truth, not the
		// HTTP responses).
		const [a, b] = await Promise.all([
			apiAllowFailure(page, 'POST', apiBase, { data: { modelId, startDate: '2099-11-01' } }),
			apiAllowFailure(page, 'POST', apiBase, { data: { modelId, startDate: '2099-11-01' } }),
		])
		const statuses = [a.status, b.status].sort()
		expect(statuses[1]).toBe(409, 'the losing request must be rejected as overlap')

		const rows = (await rowsFor(page)).filter(r => r.start_date === '2099-11-01')
		expect(rows).toHaveLength(1)
		await deleteRow(page, rows[0].id)
	})

	test('non-admin role is rejected on the admin assignment endpoint', async ({ page }) => {
		await login(page, credsFromEnv('EMPLOYEE'))
		const res = await apiAllowFailure(page, 'POST', apiBase, {
			data: { modelId: 1, startDate: '2099-12-01' },
		})
		expect([403, 404]).toContain(res.status)
	})

	test('uniform 404 for foreign assignment id (IDOR guard)', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		const modelId = await firstModelId(page)

		// Seed a row on e2e_employee, then probe it under a different uid —
		// must 404 like a missing row (no existence oracle, AC-9.14).
		const seeded = await api(page, 'POST', apiBase, {
			data: { modelId, startDate: '2099-09-01' },
		})
		const seededId = seeded.assignment.id
		try {
			const foreign = await apiAllowFailure(
				page, 'PATCH',
				`/apps/arbeitszeitcheck/api/admin/users/e2e_manager/working-time-assignments/${seededId}`,
				{ data: { endDate: '2099-09-30' } }
			)
			expect(foreign.status).toBe(404)

			const missing = await apiAllowFailure(
				page, 'PATCH',
				`/apps/arbeitszeitcheck/api/admin/users/e2e_manager/working-time-assignments/999999`,
				{ data: { endDate: '2099-09-30' } }
			)
			expect(missing.status).toBe(404)
		} finally {
			await deleteRow(page, seededId)
		}
	})
})
