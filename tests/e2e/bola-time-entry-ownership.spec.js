/**
 * Live BOLA: attacker must not read/update/delete another user's time entry via API.
 */
import { test, expect } from '@playwright/test'
import { login, credsFromEnv, hasCreds } from './helpers/auth.js'
import { api, apiAllowFailure, getRequestToken } from './helpers/api.js'
import { assertArbeitszeitcheckLoaded } from './helpers/app-config.js'

const ATTACKER = {
	username: process.env.NC_BOLA_ATTACKER_USER || 'azc_bola_b',
	password: process.env.NC_BOLA_ATTACKER_PASS || 'AzcBolaB_T3st!',
}

test.describe('BOLA time-entry ownership (live)', () => {
	test('attacker cannot show/update/delete victim entry', async ({ browser }) => {
		test.skip(!hasCreds('EMPLOYEE'), 'Requires NC_EMPLOYEE_USER / NC_EMPLOYEE_PASS')

		const victimCtx = await browser.newContext()
		const victim = await victimCtx.newPage()
		await login(victim, credsFromEnv('EMPLOYEE'))
		await victim.goto('/apps/arbeitszeitcheck/dashboard')
		await assertArbeitszeitcheckLoaded(victim)
		await getRequestToken(victim)

		const status = await api(victim, 'GET', '/apps/arbeitszeitcheck/api/clock/status')
		const serverNow = status.status?.server_now || ''
		const date = String(serverNow).slice(0, 10)
		expect(date).toMatch(/^\d{4}-\d{2}-\d{2}$/)

		// Avoid collisions with today's live clock/manual blocks — probe free evening slots.
		const slots = [
			['18:05', '18:25'],
			['18:35', '18:55'],
			['19:05', '19:25'],
			['19:35', '19:55'],
			['20:05', '20:25'],
			['21:05', '21:25'],
			['22:05', '22:25'],
		]
		let created = { ok: false, json: {} }
		for (const [startTime, endTime] of slots) {
			created = await apiAllowFailure(victim, 'POST', '/apps/arbeitszeitcheck/api/time-entries', {
				data: { date, startTime, endTime },
			})
			if (created.ok) {
				break
			}
		}
		test.skip(!created.ok, 'Could not seed victim entry: ' + JSON.stringify(created.json))
		const entryId =
			created.json?.data?.id ?? created.json?.id ?? created.json?.entry?.id ?? null
		expect(entryId).toBeTruthy()

		try {
			const attackerCtx = await browser.newContext()
			const attacker = await attackerCtx.newPage()
			await login(attacker, ATTACKER)
			await attacker.goto('/apps/arbeitszeitcheck/dashboard')
			await assertArbeitszeitcheckLoaded(attacker)
			await getRequestToken(attacker)

			const show = await apiAllowFailure(
				attacker,
				'GET',
				`/apps/arbeitszeitcheck/api/time-entries/${entryId}`,
			)
			expect(show.status, JSON.stringify(show.json)).toBe(403)
			expect(show.json?.success).toBeFalsy()

			const upd = await apiAllowFailure(
				attacker,
				'PUT',
				`/apps/arbeitszeitcheck/api/time-entries/${entryId}`,
				{ data: { date, startTime: '10:00', endTime: '11:00' } },
			)
			expect(upd.status, JSON.stringify(upd.json)).toBe(403)
			expect(upd.json?.success).toBeFalsy()

			const del = await apiAllowFailure(
				attacker,
				'DELETE',
				`/apps/arbeitszeitcheck/api/time-entries/${entryId}`,
			)
			expect(del.status, JSON.stringify(del.json)).toBe(403)
			expect(del.json?.success).toBeFalsy()

			const own = await api(victim, 'GET', `/apps/arbeitszeitcheck/api/time-entries/${entryId}`)
			expect(own?.success).toBeTruthy()
			await attackerCtx.close()
		} finally {
			await apiAllowFailure(victim, 'DELETE', `/apps/arbeitszeitcheck/api/time-entries/${entryId}`)
			await victimCtx.close()
		}
	})
})
