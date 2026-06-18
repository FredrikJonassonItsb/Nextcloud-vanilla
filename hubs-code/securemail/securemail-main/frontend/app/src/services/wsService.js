import { authService } from './authService'

class WebSocketService {
  constructor() {
    this.ws = null
    this.reconnectAttempts = 0
    this.maxReconnectAttempts = 5
    this.reconnectTimeout = null
    this.onMessageCallback = null
    this.onStatusChangeCallback = null
    this.currentStatus = 'disconnected'
  }

  connect() {
    if (!this.ws) {
      this.notifyStatusChange('connecting')
    }

    const token = localStorage.getItem('access_token')
    if (!token) {
      console.log('No token available for WebSocket connection')
      this.notifyStatusChange('disconnected')
      return
    }

    try {
      console.log('Attempting WebSocket connection...')
      // Only access_token in URL — id_token sent via X-Id-Token header on API calls
      // instead of URL params to avoid PII exposure in access logs (review finding #6)
      const params = new URLSearchParams({ token })
      this.ws = new WebSocket(`/ws?${params}`)

      this.ws.onopen = () => {
        console.log('WebSocket connected successfully')
        this.reconnectAttempts = 0
        this.notifyStatusChange('connected')
      }

      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data)
          console.log('WebSocket message received:', data)
          
          switch (data.type) {
            case 'newEmail':
              if (this.onMessageCallback) {
                this.onMessageCallback()
              }
              break
            case 'imapReconnected':
              this.notifyStatusChange('imapReconnected')
              if (this.onMessageCallback) {
                this.onMessageCallback() // Refresh emails after reconnection
              }
              break
            case 'imapConnectionLost':
              this.notifyStatusChange('imapConnectionLost')
              break
          }
        } catch (error) {
          console.error('Error processing WebSocket message:', error)
        }
      }

      this.ws.onclose = (event) => {
        console.log('WebSocket disconnected:', event.code, event.reason)
        this.notifyStatusChange('disconnected')
        this.attemptReconnect()
      }

      this.ws.onerror = (error) => {
        console.error('WebSocket error:', error)
        this.notifyStatusChange('error')
      }
    } catch (error) {
      console.error('Error creating WebSocket connection:', error)
      this.attemptReconnect()
    }
  }

  notifyStatusChange(status) {
    this.currentStatus = status
    if (this.onStatusChangeCallback) {
      this.onStatusChangeCallback(status)
    }
  }

  onStatusChange(callback) {
    this.onStatusChangeCallback = callback
    if (callback) {
      callback(this.currentStatus)
    }
  }

  attemptReconnect() {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.log('Max reconnection attempts reached')
      return
    }

    this.reconnectAttempts++
    this.reconnectTimeout = setTimeout(() => {
      console.log(`Attempting to reconnect (${this.reconnectAttempts})...`)
      this.connect()
    }, 5000 * this.reconnectAttempts)
  }

  disconnect() {
    if (this.ws) {
      this.ws.close()
    }
    if (this.reconnectTimeout) {
      clearTimeout(this.reconnectTimeout)
    }
  }

  onMessage(callback) {
    this.onMessageCallback = callback
  }
}

export const wsService = new WebSocketService() 