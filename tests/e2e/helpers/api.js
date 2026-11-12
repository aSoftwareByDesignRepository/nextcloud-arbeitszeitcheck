import { expect } from '@playwright/test'

export async function getRequestToken(page) {
  // A just-triggered navigation can destroy the JS execution context between
  // waitForLoadState and this evaluate (e.g. a dialog-accept that reloads the
  // page). The token read is read-only, so retrying after the page settles is
  // safe and removes the recurring "Execution context was destroyed" flake.
  let lastErr
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const token = await page.evaluate(() => {
        return (
          (typeof window !== 'undefined' && window.OC && window.OC.requestToken) ||
          (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) ||
          ''
        )
      })
      if (!token) {
        throw new Error('Could not determine request token')
      }
      return token
    } catch (e) {
      lastErr = e
      if (!/Execution context was destroyed|frame was detached|Target closed/i.test(String(e?.message ?? e))) {
        throw e
      }
      await page.waitForLoadState('load').catch(() => {})
      await page.waitForLoadState('domcontentloaded').catch(() => {})
    }
  }
  throw lastErr
}

export async function api(page, method, url, { data } = {}) {
  const requesttoken = await getRequestToken(page)
  const headers = { requesttoken, 'Content-Type': 'application/json' }

  const fullUrl = new URL(url, page.url()).toString()
  const upper = String(method || 'GET').toUpperCase()
  const mutating = upper === 'POST' || upper === 'PUT' || upper === 'PATCH' || upper === 'DELETE'
  const res = await page.request.fetch(fullUrl, {
    method,
    headers,
    // Always send a JSON body for mutating methods (proxy-safe), even when empty.
    data: data !== undefined ? data : (mutating ? {} : undefined),
  })

  const json = await res.json().catch(() => null)
  if (!res.ok()) {
    const msg = json?.error || `${method} ${url} failed (${res.status()})`
    throw new Error(msg)
  }
  expect(json).not.toBeNull()
  return json
}

export async function apiAllowFailure(page, method, url, { data } = {}) {
  const requesttoken = await getRequestToken(page)
  const headers = { requesttoken, 'Content-Type': 'application/json' }

  const fullUrl = new URL(url, page.url()).toString()
  const upper = String(method || 'GET').toUpperCase()
  const mutating = upper === 'POST' || upper === 'PUT' || upper === 'PATCH' || upper === 'DELETE'
  const res = await page.request.fetch(fullUrl, {
    method,
    headers,
    data: data !== undefined ? data : (mutating ? {} : undefined),
  })

  const json = await res.json().catch(() => null)
  return { ok: res.ok(), status: res.status(), json }
}

