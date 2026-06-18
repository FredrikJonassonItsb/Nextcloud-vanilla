/**
 * Ticket-based verification session for LOA-2 org messages.
 *
 * After SMS OTP is verified, a time-limited ticket is issued.
 * Frontend stores it in a JS variable (dies on page reload).
 * All subsequent org requests must present the ticket via X-Verify-Ticket header.
 *
 * See project-overview#110 / securemail#28
 */

const crypto = require('crypto')

const TICKET_TTL_MS = 15 * 60 * 1000 // 15 minutes
const MAX_STORE_SIZE = 10000

// key: ticket (hex string) → { uuid, expiresAt }
const ticketStore = new Map()

function generateTicket(uuid) {
  if (ticketStore.size >= MAX_STORE_SIZE) {
    throw new Error('Ticket store capacity exceeded')
  }
  const ticket = crypto.randomBytes(32).toString('hex')
  ticketStore.set(ticket, { uuid, expiresAt: Date.now() + TICKET_TTL_MS })
  return ticket
}

function validateTicket(ticket, uuid) {
  if (!ticket) return false
  const entry = ticketStore.get(ticket)
  if (!entry) return false
  if (entry.uuid !== uuid) return false
  if (Date.now() > entry.expiresAt) {
    ticketStore.delete(ticket)
    return false
  }
  return true
}

// Cleanup expired tickets every 5 minutes
setInterval(() => {
  const now = Date.now()
  for (const [ticket, entry] of ticketStore) {
    if (now > entry.expiresAt) ticketStore.delete(ticket)
  }
}, 5 * 60 * 1000)

module.exports = { generateTicket, validateTicket }
