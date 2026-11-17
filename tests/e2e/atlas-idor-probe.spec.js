import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { getRequestToken } from './helpers/api.js'

const BASE = '/index.php/apps/arbeitszeitcheck'

// ATLAS Lens-2 probe: real IDOR/authz checks as e2e_employee.
// Other-user objects must collapse to uniform 404 (no existence oracle).
// Admin/manager surfaces must deny (403/404).

test.describe('IDOR + role-boundary probes as e2e_employee', () => {
	let headers
	test.beforeEach(async ({ page }) => {
		await page.goto('/')
		await login(page, { username: process.env.NC_EMPLOYEE_USER, password: process.env.NC_EMPLOYEE_PASS })
		headers = { requesttoken: await getRequestToken(page), 'Content-Type': 'application/json' }
	})

	test('control: own absence is readable', async ({ page }) => {
		const r = await page.request.get(`${BASE}/api/absences/4818`, { headers })
		expect(r.status()).toBe(200)
	})

	test('other-user absence reads/decides/deletes collapse to uniform 404', async ({ page }) => {
		for (const [method, url] of [
			['GET', `${BASE}/api/absences/4769`],
			['POST', `${BASE}/api/absences/4769/approve`],
			['DELETE', `${BASE}/api/absences/4769`],
			['GET', `${BASE}/absences/4769`], // page route
		]) {
			const r = await page.request.fetch(`${BASE.slice(0)}${''}` === '' ? url.replace('/index.php', '') : url, {
				method,
				headers,
				data: method === 'GET' ? undefined : {},
			})
			console.log(`${method} ${url} -> ${r.status()}`)
			expect([404], `expected uniform 404 for ${method} ${url}, got ${r.status()} body=${(await r.text()).slice(0, 200)}`).toContain(r.status())
		}
	})

	test('other-user time entry read collapses to 404', async ({ page }) => {
		const r = await page.request.get(`${BASE}/api/time-entries/9430`, { headers })
		console.log(`GET api/time-entries/9430 -> ${r.status()}`)
		expect([404]).toContain(r.status())
	})

	test('admin + manager surfaces deny employee', async ({ page }) => {
		for (const [method, url] of [
			['GET', `${BASE}/api/admin/absence-types`],
			['GET', `${BASE}/api/admin/teams`],
			['GET', `${BASE}/api/manager/people-overview`],
			['GET', `${BASE}/api/manager/revision-pdf/users`],
		]) {
			const r = await page.request.fetch(url, { method, headers })
			console.log(`${method} ${url} -> ${r.status()}`)
			expect([403, 404], `expected denial for ${url}, got ${r.status()} body=${(await r.text()).slice(0, 200)}`).toContain(r.status())
		}
	})
})
