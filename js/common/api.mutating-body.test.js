import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Mutation guard: AzcApi must never send bare POSTs without a JSON body.
 * Empty Content-Type/body pairs are a known cause of opaque HTTP 400 from
 * reverse proxies (same class as mobile clock-in failures).
 */

describe('AzcApi mutating request body', () => {
	beforeEach(async () => {
		vi.resetModules()
		delete globalThis.window.AzcApi
		globalThis.window.OC = { requestToken: 'tok-abc' }
		await import('./api.js')
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.window.AzcApi
	})

	it('sends JSON {} with Content-Type on bare POST', async () => {
		const fetchMock = vi.fn(async () => new Response(JSON.stringify({ success: true }), {
			status: 200,
			headers: { 'content-type': 'application/json' },
		}))
		globalThis.fetch = fetchMock

		const result = await globalThis.window.AzcApi.fetch('/apps/arbeitszeitcheck/api/clock/out', {
			method: 'POST',
			silent: true,
		})
		expect(result.ok).toBe(true)
		expect(fetchMock).toHaveBeenCalledTimes(1)
		const init = fetchMock.mock.calls[0][1]
		expect(init.method).toBe('POST')
		expect(init.body).toBe(JSON.stringify({ requesttoken: 'tok-abc' }))
		expect(new Headers(init.headers).get('Content-Type')).toBe('application/json')
	})

	it('attaches requesttoken on GET too — Nextcloud CSRF-checks all verbs', async () => {
		const fetchMock = vi.fn(async () => new Response(JSON.stringify({ success: true }), {
			status: 200,
			headers: { 'content-type': 'application/json' },
		}))
		globalThis.fetch = fetchMock

		await globalThis.window.AzcApi.fetch('/apps/arbeitszeitcheck/api/business-trips', { silent: true })
		const init = fetchMock.mock.calls[0][1]
		expect(new Headers(init.headers).get('requesttoken')).toBe('tok-abc')
	})

	it('preserves explicit json payloads', async () => {
		const fetchMock = vi.fn(async () => new Response(JSON.stringify({ success: true }), {
			status: 200,
			headers: { 'content-type': 'application/json' },
		}))
		globalThis.fetch = fetchMock

		await globalThis.window.AzcApi.fetch('/apps/arbeitszeitcheck/api/clock/in', {
			method: 'POST',
			json: { projectCheckProjectId: '7' },
			silent: true,
		})
		const init = fetchMock.mock.calls[0][1]
		expect(JSON.parse(init.body)).toEqual({ projectCheckProjectId: '7' })
	})
})
