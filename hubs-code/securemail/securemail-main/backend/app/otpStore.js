/**
 * In-memory OTP store for LOA-2 SMS verification.
 *
 * Stores hashed OTP codes with TTL, attempt limits, and lockout.
 * Includes SMS send rate limiting per user+email.
 *
 * See project-overview#110 / securemail#28
 */

const { randomInt, timingSafeEqual, createHash } = require('crypto')

const OTP_TTL_MS = 5 * 60 * 1000 // 5 minutes
const VERIFIED_TTL_MS = 30 * 60 * 1000 // 30 minutes (authenticated user sessions)
const LOCKOUT_MS = 5 * 60 * 1000 // 5 minute lockout after max attempts
const MAX_ATTEMPTS = 3
const MAX_STORE_SIZE = 10000
const MAX_SMS_PER_EMAIL_PER_HOUR = 5

// key: `${userId}:${emailId}` → { codeHash, phone, expires, attempts, lockedUntil }
const otpStore = new Map()

// key: `${userId}:${emailId}` → { verifiedAt } (authenticated user sessions only)
const verifiedStore = new Map()

// key: `${userId}:${emailId}` → [timestamp, timestamp, ...]
const smsSendLog = new Map()

function hashCode(code) {
  return createHash('sha256').update(code).digest('hex')
}

function generateOtp() {
  return randomInt(100000, 1000000).toString()
}

function storeOtp(userId, emailId, code, phone) {
  if (otpStore.size >= MAX_STORE_SIZE) {
    throw new Error('OTP store capacity exceeded')
  }
  const key = `${userId}:${emailId}`
  otpStore.set(key, {
    codeHash: hashCode(code),
    phone,
    expires: Date.now() + OTP_TTL_MS,
    attempts: 0,
    lockedUntil: 0
  })
}

/**
 * Check if an active (non-expired, non-exhausted, non-locked) OTP exists.
 */
function hasActiveOtp(userId, emailId) {
  const key = `${userId}:${emailId}`
  const entry = otpStore.get(key)
  if (!entry) return false
  const now = Date.now()
  // Locked out — treat as "active" to prevent new OTP generation
  if (entry.lockedUntil > now) return true
  // Exhausted but not yet expired — treat as "active" to prevent attempt-reset bypass
  if (entry.attempts >= MAX_ATTEMPTS) return true
  // Normal active OTP
  if (now <= entry.expires) return true
  return false
}

/**
 * Check if the user is currently locked out (max attempts exhausted).
 */
function isLockedOut(userId, emailId) {
  const key = `${userId}:${emailId}`
  const entry = otpStore.get(key)
  if (!entry) return false
  if (entry.attempts >= MAX_ATTEMPTS) return true
  if (entry.lockedUntil > Date.now()) return true
  return false
}

/**
 * Check if SMS rate limit allows another send (does NOT record the send).
 * @returns {boolean} true if send is allowed, false if rate-limited
 */
function checkSmsRateLimit(userId, emailId) {
  const key = `${userId}:${emailId}`
  const hourAgo = Date.now() - 3600000
  const sends = (smsSendLog.get(key) || []).filter(ts => ts > hourAgo)
  smsSendLog.set(key, sends)
  return sends.length < MAX_SMS_PER_EMAIL_PER_HOUR
}

/**
 * Record a successful SMS send for rate limiting.
 */
function recordSmsSend(userId, emailId) {
  const key = `${userId}:${emailId}`
  const hourAgo = Date.now() - 3600000
  const sends = (smsSendLog.get(key) || []).filter(ts => ts > hourAgo)
  sends.push(Date.now())
  smsSendLog.set(key, sends)
}

/**
 * @returns {'valid'|'invalid'|'expired'|'max_attempts'|'not_found'}
 */
function verifyOtp(userId, emailId, code) {
  if (typeof code !== 'string' || !/^[0-9]{6}$/.test(code)) {
    return 'invalid'
  }

  const key = `${userId}:${emailId}`
  const entry = otpStore.get(key)

  if (!entry) return 'not_found'
  if (Date.now() > entry.expires && entry.lockedUntil <= Date.now()) {
    otpStore.delete(key)
    return 'expired'
  }
  if (entry.attempts >= MAX_ATTEMPTS) {
    // Set lockout timer but keep entry to prevent regeneration
    if (!entry.lockedUntil || entry.lockedUntil <= Date.now()) {
      entry.lockedUntil = Date.now() + LOCKOUT_MS
    }
    return 'max_attempts'
  }

  entry.attempts++

  const storedBuf = Buffer.from(hashCode(code))
  const expectedBuf = Buffer.from(entry.codeHash)
  if (storedBuf.length === expectedBuf.length && timingSafeEqual(storedBuf, expectedBuf)) {
    otpStore.delete(key)
    return 'valid'
  }

  // If this attempt exhausted the limit, set lockout
  if (entry.attempts >= MAX_ATTEMPTS) {
    entry.lockedUntil = Date.now() + LOCKOUT_MS
  }

  return 'invalid'
}

// Authenticated user verification state (NOT used for org endpoints — those use ticketStore)
function isVerified(userId, emailId) {
  const key = `${userId}:${emailId}`
  const entry = verifiedStore.get(key)
  if (!entry) return false
  if (Date.now() > entry.verifiedAt + VERIFIED_TTL_MS) {
    verifiedStore.delete(key)
    return false
  }
  return true
}

function markVerified(userId, emailId) {
  verifiedStore.set(`${userId}:${emailId}`, { verifiedAt: Date.now() })
}

// Cleanup expired entries every 60 seconds
setInterval(() => {
  const now = Date.now()
  for (const [key, entry] of otpStore) {
    if (now > entry.expires && now > (entry.lockedUntil || 0)) otpStore.delete(key)
  }
  for (const [key, entry] of verifiedStore) {
    if (now > entry.verifiedAt + VERIFIED_TTL_MS) verifiedStore.delete(key)
  }
  // Cleanup old SMS send logs
  const hourAgo = now - 3600000
  for (const [key, sends] of smsSendLog) {
    const recent = sends.filter(ts => ts > hourAgo)
    if (recent.length === 0) smsSendLog.delete(key)
    else smsSendLog.set(key, recent)
  }
}, 60000)

module.exports = {
  generateOtp,
  storeOtp,
  hasActiveOtp,
  isLockedOut,
  checkSmsRateLimit,
  recordSmsSend,
  verifyOtp,
  isVerified,
  markVerified
}
