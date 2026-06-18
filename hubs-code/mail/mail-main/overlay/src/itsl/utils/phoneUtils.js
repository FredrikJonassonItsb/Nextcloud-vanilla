/**
 * Phone number utilities - formatting, extraction, validation.
 */

import { parsePhoneNumber } from 'libphonenumber-js'

/**
 * Extract phone number from fax/sms email address.
 * @param {string} email - Email like "+46701234567@sms" or "+46812345678@fax"
 * @return {string|null} Phone number or null if not a phone-based email
 */
export function extractPhoneFromEmail(email) {
	if (!email) return null

	const match = email.match(/^([+\d]+)@(sms|fax)$/i)
	if (!match) return null

	return match[1] // Returns "+46701234567"
}

/**
 * Format phone number for display. Accepts any input:
 * - Email with @fax/@sms suffix (e.g., "0701234567@sms") — suffix is stripped
 * - Raw phone number (e.g., "0701234567", "+46701234567")
 * - Non-phone input — returned as-is (stripped of suffix if it had one)
 *
 * Swedish numbers → national format (070-123 45 67)
 * Foreign numbers → international format (+47 123 45 678)
 *
 * @param {string} input - Phone number, email address, or any string
 * @return {string} Formatted phone number or stripped input as fallback
 */
export function formatPhoneNumber(input) {
	if (typeof input !== 'string') return ''
	const stripped = input.replace(/@(?:fax|sms)$/i, '')
	if (!stripped) return ''

	try {
		const parsed = parsePhoneNumber(stripped, 'SE')
		if (parsed && parsed.isValid()) {
			// Swedish numbers → national format, foreign → international
			if (parsed.country === 'SE') {
				return parsed.formatNational()
			}
			return parsed.formatInternational()
		}
		return stripped
	} catch {
		return stripped
	}
}

/**
 * Format local Swedish phone number with spaces using libphonenumber-js.
 * @deprecated Use formatPhoneNumber() instead — it handles all cases.
 * @param {string} phone - Local phone number like "0701234567" or "0812345678"
 * @return {string} Formatted phone like "070-123 45 67" or "08-12 34 56"
 */
export function formatLocalPhoneNumber(phone) {
	return formatPhoneNumber(phone)
}

export function getValidSSN(ssn) {
	if (!ssn) {
		return ''
	}
	let result = ssn.replace('-', '')

	if (result.length === 10) {
		result = '19' + result
	}
	return result
}

export function getValidSMSNumber(smsNumber) {
	if (!smsNumber || typeof smsNumber !== 'string') {
		return null
	}
	const cleaned = smsNumber.replace(/[\s\-()]+/g, '')
	// E.164 format: optional + followed by 7-15 digits
	if (/^\+?[0-9]{7,15}$/.test(cleaned)) {
		return cleaned
	}
	return null
}
