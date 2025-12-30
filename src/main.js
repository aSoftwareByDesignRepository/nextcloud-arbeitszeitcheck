import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import { translate, translatePlural } from '@nextcloud/l10n'
import { setAppName } from '@nextcloud/vue'

import App from './App.vue'
import Dashboard from './views/Dashboard.vue'
import TimeEntries from './views/TimeEntries.vue'
import Absences from './views/Absences.vue'
import Reports from './views/Reports.vue'
import Calendar from './views/Calendar.vue'
import Timeline from './views/Timeline.vue'

// Global styles with proper isolation
import './styles/main.css'

// CRITICAL: Initialize @nextcloud/vue BEFORE creating the app
// @nextcloud/vue v9+ (for Vue 3) supports setAppName at runtime
// Also configured via DefinePlugin at build time as fallback
if (typeof setAppName === 'function') {
	setAppName('arbeitszeitcheck')
}

// Register global properties
const app = createApp(App)

// Add global properties with app name binding
// @nextcloud/l10n v3 uses translate(appName, text, ...args)
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
		// Return the text as fallback
		return arguments.length === 1 ? String(appName || '') : String(text || '')
	}
}
app.config.globalProperties.$n = (appName, singular, plural, count, ...args) => {
	try {
		const safeAppName = String(appName || 'arbeitszeitcheck')
		const safeSingular = String(singular || '')
		const safePlural = String(plural || '')
		const safeCount = count || 0
		const result = translatePlural(safeAppName, safeSingular, safePlural, safeCount, ...args)
		return result || (safeCount === 1 ? safeSingular : safePlural)
	} catch (e) {
		console.warn('Translation plural error:', e, { appName, singular, plural, count, args })
		return safeCount === 1 ? String(singular || '') : String(plural || '')
	}
}

// Create router
const router = createRouter({
	history: createWebHashHistory(),
	routes: [
		{
			path: '/',
			name: 'Dashboard',
			component: Dashboard,
			props: true
		},
		{
			path: '/time-entries',
			name: 'TimeEntries',
			component: TimeEntries,
			props: true
		},
		{
			path: '/absences',
			name: 'Absences',
			component: Absences,
			props: true
		},
		{
			path: '/reports',
			name: 'Reports',
			component: Reports,
			props: true
		},
		{
			path: '/calendar',
			name: 'Calendar',
			component: Calendar,
			props: true
		},
		{
			path: '/timeline',
			name: 'Timeline',
			component: Timeline,
			props: true
		}
	]
})

app.use(router)

// Mount the app
const mountPoint = document.querySelector('#arbeitszeitcheck-content')
if (mountPoint) {
	app.mount(mountPoint)
} else {
	console.error('ArbeitszeitCheck: Mount point #arbeitszeitcheck-content not found')
}