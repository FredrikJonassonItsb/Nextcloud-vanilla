#!/usr/bin/env node
/**
 * LOA-2 security gate tests.
 * Run: node test-security.js
 * Uses node:test + node:assert (built-in, no deps).
 *
 * See project-overview#110 / securemail#28
 */

const { describe, it, beforeEach } = require('node:test')
const assert = require('node:assert/strict')

// --- ticketStore tests ---

describe('ticketStore', () => {
  // Fresh module for each test suite to reset the Map
  let generateTicket, validateTicket

  beforeEach(() => {
    // Clear module cache to get a fresh ticketStore Map
    delete require.cache[require.resolve('./ticketStore')]
    const store = require('./ticketStore')
    generateTicket = store.generateTicket
    validateTicket = store.validateTicket
  })

  it('generateTicket returns a 64-char hex string', () => {
    const ticket = generateTicket('uuid-1')
    assert.equal(ticket.length, 64)
    assert.match(ticket, /^[0-9a-f]{64}$/)
  })

  it('generateTicket returns unique tickets', () => {
    const t1 = generateTicket('uuid-1')
    const t2 = generateTicket('uuid-1')
    assert.notEqual(t1, t2)
  })

  it('validateTicket accepts valid ticket + matching UUID', () => {
    const ticket = generateTicket('uuid-1')
    assert.equal(validateTicket(ticket, 'uuid-1'), true)
  })

  it('validateTicket rejects null/undefined/empty ticket', () => {
    assert.equal(validateTicket(null, 'uuid-1'), false)
    assert.equal(validateTicket(undefined, 'uuid-1'), false)
    assert.equal(validateTicket('', 'uuid-1'), false)
  })

  it('validateTicket rejects wrong ticket', () => {
    generateTicket('uuid-1')
    assert.equal(validateTicket('bad-ticket', 'uuid-1'), false)
  })

  it('validateTicket rejects ticket for wrong UUID', () => {
    const ticket = generateTicket('uuid-1')
    assert.equal(validateTicket(ticket, 'uuid-2'), false)
  })

  it('validateTicket rejects expired ticket', () => {
    // Monkey-patch Date.now to simulate expiry
    const realNow = Date.now
    let fakeTime = realNow()
    Date.now = () => fakeTime

    const ticket = generateTicket('uuid-1')
    assert.equal(validateTicket(ticket, 'uuid-1'), true)

    // Advance 16 minutes (past 15-min TTL)
    fakeTime += 16 * 60 * 1000
    assert.equal(validateTicket(ticket, 'uuid-1'), false)

    Date.now = realNow
  })

  it('ticket is single-use per UUID (not revoked by validation)', () => {
    const ticket = generateTicket('uuid-1')
    // Ticket should be reusable within TTL (for attachments, reply)
    assert.equal(validateTicket(ticket, 'uuid-1'), true)
    assert.equal(validateTicket(ticket, 'uuid-1'), true)
    assert.equal(validateTicket(ticket, 'uuid-1'), true)
  })

  it('MAX_STORE_SIZE prevents unbounded growth', () => {
    // Generate 10000 tickets (the limit)
    for (let i = 0; i < 10000; i++) {
      generateTicket(`uuid-${i}`)
    }
    // 10001st should throw
    assert.throws(() => generateTicket('uuid-overflow'), /capacity exceeded/)
  })
})

// --- otpStore tests ---

