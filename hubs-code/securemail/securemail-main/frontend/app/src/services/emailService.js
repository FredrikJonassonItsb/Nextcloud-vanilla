import { authService } from './authService'

// LOA-2 ticket — stored in JS variable only, dies on page reload (see project-overview#110)
let verifyTicket = null

async function fetchWithAuth(url, options = {}) {
  // Check if token needs refresh
  if (authService.isTokenExpired()) {
    try {
      const newToken = await authService.refreshToken()
      // Re-read id_token after refresh — refresh may have stored a new one
      const idToken = localStorage.getItem('id_token')
      options.headers = {
        ...options.headers,
        'Authorization': `Bearer ${newToken}`,
        ...(idToken && { 'X-Id-Token': idToken })
      }
    } catch (error) {
      // If refresh fails, redirect to signed-out page
      localStorage.clear()
      window.location.href = '/signed-out'
      throw new Error('Session expired')
    }
  } else {
    const idToken = localStorage.getItem('id_token')
    options.headers = {
      ...options.headers,
      'Authorization': `Bearer ${localStorage.getItem('access_token')}`,
      ...(idToken && { 'X-Id-Token': idToken })
    }
  }

  const response = await fetch(url, options)
  if (!response.ok) {
    // Retry once on 401 — opaque tokens may expire without client-side detection
    if (response.status === 401) {
      try {
        const newToken = await authService.refreshToken()
        const idToken = localStorage.getItem('id_token')
        options.headers = {
          ...options.headers,
          'Authorization': `Bearer ${newToken}`,
          ...(idToken && { 'X-Id-Token': idToken })
        }
        const retryResponse = await fetch(url, options)
        if (!retryResponse.ok) {
          let data
          try { data = await retryResponse.clone().json() } catch { data = null }
          const error = new Error(data?.message || `HTTP error! status: ${retryResponse.status}`)
          error.status = retryResponse.status
          error.response = data
          throw error
        }
        return retryResponse
      } catch (err) {
        if (err.status) throw err
        localStorage.clear()
        window.location.href = '/signed-out'
        throw new Error('Session expired')
      }
    }
    let data
    try { data = await response.clone().json() } catch { data = null }
    const error = new Error(data?.message || `HTTP error! status: ${response.status}`)
    error.status = response.status
    error.response = data
    throw error
  }
  return response
}

export const emailService = {
  async getEmails() {
    const response = await fetchWithAuth(`/api/emails`)
    return response.json()
  },

  async getEmail(id) {
    const response = await fetchWithAuth(`/api/emails/${id}`)
    return response.json()
  },

  async createEmail(email) {
    const response = await fetchWithAuth(`/api/emails`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(email)
    })
    return response.json()
  },

  async deleteEmail(id) {
    await fetchWithAuth(`/api/emails/${id}`, {
      method: 'DELETE'
    })
  },

  async markAsRead(id) {
    await fetchWithAuth(`/api/emails/${id}/read`, {
      method: 'PUT'
    })
  },

  async toggleRead(id) {
    const response = await fetchWithAuth(`/api/emails/${id}/toggle-read`, {
      method: 'PUT'
    })
    return response.json()
  },

  async getFlags(id) {
    const response = await fetchWithAuth(`/api/emails/${id}/flags`)
    return response.json()
  },

  async downloadAttachment(emailId, index, filename) {
    console.log('Downloading attachment:', { emailId, index, filename })
    const response = await fetchWithAuth(
      `/api/emails/${emailId}/attachments/${index}`,
      { responseType: 'blob' }
    )
    
    // Create a download link
    const blob = await response.blob()
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    window.URL.revokeObjectURL(url)
    document.body.removeChild(a)
  },

  async getRecipients() {
    const response = await fetchWithAuth('/api/get-recipients')
    return response.json()
  },

  async sendReply(messageId, responseText, attachments = []) {
    const response = await fetchWithAuth(`/api/emails/reply`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        messageId,
        responseText,
        attachments
      })
    })

    if (!response.ok) {
      const errorData = await response.text()
      throw new Error(`Failed to send reply: ${errorData}`)
    }

    // Regular reply endpoint returns empty response, not JSON
  },

  async sendMessage(from, to, subject, content, attachments = []) {
    await fetchWithAuth(`/api/emails/new`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        from,
        to,
        subject,
        content,
        attachments
      })
    })
  },

  async verifyCode(emailId, code) {
    // Need to read error response bodies, so we handle non-2xx manually
    // but still go through token refresh logic
    let token = localStorage.getItem('access_token')
    if (authService.isTokenExpired()) {
      try {
        token = await authService.refreshToken()
      } catch {
        localStorage.clear()
        window.location.href = '/signed-out'
        throw new Error('Session expired')
      }
    }
    const response = await fetch(`/api/emails/${emailId}/verify`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({ code })
    })
    const data = await response.json()
    if (!response.ok) {
      const error = new Error(data.message || 'Verifiering misslyckades.')
      error.response = data
      throw error
    }
    return data
  },

  async getOrgEmail(uuid) {
    // Organizational emails don't require authentication
    const headers = {}
    if (verifyTicket) {
      headers['X-Verify-Ticket'] = verifyTicket
    }
    const response = await fetch(`/api/org/emails/${uuid}`, { headers })
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`)
    }
    return response.json()
  },

  async verifyOrgCode(uuid, code) {
    const response = await fetch(`/api/org/emails/${uuid}/verify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code })
    })
    const data = await response.json()
    if (!response.ok) {
      const error = new Error(data.message || 'Verifiering misslyckades.')
      error.response = data
      throw error
    }
    if (data.verified && data.ticket) {
      verifyTicket = data.ticket
    }
    return data
  },

  async downloadOrgAttachment(uuid, index, filename) {
    console.log('Downloading org attachment:', { uuid, index, filename })
    const headers = {}
    if (verifyTicket) {
      headers['X-Verify-Ticket'] = verifyTicket
    }
    const response = await fetch(`/api/org/emails/${uuid}/attachments/${index}`, { headers })
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`)
    }
    
    // Create a download link
    const blob = await response.blob()
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    window.URL.revokeObjectURL(url)
    document.body.removeChild(a)
  },

  async sendOrgReply(messageUuid, responseText, attachments = []) {
    // Organizational email replies don't require authentication
    const response = await fetch(`/api/org/emails/reply`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(verifyTicket ? { 'X-Verify-Ticket': verifyTicket } : {}),
      },
      body: JSON.stringify({
        messageUuid,
        responseText,
        attachments
      })
    })

    if (!response.ok) {
      const errorData = await response.text()
      throw new Error(`Failed to send org reply: ${errorData}`)
    }

    // Parse and return the successful response
    return await response.json()
  }
} 