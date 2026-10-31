/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const src = readFileSync(join(here, 'navigation.js'), 'utf8')

function boot() {
	window.eval(src)
	document.dispatchEvent(new Event('DOMContentLoaded'))
}

function stubScroll(el, { scrollTop = 0, top = 0, height = 200 } = {}) {
	let value = scrollTop
	Object.defineProperty(el, 'scrollTop', {
		configurable: true,
		get: () => value,
		set: (next) => {
			value = next
		},
	})
	el.getBoundingClientRect = () => ({
		top,
		bottom: top + height,
		left: 0,
		right: 100,
		width: 100,
		height,
	})
}

describe('sidebar scroll', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		sessionStorage.clear()
	})

	it('remembers the menu scroll when a lower entry is opened', () => {
		document.body.innerHTML = `
			<div id="app-navigation">
				<ul class="nav-menu">
					<li><a href="/apps/arbeitszeitcheck/">Dashboard</a></li>
					<li><a id="employees" href="/apps/arbeitszeitcheck/admin/users">Employees</a></li>
				</ul>
			</div>`
		const menu = document.querySelector('.nav-menu')
		stubScroll(menu, { scrollTop: 420, top: 80, height: 300 })
		boot()

		const employees = document.getElementById('employees')
		employees.addEventListener('click', (event) => event.preventDefault())
		employees.click()

		expect(sessionStorage.getItem('azc-nav-menu-scroll')).toBe('420')
	})

	it('restores that scroll on the next page when the selection is already in view', () => {
		sessionStorage.setItem('azc-nav-menu-scroll', '420')
		document.body.innerHTML = `
			<div id="app-navigation">
				<ul class="nav-menu">
					<li class="active" aria-current="page">
						<a id="employees" href="/apps/arbeitszeitcheck/admin/users" aria-current="page">Employees</a>
					</li>
				</ul>
			</div>`
		const menu = document.querySelector('.nav-menu')
		stubScroll(menu, { scrollTop: 0, top: 80, height: 300 })
		document.getElementById('employees').getBoundingClientRect = () => ({
			top: 120,
			bottom: 160,
			left: 0,
			right: 100,
			width: 100,
			height: 40,
		})

		boot()

		expect(menu.scrollTop).toBe(420)
	})

	it('nudges the menu so a selection below the fold stays visible', () => {
		document.body.innerHTML = `
			<div id="app-navigation">
				<ul class="nav-menu">
					<li class="active">
						<a id="employees" href="/apps/arbeitszeitcheck/admin/users" aria-current="page">Employees</a>
					</li>
				</ul>
			</div>`
		const menu = document.querySelector('.nav-menu')
		stubScroll(menu, { scrollTop: 0, top: 80, height: 200 })
		document.getElementById('employees').getBoundingClientRect = () => ({
			top: 400,
			bottom: 440,
			left: 0,
			right: 100,
			width: 100,
			height: 40,
		})

		boot()

		expect(menu.scrollTop).toBe(160)
	})

	it('leaves a scroll the page already restored before paint', () => {
		sessionStorage.setItem('azc-nav-menu-scroll', '900')
		document.documentElement.classList.add('azc-nav-scroll-pending')
		document.body.innerHTML = `
			<div id="app-navigation">
				<ul class="nav-menu" data-azc-nav-scroll-ready="1">
					<li class="active"><a id="employees" href="/apps/arbeitszeitcheck/admin/users">Employees</a></li>
				</ul>
			</div>`
		const menu = document.querySelector('.nav-menu')
		stubScroll(menu, { scrollTop: 420, top: 80, height: 300 })

		boot()

		expect(menu.scrollTop).toBe(420)
		expect(document.documentElement.classList.contains('azc-nav-scroll-pending')).toBe(false)
	})
})

describe('sidebar scroll template', () => {
	const php = readFileSync(join(here, '../../templates/common/navigation.php'), 'utf8')

	it('holds the menu until the saved position is applied in the page itself', () => {
		const menu = php.indexOf('<ul class="nav-menu">')
		const hold = php.indexOf('azc-nav-scroll-pending')
		const apply = php.lastIndexOf('azc-nav-menu-scroll')
		expect(hold).toBeGreaterThan(-1)
		expect(hold).toBeLessThan(menu)
		expect(apply).toBeGreaterThan(menu)
		expect(php).toContain('azcNavScrollReady')
		expect(php).toContain("cspNonce")
	})
})
