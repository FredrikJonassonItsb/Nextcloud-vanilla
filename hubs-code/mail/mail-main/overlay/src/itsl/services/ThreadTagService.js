/**
 * SPDX-FileCopyrightText: 2026 ITSL AB <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { SDKMC_API_ROUTES } from '../store/constants.js'

/**
 * Set a tag on multiple messages (thread-level operation).
 *
 * @param {number[]} ids - Array of message database IDs
 * @param {string} imapLabel - The IMAP label to set
 * @return {Promise<object>} The tag object
 */
export async function setThreadTag(ids, imapLabel) {
	const url = generateUrl(SDKMC_API_ROUTES.SET_THREAD_TAG(imapLabel))
	const { data } = await axios.put(url, { ids })
	return data
}

/**
 * Remove a tag from multiple messages (thread-level operation).
 *
 * @param {number[]} ids - Array of message database IDs
 * @param {string} imapLabel - The IMAP label to remove
 * @return {Promise<object>} The tag object
 */
export async function removeThreadTag(ids, imapLabel) {
	const url = generateUrl(SDKMC_API_ROUTES.REMOVE_THREAD_TAG(imapLabel))
	const { data } = await axios.delete(url, { data: { ids } })
	return data
}

/**
 * Set flags on multiple messages (thread-level operation).
 *
 * @param {number[]} ids - Array of message database IDs
 * @param {object} flags - Object with flag values (e.g., { flagged: true })
 * @return {Promise<void>}
 */
export async function setThreadFlags(ids, flags) {
	const url = generateUrl(SDKMC_API_ROUTES.SET_THREAD_FLAGS)
	await axios.put(url, { ids, flags })
}
