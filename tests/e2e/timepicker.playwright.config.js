/**
 * Component spec only. The app Playwright config normalizes the shared
 * Nextcloud instance (passwords, brute-force) before every run. This file
 * does not, so the time picker can be checked without touching that instance.
 */
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
	testDir: '.',
	testMatch: 'timepicker.spec.js',
	timeout: 30_000,
	retries: 0,
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel: 'chrome' } },
	],
})
