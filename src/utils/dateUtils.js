/**
 * Date utility functions for German date format (dd.mm.yyyy)
 * 
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/**
 * Format a date string to German format (dd.mm.yyyy)
 * @param {string|Date} dateInput - ISO date string or Date object
 * @returns {string} Formatted date string (dd.mm.yyyy)
 */
export function formatDateGerman(dateInput) {
	if (!dateInput) return ''
	
	try {
		const date = dateInput instanceof Date ? dateInput : new Date(dateInput)
		if (isNaN(date.getTime())) return ''
		
		const day = String(date.getDate()).padStart(2, '0')
		const month = String(date.getMonth() + 1).padStart(2, '0')
		const year = date.getFullYear()
		
		return `${day}.${month}.${year}`
	} catch (e) {
		console.warn('formatDateGerman error:', e, dateInput)
		return ''
	}
}

/**
 * Parse a German date string (dd.mm.yyyy) to ISO format (yyyy-mm-dd)
 * @param {string} germanDate - Date string in dd.mm.yyyy format
 * @returns {string} ISO date string (yyyy-mm-dd) or empty string if invalid
 */
export function parseGermanDate(germanDate) {
	if (!germanDate) return ''
	
	// Remove any whitespace
	germanDate = germanDate.trim()
	
	// Match dd.mm.yyyy format
	const match = germanDate.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/)
	if (!match) return ''
	
	const day = parseInt(match[1], 10)
	const month = parseInt(match[2], 10)
	const year = parseInt(match[3], 10)
	
	// Validate date
	if (day < 1 || day > 31 || month < 1 || month > 12 || year < 1900 || year > 2100) {
		return ''
	}
	
	const date = new Date(year, month - 1, day)
	if (date.getDate() !== day || date.getMonth() !== month - 1 || date.getFullYear() !== year) {
		return '' // Invalid date (e.g., 31.02.2024)
	}
	
	// Return ISO format (yyyy-mm-dd)
	return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/**
 * Convert ISO date string to German format for display
 * @param {string} isoDate - ISO date string (yyyy-mm-dd)
 * @returns {string} German date string (dd.mm.yyyy)
 */
export function isoToGerman(isoDate) {
	if (!isoDate) return ''
	
	// Handle ISO datetime strings (yyyy-mm-ddTHH:mm:ss)
	const datePart = isoDate.split('T')[0]
	const parts = datePart.split('-')
	
	if (parts.length !== 3) return isoDate
	
	const year = parts[0]
	const month = parts[1]
	const day = parts[2]
	
	return `${day}.${month}.${year}`
}

/**
 * Convert German date string to ISO format for API
 * @param {string} germanDate - German date string (dd.mm.yyyy)
 * @returns {string} ISO date string (yyyy-mm-dd)
 */
export function germanToIso(germanDate) {
	return parseGermanDate(germanDate)
}

/**
 * Format a date string to German format with locale
 * @param {string|Date} dateInput - ISO date string or Date object
 * @param {string} locale - Locale code (default: 'de-DE')
 * @returns {string} Formatted date string
 */
export function formatDateLocale(dateInput, locale = 'de-DE') {
	if (!dateInput) return ''
	
	const date = dateInput instanceof Date ? dateInput : new Date(dateInput)
	if (isNaN(date.getTime())) return ''
	
	return date.toLocaleDateString(locale, {
		day: '2-digit',
		month: '2-digit',
		year: 'numeric'
	})
}

/**
 * Format time in German format (HH:mm)
 * @param {string|Date} dateInput - ISO datetime string or Date object
 * @returns {string} Formatted time string (HH:mm)
 */
export function formatTimeGerman(dateInput) {
	if (!dateInput) return ''
	
	const date = dateInput instanceof Date ? dateInput : new Date(dateInput)
	if (isNaN(date.getTime())) return ''
	
	return date.toLocaleTimeString('de-DE', {
		hour: '2-digit',
		minute: '2-digit',
		hour12: false
	})
}
