console.log("server.js: Script start");
const express = require('express')
const cors = require('cors')
const jwt = require('jsonwebtoken')
const jwksRsa = require('jwks-rsa')
const Imap = require('node-imap')
const net = require('net')
const WebSocket = require('ws')
const http = require('http')
const url = require('url')
const { simpleParser } = require('mailparser')
const nodemailer = require('nodemailer')
const axios = require('axios')
require('dotenv').config()

// Import unified SSO auth routes
const ssoAuthRoutes = require('./routes/ssoAuthRoutes');

// LOA-2 SMS verification (see project-overview#110 / securemail#28)
const { generateOtp, storeOtp, hasActiveOtp, isLockedOut, checkSmsRateLimit, recordSmsSend, verifyOtp, isVerified, markVerified } = require('./otpStore')
const { generateTicket, validateTicket } = require('./ticketStore')
const { sendSmsCode } = require('./sdkmcClient')

const app = express()
const port = process.env.LISTEN_PORT || 3000; // Use environment variable for port

const RECIPIENTS_API_URL = process.env.RECIPIENTS_API_URL;
const RECIPIENTS_API_TOKEN = process.env.RECIPIENTS_API_TOKEN;

app.use(cors())
app.use(express.json({ limit: '50mb' }))
app.use(express.urlencoded({ limit: '50mb', extended: true }))

let JWKS_URI = process.env.JWKS_URI;
let TOKEN_ISSUER = process.env.TOKEN_ISSUER;
let USER_ID_CLAIM = process.env.USER_ID_CLAIM || 'sub';

// Mount the unified SSO auth router
// The routes within ssoAuthRoutes.js will be prefixed with /api/auth
// e.g., /api/auth/callback, /api/auth/refresh
app.use('/api/auth', ssoAuthRoutes);

if (!process.env.SSO_ENDPOINT_USERINFO) {
  console.warn('[startup] SSO_ENDPOINT_USERINFO not set — identity extraction step 3 (userinfo fallback) will fail for opaque-token providers')
}

console.log("server.js: Middleware and initial setup complete.");

const jwksClient = jwksRsa({
  jwksUri: JWKS_URI,
  cache: true,
  rateLimit: true
})

// Create HTTP server
const server = http.createServer(app)

// Create WebSocket server
const wss = new WebSocket.Server({ 
  noServer: true,
  verifyClient: (info, callback) => {
    // Allow all origins for WebSocket
    const origin = info.origin || info.req.headers.origin
    callback(true)
  }
})

