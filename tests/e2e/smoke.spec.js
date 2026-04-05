import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test('Employee can open dashboard and see clock widget', async ({ page }) => {
  await login(page, credsFromEnv('EMPLOYEE'))
  await page.goto('/apps/arbeitszeitcheck/dashboard')
  await assertArbeitszeitcheckLoaded(page)

  // Dashboard should have a page title/heading area and clock actions somewhere.
  await expect(page.locator('#app-content')).toBeVisible()
  await expect(page.locator('text=/Clock in|Clock out|Start break|End break/i').first()).toBeVisible()
})

test('Health endpoint returns JSON', async ({ request }) => {
  const res = await request.get('/apps/arbeitszeitcheck/health')
  expect(res.ok()).toBeTruthy()
  const json = await res.json()
  expect(json).toHaveProperty('status')
  expect(json).toHaveProperty('timestamp')
})

