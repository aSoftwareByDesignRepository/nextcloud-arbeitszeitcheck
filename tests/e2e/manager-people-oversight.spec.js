// @ts-check
/**
 * E2E coverage for the manager/HR people-overview surface:
 *  - page renders for managers with the employee table
 *  - the people-overview API returns the manager's scoped roster
 *  - out-of-scope employee filters fail closed (no existence oracle)
 *  - admins get organization scope
 *  - the scoped violations list accepts scope=managed
 *  - axe-clean on WCAG 2.1 A/AA for the people page
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login, credsFromEnv, skipIfMissingCreds } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test.use({ trace: 'off' })

const PEOPLE_API = '/apps/arbeitszeitcheck/api/manager/people-overview'
const VIOLATIONS_API = '/apps/arbeitszeitcheck/api/compliance/violations'

test.describe('Manager people oversight (Docker dev)', () => {
	test.beforeEach(async () => {
		skipIfMissingCreds('MANAGER')
	})

	test('people page renders table, filters and employees', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)

		await expect(page.locator('#app-content.azc-app--manager-people')).toBeVisible()
		// The table un-hides once the overview payload lands.
		await expect(page.locator('#manager-people-table-wrap')).not.toHaveClass(/visually-hidden/, { timeout: 30000 })
		await expect(page.locator('#manager-people-body tr').first()).toBeVisible()
		// Manager's team 339 members must appear.
		await expect(page.locator('#manager-people-body')).toContainText('e2e_employee')
		await expect(page.locator('#manager-people-body')).toContainText('e2e_substitute')
	})

	test('people overview API returns managed scope with vacation/overtime rows', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)

		const data = await api(page, 'GET', PEOPLE_API)
		expect(data.success).toBe(true)
		expect(data.scope).toBe('managed')
		expect(Array.isArray(data.employees)).toBe(true)
		expect(data.total).toBeGreaterThanOrEqual(2)

		const uids = data.employees.map((e) => e.userId)
		expect(uids).toContain('e2e_employee')
		expect(uids).toContain('e2e_substitute')

		const row = data.employees.find((e) => e.userId === 'e2e_employee')
		expect(row).toHaveProperty('openViolations')
		expect(row).toHaveProperty('pendingAbsences')
		// Vacation may legitimately be null when the row's stats failed —
		// but when present it must expose the balance contract.
		if (row.vacation !== null) {
			expect(row.vacation).toHaveProperty('remaining')
			expect(row.vacation).toHaveProperty('unit')
			expect(row.vacation).toHaveProperty('approvedUpcoming')
		}
	})

	test('employee filter narrows to the requested member', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)

		const data = await api(page, 'GET', `${PEOPLE_API}?employeeId=e2e_employee`)
		expect(data.success).toBe(true)
		expect(data.total).toBe(1)
		expect(data.employees[0].userId).toBe('e2e_employee')
	})

	test('out-of-scope employee filter fails closed for managers', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)

		// 'admin' exists but is not in e2e_manager's team — same denial as a
		// nonexistent uid (no existence oracle).
		const res = await apiAllowFailure(page, 'GET', `${PEOPLE_API}?employeeId=admin`)
		expect(res.status).toBe(403)
		expect(res.json?.success).toBe(false)

		const resGhost = await apiAllowFailure(page, 'GET', `${PEOPLE_API}?employeeId=__ghost_no_such_user__`)
		expect(resGhost.status).toBe(403)
		expect(resGhost.json?.success).toBe(false)
	})

	test('admin sees organization scope', async ({ page }) => {
		skipIfMissingCreds('ADMIN')
		await login(page, credsFromEnv('ADMIN'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)

		const data = await api(page, 'GET', PEOPLE_API)
		expect(data.success).toBe(true)
		expect(data.scope).toBe('organization')
		// Org scope spans beyond a single team.
		expect(data.total).toBeGreaterThan(2)
	})

	test('violations API accepts managed scope and rejects organization for managers', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/compliance/violations')
		await assertArbeitszeitcheckLoaded(page)

		const managed = await api(page, 'GET', `${VIOLATIONS_API}?scope=managed`)
		expect(managed.success).toBe(true)
		expect(managed.scope).toBe('managed')
		for (const v of managed.violations) {
			expect(['e2e_employee', 'e2e_substitute', 'e2e_manager']).toContain(v.userId)
		}

		const org = await apiAllowFailure(page, 'GET', `${VIOLATIONS_API}?scope=organization`)
		expect(org.status).toBe(403)
	})

	test('people overview API honors search, filter and sort params', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)

		// search narrows by name/uid substring inside scope.
		const searched = await api(page, 'GET', `${PEOPLE_API}?search=e2e_empl`)
		expect(searched.success).toBe(true)
		for (const e of searched.employees) {
			expect(e.userId).toContain('e2e_empl')
		}
		expect(searched.total).toBeGreaterThanOrEqual(1)

		// sort=name orders display names ascending.
		const byName = await api(page, 'GET', `${PEOPLE_API}?sort=name`)
		expect(byName.success).toBe(true)
		const names = byName.employees.map((e) => String(e.displayName || e.userId).toLowerCase())
		const sorted = [...names].sort((a, b) => a.localeCompare(b))
		expect(names).toEqual(sorted)

		// default sort = open violations desc (triage order).
		const triage = await api(page, 'GET', PEOPLE_API)
		const counts = triage.employees.map((e) => Number(e.openViolations) || 0)
		for (let i = 1; i < counts.length; i++) {
			expect(counts[i - 1]).toBeGreaterThanOrEqual(counts[i])
		}

		// rows expose the critical-violation count for the severity badge.
		for (const e of triage.employees) {
			expect(e).toHaveProperty('criticalViolations')
			expect(Number(e.criticalViolations)).toBeLessThanOrEqual(Number(e.openViolations))
		}

		// invalid enums fail closed with 400, not a silent default.
		const badFilter = await apiAllowFailure(page, 'GET', `${PEOPLE_API}?filter=bogus`)
		expect(badFilter.status).toBe(400)
		const badSort = await apiAllowFailure(page, 'GET', `${PEOPLE_API}?sort=bogus`)
		expect(badSort.status).toBe(400)

		// filter pills values are accepted; pending_absences only returns
		// rows with pending counts.
		const pending = await api(page, 'GET', `${PEOPLE_API}?filter=pending_absences`)
		expect(pending.success).toBe(true)
		for (const e of pending.employees) {
			expect(Number(e.pendingAbsences)).toBeGreaterThan(0)
		}
		const attention = await api(page, 'GET', `${PEOPLE_API}?filter=needs_attention`)
		expect(attention.success).toBe(true)
	})

	test('people page wires quick-filter pills, search and sort controls', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)
		await expect(page.locator('#manager-people-table-wrap')).not.toHaveClass(/visually-hidden/, { timeout: 30000 })

		// Recipe-B pill bar: labeled radiogroup with aria-pressed state.
		const pills = page.locator('#manager-people-quick-filters .azc-filter')
		await expect(pills).toHaveCount(3)
		await expect(pills.nth(0)).toHaveAttribute('aria-pressed', 'true')

		// Clicking "Pending requests" switches aria-pressed and reloads.
		await pills.nth(2).click()
		await expect(pills.nth(2)).toHaveAttribute('aria-pressed', 'true')
		await expect(pills.nth(0)).toHaveAttribute('aria-pressed', 'false')
		// Roving tabindex: only the active pill is in the tab order.
		await expect(pills.nth(2)).toHaveAttribute('tabindex', '0')
		await expect(pills.nth(0)).toHaveAttribute('tabindex', '-1')

		// Search + sort controls exist and are labeled.
		await expect(page.locator('#manager-people-search')).toBeVisible()
		await expect(page.locator('#manager-people-sort')).toBeVisible()

		// Search applies on submit.
		await page.locator('#manager-people-search').fill('e2e_employee')
		await page.locator('#manager-people-submit').click()
		await page.waitForLoadState('networkidle')
		const rows = page.locator('#manager-people-body tr')
		const count = await rows.count()
		expect(count).toBeGreaterThanOrEqual(1)
		for (let i = 0; i < count; i++) {
			await expect(rows.nth(i)).toContainText('e2e_employee')
		}
	})

	test('people page is axe-clean (WCAG 2.1 A/AA)', async ({ page }) => {
		await login(page, credsFromEnv('MANAGER'))
		await page.goto('/apps/arbeitszeitcheck/manager/people')
		await assertArbeitszeitcheckLoaded(page)
		await page.waitForSelector('#app-content.azc-app--manager-people', { timeout: 30000 })

		const results = await new AxeBuilder({ page })
			.include('#content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})
})