// Handle WebSocket connections
server.on('upgrade', async (request, socket, head) => {
  const { query } = url.parse(request.url, true)
  const token = query.token

  if (!token) {
    socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n')
    socket.destroy()
    return
  }

  try {
    // No id_token in URL (avoid PII in access logs) — step 3 (userinfo) handles opaque tokens
    const decoded = await extractUserIdentity(token, null)
    
    wss.handleUpgrade(request, socket, head, (ws) => {
      // Use the configured USER_ID_CLAIM
      ws.userId = decoded[USER_ID_CLAIM] 
      if (!ws.userId) {
        console.error(`WebSocket upgrade failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, decoded);
        socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
        socket.destroy();
        return;
      }
      console.log('WebSocket client connected:', ws.userId)
      wss.emit('connection', ws, request)
    })
  } catch (err) {
    console.error('WebSocket upgrade failed:', err)
    socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n')
    socket.destroy()
  }
})

// Store connected clients
const clients = new Map()

// Add at the top with other imports/constants
const imapConnections = new Map()

// Add reconnection settings
const RECONNECT_DELAY = 5000
const MAX_RECONNECT_ATTEMPTS = 3

// Add token verification middleware
app.use(async (req, res, next) => {
  try {
    // Skip auth for welcome page, unified SSO auth endpoints, and org endpoints
    if (req.path === '/' || req.path.startsWith('/api/auth/') || req.path.startsWith('/api/org/')) {
      return next();
    }

    const authHeader = req.headers.authorization
    if (!authHeader) {
      return res.status(401).json({ error: 'No authorization header' })
    }

    const token = authHeader.split(' ')[1]
    if (!token) {
      return res.status(401).json({ error: 'No token provided' })
    }

    const idToken = req.headers['x-id-token'] || null
    const decoded = await extractUserIdentity(token, idToken)
    req.user = decoded
    next()
  } catch (error) {
    console.error('Token verification failed:', error)
    res.status(401).json({ error: 'Invalid token', details: error.message })
  }
})

// Create a function to manage persistent IMAP connections
async function getImapConnection(username, accessToken) {
  let connection = imapConnections.get(username)
  
  // If we have a connection and it's ready, return it
  if (connection && connection.state === 'connected') {
    console.log(`Reusing existing IMAP connection for user ${username}`)
    return connection
  }

  // If we have a connection but it's not ready, clean it up
  if (connection) {
    console.log(`Cleaning up stale connection for user ${username}`)
    connection.end()
    imapConnections.delete(username)
  }

  // Create new connection
  console.log(`Creating new IMAP connection for user ${username}`)
  connection = await createNewConnection(username, accessToken)
  imapConnections.set(username, connection)
  return connection
}

// Separate the connection creation logic
async function createNewConnection(username, accessToken) {
  const connection = createImapConnection(username, accessToken)
  let reconnectAttempts = 0
  
  return new Promise((resolve, reject) => {
    const cleanup = () => {
      connection.removeAllListeners()
      imapConnections.delete(username)
    }

    connection.once('ready', () => {
      console.log(`IMAP connection ready for user ${username}`)
      connection.openBox('INBOX', false, (err) => {
        if (err) {
          cleanup()
          reject(err)
        } else {
          reconnectAttempts = 0
          resolve(connection)
        }
      })
    })

    // Add connection error handling
    connection.on('error', async (err) => {
      console.error(`IMAP connection error for user ${username}:`, err)
      
      // If this is an initial connection error (before 'ready' event), reject the promise
      if (connection.state === 'disconnected' || connection.state === 'connected') {
        cleanup()
        reject(err)
        return
      }
      
      // Only attempt reconnect if this is still the current connection
      if (imapConnections.get(username) === connection) {
        cleanup()

        if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
          reconnectAttempts++
          console.log(`Attempting IMAP reconnection ${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS} for user ${username}`)
          
          setTimeout(async () => {
            try {
              const newConnection = await createNewConnection(username, accessToken)
              imapConnections.set(username, newConnection)
              const client = clients.get(username)
              if (client?.readyState === WebSocket.OPEN) {
                client.send(JSON.stringify({ type: 'imapReconnected' }))
              }
            } catch (error) {
              console.error(`IMAP reconnection attempt ${reconnectAttempts} failed for user ${username}:`, error)
            }
          }, RECONNECT_DELAY * reconnectAttempts)
        } else {
          console.error(`Max IMAP reconnection attempts reached for user ${username}`)
          const client = clients.get(username)
          if (client?.readyState === WebSocket.OPEN) {
            client.send(JSON.stringify({ type: 'imapConnectionLost' }))
          }
        }
      }
    })

    // Add a timeout for connection attempts
    const connectionTimeout = setTimeout(() => {
      cleanup()
      reject(new Error('IMAP connection timeout'))
    }, 30000) // 30 second timeout

    // Clear timeout on successful connection
    connection.once('ready', () => {
      clearTimeout(connectionTimeout)
    })

    // Clear timeout on error
    connection.once('error', () => {
      clearTimeout(connectionTimeout)
    })

    connection.connect()
  })
}

wss.on('connection', (ws, request) => {
  const userId = ws.userId
  clients.set(userId, ws)

  ws.on('close', () => {
    clients.delete(userId)
    // Clean up IMAP connection when WebSocket disconnects
    const connection = imapConnections.get(userId)
    if (connection) {
      console.log(`Closing IMAP connection for disconnected user ${userId}`)
      try {
        connection.end()
      } catch (err) {
        console.error('Error closing IMAP connection:', err)
      }
      imapConnections.delete(userId)
    }
  })
})

// Function to notify clients about new emails
function notifyNewEmail(userId) {
  const client = clients.get(userId)
  if (client && client.readyState === WebSocket.OPEN) {
    console.log('Sending new email notification to user:', userId)
    client.send(JSON.stringify({
      type: 'newEmail',
      timestamp: new Date().toISOString()
    }))
  } else {
    console.log('Client not connected for user:', userId)
  }
}

// IMAP connection factory
function createImapConnection(username, accessToken) {
  const imapUsername = username
  console.log('Creating new IMAP connection for user:', imapUsername)
  
  const xoauth2Token = Buffer.from(
    `user=${imapUsername}\x01auth=Bearer ${accessToken}\x01\x01`
  ).toString('base64')
  
  const imap = new Imap({
    user: imapUsername,
    host: process.env.IMAP_HOST || 'localhost',
    port: parseInt(process.env.IMAP_PORT || '1337'),
    tls: false,
    debug: (info) => {
      if (typeof info === 'string') {
        console.log(`IMAP Raw (${imapUsername}):`, info)
      } else {
        console.log(`IMAP Debug (${imapUsername}):`, {
          type: info.type,
          source: info.source,
          data: info.data?.toString()
        })
      }
    },
    authTimeout: 10000,
    keepalive: true,  // Add keepalive
    xoauth2: xoauth2Token
  })

  // Add more detailed error logging
  imap.on('error', (err) => {
    console.error('Detailed IMAP error:', {
      message: err.message,
      source: err.source,
      type: err.type,
      code: err.code,
      stack: err.stack
    })
  })

  // Add more event listeners for debugging
  imap.on('ready', () => console.log('IMAP connection ready'))
  imap.on('close', () => console.log('IMAP connection closed'))
  imap.on('end', () => console.log('IMAP connection ended'))

  imap.on('mail', (numNew) => {
    console.log(`${numNew} new email(s) arrived for user ${username}`)
    
    // Use search instead of status to find new messages
    imap.search(['UNSEEN'], (err, results) => {
      if (err) {
        console.error('Error searching for new messages:', err)
      } else {
        console.log('Unseen messages:', results)
      }
      // Always notify, even if search fails
      notifyNewEmail(username)
    })
  })

  imap.on('update', (seqno, info) => {
    console.log('Mailbox updated:', seqno, info)
    notifyNewEmail(username)
  })

  // Add more IMAP event handlers for debugging
  imap.on('alert', (msg) => {
    console.log('IMAP alert:', msg)
  })

  imap.on('expunge', (seqno) => {
    console.log('Message expunged:', seqno)
  })

  console.log("server.js: IMAP connection function 'createImapConnection' defined.");

  return imap
}

// Create IMAP connection for org account (service account)
function createOrgImapConnection() {
  const orgUsername = process.env.ORG_EMAIL
  const orgPassword = process.env.ORG_PASSWORD

  console.log('Creating new IMAP connection for org account:', orgUsername)

  const imap = new Imap({
    user: orgUsername,
    password: orgPassword,
    host: process.env.IMAP_HOST || 'localhost',
    port: parseInt(process.env.IMAP_PORT || '1337'),
    tls: false,
    authTimeout: 10000,
    connTimeout: 10000,
    debug: (info) => {
      if (typeof info === 'string') {
        console.log(`IMAP Raw (${orgUsername}):`, info)
      } else {
        console.log(`IMAP Debug (${orgUsername}):`, {
          type: info.type,
          source: info.source,
          data: info.data?.toString()
        })
      }
    }
  })

  imap.on('error', (err) => {
    console.error('Org IMAP error:', {
      message: err.message,
      source: err.source,
      type: err.type,
      user: orgUsername
    })
  })

  imap.on('ready', () => console.log('Org IMAP connection ready'))
  imap.on('close', () => console.log('Org IMAP connection closed'))
  imap.on('end', () => console.log('Org IMAP connection ended'))

  console.log("server.js: Org IMAP connection function 'createOrgImapConnection' defined.");

  return imap
}

// Convert IMAP message to our format
function parseMessage(msg) {
  return {
    id: msg.uid,
    subject: msg.headers.subject?.[0] || '(no subject)',
    from: msg.headers.from?.[0] || 'unknown',
    date: msg.headers.date?.[0] || new Date().toISOString(),
    content: msg.body || ''
  }
}

// Function to sort messages by conversation threads
function sortMessagesByThreads(messages) {
  // Step 1: Sort all messages by date (newest first)
  messages.sort((a, b) => {
    const dateA = new Date(a.date)
    const dateB = new Date(b.date)
    return dateB - dateA
  })

  // Step 2: Build thread relationships
  const messageMap = new Map()
  const threads = new Map()
  
  // Create a map for quick message lookup
  messages.forEach(msg => {
    messageMap.set(msg.messageId, msg)
  })

  // Function to find the root message of a thread
  function findThreadRoot(message) {
    if (!message.inReplyTo) {
      return message
    }
    
    // Try to find the parent message
    const parent = messageMap.get(message.inReplyTo)
    if (parent) {
      return findThreadRoot(parent)
    }
    
    // If parent not found in current messages, this is effectively a root
    return message
  }

  // Function to extract all message IDs from References header
  function parseReferences(references) {
    if (!references) return []
    return references.split(/\s+/).filter(id => id.trim().length > 0)
  }

  // Function to find thread root using References header as fallback
  function findThreadRootWithReferences(message) {
    if (!message.inReplyTo && !message.references) {
      return message
    }

    // First try In-Reply-To
    if (message.inReplyTo) {
      const parent = messageMap.get(message.inReplyTo)
      if (parent) {
        return findThreadRoot(parent)
      }
    }

    // Fallback to References header
    if (message.references) {
      const refs = parseReferences(message.references)
      for (const ref of refs) {
        const refMessage = messageMap.get(ref)
        if (refMessage) {
          return findThreadRoot(refMessage)
        }
      }
    }

    return message
  }

  // Step 3: Group messages into threads
  messages.forEach(message => {
    const root = findThreadRootWithReferences(message)
    const threadId = root.messageId || root.id
    
    if (!threads.has(threadId)) {
      threads.set(threadId, [])
    }
    threads.get(threadId).push(message)
  })

  // Step 4: Sort each thread internally by date (oldest first within thread)
  threads.forEach(threadMessages => {
    threadMessages.sort((a, b) => {
      const dateA = new Date(a.date)
      const dateB = new Date(b.date)
      return dateA - dateB
    })
  })

  // Step 5: Sort threads by their most recent message (newest thread first)
  const sortedThreads = Array.from(threads.values()).sort((threadA, threadB) => {
    const latestA = threadA[threadA.length - 1]
    const latestB = threadB[threadB.length - 1]
    const dateA = new Date(latestA.date)
    const dateB = new Date(latestB.date)
    return dateB - dateA
  })

  // Step 6: Flatten the sorted threads back into a single array
  const result = []
  sortedThreads.forEach(thread => {
    thread.forEach((message, index) => {
      // Mark messages as thread continuation if they're not the first (newest) in the thread
      message.isThreadContinuation = index > 0
      result.push(message)
    })
  })

  console.log(`Sorted ${messages.length} messages into ${threads.size} threads`)
  return result
}

// Get all emails
app.get('/api/emails', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }
    
    let imap;
    try {
      imap = await getImapConnection(userId, accessToken)
    } catch (imapError) {
      console.error('IMAP connection failed:', imapError)
      return res.status(500).json({ 
        error: 'Failed to connect to email server', 
        details: imapError.message 
      })
    }

    imap.search(['ALL'], (err, results) => {
      if (err) {
        console.error('Search error:', err)
        return res.status(500).json({ error: 'Failed to search messages' })
      }

      // If there are no messages, return an empty array immediately
      if (!results || results.length === 0) {
        console.log('No messages found in INBOX for user (might not exist yet):', userId)
        return res.json([])
      }

      const fetch = imap.fetch(results, {
        bodies: ['HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES X-SECUREMAILSENDER)'],
        struct: true,  // For attachments
        flags: true    // Add this to get flags
      })

      const messages = []

      fetch.on('message', (msg) => {
        const message = {
          id: null,
          subject: '',
          from: '',
          to: '',
          date: '',
          hasAttachments: false,
          read: false,    // Add read status
          isThreaded: false,  // Add threading status
          isSelfSent: false,   // Add self-sent status
          messageId: '',      // Message-ID header
          inReplyTo: '',      // In-Reply-To header
          references: ''      // References header
        }

        msg.on('body', (stream, info) => {
          let buffer = ''
          stream.on('data', (chunk) => {
            buffer += chunk.toString('utf8')
          })
          stream.once('end', () => {
            const headers = Imap.parseHeader(buffer)
            message.subject = headers.subject?.[0] || '(no subject)'
            message.date = headers.date?.[0] || new Date().toISOString()
            
            // Check if message is self-sent
            const isSelfSent = headers['x-securemailsender']?.[0] === 'self'
            message.isSelfSent = isSelfSent
            
            // Set from/to based on message direction
            if (isSelfSent) {
              // For self-sent messages, show recipient in "from" field
              message.from = formatSender(headers.to?.[0] || 'unknown')
              message.to = formatSender(headers.to?.[0] || 'unknown')
            } else {
              // For received messages, show sender in "from" field
              message.from = formatSender(headers.from?.[0] || 'unknown')
              message.to = formatSender(headers.to?.[0] || 'unknown')
            }
            
            // Extract threading information
            const messageId = headers['message-id']?.[0] || ''
            const inReplyTo = headers['in-reply-to']?.[0] || ''
            const references = headers['references']?.[0] || ''
            
            message.messageId = messageId
            message.inReplyTo = inReplyTo
            message.references = references
            message.isThreaded = !!(inReplyTo || references)
          })
        })

        msg.once('attributes', (attrs) => {
          message.id = attrs.uid
          message.read = attrs.flags?.includes('\\Seen') || false  // Set read status from flags
          if (attrs.struct) {
            message.hasAttachments = hasAttachments(attrs.struct)
          }
        })

        msg.once('end', () => {
          messages.push(message)
        })
      })

      fetch.once('error', (err) => {
        console.error('Fetch error:', err)
        res.status(500).json({ error: 'Failed to fetch messages' })
      })

      fetch.once('end', () => {
        // Sort messages by threads
        const threadSortedMessages = sortMessagesByThreads(messages)
        res.json(threadSortedMessages)
      })
    })
  } catch (error) {
    console.error('Failed to get emails:', error)
    res.status(500).json({ error: 'Failed to get emails', details: error.message })
  }
})

// Helper function to check for attachments
function hasAttachments(struct) {
  function traverse(parts) {
    if (!Array.isArray(parts)) return false

    for (const part of parts) {
      if (Array.isArray(part)) {
        if (traverse(part)) return true
      } else if (part.disposition && 
                 part.disposition.type.toLowerCase() === 'attachment' &&
                 ((part.type.toLowerCase() === 'application' && part.subtype.toLowerCase() === 'pdf') ||
                  (part.type.toLowerCase() === 'image' && 
                   (part.subtype.toLowerCase() === 'jpeg' || part.subtype.toLowerCase() === 'jpg')))) {
        return true
      }
    }
    return false
  }

  return traverse(struct)
}

// Get single email
app.get('/api/emails/:id', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token for provider. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }
    
    let imap;
    try {
      imap = await getImapConnection(userId, accessToken)
    } catch (imapError) {
      console.error('IMAP connection failed:', imapError)
      return res.status(500).json({ 
        error: 'Failed to connect to email server', 
        details: imapError.message 
      })
    }

    const fetch = imap.fetch(req.params.id, {
      bodies: ['HEADER.FIELDS (FROM TO SUBJECT DATE X-SECUREMAILSENDER X-LOALEVEL X-SMSNUMBER)', ''],
      struct: true
    })

    let message = {
      id: null,
      subject: '',
      from: '',
      to: '',
      date: '',
      content: '',
      attachments: [],
      isSelfSent: false
    }
    let loaLevel = 1
    let smsNumber = null

    // Create a promise to handle message parsing
    const messagePromise = new Promise((resolve, reject) => {
      fetch.on('message', (msg) => {
        const chunks = []
        
        msg.on('body', (stream, info) => {
          let buffer = ''
          stream.on('data', (chunk) => {
            buffer += chunk.toString('utf8')
          })
          stream.once('end', () => {
            if (info.which !== '') {
              // Parse headers
              const headers = Imap.parseHeader(buffer)
              message.subject = headers.subject?.[0] || '(no subject)'
              message.date = headers.date?.[0] || new Date().toISOString()
              
              // Parse LOA-2 headers
              const rawLoa = parseInt(headers['x-loalevel']?.[0] || '1', 10)
              loaLevel = Number.isNaN(rawLoa) ? 2 : rawLoa // fail-closed: treat malformed header as LOA-2
              smsNumber = headers['x-smsnumber']?.[0] || null

              // Check if message is self-sent
              const isSelfSent = headers['x-securemailsender']?.[0] === 'self'
              message.isSelfSent = isSelfSent
              
              // Set from/to based on message direction
              if (isSelfSent) {
                // For self-sent messages, show recipient as "from" for UI consistency
                message.from = formatSender(headers.to?.[0] || 'unknown')
                message.to = formatSender(headers.to?.[0] || 'unknown')
              } else {
                // For received messages, show sender as "from"
                message.from = formatSender(headers.from?.[0] || 'unknown')
                message.to = formatSender(headers.to?.[0] || 'unknown')
              }
            } else {
              chunks.push(buffer)
            }
          })
        })

        msg.once('attributes', (attrs) => {
          message.id = attrs.uid
          if (attrs.struct) {
            message.attachments = findAttachments(attrs.struct)
          }
        })

        msg.once('end', async () => {
          // Parse the content and wait for the result
          message.content = await parseMessageContent(chunks.join('\n'))
          resolve(message)
        })
      })

      fetch.once('error', (err) => {
        console.error('Fetch error:', err)
        reject(err)
      })
    })

    // Wait for the message to be fully parsed
    fetch.once('end', async () => {
      try {
        const completedMessage = await messagePromise
        if (!completedMessage.id) {
          res.status(404).json({ error: 'Message not found' })
          return
        }

        // LOA-2 SMS verification gate (see project-overview#110)
        const normalizedId = String(parseInt(req.params.id, 10))
        if (loaLevel === 2 && smsNumber && !completedMessage.isSelfSent) {
          if (!isVerified(userId, normalizedId)) {
            // Check lockout before anything else
            if (isLockedOut(userId, normalizedId)) {
              res.status(429).json({
                error: 'Too many attempts',
                message: 'För många felaktiga försök. Vänta en stund innan du försöker igen.'
              })
              return
            }

            // Only send a new SMS if no active OTP exists (prevents spam + attempt-counter bypass)
            let smsSent = false
            if (!hasActiveOtp(userId, normalizedId)) {
              if (!/^\+[1-9][0-9]{6,14}$/.test(smsNumber)) {
                console.error(`LOA-2: Invalid phone number format in X-SmsNumber header: ${smsNumber.replace(/[^\x20-\x7E]/g, '?').slice(0, 4)}***`)
                res.status(502).json({
                  error: 'Invalid phone number',
                  message: 'Ogiltigt telefonnummer för SMS-verifiering.'
                })
                return
              }
              if (!checkSmsRateLimit(userId, normalizedId)) {
                console.error(`LOA-2: SMS rate limit exceeded for message ${normalizedId}`)
                res.status(429).json({
                  error: 'Rate limit exceeded',
                  message: 'För många SMS skickade. Försök igen senare.'
                })
                return
              }
              try {
                const code = generateOtp()
                await sendSmsCode(smsNumber, code)
                recordSmsSend(userId, normalizedId)
                storeOtp(userId, normalizedId, code, smsNumber)
                smsSent = true
                console.log(`LOA-2: SMS code sent for message ${normalizedId}`)
              } catch (smsErr) {
                console.error('LOA-2: Failed to send SMS code:', smsErr.message)
                res.status(502).json({
                  error: 'Failed to send verification code',
                  message: 'Kunde inte skicka verifieringskod via SMS. Försök igen senare.'
                })
                return
              }
            }

            res.json({
              id: completedMessage.id,
              requiresVerification: true,
              loaLevel: 2,
              smsSent
            })
            return
          }
        }

        res.json(completedMessage)
      } catch (err) {
        res.status(500).json({ error: 'Failed to fetch message' })
      }
    })
  } catch (error) {
    console.error('Failed to fetch email:', error)
    res.status(500).json({ error: 'Failed to fetch email' })
  }
})

// LOA-2: Verify SMS code (see project-overview#110 / securemail#28)
app.post('/api/emails/:id/verify', async (req, res) => {
  try {
    const userId = req.user[USER_ID_CLAIM]
    if (!userId) {
      return res.status(401).json({ error: 'Invalid token: User identifier not found' })
    }

    const emailId = String(parseInt(req.params.id, 10))
    const { code } = req.body

    if (!code || typeof code !== 'string' || !/^[0-9]{6}$/.test(code)) {
      return res.status(400).json({ error: 'Invalid code', message: 'Koden måste vara 6 siffror.' })
    }

    const result = verifyOtp(userId, emailId, code)

    if (result === 'valid') {
      markVerified(userId, emailId)
      console.log(`LOA-2: Message ${emailId} verified for user ${String(userId).slice(0, 4)}***`)
      return res.json({ verified: true })
    }

    if (result === 'expired' || result === 'not_found') {
      return res.status(410).json({
        error: 'Code expired',
        message: 'Koden har gått ut. Öppna meddelandet igen för att få en ny kod.'
      })
    }

    if (result === 'max_attempts') {
      return res.status(429).json({
        error: 'Too many attempts',
        message: 'För många försök. Öppna meddelandet igen för att få en ny kod.'
      })
    }

    // result === 'invalid'
    return res.status(403).json({
      error: 'Invalid code',
      message: 'Felaktig kod. Försök igen.'
    })
  } catch (error) {
    console.error('LOA-2 verification error:', error)
    res.status(500).json({ error: 'Verification failed', message: 'Ett oväntat fel uppstod. Försök igen.' })
  }
})

// Helper function to decode filename - move this outside other functions
function decodeFilename(params) {
  if (!params) return null
  
  // Try to get filename from either disposition params or regular params
  let filename = params.filename || params.name
  
  if (!filename) {
    // Check for RFC 2231 encoded filename
    filename = params['filename*'] || params['name*']
    if (filename) {
      try {
        // RFC 2231 format is: charset''encoded-text
        const [charset, , encodedName] = filename.split("'")
        if (charset.toLowerCase() === 'utf-8') {
          return decodeURIComponent(encodedName)
        }
      } catch (error) {
        console.error('Failed to decode RFC 2231 filename:', error)
      }
    }
    return null
  }

  // Handle regular MIME encoded filename (=?charset?encoding?encoded-text?=)
  if (filename.startsWith('=?')) {
    try {
      const { decodeWords } = require('mailparser').mimeWordsDecode
      return decodeWords(filename)
    } catch (error) {
      console.error('Failed to decode MIME words filename:', error)
      return filename
    }
  }
  
  return filename
}

// Shared function for finding attachments
function findAttachment(struct, targetPartId = null) {
  const attachments = []

  function buildPartId(index, prefix = '') {
    const partId = prefix ? `${prefix}.${index + 1}` : `${index + 1}`
    console.log('Building part ID:', { index, prefix, partId })  // Debug log
    return partId
  }

  function traverse(parts, prefix = '') {
    if (!Array.isArray(parts)) return null

    for (let i = 0; i < parts.length; i++) {
      const part = parts[i]
      const partId = buildPartId(i, prefix)

      // Process attachments first before recursing
      if (part.disposition && part.disposition.type.toLowerCase() === 'attachment') {
        const info = {
          type: `${part.type}/${part.subtype}`.toLowerCase(),
          filename: decodeFilename(part.disposition.params) || 
                   decodeFilename(part.params) || 
                   'unnamed-attachment',
          size: part.size || 0,
          _partId: partId  // Keep it internally but don't expose it in the API
        }

        // For listing attachments
        if (!targetPartId) {
          // Include all supported attachment types
          const supportedTypes = [
            'application/pdf',
            'image/jpeg', 
            'image/jpg',
            'image/png',
            'application/vnd.oasis.opendocument.text', // ODT
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' // DOCX
          ]
          
          if (supportedTypes.includes(info.type)) {
            // Remove internal _partId before adding to list
            const { _partId, ...publicInfo } = info
            attachments.push(publicInfo)
          }
        }
        // For finding specific attachment
        else if (partId === targetPartId) {
          return info
        }
      }

      // Then recurse into nested parts
      if (Array.isArray(part)) {
        const result = traverse(part, partId)
        if (targetPartId && result) return result
      }
    }
    return targetPartId ? null : attachments
  }

  return traverse(struct, '')
}

// Use in findAttachments
function findAttachments(struct) {
  return findAttachment(struct)
}

// Use in download endpoint
function findAttachmentInfo(struct, targetPartId) {
  return findAttachment(struct, targetPartId)
}

// Helper function to parse message content
async function parseMessageContent(rawContent) {
  try {
    const parsed = (((await simpleParser(rawContent)).text) || '')
    return parsed.trim()
  } catch (error) {
    console.error('Error parsing message content:', error)
    return ''
  }
}

// Create new email
app.post('/api/emails', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token for provider. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }
    let imap;
    try {
      imap = await getImapConnection(userId, accessToken)
    } catch (imapError) {
      console.error('IMAP connection failed:', imapError)
      return res.status(500).json({ 
        error: 'Failed to connect to email server', 
        details: imapError.message 
      })
    }

    // Format email in MIME format
    const message = [
      'From: ' + req.body.from,
      'To: ' + userId + '@securemail',
      'Subject: ' + req.body.subject,
      'Date: ' + new Date().toUTCString(),
      'Content-Type: text/plain; charset=utf-8',
      '',
      req.body.content
    ].join('\r\n')

    // Append the message to the mailbox
    imap.append(message, {mailbox: 'INBOX'}, (err) => {
      if (err) {
        console.error('Failed to append message:', err)
        return res.status(500).json({ error: 'Failed to create message' })
      }

      res.status(201).json({
        ...req.body,
        date: new Date().toISOString()
      })
    })
  } catch (error) {
    console.error('Failed to create email:', error)
    res.status(500).json({ error: 'Failed to create email', details: error.message })
  }
})

// Delete email
app.delete('/api/emails/:id', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }
    let imap;
    try {
      imap = await getImapConnection(userId, accessToken)
    } catch (imapError) {
      console.error('IMAP connection failed:', imapError)
      return res.status(500).json({
        error: 'Failed to connect to email server',
        details: imapError.message
      })
    }

    // LOA-2: Block deletion if message not verified (see project-overview#110)
    const normalizedDelId = String(parseInt(req.params.id, 10))
    if (!isVerified(userId, normalizedDelId)) {
      const loaCheck = await Promise.race([
        new Promise((resolve) => {
          let resolved = false
          const f = imap.fetch(req.params.id, { bodies: ['HEADER.FIELDS (X-LOALEVEL)'] })
          f.on('message', (msg) => {
            msg.on('body', (stream) => {
              let buf = ''
              stream.on('data', (chunk) => { buf += chunk.toString('utf8') })
              stream.once('end', () => {
                if (!resolved) { resolved = true; const v = parseInt(Imap.parseHeader(buf)['x-loalevel']?.[0] || '1', 10); resolve(Number.isNaN(v) ? 2 : v) }
              })
            })
          })
          f.once('error', () => { if (!resolved) { resolved = true; resolve(2) } })
          f.once('end', () => { if (!resolved) { resolved = true; resolve(2) } })
        }),
        new Promise((resolve) => setTimeout(() => resolve(2), 10000))
      ])
      if (loaCheck === 2) {
        return res.status(403).json({
          error: 'Verification required',
          message: 'Du måste verifiera med SMS-kod innan du kan ta bort meddelandet.'
        })
      }
    }

    // Mark message as deleted and expunge
    imap.addFlags(req.params.id, '\\Deleted', (err) => {
      if (err) {
        console.error('Failed to mark message as deleted:', err)
        return res.status(500).json({ error: 'Failed to delete message' })
      }

      imap.expunge((err) => {
        if (err) {
          console.error('Failed to expunge message:', err)
          return res.status(500).json({ error: 'Failed to delete message' })
        }

        res.status(204).send()
      })
    })
  } catch (error) {
    console.error('Failed to delete email:', error)
    res.status(500).json({ error: 'Failed to delete email' })
  }
})


// Helper function to store sent message in INBOX
async function storeSentMessageInInbox(imap, fromEmail, toEmail, subject, content, inReplyTo = null, references = null, attachments = []) {
  try {
    // Create RFC 2822 formatted message
    const currentDate = new Date().toUTCString()
    const messageId = `<${Date.now()}-${Math.random().toString(36).substr(2, 9)}@securemail>`
    
    let emailContent = [
      `From: ${fromEmail}`,
      `To: ${toEmail}`,
      `Subject: ${subject}`,
      `Date: ${currentDate}`,
      `Message-ID: ${messageId}`,
      `X-SecuremailSender: self`,
      `MIME-Version: 1.0`
    ]
    
    // Add threading headers if provided
    if (inReplyTo) {
      emailContent.push(`In-Reply-To: ${inReplyTo}`)
    }
    if (references) {
      emailContent.push(`References: ${references}`)
    }
    
    // Create multipart message if attachments exist
    if (attachments && attachments.length > 0) {
      const boundary = `boundary_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`
      emailContent.push(`Content-Type: multipart/mixed; boundary="${boundary}"`)
      emailContent.push('')
      emailContent.push(`--${boundary}`)
      emailContent.push('Content-Type: text/plain; charset=utf-8')
      emailContent.push('Content-Transfer-Encoding: 8bit')
      emailContent.push('')
      emailContent.push(content)
      
      // Add zero-content attachments
      for (const attachment of attachments) {
        emailContent.push(`--${boundary}`)
        emailContent.push(`Content-Type: ${getMimeType(attachment.name)}`)
        emailContent.push(`Content-Disposition: attachment; filename="${attachment.name}"`)
        emailContent.push('Content-Transfer-Encoding: base64')
        emailContent.push('Content-Length: 0')
        emailContent.push('')
        // No content - this is a zero-content placeholder
        emailContent.push('')
      }
      
      emailContent.push(`--${boundary}--`)
    } else {
      // Simple text message without attachments
      emailContent.push('Content-Type: text/plain; charset=utf-8')
      emailContent.push('Content-Transfer-Encoding: 8bit')
      emailContent.push('')
      emailContent.push(content)
    }
    
    const rawMessage = emailContent.join('\r\n')
    
    // Append to INBOX
    await new Promise((resolve, reject) => {
      imap.append(rawMessage, { mailbox: 'INBOX', flags: ['\\Seen'] }, (err) => {
        if (err) {
          console.error('Failed to store message in INBOX:', err)
          reject(err)
        } else {
          console.log(`✅ Sent message stored in INBOX${attachments.length > 0 ? ` with ${attachments.length} zero-content attachments` : ''}`)
          resolve()
        }
      })
    })
  } catch (error) {
    console.error('Error storing sent message in INBOX:', error)
    throw error
  }
}

// Helper function to get MIME type from filename
function getMimeType(filename) {
  const extension = filename.toLowerCase().split('.').pop()
  const mimeTypes = {
    'jpg': 'image/jpeg',
    'jpeg': 'image/jpeg',
    'png': 'image/png',
    'pdf': 'application/pdf',
    'odt': 'application/vnd.oasis.opendocument.text',
    'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
  }
  return mimeTypes[extension] || 'application/octet-stream'
}

// Function to send read receipt (Message Disposition Notification)
async function sendReadReceipt(recipientEmail, originalSender, originalMessageId, receiptTo) {
  try {
    // Extract email address from potentially formatted sender
    const senderEmail = originalSender.includes('<') 
      ? originalSender.match(/<([^>]+)>/)?.[1] 
      : originalSender

    // Extract email address from receipt destination
    const receiptEmail = receiptTo.includes('<') 
      ? receiptTo.match(/<([^>]+)>/)?.[1] 
      : receiptTo

    // Use the original To field exactly as it is for the From field
    const fromField = recipientEmail

    if (!senderEmail || !receiptEmail || !fromField) {
      console.log('Invalid sender, receipt, or from email addresses, skipping read receipt')
      return
    }

    // Create Message Disposition Notification (MDN) content according to RFC 3798
    const currentTime = new Date().toUTCString()
    
    // Part 1: Human-readable text (Swedish as requested)
    const humanReadableText = [
      'Detta är en läskvittens för det säkra meddelande som du skickade.',
      '',
      'Mottagaren har öppnat det säkra meddelandet.',
      '',
      `Skickat: ${currentTime}`,
      `Från: ${fromField}`,
      `Till: ${senderEmail}`,
      `Ursprungligt meddelande-ID: ${originalMessageId}`
    ].join('\r\n')

    // Part 2: Machine-readable MDN report (RFC 3798 format)
    const mdnReport = [
      'Reporting-UA: SecureMail/1.0; SecureMail Email Client',
      `MDN-Gateway: rfc822; ${fromField}`,
      `Original-Recipient: rfc822; ${receiptEmail}`,
      `Final-Recipient: rfc822; ${fromField}`,
      `Original-Message-ID: ${originalMessageId}`,
      `Disposition: manual-action/MDN-sent-manually; displayed`,
      `X-Display-Date: ${currentTime}`
    ].join('\r\n')

    const mailOptions = {
      from: fromField,
      to: receiptEmail,
      subject: `Disposition Notification`,
      text: humanReadableText,
      headers: {
        'Auto-Submitted': 'auto-replied',
        'In-Reply-To': originalMessageId,
        'References': originalMessageId,
        'X-MDN-Dispose': 'displayed',
        'Precedence': 'auto_reply',
        'X-Mailer': 'SecureMail MDN Generator',
        'Content-Type': 'multipart/report; report-type=disposition-notification'
      },
      // Add the MDN report as an attachment with correct content type
      attachments: [{
        contentType: 'message/disposition-notification',
        content: mdnReport,
        encoding: 'binary'
      }]
    }

    console.log('Attempting to send MDN email with options:', {
      from: mailOptions.from,
      to: mailOptions.to,
      subject: mailOptions.subject,
      hasText: !!mailOptions.text,
      hasAttachments: !!mailOptions.attachments && mailOptions.attachments.length > 0,
      headers: mailOptions.headers
    })

    const result = await smtpTransport.sendMail(mailOptions)
    console.log(`✅ Read receipt successfully sent from ${fromField} to ${receiptEmail}`)
    console.log('SMTP result:', result)
  } catch (error) {
    console.error('Error sending read receipt:', error)
    throw error
  }
}

// Add new endpoint to mark message as read
app.put('/api/emails/:id/read', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }

    let imap;
    try {
      imap = await getImapConnection(userId, accessToken)
    } catch (imapError) {
      console.error('IMAP connection failed:', imapError)
      return res.status(500).json({
        error: 'Failed to connect to email server',
        details: imapError.message
      })
    }

    // LOA-2: Block read receipt + mark-as-read if message not verified (see project-overview#110)
    const normalizedReadId = String(parseInt(req.params.id, 10))
    if (!isVerified(userId, normalizedReadId)) {
      const loaCheck = await Promise.race([
        new Promise((resolve) => {
          let resolved = false
          const f = imap.fetch(req.params.id, { bodies: ['HEADER.FIELDS (X-LOALEVEL)'] })
          f.on('message', (msg) => {
            msg.on('body', (stream) => {
              let buf = ''
              stream.on('data', (chunk) => { buf += chunk.toString('utf8') })
              stream.once('end', () => {
                if (!resolved) { resolved = true; const v = parseInt(Imap.parseHeader(buf)['x-loalevel']?.[0] || '1', 10); resolve(Number.isNaN(v) ? 2 : v) }
              })
            })
          })
          f.once('error', () => { if (!resolved) { resolved = true; resolve(2) } }) // fail-closed
          f.once('end', () => { if (!resolved) { resolved = true; resolve(2) } }) // no message found = fail-closed
        }),
        new Promise((resolve) => setTimeout(() => resolve(2), 10000)) // 10s timeout = fail-closed
      ])
      if (loaCheck === 2) {
        return res.status(403).json({
          error: 'Verification required',
          message: 'Du måste verifiera med SMS-kod innan meddelandet kan markeras som läst.'
        })
      }
    }

    // First, fetch the message headers to check for read receipt requests
    const fetch = imap.fetch(req.params.id, {
      bodies: ['HEADER.FIELDS (FROM TO MESSAGE-ID DISPOSITION-NOTIFICATION-TO RETURN-RECEIPT-TO)']
    })

    fetch.on('message', (msg) => {
      msg.on('body', (stream, info) => {
        let buffer = ''
        stream.on('data', (chunk) => {
          buffer += chunk.toString('utf8')
        })

        stream.once('end', async () => {
          const headers = Imap.parseHeader(buffer)
          const originalSender = headers.from?.[0] || ''
          const originalRecipient = headers.to?.[0] || ''
          const originalMessageId = headers['message-id']?.[0] || ''
          const dispositionNotificationTo = headers['disposition-notification-to']?.[0] || ''
          const returnReceiptTo = headers['return-receipt-to']?.[0] || ''

          console.log('Read receipt check:', {
            originalSender,
            originalRecipient,
            originalMessageId,
            dispositionNotificationTo,
            returnReceiptTo,
            messageId: req.params.id
          })

          // Send read receipt if requested
          if (dispositionNotificationTo || returnReceiptTo) {
            console.log(`Read receipt requested - attempting to send from ${originalRecipient} to ${dispositionNotificationTo || returnReceiptTo}`)
            try {
              await sendReadReceipt(
                originalRecipient, 
                originalSender, 
                originalMessageId, 
                dispositionNotificationTo || returnReceiptTo
              )
              console.log(`✅ Read receipt successfully sent for message ${req.params.id}`)
            } catch (receiptError) {
              console.error('❌ Failed to send read receipt:', receiptError)
              console.error('Receipt error details:', {
                originalRecipient,
                originalSender,
                originalMessageId,
                receiptTo: dispositionNotificationTo || returnReceiptTo,
                error: receiptError.message
              })
              // Don't fail the read marking if receipt sending fails
            }
          } else {
            console.log(`No read receipt requested for message ${req.params.id}`)
          }

          // Mark the message as read
    imap.addFlags(req.params.id, '\\Seen', (err) => {
      if (err) {
        console.error('Failed to mark message as read:', err)
        return res.status(500).json({ error: 'Failed to mark message as read' })
      }
      res.status(200).send()
          })
        })
      })
    })

    fetch.once('error', (err) => {
      console.error('Failed to fetch message for read receipt:', err)
      res.status(500).json({ error: 'Failed to mark as read' })
    })
  } catch (error) {
    console.error('Failed to mark email as read:', error)
    res.status(500).json({ error: 'Failed to mark as read', details: error.message })
  }
})

// Add new endpoint to toggle message read status
app.put('/api/emails/:id/toggle-read', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }
    const imap = await getImapConnection(userId, accessToken)

    // LOA-2: Block toggle-read if message not verified (see project-overview#110)
    const normalizedToggleId = String(parseInt(req.params.id, 10))
    if (!isVerified(userId, normalizedToggleId)) {
      const loaCheck = await Promise.race([
        new Promise((resolve) => {
          let resolved = false
          const f = imap.fetch(req.params.id, { bodies: ['HEADER.FIELDS (X-LOALEVEL)'] })
          f.on('message', (msg) => {
            msg.on('body', (stream) => {
              let buf = ''
              stream.on('data', (chunk) => { buf += chunk.toString('utf8') })
              stream.once('end', () => {
                if (!resolved) { resolved = true; const v = parseInt(Imap.parseHeader(buf)['x-loalevel']?.[0] || '1', 10); resolve(Number.isNaN(v) ? 2 : v) }
              })
            })
          })
          f.once('error', () => { if (!resolved) { resolved = true; resolve(2) } })
          f.once('end', () => { if (!resolved) { resolved = true; resolve(2) } })
        }),
        new Promise((resolve) => setTimeout(() => resolve(2), 10000))
      ])
      if (loaCheck === 2) {
        return res.status(403).json({
          error: 'Verification required',
          message: 'Du måste verifiera med SMS-kod innan du kan ändra lässtatus.'
        })
      }
    }

    // First get current flags
    const fetch = imap.fetch(req.params.id, { flags: true })
    
    fetch.on('message', (msg) => {
      msg.once('attributes', (attrs) => {
        const isRead = attrs.flags?.includes('\\Seen')
        // Toggle the Seen flag
        if (isRead) {
          imap.delFlags(req.params.id, '\\Seen', (err) => {
            if (err) {
              console.error('Failed to mark message as unread:', err)
              return res.status(500).json({ error: 'Failed to mark message as unread' })
            }
            res.status(200).json({ read: false })
          })
        } else {
          imap.addFlags(req.params.id, '\\Seen', (err) => {
            if (err) {
              console.error('Failed to mark message as read:', err)
              return res.status(500).json({ error: 'Failed to mark message as read' })
            }
            res.status(200).json({ read: true })
          })
        }
      })
    })

    fetch.once('error', (err) => {
      console.error('Failed to fetch message flags:', err)
      res.status(500).json({ error: 'Failed to toggle read status' })
    })
  } catch (error) {
    console.error('Failed to toggle read status:', error)
    res.status(500).json({ error: 'Failed to toggle read status' })
  }
})

// Add endpoint to get message flags
app.get('/api/emails/:id/flags', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }
    const imap = await getImapConnection(userId, accessToken)

    // LOA-2: Block flag access if message not verified (see project-overview#110)
    const normalizedFlagId = String(parseInt(req.params.id, 10))
    if (!isVerified(userId, normalizedFlagId)) {
      const loaCheck = await Promise.race([
        new Promise((resolve) => {
          let resolved = false
          const f = imap.fetch(req.params.id, { bodies: ['HEADER.FIELDS (X-LOALEVEL)'] })
          f.on('message', (msg) => {
            msg.on('body', (stream) => {
              let buf = ''
              stream.on('data', (chunk) => { buf += chunk.toString('utf8') })
              stream.once('end', () => {
                if (!resolved) { resolved = true; const v = parseInt(Imap.parseHeader(buf)['x-loalevel']?.[0] || '1', 10); resolve(Number.isNaN(v) ? 2 : v) }
              })
            })
          })
          f.once('error', () => { if (!resolved) { resolved = true; resolve(2) } })
          f.once('end', () => { if (!resolved) { resolved = true; resolve(2) } })
        }),
        new Promise((resolve) => setTimeout(() => resolve(2), 10000))
      ])
      if (loaCheck === 2) {
        return res.status(403).json({
          error: 'Verification required',
          message: 'Du måste verifiera med SMS-kod innan du kan se meddelandets status.'
        })
      }
    }

    const fetch = imap.fetch(req.params.id, { flags: true })

    fetch.on('message', (msg) => {
      msg.once('attributes', (attrs) => {
        const isRead = attrs.flags?.includes('\\Seen')
        res.json({ read: isRead })
      })
    })

    fetch.once('error', (err) => {
      console.error('Failed to fetch message flags:', err)
      res.status(500).json({ error: 'Failed to get message flags' })
    })
  } catch (error) {
    console.error('Failed to get message flags:', error)
    res.status(500).json({ error: 'Failed to get message flags', details: error.message })
  }
})

// Update the download endpoint
app.get('/api/emails/:id/attachments/:index', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }

    const imap = await getImapConnection(userId, accessToken)

    // LOA-2: Block attachment download if message not verified (see project-overview#110)
    const normalizedAttId = String(parseInt(req.params.id, 10))
    if (!isVerified(userId, normalizedAttId)) {
      const loaCheck = await Promise.race([
        new Promise((resolve) => {
          let resolved = false
          const f = imap.fetch(req.params.id, { bodies: ['HEADER.FIELDS (X-LOALEVEL)'] })
          f.on('message', (msg) => {
            msg.on('body', (stream) => {
              let buf = ''
              stream.on('data', (chunk) => { buf += chunk.toString('utf8') })
              stream.once('end', () => {
                if (!resolved) { resolved = true; const v = parseInt(Imap.parseHeader(buf)['x-loalevel']?.[0] || '1', 10); resolve(Number.isNaN(v) ? 2 : v) }
              })
            })
          })
          f.once('error', () => { if (!resolved) { resolved = true; resolve(2) } })
          f.once('end', () => { if (!resolved) { resolved = true; resolve(2) } })
        }),
        new Promise((resolve) => setTimeout(() => resolve(2), 10000))
      ])
      if (loaCheck === 2) {
        return res.status(403).json({
          error: 'Verification required',
          message: 'Du måste verifiera med SMS-kod innan du kan ladda ner bilagor.'
        })
      }
    }

    const fetch = imap.fetch(req.params.id, {
      bodies: ['HEADER.FIELDS (FROM TO SUBJECT DATE)', ''],
      struct: true
    })

    let headersSent = false

    fetch.on('message', (msg) => {
      const chunks = []
      
      msg.on('body', (stream, info) => {
        if (info.which === '') {  // This is the full message body
          stream.on('data', chunk => {
            chunks.push(chunk)
          })
        }
      })

      msg.once('attributes', (attrs) => {
        if (!attrs.struct || headersSent) {
          return
        }

        // Get all attachments first
        const attachments = findAttachment(attrs.struct)
        const index = parseInt(req.params.index)
        const attachmentInfo = attachments[index]

        if (!attachmentInfo) {
          if (!headersSent) {
            headersSent = true
            res.status(404).json({ error: 'Attachment not found' })
          }
          return
        }
      })

      msg.once('end', async () => {
        try {
          // Parse the full message
          const parsed = await simpleParser(Buffer.concat(chunks))
          const index = parseInt(req.params.index)
          
          // Get the attachment at the specified index
          const attachment = parsed.attachments[index]
          
          if (!attachment) {
            if (!headersSent) {
              headersSent = true
              res.status(404).json({ error: 'Attachment not found' })
            }
            return
          }

          if (!headersSent) {
            headersSent = true
            res.setHeader('Content-Type', attachment.contentType)
            res.setHeader('Content-Disposition', `attachment; filename="${attachment.filename}"`)
            res.end(attachment.content)
          }
        } catch (error) {
          console.error('Failed to parse message:', error)
          if (!headersSent) {
            headersSent = true
            res.status(500).json({ error: 'Failed to process attachment' })
          }
        }
      })
    })

    fetch.once('error', (err) => {
      console.error('Fetch error:', err)
      if (!headersSent) {
        headersSent = true
        res.status(500).json({ error: 'Failed to fetch message' })
      }
    })
  } catch (error) {
    console.error('Failed to download attachment:', error)
    res.status(500).json({ error: 'Failed to download attachment' })
  }
})

// Add this after other const declarations
const smtpTransport = nodemailer.createTransport({
  host: process.env.SMTP_HOST || 'localhost',
  port: parseInt(process.env.SMTP_PORT || '1338'),
  secure: false, // plain text
  tls: {
    rejectUnauthorized: false // accept self-signed certificates
  }
})

// Update the reply endpoint to actually send the email
app.post('/api/emails/reply', async (req, res) => {
  try {
    const { messageId, responseText, attachments } = req.body
    
    if (!messageId || !responseText) {
      return res.status(400).json({ error: 'Missing required fields' })
    }

    // Validate response text
    if (typeof responseText !== 'string') {
      return res.status(400).json({ error: 'Response text must be a string' })
    }

    if (responseText.trim().length === 0) {
      return res.status(400).json({ error: 'Response text cannot be empty' })
    }

    if (responseText.length > 10000) {
      return res.status(400).json({ error: 'Response text cannot exceed 10000 characters' })
    }

    // Validate attachments if provided
    if (attachments !== undefined) {
      if (!Array.isArray(attachments)) {
        return res.status(400).json({ error: 'Attachments must be an array' })
      }

      for (const [index, attachment] of attachments.entries()) {
        if (typeof attachment !== 'object' || attachment === null) {
          return res.status(400).json({ error: `Attachment ${index} must be an object` })
        }

        if (typeof attachment.name !== 'string' || attachment.name.trim().length === 0) {
          return res.status(400).json({ error: `Attachment ${index} name must be a non-empty string` })
        }

        if (typeof attachment.data !== 'string' || attachment.data.trim().length === 0) {
          return res.status(400).json({ error: `Attachment ${index} data must be a non-empty string` })
        }

        // Validate base64 format
        try {
          Buffer.from(attachment.data, 'base64')
        } catch (error) {
          return res.status(400).json({ error: `Attachment ${index} data is not valid base64` })
        }

        // Validate file extension (basic security check)
        const allowedExtensions = ['.jpg', '.jpeg', '.png', '.pdf', '.odt', '.docx']
        const fileName = attachment.name.toLowerCase()
        const isValidExtension = allowedExtensions.some(ext => fileName.endsWith(ext))
        
        if (!isValidExtension) {
          return res.status(400).json({ 
            error: `Attachment ${index} has invalid file type. Only images (JPG, PNG) and documents (PDF, ODT, DOCX) are allowed.` 
          })
        }

        // Log attachment info
        console.log(`Reply attachment ${index + 1}: ${attachment.name}, data length: ${attachment.data.length} characters`)
      }

      console.log(`Total reply attachments received: ${attachments.length}`)
    }

    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }

    let imap;
    try {
      imap = await getImapConnection(userId, accessToken)
    } catch (imapError) {
      console.error('IMAP connection failed:', imapError)
      return res.status(500).json({
        error: 'Failed to connect to email server',
        details: imapError.message
      })
    }

    // LOA-2: Block reply if original message not verified (see project-overview#110)
    const normalizedReplyId = String(parseInt(messageId, 10))
    if (!isVerified(userId, normalizedReplyId)) {
      const loaCheck = await Promise.race([
        new Promise((resolve) => {
          let resolved = false
          const f = imap.fetch(messageId, { bodies: ['HEADER.FIELDS (X-LOALEVEL)'] })
          f.on('message', (msg) => {
            msg.on('body', (stream) => {
              let buf = ''
              stream.on('data', (chunk) => { buf += chunk.toString('utf8') })
              stream.once('end', () => {
                if (!resolved) { resolved = true; const v = parseInt(Imap.parseHeader(buf)['x-loalevel']?.[0] || '1', 10); resolve(Number.isNaN(v) ? 2 : v) }
              })
            })
          })
          f.once('error', () => { if (!resolved) { resolved = true; resolve(2) } })
          f.once('end', () => { if (!resolved) { resolved = true; resolve(2) } })
        }),
        new Promise((resolve) => setTimeout(() => resolve(2), 10000))
      ])
      if (loaCheck === 2) {
        return res.status(403).json({
          error: 'Verification required',
          message: 'Du måste verifiera med SMS-kod innan du kan svara på meddelandet.'
        })
      }
    }

    // Fetch the original message to get sender, subject, Message-ID and To field
    const fetch = imap.fetch(messageId, {
      bodies: ['HEADER.FIELDS (FROM TO SUBJECT MESSAGE-ID X-SMSNUMBER X-LOALEVEL)']
    })

    fetch.on('message', (msg) => {
      msg.on('body', (stream, info) => {
        let buffer = ''
        stream.on('data', (chunk) => {
          buffer += chunk.toString('utf8')
        })
        
        stream.once('end', async () => {
          const headers = Imap.parseHeader(buffer)
          const originalSender = headers.from?.[0] || 'unknown'
          const originalRecipient = headers.to?.[0] || ''
          const originalSubject = headers.subject?.[0] || '(no subject)'
          const originalMessageId = headers['message-id']?.[0]
          const originalSmsNumber = headers['x-smsnumber']?.[0] || ''
          const rawLoaLevel = parseInt(headers['x-loalevel']?.[0] || '1', 10)
          const originalLoaLevel = Number.isNaN(rawLoaLevel) ? 2 : rawLoaLevel

          // Extract the reply-from email using the same format as read receipts
          // Use the original To field exactly as it is for the From field
          const replyFromEmail = originalRecipient
          
          // Log the reply information
          console.log('New reply:', {
            originalMessageId: messageId,
            from: replyFromEmail,
            to: originalSender,
            subject: `Re: ${originalSubject}`,
            responseText: responseText,
            inReplyTo: originalMessageId
          })

          // Prepare attachments for Nodemailer
          const nodemailerAttachments = []
          if (attachments && attachments.length > 0) {
            for (const attachment of attachments) {
              nodemailerAttachments.push({
                filename: attachment.name,
                content: attachment.data,
                encoding: 'base64'
                // Nodemailer will auto-detect contentType from filename
              })
            }
            console.log(`Prepared ${nodemailerAttachments.length} attachments for reply`)
          }

          // Send the email via SMTP with threading headers
          try {
            const replySubject = originalSubject.startsWith('Re:') 
              ? originalSubject 
              : `Re: ${originalSubject}`
            
            const replyHeaders = {}
            if (originalSmsNumber) replyHeaders['X-SmsNumber'] = originalSmsNumber
            if (originalLoaLevel > 1) replyHeaders['X-LoaLevel'] = String(originalLoaLevel)

            await smtpTransport.sendMail({
              from: replyFromEmail,
              to: originalSender,
              subject: replySubject,
              text: responseText,
              inReplyTo: originalMessageId,
              references: originalMessageId,
              attachments: nodemailerAttachments,
              headers: replyHeaders
            })
            
            // Store a copy in INBOX
            try {
              // Convert attachment data to metadata (name only, no content)
              const attachmentMetadata = attachments && attachments.length > 0 
                ? attachments.map(att => ({ name: att.name }))
                : []
              
              await storeSentMessageInInbox(
                imap,
                replyFromEmail,
                originalSender,
                replySubject,
                responseText,
                originalMessageId,
                originalMessageId,
                attachmentMetadata
              )
            } catch (storeError) {
              console.error('Failed to store reply in INBOX:', storeError)
              // Don't fail the whole operation if storing fails
            }
            
            res.status(200).send()
          } catch (error) {
            console.error('SMTP error:', error)
            res.status(500).json({ error: 'Failed to send email' })
          }
        })
      })
    })

    fetch.once('error', (err) => {
      console.error('Failed to fetch original message:', err)
      res.status(500).json({ error: 'Failed to process reply' })
    })
  } catch (error) {
    console.error('Failed to process reply:', error)
    res.status(500).json({ error: 'Failed to process reply', details: error.message })
  }
})

// Helper function to fetch recipients from external API
async function fetchAllowedRecipients() {
  try {
    const response = await axios.get(RECIPIENTS_API_URL, {
      headers: {
        'X-Api-Token': RECIPIENTS_API_TOKEN,
      }
    })
    return response.data
  } catch (error) {
    console.error('Error fetching recipients from external API:', error)
    throw error
  }
}

// Recipients endpoint
app.get('/api/get-recipients', async (req, res) => {
  try {
    const recipients = await fetchAllowedRecipients()
    res.json(recipients)
  } catch (error) {
    console.error('Error fetching recipients:', error)
    res.status(500).json({
      error: 'Failed to fetch recipients',
      details: error.message
    })
  }
})

// Add new endpoint to send message
app.post('/api/emails/new', async (req, res) => {
  try {
    const accessToken = req.headers.authorization.split(' ')[1]
    // Use the configured USER_ID_CLAIM from req.user
    const userId = req.user[USER_ID_CLAIM];
    if (!userId) {
      console.error(`API call failed: User ID claim '${USER_ID_CLAIM}' not found in token. Token:`, req.user);
      return res.status(401).json({ error: 'Invalid token: User identifier not found' });
    }

    const { from, to, subject, content, attachments } = req.body

    if (!from || !to || !subject || !content) {
      return res.status(400).json({ error: 'Missing required fields' })
    }

    if (typeof from !== 'string' || from.trim().length === 0) {
      return res.status(400).json({ error: 'From must be a non-empty string' })
    }

    if (typeof subject !== 'string' || subject.trim().length === 0) {
      return res.status(400).json({ error: 'Subject must be a non-empty string.' })
    }

    if (typeof content !== 'string' || content.trim().length === 0) {
      return res.status(400).json({ error: 'Message content must be a non-empty string.' })
    }

    if (content.length > 10000) {
      return res.status(400).json({ error: 'Message content cannot exceed 10000 characters' })
    }

    // Validate attachments if provided
    if (attachments !== undefined) {
      if (!Array.isArray(attachments)) {
        return res.status(400).json({ error: 'Attachments must be an array' })
      }

      for (const [index, attachment] of attachments.entries()) {
        if (typeof attachment !== 'object' || attachment === null) {
          return res.status(400).json({ error: `Attachment ${index} must be an object` })
        }

        if (typeof attachment.name !== 'string' || attachment.name.trim().length === 0) {
          return res.status(400).json({ error: `Attachment ${index} name must be a non-empty string` })
        }

        if (typeof attachment.data !== 'string' || attachment.data.trim().length === 0) {
          return res.status(400).json({ error: `Attachment ${index} data must be a non-empty string` })
        }

        // Validate base64 format
        try {
          Buffer.from(attachment.data, 'base64')
        } catch (error) {
          return res.status(400).json({ error: `Attachment ${index} data is not valid base64` })
        }

        // Validate file extension (basic security check)
        const allowedExtensions = ['.jpg', '.jpeg', '.png', '.pdf', '.odt', '.docx']
        const fileName = attachment.name.toLowerCase()
        const isValidExtension = allowedExtensions.some(ext => fileName.endsWith(ext))
        
        if (!isValidExtension) {
          return res.status(400).json({ 
            error: `Attachment ${index} has invalid file type. Only images (JPG, PNG) and documents (PDF, ODT, DOCX) are allowed.` 
          })
        }

        // Log attachment info
        console.log(`Attachment ${index + 1}: ${attachment.name}, data length: ${attachment.data.length} characters`)
      }

      console.log(`Total attachments received: ${attachments.length}`)
    }

    // Verify recipient is allowed
    const allowedRecipients = await fetchAllowedRecipients()
    const recipient = allowedRecipients.find(r => r.email === to)
    if (!recipient) {
      return res.status(400).json({ error: 'Invalid recipient' })
    }

    // Log the reply information
    console.log('New message:', {
      from,
      to,
      subject,
      content,
    })

    // Prepare attachments for Nodemailer
    const nodemailerAttachments = []
    if (attachments && attachments.length > 0) {
      for (const attachment of attachments) {
        nodemailerAttachments.push({
          filename: attachment.name,
          content: attachment.data,
          encoding: 'base64'
          // Nodemailer will auto-detect contentType from filename
        })
      }
      console.log(`Prepared ${nodemailerAttachments.length} attachments for sending`)
    }

    // Prepare email headers
    const mailOptions = {
      from: `${from}.${req.user[USER_ID_CLAIM]}.securemail`,
      to: to,
      subject: subject,
      text: content,
      attachments: nodemailerAttachments
    }

    // Add read receipt headers if requested
    const { requestReadReceipt } = req.body
    if (requestReadReceipt === true) {
      mailOptions.headers = {
        'Disposition-Notification-To': `${from}.${req.user[USER_ID_CLAIM]}.securemail`,
        'Return-Receipt-To': `${from}.${req.user[USER_ID_CLAIM]}.securemail`
      }
      console.log('Read receipt requested for outgoing message')
    }

    // Get IMAP connection for storing sent message
    let imap;
    try {
      imap = await getImapConnection(userId, accessToken)
    } catch (imapError) {
      console.error('IMAP connection failed:', imapError)
      return res.status(500).json({ 
        error: 'Failed to connect to email server', 
        details: imapError.message 
      })
    }

    // Send the email via SMTP
    try {
      await smtpTransport.sendMail(mailOptions)

      // Store a copy in INBOX
      try {
        // Convert attachment data to metadata (name only, no content)
        const attachmentMetadata = attachments && attachments.length > 0 
          ? attachments.map(att => ({ name: att.name }))
          : []
        
        await storeSentMessageInInbox(
          imap,
          `${from}.${req.user[USER_ID_CLAIM]}.securemail`,
          to,
          subject,
          content,
          null, // no inReplyTo for new messages
          null, // no references for new messages  
          attachmentMetadata
        )
      } catch (storeError) {
        console.error('Failed to store new message in INBOX:', storeError)
        // Don't fail the whole operation if storing fails
      }

      res.status(201).end()
    } catch (error) {
      console.error('SMTP error:', error)
      res.status(500).json({ error: 'Failed to send email' })
    }
  } catch (error) {
    console.error('Failed to send message:', error)
    res.status(500).json({ error: 'Failed to send message', details: error.message })
  }
})

// Organizational email endpoint - get email by UUID
app.get('/api/org/emails/:uuid', async (req, res) => {
  try {
    const uuid = req.params.uuid
    console.log(`Searching for org email with UUID: ${uuid}`)

    // Create org IMAP connection
    const imap = createOrgImapConnection()
    
    // Connect to IMAP server
    await new Promise((resolve, reject) => {
      const cleanup = () => {
        imap.removeAllListeners()
      }

      imap.once('ready', () => {
        console.log('Org IMAP connection ready, opening INBOX')
        imap.openBox('INBOX', true, (err) => { // Read-only mode
          if (err) {
            cleanup()
            reject(err)
          } else {
            resolve()
          }
        })
      })

      imap.on('error', (err) => {
        console.error('Org IMAP connection error:', err)
        cleanup()
        reject(err)
      })

      // Add connection timeout
      const connectionTimeout = setTimeout(() => {
        cleanup()
        reject(new Error('Org IMAP connection timeout'))
      }, 30000)

      imap.once('ready', () => {
        clearTimeout(connectionTimeout)
      })

      console.log('Connecting org IMAP...')
      imap.connect()
    })

    // Search for emails with the specific X-Org-Auth-Token header
    const searchResults = await new Promise((resolve, reject) => {
      // Try different search syntax - HEADER should have field name and value as separate arguments
      const headerSearchTerm = `X-Org-Auth-Token ${uuid}`
      console.log('Searching for header:', headerSearchTerm)
      
      // Alternative approach: search for all messages first, then filter by header manually
      imap.search(['ALL'], (err, results) => {
        if (err) {
          console.error('Search error:', err)
          reject(err)
        } else {
          console.log(`Found ${results ? results.length : 0} total messages, will filter by header`)
          resolve(results || [])
        }
      })
    })

    if (searchResults.length === 0) {
      imap.end()
      return res.status(404).json({ error: 'Message not found' })
    }

    // Now fetch all messages and filter by X-Org-Auth-Token header
    let matchingMessage = null
    
    for (const messageId of searchResults) {
      console.log(`Checking message ${messageId} for UUID ${uuid}`)
      
      const message = await new Promise((resolve, reject) => {
        const fetch = imap.fetch(messageId, {
          bodies: ['HEADER.FIELDS (FROM TO SUBJECT DATE X-ORG-AUTH-TOKEN X-LOALEVEL X-SMSNUMBER)', ''],
          struct: true
        })

        let messageData = {
          id: messageId,
          subject: '',
          from: '',
          to: '',
          date: '',
          content: '',
          attachments: [],
          headers: {},
          struct: null,
          isOrgMessage: true,
          loaLevel: 1,
          smsNumber: null
        }

        const chunks = []

        fetch.on('message', (msg) => {
          msg.on('body', (stream, info) => {
            let buffer = ''
            stream.on('data', (chunk) => {
              buffer += chunk.toString('utf8')
            })
            stream.once('end', () => {
              if (info.which !== '') {
                // Parse headers using the same logic as regular emails
                const headers = Imap.parseHeader(buffer)
                messageData.headers = headers
                messageData.subject = headers.subject?.[0] || '(no subject)'
                messageData.date = headers.date?.[0] || new Date().toISOString()
                messageData.from = formatSender(headers.from?.[0] || 'unknown')
                messageData.to = formatSender(headers.to?.[0] || 'unknown')

                // Parse LOA-2 headers (see project-overview#110)
                const rawLoa = parseInt(headers['x-loalevel']?.[0] || '1', 10)
                messageData.loaLevel = Number.isNaN(rawLoa) ? 2 : rawLoa // fail-closed
                messageData.smsNumber = headers['x-smsnumber']?.[0] || null
              } else {
                chunks.push(buffer)
              }
            })
          })

          msg.once('attributes', (attrs) => {
            messageData.uid = attrs.uid
            if (attrs.struct) {
              messageData.attachments = findAttachments(attrs.struct)
            }
          })

          msg.once('end', async () => {
            // Parse the content using the same function as regular emails
            messageData.content = await parseMessageContent(chunks.join('\n'))
            resolve(messageData)
          })
        })

        fetch.once('error', reject)
      })

      // Check if this message has the matching UUID
      const orgToken = message.headers['x-org-auth-token']
      console.log(`Message ${messageId} has X-Org-Auth-Token:`, orgToken)
      
      if (orgToken && orgToken.includes(uuid)) {
        console.log(`Found matching message ${messageId} for UUID ${uuid}`)
        matchingMessage = message
        break
      }
    }

    if (!matchingMessage) {
      imap.end()
      return res.status(404).json({ error: 'Message not found' })
    }

    const message = matchingMessage

    // Close IMAP connection
    imap.end()

    // Extract the actual UUID from the header (preserving original case)
    const headerUuid = message.headers['x-org-auth-token']?.[0] || uuid

    // LOA-2 SMS verification gate for org messages (see project-overview#110)
    if (message.loaLevel === 2 && message.smsNumber) {
      const ticket = req.headers['x-verify-ticket']
      if (!validateTicket(ticket, uuid)) {
        // Check lockout before anything else
        if (isLockedOut(uuid, uuid)) {
          return res.status(429).json({
            error: 'Too many attempts',
            message: 'För många felaktiga försök. Vänta en stund innan du försöker igen.'
          })
        }

        // Only send a new SMS if no active OTP exists
        let smsSent = false
        if (!hasActiveOtp(uuid, uuid)) {
          if (!/^\+[1-9][0-9]{6,14}$/.test(message.smsNumber)) {
            console.error(`LOA-2 org: Invalid phone number format in X-SmsNumber header: ${message.smsNumber.replace(/[^\x20-\x7E]/g, '?').slice(0, 4)}***`)
            return res.status(502).json({
              error: 'Invalid phone number',
              message: 'Ogiltigt telefonnummer för SMS-verifiering.'
            })
          }
          if (!checkSmsRateLimit(uuid, uuid)) {
            console.error(`LOA-2 org: SMS rate limit exceeded for message ${uuid}`)
            return res.status(429).json({
              error: 'Rate limit exceeded',
              message: 'För många SMS skickade. Försök igen senare.'
            })
          }
          try {
            const code = generateOtp()
            await sendSmsCode(message.smsNumber, code)
            recordSmsSend(uuid, uuid)
            storeOtp(uuid, uuid, code, message.smsNumber)
            smsSent = true
            console.log(`LOA-2 org: SMS code sent for message ${uuid}`)
          } catch (smsErr) {
            console.error('LOA-2 org: Failed to send SMS code:', smsErr.message)
            return res.status(502).json({
              error: 'Failed to send verification code',
              message: 'Kunde inte skicka verifieringskod via SMS. Försök igen senare.'
            })
          }
        }

        return res.json({
          id: headerUuid,
          requiresVerification: true,
          loaLevel: 2,
          smsSent,
          uuid: headerUuid,
          isOrgMessage: true
        })
      }
    }

    // Format response exactly like regular email format
    const formattedMessage = {
      id: headerUuid, // Use UUID from header instead of internal message ID
      subject: message.subject,
      from: message.from,
      to: message.to,
      date: message.date,
      content: message.content,
      attachments: message.attachments,
      uuid: headerUuid,
      isOrgMessage: true
    }

    console.log(`Successfully retrieved org message for UUID: ${uuid}`)
    res.json(formattedMessage)

  } catch (error) {
    console.error('Failed to fetch org email:', error)
    res.status(500).json({ 
      error: 'Failed to fetch organizational email', 
      details: error.message 
    })
  }
})

// LOA-2: Verify SMS code for org messages (see project-overview#110)
app.post('/api/org/emails/:uuid/verify', async (req, res) => {
  try {
    const uuid = req.params.uuid
    const { code } = req.body

    if (!code || typeof code !== 'string' || !/^[0-9]{6}$/.test(code)) {
      return res.status(400).json({ error: 'Invalid code', message: 'Koden måste vara 6 siffror.' })
    }

    const result = verifyOtp(uuid, uuid, code)

    if (result === 'valid') {
      const ticket = generateTicket(uuid)
      console.log(`LOA-2 org: Message ${uuid.slice(0, 8)}*** verified, ticket issued`)
      return res.json({ verified: true, ticket })
    }

    if (result === 'expired' || result === 'not_found') {
      return res.status(410).json({
        error: 'Code expired',
        message: 'Koden har gått ut. Öppna meddelandet igen för att få en ny kod.'
      })
    }

    if (result === 'max_attempts') {
      return res.status(429).json({
        error: 'Too many attempts',
        message: 'För många försök. Öppna meddelandet igen för att få en ny kod.'
      })
    }

    // result === 'invalid'
    return res.status(403).json({
      error: 'Invalid code',
      message: 'Felaktig kod. Försök igen.'
    })
  } catch (error) {
    console.error('LOA-2 org verification error:', error)
    res.status(500).json({ error: 'Verification failed', message: 'Ett oväntat fel uppstod. Försök igen.' })
  }
})

// Organizational email attachment download endpoint
app.get('/api/org/emails/:uuid/attachments/:index', async (req, res) => {
  try {
    const uuid = req.params.uuid
    const index = parseInt(req.params.index)
    console.log(`Downloading attachment ${index} for org email UUID: ${uuid}`)

    // Create org IMAP connection
    const imap = createOrgImapConnection()
    
    // Connect to IMAP server
    await new Promise((resolve, reject) => {
      const cleanup = () => {
        imap.removeAllListeners()
      }

      imap.once('ready', () => {
        console.log('Org IMAP connection ready for attachment download')
        imap.openBox('INBOX', true, (err) => { // Read-only mode
          if (err) {
            cleanup()
            reject(err)
          } else {
            resolve()
          }
        })
      })

      imap.on('error', (err) => {
        console.error('Org IMAP connection error during attachment download:', err)
        cleanup()
        reject(err)
      })

      const connectionTimeout = setTimeout(() => {
        cleanup()
        reject(new Error('Org IMAP connection timeout during attachment download'))
      }, 30000)

      imap.once('ready', () => {
        clearTimeout(connectionTimeout)
      })

      imap.connect()
    })

    // Search for the message with the UUID
    const searchResults = await new Promise((resolve, reject) => {
      imap.search(['ALL'], (err, results) => {
        if (err) {
          reject(err)
        } else {
          resolve(results || [])
        }
      })
    })

    // Find the matching message
    let targetMessageId = null
    for (const messageId of searchResults) {
      const messageInfo = await new Promise((resolve, reject) => {
        const fetch = imap.fetch(messageId, {
          bodies: 'HEADER.FIELDS (X-ORG-AUTH-TOKEN X-LOALEVEL)',
        })

        let info = { foundUuid: false, loaLevel: 1 }
        fetch.on('message', (msg) => {
          msg.on('body', (stream) => {
            let buffer = ''
            stream.on('data', (chunk) => {
              buffer += chunk.toString('utf8')
            })
            stream.once('end', () => {
              const headers = Imap.parseHeader(buffer)
              const orgToken = headers['x-org-auth-token']
              if (orgToken && orgToken.includes(uuid)) {
                info.foundUuid = true
              }
              const rawLoa = parseInt(headers['x-loalevel']?.[0] || '1', 10)
              info.loaLevel = Number.isNaN(rawLoa) ? 2 : rawLoa // fail-closed
            })
          })
        })

        fetch.once('error', reject)
        fetch.once('end', () => {
          resolve(info)
        })
      })

      if (messageInfo.foundUuid) {
        targetMessageId = messageId
        // LOA-2: Block attachment download if not verified (see project-overview#110)
        const ticket = req.headers['x-verify-ticket']
        if (messageInfo.loaLevel === 2 && !validateTicket(ticket, uuid)) {
          imap.end()
          return res.status(403).json({
            error: 'Verification required',
            message: 'Du måste verifiera med SMS-kod innan du kan ladda ner bilagor.'
          })
        }
        break
      }
    }

    if (!targetMessageId) {
      imap.end()
      return res.status(404).json({ error: 'Message not found' })
    }

    // Fetch the message to get attachment
    const attachment = await new Promise((resolve, reject) => {
      const fetch = imap.fetch(targetMessageId, {
        bodies: '',
        struct: true
      })

      let headersSent = false
      fetch.on('message', (msg) => {
        const chunks = []

        msg.on('body', (stream) => {
          stream.on('data', (chunk) => {
            chunks.push(chunk)
          })
        })

        msg.once('attributes', (attrs) => {
          // Get all attachments first
          const attachments = findAttachment(attrs.struct)
          const attachmentInfo = attachments[index]

          if (!attachmentInfo) {
            if (!headersSent) {
              headersSent = true
              imap.end()
              res.status(404).json({ error: 'Attachment not found' })
            }
            return
          }

          msg.once('end', async () => {
            try {
              // Parse the full message
              const parsed = await simpleParser(Buffer.concat(chunks))
              
              // Get the attachment at the specified index
              const attachment = parsed.attachments[index]
              
              if (!attachment) {
                if (!headersSent) {
                  headersSent = true
                  imap.end()
                  res.status(404).json({ error: 'Attachment not found' })
                }
                return
              }

              if (!headersSent) {
                headersSent = true
                // Set response headers for file download
                res.setHeader('Content-Type', attachment.contentType || 'application/octet-stream')
                res.setHeader('Content-Disposition', `attachment; filename="${attachment.filename || 'attachment'}"`)
                res.setHeader('Content-Length', attachment.content.length)
                
                // Send the attachment content
                res.send(attachment.content)
                imap.end()
                resolve(attachment)
              }
            } catch (error) {
              console.error('Error processing attachment:', error)
              if (!headersSent) {
                headersSent = true
                imap.end()
                res.status(500).json({ error: 'Failed to process attachment' })
              }
              reject(error)
            }
          })
        })
      })

      fetch.once('error', (err) => {
        console.error('Fetch error during attachment download:', err)
        if (!headersSent) {
          headersSent = true
          imap.end()
          res.status(500).json({ error: 'Failed to fetch attachment' })
        }
        reject(err)
      })
    })

  } catch (error) {
    console.error('Failed to download org email attachment:', error)
    res.status(500).json({ 
      error: 'Failed to download attachment', 
      details: error.message 
    })
  }
})

// Organizational email reply endpoint
app.post('/api/org/emails/reply', async (req, res) => {
  try {
    const { messageUuid, responseText, attachments } = req.body
    
    if (!messageUuid || !responseText) {
      return res.status(400).json({ error: 'Missing required fields' })
    }

    // Validate response text
    if (typeof responseText !== 'string') {
      return res.status(400).json({ error: 'Response text must be a string' })
    }

    if (responseText.trim().length === 0) {
      return res.status(400).json({ error: 'Response text cannot be empty' })
    }

    if (responseText.length > 10000) {
      return res.status(400).json({ error: 'Response text cannot exceed 10000 characters' })
    }

    // Validate attachments if provided
    if (attachments !== undefined) {
      if (!Array.isArray(attachments)) {
        return res.status(400).json({ error: 'Attachments must be an array' })
      }

      for (const [index, attachment] of attachments.entries()) {
        if (typeof attachment !== 'object' || attachment === null) {
          return res.status(400).json({ error: `Attachment ${index} must be an object` })
        }

        if (typeof attachment.name !== 'string' || attachment.name.trim().length === 0) {
          return res.status(400).json({ error: `Attachment ${index} name must be a non-empty string` })
        }

        if (typeof attachment.data !== 'string' || attachment.data.trim().length === 0) {
          return res.status(400).json({ error: `Attachment ${index} data must be a non-empty string` })
        }

        // Validate base64 format
        try {
          Buffer.from(attachment.data, 'base64')
        } catch (error) {
          return res.status(400).json({ error: `Attachment ${index} data is not valid base64` })
        }

        // Validate file extension (basic security check)
        const allowedExtensions = ['.jpg', '.jpeg', '.png', '.pdf', '.odt', '.docx']
        const fileName = attachment.name.toLowerCase()
        const isValidExtension = allowedExtensions.some(ext => fileName.endsWith(ext))
        
        if (!isValidExtension) {
          return res.status(400).json({ 
            error: `Attachment ${index} has invalid file type. Only images (JPG, PNG) and documents (PDF, ODT, DOCX) are allowed.` 
          })
        }

        // Log attachment info
        console.log(`Org reply attachment ${index + 1}: ${attachment.name}, data length: ${attachment.data.length} characters`)
      }

      console.log(`Total org reply attachments received: ${attachments.length}`)
    }

    console.log('Processing org email reply for UUID:', messageUuid)

    // Create org IMAP connection
    const imap = createOrgImapConnection()
    
    // Connect to IMAP server
    await new Promise((resolve, reject) => {
      const cleanup = () => {
        imap.removeAllListeners()
      }

      imap.once('ready', () => {
        console.log('Org IMAP connection ready for reply processing')
        imap.openBox('INBOX', true, (err) => { // Read-only mode
          if (err) {
            cleanup()
            reject(err)
          } else {
            resolve()
          }
        })
      })

      imap.on('error', (err) => {
        console.error('Org IMAP connection error during reply processing:', err)
        cleanup()
        reject(err)
      })

      const connectionTimeout = setTimeout(() => {
        cleanup()
        reject(new Error('Org IMAP connection timeout during reply processing'))
      }, 30000)

      imap.once('ready', () => {
        clearTimeout(connectionTimeout)
      })

      imap.connect()
    })

    // Search for the original message
    const searchResults = await new Promise((resolve, reject) => {
      imap.search(['ALL'], (err, results) => {
        if (err) {
          reject(err)
        } else {
          resolve(results || [])
        }
      })
    })

    // Find the matching message
    let originalMessage = null
    for (const messageId of searchResults) {
      const messageData = await new Promise((resolve, reject) => {
        const fetch = imap.fetch(messageId, {
          bodies: ['HEADER.FIELDS (FROM TO SUBJECT MESSAGE-ID X-ORG-AUTH-TOKEN X-LOALEVEL X-SMSNUMBER)', ''],
          struct: true
        })

        let data = {
          id: messageId,
          headers: {},
          body: '',
          hasUuid: false,
          loaLevel: 1
        }

        const chunks = []

        fetch.on('message', (msg) => {
          msg.on('body', (stream, info) => {
            let buffer = ''
            stream.on('data', (chunk) => {
              buffer += chunk.toString('utf8')
            })
            stream.once('end', () => {
              if (info.which !== '') {
                const headers = Imap.parseHeader(buffer)
                data.headers = headers
                
                // Check if this message has the matching UUID
                const orgToken = headers['x-org-auth-token']
                if (orgToken && orgToken.includes(messageUuid)) {
                  data.hasUuid = true
                }
                const rawLoa = parseInt(headers['x-loalevel']?.[0] || '1', 10)
                data.loaLevel = Number.isNaN(rawLoa) ? 2 : rawLoa // fail-closed
                data.smsNumber = headers['x-smsnumber']?.[0] || ''
              } else {
                chunks.push(buffer)
              }
            })
          })

          msg.once('end', () => {
            data.body = chunks.join('\n')
            resolve(data)
          })
        })

        fetch.once('error', reject)
      })

      if (messageData.hasUuid) {
        originalMessage = messageData
        break
      }
    }

    if (!originalMessage) {
      imap.end()
      return res.status(404).json({ error: 'Original message not found' })
    }

    // LOA-2: Block reply if not verified (see project-overview#110)
    if (originalMessage.loaLevel === 2) {
      const ticket = req.headers['x-verify-ticket']
      if (!validateTicket(ticket, messageUuid)) {
        imap.end()
        return res.status(403).json({
          error: 'Verification required',
          message: 'Du måste verifiera med SMS-kod innan du kan svara.'
        })
      }
    }

    // Extract original message details
    const originalSender = originalMessage.headers.from?.[0] || 'unknown'
    const originalRecipient = originalMessage.headers.to?.[0] || 'org@securemail'
    const originalSubject = originalMessage.headers.subject?.[0] || '(no subject)'
    const originalMessageId = originalMessage.headers['message-id']?.[0]

    // For org replies, use the original To field as From (same as regular replies)
    const replyFromEmail = originalRecipient
    const replyToEmail = originalSender

    console.log('Org reply details:', {
      messageUuid,
      from: replyFromEmail,
      to: replyToEmail,
      subject: `Re: ${originalSubject}`,
      inReplyTo: originalMessageId
    })

    // Close IMAP connection
    imap.end()

    // Prepare attachments for Nodemailer
    const nodemailerAttachments = []
    if (attachments && attachments.length > 0) {
      for (const attachment of attachments) {
        nodemailerAttachments.push({
          filename: attachment.name,
          content: attachment.data,
          encoding: 'base64'
          // Nodemailer will auto-detect contentType from filename
        })
      }
      console.log(`Prepared ${nodemailerAttachments.length} attachments for org reply`)
    }

    // Send the reply via SMTP
    try {
      const replySubject = originalSubject.startsWith('Re:') 
        ? originalSubject 
        : `Re: ${originalSubject}`
      
      await smtpTransport.sendMail({
        from: replyFromEmail,
        to: replyToEmail,
        subject: replySubject,
        text: responseText,
        inReplyTo: originalMessageId,
        references: originalMessageId,
        attachments: nodemailerAttachments,
        headers: {
          'X-Org-Reply-To': messageUuid,
          ...(originalMessage.smsNumber && { 'X-SmsNumber': originalMessage.smsNumber }),
          ...(originalMessage.loaLevel > 1 && { 'X-LoaLevel': String(originalMessage.loaLevel) })
        }
      })
      
      console.log('Org reply sent successfully')
      res.status(200).json({ message: 'Reply sent successfully' })
    } catch (error) {
      console.error('SMTP error during org reply:', error)
      res.status(500).json({ error: 'Failed to send reply' })
    }

  } catch (error) {
    console.error('Failed to process org reply:', error)
    res.status(500).json({ 
      error: 'Failed to process reply', 
      details: error.message 
    })
  }
})

console.log("server.js: About to call server.listen()");
// Start the server
server.listen(port, () => {
  console.log("server.js: server.listen() callback executed.");
  console.log(`Securemail backend server listening at port ${port}`)
})

async function extractUserIdentity(accessToken, idToken) {
  // 3-step identity cascade — backend does NOT verify tokens, just extracts identity.
  // Dovecot is the auth authority (validates via userinfo on IMAP XOAUTH2).

  // Step 1: try decode access_token as JWT (GrandID, ADFS — JWT access_tokens)
  const decodedAccess = jwt.decode(accessToken, { complete: true })
  if (decodedAccess && decodedAccess.payload) {
    return decodedAccess.payload
  }

  // Step 2: try decode id_token as JWT (Nexus — opaque access_token, JWT id_token)
  if (idToken) {
    const decodedId = jwt.decode(idToken, { complete: true })
    if (decodedId && decodedId.payload) {
      console.log('[extractUserIdentity] Fallback: identity from JWT id_token')
      return decodedId.payload
    }
  }

  // Step 3: call userinfo with access_token (last resort — both tokens opaque)
  const userInfoUrl = process.env.SSO_ENDPOINT_USERINFO
  if (!userInfoUrl) throw new Error('SSO_ENDPOINT_USERINFO not configured')
  try {
    const resp = await axios.get(userInfoUrl, { headers: { Authorization: 'Bearer ' + accessToken } })
    if (resp.data && typeof resp.data === 'object') {
      console.log('[extractUserIdentity] Fallback: identity from userinfo endpoint')
      return resp.data
    }
    throw new Error('Empty userinfo response')
  } catch (err) {
    console.error('[extractUserIdentity] userinfo call failed:', err.message)
    throw err
  }
}

// Add connection cleanup on process exit
process.on('SIGINT', () => {
  console.log('Cleaning up IMAP connections...')
  for (const [userId, connection] of imapConnections.entries()) {
    try {
      connection.end()
    } catch (err) {
      console.error(`Error closing IMAP connection for ${userId}:`, err)
    }
  }
  imapConnections.clear()
  process.exit(0)
})


function formatSender(sender) {
  /**
   * Format the sender information to show either the name or username part.
   * 
   * Examples:
   *   "John Doe <john.doe@example.com>" -> "John Doe"
   *   "jane.smith@example.com" -> "jane.smith"
   */
  // Check if there's a name part (format: "Name <email@domain.com>")
  if (sender.includes('<') && sender.includes('>')) {
    return sender.split('<')[0].trim();
  }
  
  // If no name, return the part before @ in the email address
  return sender.split('@')[0];
}
