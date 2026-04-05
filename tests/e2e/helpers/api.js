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
  const res = await page.request.fetch(fullUrl, {
    method,
    headers,
    data: data ?? undefined,
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
  const res = await page.request.fetch(fullUrl, {
    method,
    headers,
    data: data ?? undefined,
  })

  const json = await res.json().catch(() => null)
  return { ok: res.ok(), status: res.status(), json }
}

