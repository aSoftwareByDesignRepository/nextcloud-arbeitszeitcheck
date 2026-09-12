/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const src = readFileSync(join(here, 'mobile-nav.js'), 'utf8')

function bootMobileNav() {
	// Fresh eval in window scope (IIFE attaches listeners on DOMContentLoaded / load).
	window.eval(src)
	document.dispatchEvent(new Event('DOMContentLoaded'))
	window.dispatchEvent(new Event('load'))
}

describe('azc mobile-nav (GH #33)', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		document.body.className = ''
		vi.stubGlobal('matchMedia', (query) => ({
			matches: String(query).includes('min-width: 1024') ? false : false,
			media: query,
			addEventListener: () => {},
			removeEventListener: () => {},
			addListener: () => {},
			removeListener: () => {},
		}))
	})

	it('opens drawer from in-page Menu and portals nav to body', () => {
		document.body.innerHTML = `
			<div id="content" class="app-arbeitszeitcheck">
				<div id="arbeitszeitcheck-app">
					<nav id="app-navigation" class="azc-nav"></nav>
					<div id="app-content" class="azc-app">
						<div id="app-content-wrapper" class="azc-shell">
							<button type="button" id="azc-nav-toggle" aria-expanded="false"
								data-aria-label-open="Open" data-aria-label-close="Close">Menu</button>
							<a href="/apps/arbeitszeitcheck/dashboard">Dashboard</a>
						</div>
					</div>
				</div>
			</div>
			<button type="button" id="app-navigation-toggle">core</button>
		`
		const nav = document.getElementById('app-navigation')
		nav.innerHTML = '<ul class="nav-menu"><li><a href="/apps/arbeitszeitcheck/dashboard">Dashboard</a></li></ul>'

		bootMobileNav()

		const toggle = document.getElementById('azc-nav-toggle')
		const core = document.getElementById('app-navigation-toggle')
		expect(toggle).toBeTruthy()
		expect(core.style.display).toBe('none')

		toggle.click()
		expect(toggle.getAttribute('aria-expanded')).toBe('true')
		expect(nav.classList.contains('azc-nav--open')).toBe(true)
		expect(document.body.classList.contains('azc-nav-open')).toBe(true)
		expect(nav.parentElement).toBe(document.body)

		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		expect(toggle.getAttribute('aria-expanded')).toBe('false')
		expect(nav.classList.contains('azc-nav--open')).toBe(false)

		// Tear down MutationObserver work by removing nodes before next test.
		document.body.innerHTML = ''
	})
})
