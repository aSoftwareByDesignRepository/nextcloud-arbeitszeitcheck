/**
 * Admin settings JavaScript entry point for ArbeitszeitCheck
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import AdminSettings from './AdminSettings.vue'

const mountPoint = document.querySelector('#arbeitszeitcheck-admin-settings')
if (mountPoint) {
	const app = createApp(AdminSettings)
	app.mount(mountPoint)
} else {
	console.error('Mount point #arbeitszeitcheck-admin-settings not found')
}