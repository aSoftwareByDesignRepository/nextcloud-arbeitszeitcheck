// @ts-check
/**
 * Admin → Teams bulk “Find people” — live browser gauntlet (Kraft UX-R8-03).
 *
 * Not a shallow happy-path: proves search= contract, debounce floor, empty/error,
 * exclude-already-on-team, multi-select confirm/cancel, managers path, a11y, and
 * that confirm copy includes the selected unit name (#team-detail-name).
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { loginAs, gotoApp } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

test.describe.configure({ mode: 'serial' })

const TEAM_NAME = `E2E Bulk Find ${Date.now()}`
const EMPLOYEE_UID = process.env.NC_EMPLOYEE_USER || 'e2e_employee'
const MANAGER_UID = process.env.NC_MANAGER_USER || 'e2e_manager'
const SUBSTITUTE_UID = process.env.NC_SUBSTITUTE_USER || 'e2e_substitute'

/** @type {number|null} */
let teamId = null

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} needle
 */
function waitPickerSearch(page, needle) {
	return page.waitForResponse(
		(res) => {
			if (res.request().method() !== 'GET') return false
			const url = res.url()
			if (!url.includes('/api/admin/users')) return false
			if (!url.includes('picker=1')) return false
			const u = new URL(url)
			return u.searchParams.get('search') === needle
		},
		{ timeout: 60_000 },
	)
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function ensureAppTeamsEnabled(page) {
	const cfg = await api(page, 'GET', '/apps/arbeitszeitcheck/api/admin/teams/config/use-app-teams')
	if (!cfg.useAppTeams) {
		await api(page, 'PUT', '/apps/arbeitszeitcheck/api/admin/teams/config/use-app-teams', {
			data: { useAppTeams: true },
		})
	}
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function openBulkMembersModal(page) {
	await page.locator('#team-add-members-bulk').click()
	await expect(page.locator('#modal-bulk-add-members')).toBeVisible({ timeout: 15_000 })
	await expect(page.locator('#bulk-member-search')).toBeVisible()
	await expect(page.locator('label[for="bulk-member-search"]')).toHaveText(/Find people|Personen suchen/i)
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} query
 */
async function typeBulkSearch(page, query) {
	const search = page.locator('#bulk-member-search')
	await search.fill('')
	const responsePromise = waitPickerSearch(page, query)
	await search.fill(query)
	const res = await responsePromise
	expect(res.ok()).toBeTruthy()
	const reqUrl = new URL(res.url())
	expect(reqUrl.searchParams.get('search')).toBe(query)
	expect(reqUrl.searchParams.has('q')).toBe(false)
	expect(reqUrl.searchParams.get('picker')).toBe('1')
	return res
}

test.describe('Admin teams bulk Find people (Kraft live)', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		await loginAs(page, 'ADMIN')
		await ensureAppTeamsEnabled(page)
		const created = await api(page, 'POST', '/apps/arbeitszeitcheck/api/admin/teams', {
			data: { name: TEAM_NAME, parentId: null, sortOrder: 0 },
		})
		expect(created.success).toBe(true)
		teamId = Number(created.team?.id)
		expect(teamId).toBeGreaterThan(0)
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		if (!teamId) return
		const page = await browser.newPage()
		await loginAs(page, 'ADMIN')
		await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/teams/${teamId}`)
		await page.close()
	})

	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'ADMIN')
		await gotoApp(page, '/apps/arbeitszeitcheck/admin/teams')
		await page.waitForSelector(`#admin-teams-tree .teams-tree__item[data-team-id="${teamId}"]`, {
			timeout: 30_000,
		})
		await page.locator(`.teams-tree__item[data-team-id="${teamId}"] .teams-tree__label`).click()
		await expect(page.locator('#admin-team-detail')).not.toHaveClass(/hidden/, { timeout: 15_000 })
		await expect(page.locator('#team-detail-name')).toHaveText(TEAM_NAME)
		await expect(page.locator('#team-add-members-bulk')).toBeVisible()
	})

	test('opens modal: Find people label, focus, submit disabled, axe-clean', async ({ page }) => {
		await openBulkMembersModal(page)
		await expect(page.locator('#bulk-member-submit')).toBeDisabled()
		await expect(page.locator('#bulk-member-search')).toBeFocused()
		await expect(page.locator('#bulk-member-results')).toHaveAttribute('role', 'group')
		await expect(page.locator('#bulk-member-status')).toHaveAttribute('aria-live', 'polite')

		const results = await new AxeBuilder({ page })
			.include('#modal-bulk-add-members')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('single-character query does not hit picker API', async ({ page }) => {
		await openBulkMembersModal(page)
		let sawSearch = false
		page.on('request', (req) => {
			if (req.method() === 'GET' && req.url().includes('/api/admin/users') && req.url().includes('picker=1')) {
				sawSearch = true
			}
		})
		await page.locator('#bulk-member-search').fill('e')
		await page.waitForTimeout(450)
		expect(sawSearch).toBe(false)
		await expect(page.locator('#bulk-member-results')).toBeEmpty()
	})

	test('search uses search= (not q=) and finds employee by login', async ({ page }) => {
		await openBulkMembersModal(page)
		await typeBulkSearch(page, EMPLOYEE_UID)
		const hit = page.locator('#bulk-member-results .team-bulk-results__item', {
			hasText: EMPLOYEE_UID,
		})
		await expect(hit).toBeVisible({ timeout: 15_000 })
		await expect(hit).toContainText(/\(/) // displayName (uid) shape
	})

	test('no matching users shows empty copy', async ({ page }) => {
		await openBulkMembersModal(page)
		const nonsense = `zzznomatch-${Date.now()}`
		await typeBulkSearch(page, nonsense)
		await expect(page.locator('#bulk-member-results')).toContainText(
			/No matching users found|Keine passenden Benutzer gefunden/i,
		)
		await expect(page.locator('#bulk-member-submit')).toBeDisabled()
	})

	test('already-on-team members are excluded from bulk hits', async ({ page }) => {
		await apiAllowFailure(page, 'POST', `/apps/arbeitszeitcheck/api/admin/teams/${teamId}/members`, {
			data: { userId: EMPLOYEE_UID },
		})
		await openBulkMembersModal(page)
		await typeBulkSearch(page, EMPLOYEE_UID)
		await expect(page.locator('#bulk-member-results .team-bulk-results__item', { hasText: EMPLOYEE_UID })).toHaveCount(0)
		await expect(page.locator('#bulk-member-results')).not.toContainText(`(${EMPLOYEE_UID})`)
	})

	test('multi-select, confirm cancel aborts; confirm accept adds + shows summary', async ({ page }) => {
		// Ensure candidates are not already members (employee may be from prior exclude test)
		await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/teams/${teamId}/members/${encodeURIComponent(SUBSTITUTE_UID)}`)
		await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/teams/${teamId}/members/${encodeURIComponent(MANAGER_UID)}`)

		await openBulkMembersModal(page)

		// One search that returns both fixtures — keep both checkboxes in the same renderHits pass
		// so selected Map + DOM stay aligned (re-search recreates nodes and is easy to mis-test).
		await typeBulkSearch(page, 'e2e_')
		// Prove updated bulk JS is loaded (selection sync / data-user-id)
		await expect(page.locator('#bulk-member-results input[data-user-id]').first()).toBeAttached({
			timeout: 5_000,
		})
		const subHit = page.locator('#bulk-member-results .team-bulk-results__item', { hasText: SUBSTITUTE_UID })
		const mgrHit = page.locator('#bulk-member-results .team-bulk-results__item', { hasText: MANAGER_UID })
		await expect(subHit).toBeVisible({ timeout: 15_000 })
		await expect(mgrHit).toBeVisible({ timeout: 15_000 })
		// Click the label (not check()) so the nested checkbox toggles via real UI events
		await subHit.click()
		await expect(subHit.locator('input[type="checkbox"]')).toBeChecked()
		await expect(page.locator('#bulk-member-submit')).toBeEnabled()
		await expect(page.locator('#bulk-member-status')).toContainText(/1/)
		await mgrHit.click()
		await expect(mgrHit.locator('input[type="checkbox"]')).toBeChecked()
		await expect(page.locator('#bulk-member-submit')).toContainText(/2/, { timeout: 5_000 })
		await expect(page.locator('#bulk-member-status')).toContainText(/2/)

		// Cancel confirm → no batch
		page.once('dialog', async (dialog) => {
			expect(dialog.message()).toMatch(new RegExp(TEAM_NAME.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
			await dialog.dismiss()
		})
		let batchSeen = false
		page.on('request', (req) => {
			if (req.method() === 'POST' && req.url().includes(`/teams/${teamId}/members/batch`)) {
				batchSeen = true
			}
		})
		await page.locator('#bulk-member-submit').click()
		await page.waitForTimeout(400)
		expect(batchSeen).toBe(false)
		await expect(page.locator('#modal-bulk-add-members')).toBeVisible()

		// Accept confirm → batch succeeds (team name must appear — #team-detail-name wiring)
		const batchPromise = page.waitForResponse(
			(res) =>
				res.request().method() === 'POST'
				&& res.url().includes(`/api/admin/teams/${teamId}/members/batch`),
			{ timeout: 60_000 },
		)
		page.once('dialog', async (dialog) => {
			expect(dialog.message()).toContain(TEAM_NAME)
			await dialog.accept()
		})
		await page.locator('#bulk-member-submit').click()
		const batchRes = await batchPromise
		expect(batchRes.ok()).toBeTruthy()
		const body = await batchRes.json()
		expect(body.success).toBe(true)
		await expect(page.locator('#bulk-member-result-callout')).toBeVisible()
		await expect(page.locator('#bulk-member-result-callout')).toContainText(/Added|Hinzugefügt|skipped|übersprungen|failed|fehlgeschlagen/i)

		const members = await api(page, 'GET', `/apps/arbeitszeitcheck/api/admin/teams/${teamId}/members`)
		const ids = (members.members || []).map((m) => String(m.userId))
		expect(ids).toEqual(expect.arrayContaining([SUBSTITUTE_UID, MANAGER_UID]))
	})

	test('picker network failure shows Failed to load users', async ({ page }) => {
		await openBulkMembersModal(page)
		await page.route('**/api/admin/users?*picker=1*', async (route) => {
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ success: false, error: 'boom' }),
			})
		})
		await page.locator('#bulk-member-search').fill('admin')
		await expect(page.locator('#bulk-member-results')).toContainText(
			/Failed to load users|Benutzer konnten nicht geladen werden/i,
			{ timeout: 15_000 },
		)
		await page.unroute('**/api/admin/users?*picker=1*')
	})

	test('Cancel closes modal without batch', async ({ page }) => {
		await openBulkMembersModal(page)
		let batchSeen = false
		page.on('request', (req) => {
			if (req.method() === 'POST' && req.url().includes('/members/batch')) batchSeen = true
		})
		await page.locator('#modal-bulk-add-members [data-action="close-modal"]').click()
		await expect(page.locator('#modal-bulk-add-members')).toHaveCount(0, { timeout: 10_000 })
		expect(batchSeen).toBe(false)
	})

	test('managers bulk path also sends search= and finds people', async ({ page }) => {
		await page.locator('#tab-managers').click()
		await expect(page.locator('#panel-managers')).toBeVisible()
		await page.locator('#team-add-managers-bulk').click()
		await expect(page.locator('#modal-bulk-add-managers')).toBeVisible()
		await expect(page.locator('label[for="bulk-manager-search"]')).toHaveText(/Find people|Personen suchen/i)

		const res = await (async () => {
			const p = waitPickerSearch(page, MANAGER_UID)
			await page.locator('#bulk-manager-search').fill(MANAGER_UID)
			return p
		})()
		const u = new URL(res.url())
		expect(u.searchParams.get('search')).toBe(MANAGER_UID)
		expect(u.searchParams.has('q')).toBe(false)
		await expect(
			page.locator('#bulk-manager-results .team-bulk-results__item', { hasText: MANAGER_UID }),
		).toBeVisible({ timeout: 15_000 })
		await page.locator('#modal-bulk-add-managers [data-action="close-modal"]').click()
	})

	test('counter persists when search is cleared and new person selected (Kraft regression)', async ({ page }) => {
		// Clean up any existing memberships to ensure clean test state
		await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/teams/${teamId}/members/${encodeURIComponent(SUBSTITUTE_UID)}`)
		await apiAllowFailure(page, 'DELETE', `/apps/arbeitszeitcheck/api/admin/teams/${teamId}/members/${encodeURIComponent(MANAGER_UID)}`)

		await openBulkMembersModal(page)

		// Step 1: Search for and select person 1
		await typeBulkSearch(page, SUBSTITUTE_UID)
		const subHit = page.locator('#bulk-member-results .team-bulk-results__item', { hasText: SUBSTITUTE_UID })
		await expect(subHit).toBeVisible({ timeout: 15_000 })
		await subHit.click()
		await expect(subHit.locator('input[type="checkbox"]')).toBeChecked()
		await expect(page.locator('#bulk-member-submit')).toBeEnabled()
		await expect(page.locator('#bulk-member-submit')).toContainText(/1/)
		await expect(page.locator('#bulk-member-status')).toContainText(/1/)

		// Step 2: Clear the search - counter should still show 1
		await page.locator('#bulk-member-search').fill('')
		await expect(page.locator('#bulk-member-results')).toBeEmpty()
		// The critical fix: counter should persist at 1 even after clearing search
		await expect(page.locator('#bulk-member-submit')).toContainText(/1/)
		await expect(page.locator('#bulk-member-status')).toContainText(/1/)

		// Step 3: Search for and select person 2
		await typeBulkSearch(page, MANAGER_UID)
		const mgrHit = page.locator('#bulk-member-results .team-bulk-results__item', { hasText: MANAGER_UID })
		await expect(mgrHit).toBeVisible({ timeout: 15_000 })
		await mgrHit.click()
		await expect(mgrHit.locator('input[type="checkbox"]')).toBeChecked()

		// Step 4: Counter should now show 2 (the bug was it stayed at 1)
		await expect(page.locator('#bulk-member-submit')).toContainText(/2/, { timeout: 5_000 })
		await expect(page.locator('#bulk-member-status')).toContainText(/2/)

		// Clean up - close modal without submitting
		await page.locator('#modal-bulk-add-members [data-action="close-modal"]').click()
		await expect(page.locator('#modal-bulk-add-members')).toHaveCount(0, { timeout: 10_000 })
	})
})
