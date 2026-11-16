import { expect } from '@playwright/test'

export async function getRequestToken(page) {
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
}

export async function api(page, method, url, { data } = {}) {
  const requesttoken = await getRequestToken(page)
  const headers = { requesttoken, 'Content-Type': 'application/json' }

  const fullUrl = new URL(url, page.url()).toString()
  const upper = String(method || 'GET').toUpperCase()
  const mutating = upper === 'POST' || upper === 'PUT' || upper === 'PATCH' || upper === 'DELETE'
  // 423 = designed retryable busy: parallel specs share fixture users, and
  // same-user+same-month mutations serialize on the user-month lock.
  let res
  for (let attempt = 0; attempt < 3; attempt++) {
    res = await page.request.fetch(fullUrl, {
      method,
      headers,
      // Always send a JSON body for mutating methods (proxy-safe), even when empty.
      data: data !== undefined ? data : (mutating ? {} : undefined),
    })
    if (res.status() !== 423 || attempt === 2) {
      break
    }
    await new Promise((r) => setTimeout(r, 250 * (attempt + 1)))
  }

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
  // Same 423 retryable-busy handling as api() — transient lock contention
  // between specs must not masquerade as the failure a test asserts.
  let res
  for (let attempt = 0; attempt < 3; attempt++) {
    res = await page.request.fetch(fullUrl, {
      method,
      headers,
      data: data !== undefined ? data : (mutating ? {} : undefined),
    })
    if (res.status() !== 423 || attempt === 2) {
      break
    }
    await new Promise((r) => setTimeout(r, 250 * (attempt + 1)))
  }

  const json = await res.json().catch(() => null)
  return { ok: res.ok(), status: res.status(), json }
}

