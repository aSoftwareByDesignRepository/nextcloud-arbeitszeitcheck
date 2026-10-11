import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

test('Employee can open dashboard and see clock widget', async ({ page }) => {
  await login(page, credsFromEnv('EMPLOYEE'))
  await page.goto('/apps/arbeitszeitcheck/dashboard')
  await assertArbeitszeitcheckLoaded(page)

  // Dashboard should have a page title/heading area and clock actions somewhere.
  // Scope to real buttons: a page-level text locator can resolve to a hidden
  // notification ("Don't forget to clock out") before the button in DOM order.
  // The label also depends on the employee's stamp state (parallel specs can
  // leave the user active/on break/paused) — accept every possible action.
  await expect(page.locator('#app-content')).toBeVisible()
  await expect(
    page.locator('#app-content button').filter({
      hasText: /Clock in|Clock out|Start break|End break|Resume after break|Complete session/i,
    }).first()
  ).toBeVisible()
})

test('Health endpoint returns JSON', async ({ request }) => {
  const res = await request.get('/apps/arbeitszeitcheck/health')
  expect(res.ok()).toBeTruthy()
  const json = await res.json()
  expect(json).toHaveProperty('status')
  expect(json).toHaveProperty('timestamp')
})

test('Dashboard exposes timezone bootstrap for client timer', async ({ page }) => {
  await login(page, credsFromEnv('EMPLOYEE'))
  await page.goto('/apps/arbeitszeitcheck/dashboard')
  await assertArbeitszeitcheckLoaded(page)

  const bootstrap = await page.evaluate(() => ({
    storageTz: window.ArbeitszeitCheck?.tz?.storage || '',
    displayTz: window.ArbeitszeitCheck?.tz?.display || '',
    serverNow: window.ArbeitszeitCheck?.serverNow || '',
    hasTimeApi: Boolean(window.ArbeitszeitCheckTime?.parseInstant),
  }))

  expect(bootstrap.storageTz).not.toBe('')
  expect(bootstrap.displayTz).not.toBe('')
  expect(bootstrap.serverNow.length).toBeGreaterThan(10)
  expect(bootstrap.hasTimeApi).toBe(true)
})

test('GDPR export returns downloadable response when logged in', async ({ page }) => {
  await login(page, credsFromEnv('EMPLOYEE'))
  const res = await page.request.get('/apps/arbeitszeitcheck/gdpr/export')
  expect(res.ok()).toBeTruthy()
  const disposition = res.headers()['content-disposition'] || ''
  expect(disposition).toMatch(/attachment/i)
})

test('Month closure feature endpoint returns JSON when logged in', async ({ page }) => {
  await login(page, credsFromEnv('EMPLOYEE'))
  const res = await page.request.get('/apps/arbeitszeitcheck/api/month-closure/feature')
  expect(res.ok()).toBeTruthy()
  const json = await res.json()
  expect(json).toHaveProperty('enabled')
  expect(typeof json.enabled).toBe('boolean')
})

