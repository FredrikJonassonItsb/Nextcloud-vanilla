/**
 * SDKMC internal API client for sending SMS auth codes.
 *
 * Reuses RECIPIENTS_API_URL and RECIPIENTS_API_TOKEN — the same env vars
 * that securemail already uses to fetch mailboxes from sdkmc. Both calls
 * hit the same Nextcloud instance with the same X-Api-Token auth, so
 * adding separate SDKMC_API_URL/SDKMC_API_TOKEN vars would just duplicate
 * config and create a deployment burden for zero benefit.
 *
 * See project-overview#110 / securemail#28
 */

const axios = require('axios')

const RECIPIENTS_API_URL = process.env.RECIPIENTS_API_URL
const RECIPIENTS_API_TOKEN = process.env.RECIPIENTS_API_TOKEN

if (RECIPIENTS_API_URL && !RECIPIENTS_API_URL.startsWith('https://')) {
  console.warn('WARNING: RECIPIENTS_API_URL is not HTTPS — OTP codes may be transmitted insecurely')
}

function getSmsEndpoint() {
  const base = new URL(RECIPIENTS_API_URL).origin
  return `${base}/apps/sdkmc/api/v2/securemail/sms/send-auth-code`
}

/**
 * Send an SMS authentication code via SDKMC.
 *
 * @param {string} phone - Recipient phone number (e.g. +46706102529)
 * @param {string} code - The OTP code to send
 * @returns {Promise<{status: string, messageId: string}>}
 */
async function sendSmsCode(phone, code) {
  if (!RECIPIENTS_API_URL || !RECIPIENTS_API_TOKEN) {
    throw new Error('RECIPIENTS_API_URL and RECIPIENTS_API_TOKEN must be configured for SMS')
  }

  const response = await axios.post(getSmsEndpoint(), new URLSearchParams({
    recipient: phone,
    code: code
  }).toString(), {
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Api-Token': RECIPIENTS_API_TOKEN
    },
    timeout: 10000
  })

  return response.data
}

module.exports = { sendSmsCode }
