/**
 * Collect desktop table layout metrics for issue #12 (inflexible width / clipped actions).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} tableSelector CSS selector for the data table
 * @returns {Promise<{
 *   shellIsWide: boolean,
 *   containerUsesShell: boolean,
 *   theadIsTableHeader: boolean,
 *   actionFullyVisible: boolean,
 *   actionReachableByScroll: boolean,
 *   hasActionButton: boolean,
 *   containerWidth: number,
 *   shellWidth: number,
 *   needsHorizontalScroll: boolean,
 * }>}
 */
export async function collectDesktopTableMetrics(page, tableSelector) {
	const metrics = await page.evaluate((sel) => {
		const table = document.querySelector(sel)
		const container = table?.closest('.table-container')
		const shell = document.getElementById('app-content-wrapper')
		if (!table || !container || !shell) {
			return null
		}

		const shellStyle = window.getComputedStyle(shell)
		const thead = table.querySelector('thead')
		const theadStyle = thead ? window.getComputedStyle(thead) : null
		// Prefer desktop panel buttons; skip mobile-only toggles that are
		// display:none above the card breakpoint (otherwise metrics always fail).
		const actionCandidates = [
			...table.querySelectorAll(
				'td.actions-cell .azc-table-actions .btn, td.actions-cell .azc-table-actions .azc-btn, td.azc-table-actions-col .btn, td.azc-table-actions-col .azc-btn, .azc-table-actions .btn, .azc-table-actions .azc-btn, td.actions-cell .btn, td.actions-cell .azc-btn',
			),
		]
		const isLaidOut = (el) => {
			const cs = window.getComputedStyle(el)
			const r = el.getBoundingClientRect()
			return cs.display !== 'none'
				&& cs.visibility !== 'hidden'
				&& r.width > 0
				&& r.height > 0
		}
		const actionBtn = actionCandidates.find(isLaidOut) || null

		const shellRect = shell.getBoundingClientRect()
		const containerRect = container.getBoundingClientRect()

		const shellMaxWidth = shellStyle.maxWidth
		const shellIsWide = shell.classList.contains('azc-shell--wide')
			|| shellMaxWidth === 'none'
			|| parseFloat(shellMaxWidth) >= shellRect.width * 0.9

		const containerUsesShell = containerRect.width >= shellRect.width * 0.88

		const theadIsTableHeader = !theadStyle
			|| (theadStyle.position !== 'absolute' && parseFloat(theadStyle.height) > 4)

		const needsHorizontalScroll = container.scrollWidth > container.clientWidth + 2

		const isBtnVisibleInContainer = (btn) => {
			if (!btn) {
				return true
			}
			const btnRect = btn.getBoundingClientRect()
			const rect = container.getBoundingClientRect()
			return btnRect.width > 0
				&& btnRect.height > 0
				&& btnRect.right <= rect.right + 2
				&& btnRect.left >= rect.left - 2
		}

		let actionFullyVisible = isBtnVisibleInContainer(actionBtn)
		let actionReachableByScroll = actionFullyVisible

		if (actionBtn && !actionFullyVisible && needsHorizontalScroll) {
			const priorScroll = container.scrollLeft
			container.scrollLeft = container.scrollWidth
			actionReachableByScroll = isBtnVisibleInContainer(actionBtn)
			container.scrollLeft = priorScroll
		}

		return {
			shellIsWide,
			containerUsesShell,
			theadIsTableHeader,
			actionFullyVisible,
			actionReachableByScroll,
			hasActionButton: Boolean(actionBtn),
			containerWidth: containerRect.width,
			shellWidth: shellRect.width,
			needsHorizontalScroll,
		}
	}, tableSelector)

	if (metrics === null) {
		throw new Error(`Desktop table layout: missing shell/table for ${tableSelector}`)
	}

	return metrics
}

/**
 * @param {import('@playwright/test').Expect} expect
 * @param {Awaited<ReturnType<typeof collectDesktopTableMetrics>>} metrics
 * @param {{ requireFullyVisibleActions?: boolean }} [options]
 */
export function assertDesktopTableMetrics(expect, metrics, options = {}) {
	const requireFullyVisible = options.requireFullyVisibleActions !== false

	expect(metrics.shellIsWide, 'shell should be wide (no artificial max-width cap)').toBe(true)
	expect(metrics.containerUsesShell, 'table container should fill the shell width').toBe(true)
	expect(metrics.theadIsTableHeader, 'desktop view must not use mobile card reflow').toBe(true)

	if (!metrics.hasActionButton) {
		return
	}

	if (requireFullyVisible) {
		expect(
			metrics.actionFullyVisible,
			'action buttons must be fully visible without horizontal scrolling on wide desktop',
		).toBe(true)
		return
	}

	expect(
		metrics.actionFullyVisible || metrics.actionReachableByScroll,
		'action buttons must be visible or reachable via horizontal scroll',
	).toBe(true)
}
