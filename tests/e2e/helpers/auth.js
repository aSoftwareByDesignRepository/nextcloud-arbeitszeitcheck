import { test } from '@playwright/test'
import { assertNcReady, gotoApp } from './auth-guard.js'

export { assertNcReady, gotoApp } from './auth-guard.js'

export function hasCreds(role) {
	const u = process.env[`NC_${role}_USER`]
	const p = process.env[`NC_${role}_PASS`]
	return Boolean(u && p)
}

export function skipIfMissingCreds(role) {
	if (!hasCreds(role)) {
		test.skip(
			true,
			`Missing NC_${role}_USER / NC_${role}_PASS. Copy tests/e2e/.env.example to tests/e2e/.env (dev/CI only).`,
		)
	}
}

export async function login(page, { username, password }) {
	for (let attempt = 1; attempt <= 2; attempt++) {
		await page.goto('/login')

		const upgradeHeading = page.getByRole('heading', {
			name: /app update required|update needed|aktualisierung (erforderlich|benötigt)/i,
		})
		if (await upgradeHeading.isVisible({ timeout: 1500 }).catch(() => false)) {
			throw new Error(
				'Nextcloud shows Update needed on /login. From nextcloud/: docker compose exec -u www-data nextcloud php occ upgrade',
			)
		}

		const userInput = page
			.getByRole('textbox', { name: /account name|email|benutzername|e-mail/i })
			.or(page.locator('#user'))
			.or(page.locator('input[name="user"]'))
			.first()
		const passInput = page
			.getByRole('textbox', { name: /^password$|^passwort$/i })
			.or(page.locator('#password'))
			.or(page.locator('input[name="password"]'))
			.first()

		await userInput.waitFor({ state: 'visible', timeout: 20_000 })
		await userInput.fill(username)
		await passInput.fill(password)

		const submit = page
			.getByRole('button', { name: /^log in$|^anmelden$/i })
			.or(page.locator('button[type="submit"]'))
			.first()
		await submit.click()

		try {
			await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 })
			break
		} catch (e) {
			// Transient NC flakes under parallel load: rapid re-login can land on
			// a stale-requesttoken form and bounce back to /login with a "Session
			// error" banner, or the login POST can be dropped/slow server-side.
			// A fresh GET re-mints the token — one retry covers both; real auth
			// failures fail identically on the second attempt.
			const tooMany = await page
				.getByRole('heading', { name: /too many requests|zu viele anfragen/i })
				.isVisible({ timeout: 1000 })
				.catch(() => false)
			if (tooMany) {
				throw new Error(
					'Login hit Nextcloud brute-force throttling — relax it for e2e: ' +
						'docker compose exec nextcloud php occ config:system:set ' +
						'auth.bruteforce.protection.testing --value=true --type=boolean; ' +
						'auth.bruteforce.max-attempts --value=100000 --type=integer',
				)
			}
			if (attempt === 1) {
				continue
			}
			throw e
		}
	}
	// networkidle is not reliable here — the NC dashboard keeps long-polling
	// requests open, so it can never quiesce under parallel load. 'load' plus
	// assertNcReady's app-shell check is the real readiness signal.
	await page.waitForLoadState('load')
	await assertNcReady(page)
}

export async function loginAs(page, role) {
	skipIfMissingCreds(role)
	await login(page, credsFromEnv(role))
}

export function credsFromEnv(role) {
	const u = process.env[`NC_${role}_USER`]
	const p = process.env[`NC_${role}_PASS`]
	if (!u || !p) {
		throw new Error(
			`Missing NC_${role}_USER / NC_${role}_PASS (set in tests/e2e/.env for local E2E)`,
		)
	}
	return { username: u, password: p }
}