describe('otpStore', () => {
  let generateOtp, storeOtp, hasActiveOtp, isLockedOut, verifyOtp
  let isVerified, markVerified, checkSmsRateLimit, recordSmsSend

  beforeEach(() => {
    delete require.cache[require.resolve('./otpStore')]
    const store = require('./otpStore')
    generateOtp = store.generateOtp
    storeOtp = store.storeOtp
    hasActiveOtp = store.hasActiveOtp
    isLockedOut = store.isLockedOut
    verifyOtp = store.verifyOtp
    isVerified = store.isVerified
    markVerified = store.markVerified
    checkSmsRateLimit = store.checkSmsRateLimit
    recordSmsSend = store.recordSmsSend
  })

  it('generateOtp returns 6-digit string', () => {
    const otp = generateOtp()
    assert.match(otp, /^[0-9]{6}$/)
  })

  it('verifyOtp accepts correct code', () => {
    const code = generateOtp()
    storeOtp('user1', 'email1', code, '+46701234567')
    assert.equal(verifyOtp('user1', 'email1', code), 'valid')
  })

  it('verifyOtp rejects wrong code', () => {
    storeOtp('user1', 'email1', '123456', '+46701234567')
    assert.equal(verifyOtp('user1', 'email1', '654321'), 'invalid')
  })

  it('verifyOtp rejects non-6-digit input', () => {
    storeOtp('user1', 'email1', '123456', '+46701234567')
    assert.equal(verifyOtp('user1', 'email1', '12345'), 'invalid')
    assert.equal(verifyOtp('user1', 'email1', '1234567'), 'invalid')
    assert.equal(verifyOtp('user1', 'email1', 'abcdef'), 'invalid')
    assert.equal(verifyOtp('user1', 'email1', ''), 'invalid')
    assert.equal(verifyOtp('user1', 'email1', null), 'invalid')
  })

  it('brute-force lockout after 3 wrong attempts', () => {
    storeOtp('user1', 'email1', '123456', '+46701234567')
    assert.equal(verifyOtp('user1', 'email1', '000001'), 'invalid')
    assert.equal(verifyOtp('user1', 'email1', '000002'), 'invalid')
    assert.equal(verifyOtp('user1', 'email1', '000003'), 'invalid') // 3rd exhausts limit, sets lockout
    // 4th attempt and beyond get max_attempts
    assert.equal(verifyOtp('user1', 'email1', '000004'), 'max_attempts')
    // Even correct code should fail after lockout
    assert.equal(verifyOtp('user1', 'email1', '123456'), 'max_attempts')
  })

  it('isLockedOut returns true after max attempts', () => {
    storeOtp('user1', 'email1', '123456', '+46701234567')
    assert.equal(isLockedOut('user1', 'email1'), false)
    verifyOtp('user1', 'email1', '000001')
    verifyOtp('user1', 'email1', '000002')
    verifyOtp('user1', 'email1', '000003')
    assert.equal(isLockedOut('user1', 'email1'), true)
  })

  it('hasActiveOtp prevents new OTP generation', () => {
    assert.equal(hasActiveOtp('user1', 'email1'), false)
    storeOtp('user1', 'email1', '123456', '+46701234567')
    assert.equal(hasActiveOtp('user1', 'email1'), true)
  })

  it('hasActiveOtp stays true during lockout', () => {
    storeOtp('user1', 'email1', '123456', '+46701234567')
    verifyOtp('user1', 'email1', '000001')
    verifyOtp('user1', 'email1', '000002')
    verifyOtp('user1', 'email1', '000003')
    // Locked out but hasActiveOtp should still be true to prevent regen
    assert.equal(hasActiveOtp('user1', 'email1'), true)
  })

  it('markVerified + isVerified work for authenticated sessions', () => {
    assert.equal(isVerified('user1', 'email1'), false)
    markVerified('user1', 'email1')
    assert.equal(isVerified('user1', 'email1'), true)
  })

  it('isVerified is scoped to user+email', () => {
    markVerified('user1', 'email1')
    assert.equal(isVerified('user1', 'email1'), true)
    assert.equal(isVerified('user1', 'email2'), false)
    assert.equal(isVerified('user2', 'email1'), false)
  })

  it('isVerified expires after TTL', () => {
    const realNow = Date.now
    let fakeTime = realNow()
    Date.now = () => fakeTime

    markVerified('user1', 'email1')
    assert.equal(isVerified('user1', 'email1'), true)

    // Advance 31 minutes (past 30-min TTL)
    fakeTime += 31 * 60 * 1000
    assert.equal(isVerified('user1', 'email1'), false)

    Date.now = realNow
  })

  it('SMS rate limit allows 5 per hour', () => {
    for (let i = 0; i < 5; i++) {
      assert.equal(checkSmsRateLimit('user1', 'email1'), true)
      recordSmsSend('user1', 'email1')
    }
    assert.equal(checkSmsRateLimit('user1', 'email1'), false)
  })

  it('verifyOtp returns not_found for unknown key', () => {
    assert.equal(verifyOtp('unknown', 'unknown', '123456'), 'not_found')
  })

  it('successful verify deletes OTP (prevents reuse)', () => {
    const code = generateOtp()
    storeOtp('user1', 'email1', code, '+46701234567')
    assert.equal(verifyOtp('user1', 'email1', code), 'valid')
    // Second attempt should fail — OTP consumed
    assert.equal(verifyOtp('user1', 'email1', code), 'not_found')
  })
})

