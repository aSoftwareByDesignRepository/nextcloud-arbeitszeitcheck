// @ts-check
import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const nextcloudRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../..')

/** Selectable NC user themes (theming app theme ids). */
export const USER_THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']

/**
 * Repair corrupted theming prefs (int `0` vs JSON `[]`) that make OCS theme
 * enable/disable return HTTP 500 (TypeConflictException).
 * @param {string} userId
 */
export function repairUserThemePrefs(userId) {
	if (!userId) return
	for (const app of ['theming', 'accessibility']) {
		try {
			occ(['user:setting', userId, app, 'enabled-themes', '--delete'])
		} catch {
			/* absent is fine */
		}
		try {
			occ(['user:setting', userId, app, 'enabled-themes', '[]'])
		} catch {
			/* best-effort */
		}
	}
}

/**
 * Enable exactly one user theme through Nextcloud's OCS theming API, then
 * reload and wait for body[data-theme-*] to prove the switch landed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} themeId
 */
export async function setUserTheme(page, themeId) {
	const attempt = async () => page.evaluate(async ({ target, all }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
				method: 'DELETE', credentials: 'same-origin', headers,
			})
			if (!res.ok && res.status !== 400) {
				problems.push(`disable ${id}: HTTP ${res.status}`)
			}
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, {
			method: 'PUT', credentials: 'same-origin', headers,
		})
		if (!res.ok && res.status !== 400) {
			problems.push(`enable ${target}: HTTP ${res.status}`)
		}
		return problems
	}, { target: themeId, all: USER_THEMES })

	let failures = await attempt()
	if (failures.some((f) => /HTTP 500/.test(f))) {
		const userId = await page.evaluate(() => window.OC?.currentUser || document.querySelector('head[data-user]')?.getAttribute('data-user') || '')
		repairUserThemePrefs(userId)
		await page.reload({ waitUntil: 'domcontentloaded' })
		failures = await attempt()
	}
	if (failures.length > 0) {
		throw new Error(`Theme switch to "${themeId}" failed: ${failures.join('; ')}`)
	}
	await page.reload({ waitUntil: 'domcontentloaded' })
	await page.waitForSelector(`body[data-theme-${themeId}]`, { timeout: 15_000 })
}

/**
 * @param {import('@playwright/test').Page} page
 */
export async function resetUserTheme(page) {
	await page.evaluate(async (all) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		for (const id of all) {
			await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
				method: 'DELETE', credentials: 'same-origin', headers,
			}).catch(() => {})
		}
	}, USER_THEMES)
	await page.reload({ waitUntil: 'domcontentloaded' })
}

/** @param {string[]} occArgs */
function occ(occArgs) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...occArgs,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 })
}

/** @param {string} hexColor e.g. '#B02E1C' */
export function setAccentColor(hexColor) {
	occ(['theming:config', 'primary_color', hexColor])
}

export function resetAccentColor() {
	try {
		occ(['theming:config', '--reset', 'primary_color'])
	} catch {
		occ(['config:app:delete', 'theming', 'primary_color'])
		try {
			occ(['theming:config', 'cachebuster', String(Date.now())])
		} catch {
			/* best-effort */
		}
	}
}
