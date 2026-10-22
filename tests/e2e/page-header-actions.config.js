import { defineConfig, devices } from '@playwright/test'

/** Isolated from the shared Nextcloud suite: this spec never logs in. */
export default defineConfig({
	testDir: '.',
	testMatch: 'page-header-actions.spec.js',
	timeout: 30_000,
	retries: 0,
	use: {
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel: 'chrome' } },
	],
})
