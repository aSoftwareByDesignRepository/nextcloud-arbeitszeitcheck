import { expect } from '@playwright/test'

/**
 * Type a 24-hour time into an ArbeitszeitCheck time picker.
 * hiddenId is the submitted input, for example "entry-start-time".
 */
export async function setAzcTime(page, hiddenId, hhmm) {
	const [hour, minute] = hhmm.split(':')
	const hourInput = page.locator(`#${hiddenId}-hour`)
	await hourInput.click()
	await hourInput.press(hour[0])
	await hourInput.press(hour[1])
	const minuteInput = page.locator(`#${hiddenId}-minute`)
	await minuteInput.click()
	await minuteInput.press(minute[0])
	await minuteInput.press(minute[1])
	await expect(page.locator(`#${hiddenId}`)).toHaveValue(hhmm)
}
