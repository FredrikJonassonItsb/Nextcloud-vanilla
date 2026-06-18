import { ref, provide, inject, onMounted, onUnmounted } from 'vue'
import { emailService } from '../services/emailService'
import { wsService } from '../services/wsService'
import { authService } from '../services/authService'

const EmailsKey = Symbol('Emails')

export function provideEmails() {
  const emails = ref([])
  const loading = ref(true)
  const error = ref(null)

  async function refreshEmails() {
    try {
      loading.value = true
      error.value = null
      emails.value = await emailService.getEmails()
    } catch (err) {
      console.error('Failed to fetch emails:', err)
      error.value = 'Kunde inte hämta e-postmeddelanden. Kontrollera din internetanslutning och försök igen.'
      emails.value = []
    } finally {
      loading.value = false
    }
  }

  // Setup WebSocket connection and message handling
  function setupWebSocket() {
    wsService.connect()
    wsService.onMessage(() => {
      console.log('New email notification received, refreshing list...')
      refreshEmails()
    })
  }

  onMounted(() => {
    // Only setup WebSocket, don't automatically fetch emails
    setupWebSocket()
  })

  onUnmounted(() => {
    wsService.disconnect()
  })

  // Reconnect WebSocket when token is refreshed
  if (authService.onTokenRefresh) {
    authService.onTokenRefresh(() => {
      console.log('Token refreshed, reconnecting WebSocket...')
      wsService.disconnect()
      setupWebSocket()
    })
  }

  provide(EmailsKey, {
    emails,
    loading,
    error,
    refreshEmails
  })

  return { emails, loading, error, refreshEmails }
}

export function useEmails() {
  const emails = inject(EmailsKey)
  if (!emails) {
    throw new Error('useEmails must be used within a component that provides emails')
  }
  return emails
} 