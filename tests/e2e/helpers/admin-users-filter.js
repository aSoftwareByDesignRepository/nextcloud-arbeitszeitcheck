/**
 * Employee list access filter helpers for E2E (dev/CI only).
 */

/** @type {number} */
const FILTER_API_TIMEOUT_MS = 120_000;

/**
 * The filter fields stay closed until Filtern is pressed, unless a filter is already active.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function openEmployeeListFilters(page) {
	const section = page.locator('#filter-section');
	if (await section.isVisible()) {
		return;
	}
	await page.locator('#btn-filter').click();
	await section.waitFor({ state: 'visible' });
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {'app_access' | 'all'} filter
 */
async function applyAccessFilter(page, filter) {
	await openEmployeeListFilters(page);
	const select = page.locator('#employee-list-access-filter');
	await select.selectOption(filter);
	const responsePromise = page.waitForResponse(
		(res) =>
			res.url().includes('/api/admin/users')
			&& res.url().includes(`filter=${filter}`)
			&& !res.url().includes('picker=1')
			&& res.request().method() === 'GET',
		{ timeout: FILTER_API_TIMEOUT_MS },
	);
	await page.locator('#employee-list-filter-apply').click();
	await responsePromise;
}

/**
 * Activate a filter, no-op when it is already selected.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'app_access' | 'all'} filter
 */
export async function selectEmployeeListAccessFilter(page, filter) {
	const select = page.locator('#employee-list-access-filter');
	if ((await select.inputValue()) === filter) {
		return;
	}
	await applyAccessFilter(page, filter);
}

/**
 * Force a filter change even when the target is already active (toggle away, then back).
 *
 * @param {import('@playwright/test').Page} page
 * @param {'app_access' | 'all'} filter
 */
export async function switchEmployeeListAccessFilter(page, filter) {
	const select = page.locator('#employee-list-access-filter');
	if ((await select.inputValue()) === filter) {
		const other = filter === 'app_access' ? 'all' : 'app_access';
		await applyAccessFilter(page, other);
	}
	await applyAccessFilter(page, filter);
}