// --- Cross-concern tests ---

describe('ticket vs verified separation', () => {
  it('ticketStore and otpStore are independent modules', () => {
    delete require.cache[require.resolve('./ticketStore')]
    delete require.cache[require.resolve('./otpStore')]
    const ticket = require('./ticketStore')
    const otp = require('./otpStore')

    // ticketStore should NOT have isVerified/markVerified
    assert.equal(typeof ticket.isVerified, 'undefined')
    assert.equal(typeof ticket.markVerified, 'undefined')

    // otpStore should NOT have generateTicket/validateTicket
    assert.equal(typeof otp.generateTicket, 'undefined')
    assert.equal(typeof otp.validateTicket, 'undefined')

    // Each has its own exports
    assert.equal(typeof ticket.generateTicket, 'function')
    assert.equal(typeof ticket.validateTicket, 'function')
    assert.equal(typeof otp.isVerified, 'function')
    assert.equal(typeof otp.markVerified, 'function')
  })

  it('ticket for UUID does not affect isVerified for same UUID', () => {
    delete require.cache[require.resolve('./ticketStore')]
    delete require.cache[require.resolve('./otpStore')]
    const { generateTicket, validateTicket } = require('./ticketStore')
    const { isVerified, markVerified } = require('./otpStore')

    const ticket = generateTicket('uuid-1')
    // Ticket exists but isVerified should still be false
    assert.equal(isVerified('uuid-1', 'uuid-1'), false)
    // markVerified should not affect ticket
    markVerified('user1', 'email1')
    assert.equal(validateTicket(ticket, 'uuid-1'), true)
    assert.equal(isVerified('user1', 'email1'), true)
    // Cross: ticket for uuid-1 doesn't verify user1/email1
    assert.equal(isVerified('uuid-1', 'uuid-1'), false)
  })
})

// --- OTP expiry tests ---

describe('otpStore expiry', () => {
  let storeOtp, verifyOtp, hasActiveOtp, generateOtp

  beforeEach(() => {
    delete require.cache[require.resolve('./otpStore')]
    const store = require('./otpStore')
    storeOtp = store.storeOtp
    verifyOtp = store.verifyOtp
    hasActiveOtp = store.hasActiveOtp
    generateOtp = store.generateOtp
  })

  it('OTP expires after 5 minutes', () => {
    const realNow = Date.now
    let fakeTime = realNow()
    Date.now = () => fakeTime

    const code = '123456'
    storeOtp('user1', 'email1', code, '+46701234567')
    assert.equal(verifyOtp('user1', 'email1', code), 'valid')

    // New OTP
    storeOtp('user1', 'email1', code, '+46701234567')
    // Advance 6 minutes
    fakeTime += 6 * 60 * 1000
    assert.equal(verifyOtp('user1', 'email1', code), 'expired')

    Date.now = realNow
  })

  it('hasActiveOtp returns false after expiry', () => {
    const realNow = Date.now
    let fakeTime = realNow()
    Date.now = () => fakeTime

    storeOtp('user1', 'email1', '123456', '+46701234567')
    assert.equal(hasActiveOtp('user1', 'email1'), true)

    // Advance 6 minutes
    fakeTime += 6 * 60 * 1000
    assert.equal(hasActiveOtp('user1', 'email1'), false)

    Date.now = realNow
  })
})

