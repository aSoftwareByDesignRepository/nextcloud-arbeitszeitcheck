import { test, expect } from '@playwright/test'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const components = resolve(dirname(fileURLToPath(import.meta.url)), '../../js/common/components.js')

function shell(body) {
	return `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Header</title>
<style>
  body { margin: 0; font: 16px/1.4 sans-serif; }
  .azc-page-header__main { display: grid; grid-template-columns: 56px minmax(0, 1fr) auto; align-items: start; }
  .azc-page-header__actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
  .azc-page-header__actions:empty { display: none; }
  .azc-btn, .btn { display: inline-flex; align-items: center; min-height: 44px; min-width: 44px; padding: 0 1.15rem; white-space: nowrap; }
</style></head><body>${body}</body></html>`
}

test.use({ viewport: { width: 1100, height: 800 } })

test('controls already in the header stay in place when the page script runs', async ({ page }) => {
	await page.setContent(shell(`
    <div class="azc-page-header__main">
      <div></div>
      <h1>Absences</h1>
      <div id="azc-page-actions" class="azc-page-header__actions">
        <button id="act-a" class="azc-btn" type="button">Request time off</button>
        <button id="act-b" class="azc-btn" type="button">Filter</button>
      </div>
    </div>`), { waitUntil: 'domcontentloaded' })

	const beforeA = await page.locator('#act-a').boundingBox()
	const beforeB = await page.locator('#act-b').boundingBox()
	expect(beforeA).not.toBeNull()
	expect(beforeB).not.toBeNull()
	expect(beforeB.x).toBeGreaterThan(beforeA.x)

	await page.addScriptTag({ path: components })
	await page.evaluate(() => window.ArbeitszeitCheckComponents.relocatePageActions())

	const afterA = await page.locator('#act-a').boundingBox()
	const afterB = await page.locator('#act-b').boundingBox()
	expect(afterA).toEqual(beforeA)
	expect(afterB).toEqual(beforeB)
	expect(await page.locator('#azc-page-actions').getAttribute('aria-live')).toBeNull()
})

test('a leftover source is moved once, then the buttons hold still', async ({ page }) => {
	await page.setContent(shell(`
    <div class="azc-page-header__main">
      <div></div>
      <h1>Time entries</h1>
      <div id="azc-page-actions" class="azc-page-header__actions"></div>
    </div>
    <div class="header-actions">
      <button id="act-a" class="azc-btn" type="button">Add time entry</button>
      <button id="act-b" class="azc-btn" type="button">Filter</button>
      <button id="act-c" class="azc-btn" type="button">Download PDF</button>
    </div>`), { waitUntil: 'domcontentloaded' })

	await page.evaluate(() => {
		window.__appends = []
		const target = document.getElementById('azc-page-actions')
		const original = target.appendChild.bind(target)
		target.appendChild = (node) => {
			window.__appends.push(node.nodeType)
			return original(node)
		}
	})
	await page.addScriptTag({ path: components })

	const appends = await page.evaluate(() => window.__appends)
	const fragmentType = await page.evaluate(() => Node.DOCUMENT_FRAGMENT_NODE)
	expect(appends).toEqual([fragmentType])
	await expect(page.locator('#azc-page-actions button')).toHaveCount(3)
	await expect(page.locator('.header-actions')).toHaveCount(0)

	const placed = await page.locator('#act-a').boundingBox()
	await page.evaluate(() => window.ArbeitszeitCheckComponents.relocatePageActions())
	expect(await page.locator('#act-a').boundingBox()).toEqual(placed)
	expect(await page.evaluate(() => window.__appends.length)).toBe(1)
})
