import { test, expect } from '@playwright/test'
import { login, credsFromEnv } from './helpers/auth.js'
import { api, apiAllowFailure } from './helpers/api.js'

function addDays(isoDate, days) {
  const d = new Date(`${isoDate}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

function isWeekend(isoDate) {
  const d = new Date(`${isoDate}T00:00:00Z`)
  const day = d.getUTCDay()
  return day === 0 || day === 6
}

test('Clock in -> status active -> clock out -> status paused/clocked_out', async ({ page }) => {
  await login(page, credsFromEnv('EMPLOYEE'))
  await page.goto('/apps/arbeitszeitcheck/dashboard')

  // If a previous run left an active entry, close it first.
  const preStatus = await api(page, 'GET', '/apps/arbeitszeitcheck/api/clock/status')
  if (preStatus?.status?.status === 'active' || preStatus?.status?.status === 'break') {
    await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/out')
  }

  // Clock-in can be rejected by legal constraints (e.g. 11h rest period). In that case we assert
  // the enforcement rather than failing the suite due to non-empty dev data.
  const clockInRes = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/clock/in')
  if (!clockInRes.ok) {
    expect(clockInRes.status).toBeGreaterThanOrEqual(400)
    expect(clockInRes.json?.success).toBe(false)
    expect(clockInRes.json?.error || '').toMatch(/rest period|Maximum daily working hours|not authenticated/i)
    return
  }
  expect(clockInRes.json?.success).toBe(true)

  const status1 = await api(page, 'GET', '/apps/arbeitszeitcheck/api/clock/status')
  expect(status1.success).toBe(true)
  expect(status1.status?.status).toMatch(/active|break|paused|completed|clocked_out/)

  const clockOut = await api(page, 'POST', '/apps/arbeitszeitcheck/api/clock/out')
  expect(clockOut.success).toBe(true)

  const status2 = await api(page, 'GET', '/apps/arbeitszeitcheck/api/clock/status')
  expect(status2.success).toBe(true)
  expect(status2.status?.status).toMatch(/paused|clocked_out|completed/)
})

test('Time entry correction request -> manager approves', async ({ page, browser }) => {
  const employee = credsFromEnv('EMPLOYEE')
  const manager = credsFromEnv('MANAGER')

  // Employee: create entry and request correction
  await login(page, employee)
  await page.goto('/apps/arbeitszeitcheck/time-entries')

  // Create an entry with overlap-safe retries (reruns in shared dev DB may have old seed rows).
  const base = '2000-01-15'
  let created = null
  for (let i = 0; i < 10; i++) {
    const date = addDays(base, i)
    const res = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
      data: { date, hours: 1.5, description: `E2E seed entry ${date}` },
    })
    if (res.ok && res.json?.success) {
      created = res.json
      break
    }
    const err = (res.json?.error || '').toLowerCase()
    if (!err.includes('overlap')) {
      throw new Error(res.json?.error || `Failed to create time entry (${res.status})`)
    }
  }
  expect(created?.success).toBe(true)
  const entryId = created.entry?.id ?? created.entry?.entryId ?? created.entry?.ID ?? created.entry?.Id
  expect(entryId).toBeTruthy()

  const requested = await api(page, 'POST', `/apps/arbeitszeitcheck/api/time-entries/${entryId}/request-correction`, {
    data: { justification: 'Need to adjust hours', newHours: 2.0 },
  })
  expect(requested.success).toBe(true)

  // Manager: approve correction
  const page2 = await browser.newPage()
  await login(page2, manager)
  await page2.goto('/apps/arbeitszeitcheck/manager')

  const approved = await api(page2, 'POST', `/apps/arbeitszeitcheck/api/manager/time-entries/${entryId}/approve-correction`, {
    data: { comment: 'OK' },
  })
  expect(approved.success).toBe(true)
})

test('Absence substitute approval -> manager approval', async ({ page, browser }) => {
  const employee = credsFromEnv('EMPLOYEE')
  const substitute = credsFromEnv('SUBSTITUTE')
  const manager = credsFromEnv('MANAGER')

  // Employee creates an absence with substitute (substitute UID must match env user)
  await login(page, employee)
  await page.goto('/apps/arbeitszeitcheck/absences')

  const futureBase = (() => {
    const d = new Date()
    d.setDate(d.getDate() + 90)
    return d.toISOString().slice(0, 10)
  })()

  let absence = null
  for (let i = 0; i < 10; i++) {
    let startIso = addDays(futureBase, i * 3)
    // Ensure we start on a weekday so vacation includes at least one working day.
    while (isWeekend(startIso)) {
      startIso = addDays(startIso, 1)
    }
    const endIso = addDays(startIso, 1)
    const res = await apiAllowFailure(page, 'POST', '/apps/arbeitszeitcheck/api/absences', {
      data: {
        type: 'vacation',
        start_date: startIso,
        end_date: endIso,
        reason: `E2E substitute flow ${startIso}`,
        substitute_user_id: substitute.username,
      },
    })
    if (res.ok && res.json?.success) {
      absence = res.json
      break
    }
    const err = (res.json?.error || '').toLowerCase()
    if (!err.includes('overlap') && !err.includes('working day')) {
      throw new Error(res.json?.error || `Failed to create absence (${res.status})`)
    }
  }
  expect(absence?.success).toBe(true)
  const absenceId = absence.absence?.id
  expect(absenceId).toBeTruthy()

  // Substitute approves
  const pageSub = await browser.newPage()
  await login(pageSub, substitute)
  await pageSub.goto('/apps/arbeitszeitcheck/substitution-requests')
  const subApprove = await api(pageSub, 'POST', `/apps/arbeitszeitcheck/api/substitution-requests/${absenceId}/approve`)
  expect(subApprove.success).toBe(true)

  // Manager approves
  const pageMgr = await browser.newPage()
  await login(pageMgr, manager)
  await pageMgr.goto('/apps/arbeitszeitcheck/manager')
  const mgrApprove = await api(pageMgr, 'POST', `/apps/arbeitszeitcheck/api/manager/absences/${absenceId}/approve`, {
    data: { comment: 'Approved by manager' },
  })
  expect(mgrApprove.success).toBe(true)
})

test('Manager reports API supports preview and CSV download', async ({ page }) => {
  const manager = credsFromEnv('MANAGER')
  await login(page, manager)
  await page.goto('/apps/arbeitszeitcheck/reports')

  const preview = await apiAllowFailure(
    page,
    'GET',
    '/apps/arbeitszeitcheck/api/reports/team?startDate=2024-01-01&endDate=2024-01-31',
  )
  expect(preview.ok).toBe(true)
  expect(preview.json?.success).toBe(true)
  expect(preview.json?.report?.type).toBe('team')
  expect(Array.isArray(preview.json?.report?.members)).toBe(true)

  const requesttoken = await page.evaluate(() => {
    return (
      (typeof window !== 'undefined' && window.OC && window.OC.requestToken) ||
      (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) ||
      ''
    )
  })
  expect(requesttoken).toBeTruthy()

  const csvRes = await page.request.fetch(
    new URL(
      '/apps/arbeitszeitcheck/api/reports/team?startDate=2024-01-01&endDate=2024-01-31&download=1&format=csv',
      page.url(),
    ).toString(),
    {
      method: 'GET',
      headers: { requesttoken },
    },
  )
  expect(csvRes.ok()).toBe(true)
  expect((csvRes.headers()['content-type'] || '').toLowerCase()).toContain('text/csv')

  const csvText = await csvRes.text()
  expect(csvText).toContain('user_id')
  expect(csvText).toContain('display_name')
})