// --- Edge cases ---

describe('edge cases', () => {
  it('ticketStore: multiple tickets for same UUID', () => {
    delete require.cache[require.resolve('./ticketStore')]
    const { generateTicket, validateTicket } = require('./ticketStore')

    const t1 = generateTicket('uuid-1')
    const t2 = generateTicket('uuid-1')
    // Both should be valid (user might verify from two tabs)
    assert.equal(validateTicket(t1, 'uuid-1'), true)
    assert.equal(validateTicket(t2, 'uuid-1'), true)
    // Neither works for wrong UUID
    assert.equal(validateTicket(t1, 'uuid-2'), false)
    assert.equal(validateTicket(t2, 'uuid-2'), false)
  })

  it('otpStore: storeOtp replaces existing OTP for same key', () => {
    delete require.cache[require.resolve('./otpStore')]
    const { storeOtp, verifyOtp } = require('./otpStore')

    storeOtp('user1', 'email1', '111111', '+46701234567')
    storeOtp('user1', 'email1', '222222', '+46701234567')
    // Old code should fail
    assert.equal(verifyOtp('user1', 'email1', '111111'), 'invalid')
  })

  it('otpStore: MAX_STORE_SIZE prevents unbounded growth', () => {
    delete require.cache[require.resolve('./otpStore')]
    const { storeOtp } = require('./otpStore')

    for (let i = 0; i < 10000; i++) {
      storeOtp(`user-${i}`, 'email', '123456', '+46701234567')
    }
    assert.throws(() => storeOtp('overflow', 'email', '123456', '+46701234567'), /capacity exceeded/)
  })

  it('otpStore: code validation is strict (no type coercion)', () => {
    delete require.cache[require.resolve('./otpStore')]
    const { storeOtp, verifyOtp } = require('./otpStore')

    storeOtp('user1', 'email1', '123456', '+46701234567')
    // Number instead of string
    assert.equal(verifyOtp('user1', 'email1', 123456), 'invalid')
    // Object
    assert.equal(verifyOtp('user1', 'email1', { code: '123456' }), 'invalid')
    // Array
    assert.equal(verifyOtp('user1', 'email1', ['123456']), 'invalid')
  })

  it('ticketStore: empty string ticket rejected', () => {
    delete require.cache[require.resolve('./ticketStore')]
    const { validateTicket } = require('./ticketStore')
    assert.equal(validateTicket('', 'uuid-1'), false)
  })

  it('ticketStore: ticket validation after cleanup cycle', () => {
    delete require.cache[require.resolve('./ticketStore')]
    const { generateTicket, validateTicket } = require('./ticketStore')

    const realNow = Date.now
    let fakeTime = realNow()
    Date.now = () => fakeTime

    const ticket = generateTicket('uuid-1')

    // Still within TTL
    fakeTime += 10 * 60 * 1000 // 10 min
    assert.equal(validateTicket(ticket, 'uuid-1'), true)

    // Just past TTL
    fakeTime += 6 * 60 * 1000 // 16 min total
    assert.equal(validateTicket(ticket, 'uuid-1'), false)

    Date.now = realNow
  })

  it('otpStore: SMS rate limit is per user+email pair', () => {
    delete require.cache[require.resolve('./otpStore')]
    const { checkSmsRateLimit, recordSmsSend } = require('./otpStore')

    // Max out user1/email1
    for (let i = 0; i < 5; i++) recordSmsSend('user1', 'email1')
    assert.equal(checkSmsRateLimit('user1', 'email1'), false)

    // user1/email2 should still be allowed
    assert.equal(checkSmsRateLimit('user1', 'email2'), true)
    // user2/email1 should still be allowed
    assert.equal(checkSmsRateLimit('user2', 'email1'), true)
  })
})
