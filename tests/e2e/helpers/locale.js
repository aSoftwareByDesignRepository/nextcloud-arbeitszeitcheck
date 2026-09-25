// @ts-check
import { execFileSync } from 'child_process'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const nextcloudRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../..')

/** Supported ArbeitszeitCheck web locales per appinfo/info.xml / l10n catalog. */
export const SUPPORTED_LOCALES = [
	'en',
	'de',
	'fr',
	'es',
	'da',
	'nl',
	'it',
	'pl',
	'sv',
	'nb',
	'pt_BR',
]

/** Nextcloud core user-language setting key. */
const CORE_LANG_SETTING = 'core'
const LANG_APP = 'lang'

/** @param {string[]} occArgs */
function occ(occArgs) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...occArgs,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 })
}

/**
 * Set the Nextcloud user language for the given user id.
 * @param {string} userId
 * @param {string} locale
 */
export function setUserLanguage(userId, locale) {
	occ(['user:setting', userId, CORE_LANG_SETTING, LANG_APP, locale])
}

/**
 * Reset the user language to English (en).
 * @param {string} userId
 */
export function resetUserLanguage(userId) {
	try {
		occ(['user:setting', userId, CORE_LANG_SETTING, LANG_APP, 'en'])
	} catch {
		try {
			occ(['user:setting', '--delete', userId, CORE_LANG_SETTING, LANG_APP])
		} catch {
			/* best-effort cleanup */
		}
	}
}

/**
 * Best-effort current user id from Nextcloud's page state.
 * @param {import('@playwright/test').Page} page
 */
export async function getCurrentUserId(page) {
	return page.evaluate(() => {
		if (typeof window !== 'undefined' && window.OC?.currentUser) {
			return window.OC.currentUser
		}
		const meta = document.querySelector('head[data-user]')
		if (meta) {
			return meta.getAttribute('data-user') ?? ''
		}
		const userInput = document.querySelector('input#user[name="user"]')
		return userInput instanceof HTMLInputElement ? userInput.value : ''
	})
}
