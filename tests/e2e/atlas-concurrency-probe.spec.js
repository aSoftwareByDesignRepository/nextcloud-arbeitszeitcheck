import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { getRequestToken } from './helpers/api.js'

const BASE = '/index.php/apps/arbeitszeitcheck'

// ATLAS Lens-1 probe: real concurrent double clock-in.
// Ground truth = HTTP statuses; DB verification done externally.
test('concurrent double clock-in does not double-apply', async ({ page }) => {
	await page.goto('/')
	await login(page, { username: process.env.NC_EMPLOYEE_USER, password: process.env.NC_EMPLOYEE_PASS })
	const requesttoken = await getRequestToken(page)
	const headers = { requesttoken, 'Content-Type': 'application/json' }

	// ensure clean state: clock out if open
	await page.request.post(`${BASE}/api/clock/out`, { headers, data: {} }).catch(() => {})

	const [r1, r2] = await Promise.all([
		page.request.post(`${BASE}/api/clock/in`, { headers, data: {} }),
		page.request.post(`${BASE}/api/clock/in`, { headers, data: {} }),
	])
	const statuses = [r1.status(), r2.status()].sort()
	console.log('clock-in statuses:', JSON.stringify(statuses))
	const j1 = await r1.json().catch(() => null)
	const j2 = await r2.json().catch(() => null)
	console.log('r1:', JSON.stringify(j1)?.slice(0, 300))
	console.log('r2:', JSON.stringify(j2)?.slice(0, 300))
	expect(statuses).not.toEqual([200, 200].sort())

	// concurrent double clock-out
	const [o1, o2] = await Promise.all([
		page.request.post(`${BASE}/api/clock/out`, { headers, data: {} }),
		page.request.post(`${BASE}/api/clock/out`, { headers, data: {} }),
	])
	const os = [o1.status(), o2.status()].sort()
	console.log('clock-out statuses:', JSON.stringify(os))
	const oj1 = await o1.json().catch(() => null)
	const oj2 = await o2.json().catch(() => null)
	console.log('o1:', JSON.stringify(oj1)?.slice(0, 300))
	console.log('o2:', JSON.stringify(oj2)?.slice(0, 300))
	expect(os).not.toEqual([200, 200].sort())
})
