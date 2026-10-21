/**
 * @vitest-environment jsdom
 */
import { describe, expect, it } from 'vitest'

import './table-pager.js'

const pager = window.AzcTablePager

describe('table pager window', () => {
	it('offers 10, 25, 50 and 100, and defaults to 25', () => {
		expect(pager.DEFAULT_SIZE).toBe(25)
		expect(pager.SIZES).toEqual([10, 25, 50, 100])
		expect(pager.perPage(null)).toBe(25)
		expect(pager.perPage('15')).toBe(25)
		expect(pager.perPage('25.0')).toBe(25)
		expect(pager.perPage('2e1')).toBe(25)
		expect(pager.perPage(' 25')).toBe(25)
		expect(pager.perPage('10')).toBe(10)
		expect(pager.perPage(50)).toBe(50)
		expect(pager.perPage('100')).toBe(100)
	})

	it('matches the footer example on the first page of eight', () => {
		expect(pager.items(1, 8)).toEqual([1, 2, 3, 4, pager.ELLIPSIS, 8])
		expect(pager.items(5, 8)).toEqual([1, pager.ELLIPSIS, 5, 6, 7, 8])
		expect(pager.items(6, 12)).toEqual([1, pager.ELLIPSIS, 5, 6, 7, pager.ELLIPSIS, 12])
		expect(pager.items(8, 8)).toEqual([1, pager.ELLIPSIS, 5, 6, 7, 8])
		expect(pager.items(1, 7)).toEqual([1, 2, 3, 4, 5, 6, 7])
	})

	it('keeps filters in the link and omits the default page', () => {
		const nav = document.createElement('div')
		nav.dataset.base = '/apps/arbeitszeitcheck/absences'
		nav.dataset.filters = 'start_date=2026-01-01&status=pending'
		expect(pager.hrefFor(nav, 1, 25)).toBe('/apps/arbeitszeitcheck/absences?start_date=2026-01-01&status=pending')
		expect(pager.hrefFor(nav, 2, 10)).toBe('/apps/arbeitszeitcheck/absences?start_date=2026-01-01&status=pending&per_page=10&page=2')
		nav.dataset.filters = ''
		expect(pager.hrefFor(nav, 1, 25)).toBe('/apps/arbeitszeitcheck/absences')
	})

	it('shows one page of rows and moves the total with the selection', () => {
		const rowHtml = Array.from({ length: 11 }, (_, index) => `<tr data-absence-id="${index + 1}"></tr>`).join('')
		document.body.innerHTML = `
			<table id="absences-table"><tbody>${rowHtml}</tbody></table>
			<div id="absences-table-pager" class="azc-table-pager"
				data-table="absences-table"
				data-base="/apps/arbeitszeitcheck/absences"
				data-filters=""
				data-page="1"
				data-per-page="10"
				data-total-template="Total %s"
				data-page-of-template="Page %1$s of %2$s"
				data-page-label="Page %s"
				data-prev-label="Previous"
				data-next-label="Next">
				<p data-pager-total></p>
				<nav data-pager-pages></nav>
				<p data-pager-live></p>
			</div>`
		const nav = document.getElementById('absences-table-pager')
		pager.showPage(nav, 1, false)
		const rows = document.querySelectorAll('#absences-table tbody tr')
		expect(rows[0].hidden).toBe(false)
		expect(rows[9].hidden).toBe(false)
		expect(rows[10].hidden).toBe(true)
		expect(nav.querySelector('[data-pager-total]').textContent).toBe('Total 11')
		expect(nav.querySelector('[aria-current="page"]').textContent).toBe('1')
		expect(nav.querySelector('[data-pager-live]').textContent).toBe('Page 1 of 2')

		rows[10].remove()
		pager.resync(nav)
		expect(document.querySelectorAll('#absences-table tbody tr[hidden]').length).toBe(0)
		expect(nav.querySelector('[data-pager-total]').textContent).toBe('Total 10')
		expect(nav.querySelector('[data-pager-live]').textContent).toBe('Page 1 of 1')
	})

	it('keeps a second table on its own page key and ignores an empty sentence', () => {
		window.history.replaceState({}, '', '/apps/arbeitszeitcheck/compliance?userId=ada')
		document.body.innerHTML = `
			<table id="violations-table"><tbody>
				<tr data-azc-row></tr>
				<tr data-azc-row></tr>
				<tr><td class="empty-state">Great</td></tr>
			</tbody></table>
			<div id="violations-table-pager" class="azc-table-pager"
				data-table="violations-table" data-base="/apps/arbeitszeitcheck/compliance"
				data-filters="userId=ada" data-page="1" data-per-page="25"
				data-page-param="v_page" data-size-param="v_per_page" data-mode="client"
				data-hash="compliance-violations" data-rows="tbody tr[data-azc-row]"></div>
			<div id="type-pager" class="azc-table-pager"
				data-table="missing" data-base="/apps/arbeitszeitcheck/compliance"
				data-filters="" data-page="3" data-per-page="10"
				data-page-param="t_page" data-size-param="t_per_page"></div>`
		const nav = document.getElementById('violations-table-pager')
		expect(pager.hrefFor(nav, 2, 25)).toBe('/apps/arbeitszeitcheck/compliance?userId=ada&v_page=2&t_per_page=10&t_page=3#compliance-violations')
		pager.showPage(nav, 1, false)
		expect(nav.hidden).toBe(false)
		expect(document.querySelectorAll('#violations-table tbody tr[data-azc-row]').length).toBe(2)
		nav.dataset.perPage = '10'
		pager.showPage(nav, 1, false)
		expect(document.querySelector('#violations-table tbody tr[data-azc-row][hidden]')).toBeNull()

		document.querySelector('#violations-table tbody').innerHTML = '<tr><td class="empty-state">None</td></tr>'
		pager.resync(nav)
		expect(nav.hidden).toBe(true)
	})

	it('changes page size without a reload when the rows were just replaced', () => {
		document.body.innerHTML = `
			<table id="rows"><tbody>
				${Array.from({ length: 11 }, () => '<tr data-azc-row></tr>').join('')}
			</tbody></table>
			<div id="rows-pager" class="azc-table-pager" data-table="rows" data-base="/list"
				data-filters="" data-page="2" data-per-page="25" data-mode="client"
				data-page-param="Page" data-total-template="Total %s" data-page-of-template="Page %1$s of %2$s"
				data-page-label="Page %s">
				<form><select name="per_page"><option value="10">10/page</option></select></form>
				<nav data-pager-pages></nav>
				<p data-pager-total></p>
				<p data-pager-live></p>
			</div>`
		const nav = document.getElementById('rows-pager')
		pager.attach(nav)
		nav.querySelector('select').value = '10'
		nav.querySelector('select').dispatchEvent(new Event('change'))
		expect(document.getElementById('rows')).not.toBeNull()
		expect(nav.dataset.perPage).toBe('10')
		expect(nav.dataset.page).toBe('1')
		expect(document.querySelectorAll('#rows tbody tr[hidden]').length).toBe(1)
		expect(nav.querySelector('[data-pager-live]').textContent).toBe('Page 1 of 2')
	})
})
