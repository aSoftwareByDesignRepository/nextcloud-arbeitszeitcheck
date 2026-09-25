import { defineConfig, devices } from '@playwright/test'
import { existsSync, readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const configDir = dirname(fileURLToPath(import.meta.url))
const envFile = resolve(configDir, 'tests/e2e/.env')
if (existsSync(envFile)) {
  for (const line of readFileSync(envFile, 'utf8').split('\n')) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) {
      continue
    }
    const eq = trimmed.indexOf('=')
    if (eq <= 0) {
      continue
    }
    const key = trimmed.slice(0, eq).trim()
    let value = trimmed.slice(eq + 1).trim()
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1)
    }
    if (process.env[key] === undefined) {
      process.env[key] = value
    }
  }
}

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081'

// Specs that persist shared server state — per-user prefs (theme/language),
// global app settings (approval, clock stamping, country/holidays), or shared
// entities (teams config, the shared employee profile). All specs share one
// Nextcloud instance and the same test users, so a mutation mid-run bleeds
// into unrelated parallel tests (e.g. approval flag flipped while another
// spec asserts the create form). These run serialized via project deps below.
const STATEFUL_SPECS = [
  /bachus-theme-responsive\.spec\.js/,
  /feedback-companion-theme-a11y\.spec\.js/,
  /paid-absence-theme-responsive\.spec\.js/,
  /theme-responsive-a11y\.spec\.js/,
  /locale-rendering-overflow-rawkey\.spec\.js/,
  /admin-dach-country-region\.spec\.js/,
  /admin-holidays-auto-restore\.spec\.js/,
  /admin-time-capture-org\.spec\.js/,
  /compliance-gate-smoke\.spec\.js/,
  /concurrent-pending-decision\.spec\.js/,
  /manual-create-pending-approval\.spec\.js/,
  /manual-entry-justification\.spec\.js/,
  /admin-teams-bulk-find-people\.spec\.js/,
  /admin-users-profile\.spec\.js/,
]

export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    // Specs that persist per-user server state (theme/language) must not
    // interleave with the parallel pool or with each other — they share the
    // same test users. Each runs as its own project chained via
    // `dependencies`, which serializes them after the parallel suite.
    // For targeted local runs use `npx playwright test --no-deps`.
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
      testIgnore: STATEFUL_SPECS,
    },
    ...STATEFUL_SPECS.map((match, i) => ({
      name: `stateful-${i}`,
      use: { ...devices['Desktop Chrome'] },
      testMatch: match,
      dependencies: [i === 0 ? 'chromium' : `stateful-${i - 1}`],
    })),
  ],
})

