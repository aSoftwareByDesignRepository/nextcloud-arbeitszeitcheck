/**
 * Audit log viewer JavaScript entry point for ArbeitszeitCheck
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import AuditLogViewer from './views/AuditLogViewer.vue'
import { translate } from '@nextcloud/l10n'

const mountPoint = document.querySelector('#arbeitszeitcheck-audit-log')
if (mountPoint) {
	const app = createApp(AuditLogViewer)
	// Wrap translate to always use app name and ensure all inputs are strings
	app.config.globalProperties.$t = function(appName, text, ...args) {
		try {
			// If called with just one argument, assume it's the text and use default app name
			if (arguments.length === 1) {
				const safeText = String(appName || '')
				const result = translate('arbeitszeitcheck', safeText)
				return result || safeText
			}
			// Otherwise use the provided app name or default
			const safeAppName = String(appName || 'arbeitszeitcheck')
			const safeText = String(text || '')
			const result = translate(safeAppName, safeText, ...args)
			return result || safeText
		} catch (e) {
			console.warn('Translation error:', e, { appName, text, args })
			return arguments.length === 1 ? String(appName || '') : String(text || '')
		}
	}
	app.mount(mountPoint)
} else {
	console.error('Mount point #arbeitszeitcheck-audit-log not found')
}
