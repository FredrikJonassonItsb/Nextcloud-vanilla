<template>
  <v-container>
    <v-row>
      <v-col>
        <!-- Show loading while checking auth state -->
        <template v-if="checkingAuth">
          <v-progress-circular
            indeterminate
            color="primary"
            class="d-block mx-auto my-4"
          ></v-progress-circular>
        </template>

        <!-- Show loading when not authenticated (will auto-redirect to SSO) -->
        <template v-else-if="!isAuthenticated && !isOAuthCallback">
          <v-progress-circular
            indeterminate
            color="primary"
            class="d-block mx-auto my-4"
          ></v-progress-circular>
        </template>

        <!-- Show loading during oauth callback -->
        <template v-else-if="isOAuthCallback">
          <v-progress-circular
            indeterminate
            color="primary"
            class="d-block mx-auto my-4"
          ></v-progress-circular>
        </template>

        <!-- Show email list when authenticated -->
        <template v-else>
          <div class="email-list-header">
            <h2>Inkorg</h2>
            <v-btn
              color="primary"
              prepend-icon="mdi-email-plus"
              @click="router.push('/compose')"
            >
              Skriv nytt meddelande
            </v-btn>
          </div>

          <div class="d-flex align-center mb-4">
            <v-chip
              v-if="!signingOut && shouldShowConnectionStatus"
              :color="connectionStatus.color"
              class="mr-2"
            >
              {{ connectionStatus.text }}
            </v-chip>
          </div>

          <v-data-table
            v-if="!loading && !emailsLoading && !emailsError"
            :items="emails"
            :headers="smAndDown ? mobileHeaders : desktopHeaders"
            :mobile="smAndDown"
            :items-per-page="-1"
            :disable-sort="true"
            hover
            @click:row="(event, {item}) => router.push(`/email/${item.id}`)"
            class="bg-white email-table"
          >
            <template v-slot:item.status="{ item }">
              <div class="d-flex align-center">
                <v-icon v-if="!smAndDown" :color="item.read ? 'grey' : 'primary'" class="mr-2">
                  {{ item.read ? 'mdi-email-open' : 'mdi-email' }}
                </v-icon>
                <v-icon v-if="item.hasAttachments" size="small" color="grey">
                  mdi-paperclip
                </v-icon>
              </div>
            </template>
            
            <template v-slot:item.subject="{ item }">
              <div :class="{ 'thread-indented': item.isThreadContinuation }">
                <span :class="{ 'font-weight-bold': !item.read }">
                  {{ item.subject }}
                </span>
              </div>
            </template>
            
            <template v-slot:item.from="{ item }">
              <div class="d-flex align-center">
                <v-icon 
                  v-if="item.isSelfSent" 
                  size="small" 
                  color="green-darken-2" 
                  class="mr-1"
                >
                  mdi-arrow-right
                </v-icon>
                <v-icon 
                  v-else 
                  size="small" 
                  color="blue-darken-2" 
                  class="mr-1"
                >
                  mdi-arrow-left
                </v-icon>
                <span :class="{ 'font-weight-bold': !item.read }">
                  {{ item.from }}
                </span>
              </div>
            </template>
            
            <template v-slot:item.date="{ item }">
              <span :class="{ 'font-weight-bold': !item.read }">
                {{ formatDate(item.date) }}
              </span>
            </template>
          </v-data-table>

          <v-progress-circular
            v-else-if="loading || emailsLoading"
            indeterminate
            color="primary"
            class="d-block mx-auto my-4"
          ></v-progress-circular>

          <!-- Show error message if there's an error -->
          <v-alert
            v-else-if="emailsError"
            type="error"
            class="mt-4"
          >
            <div class="d-flex justify-space-between align-center">
              <span>{{ emailsError }}</span>
              <v-btn
                color="error"
                variant="outlined"
                size="small"
                @click="retryFetchEmails"
              >
                Försök igen
              </v-btn>
            </div>
          </v-alert>

          <!-- Show "no messages" only when there are no emails and no error -->
          <v-alert
            v-else
            type="info"
            class="mt-4"
          >
            Inga meddelanden att visa
          </v-alert>
        </template>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted, inject } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useEmails } from '../composables/useEmails'
import { wsService } from '../services/wsService'
import { authService } from '../services/authService'

const router = useRouter()
const route = useRoute()
const loading = ref(false)
const checkingAuth = ref(true)
const connectionState = ref('disconnected')
const { smAndDown } = useDisplay()

// Add signingOut state from parent
const signingOut = inject('signingOut', ref(false))

const isAuthenticated = computed(() => {
  return localStorage.getItem('isAuthenticated') === 'true'
})

const isOAuthCallback = computed(() => {
  return !!route.query.code
})

// Destructure loading, emails, error and refreshEmails from useEmails
const { emails = ref([]), loading: emailsLoading, error: emailsError, refreshEmails } = isAuthenticated.value ? useEmails() : {}

const shouldShowConnectionStatus = computed(() => {
  return ['disconnected', 'connecting', 'imapConnectionLost', 'error'].includes(connectionState.value)
})

const connectionStatus = computed(() => {
  switch (connectionState.value) {
    case 'disconnected':
      return { color: 'error', text: 'Frånkopplad' }
    case 'connecting':
      return { color: 'warning', text: 'Ansluter...' }
    case 'imapConnectionLost':
      return { color: 'error', text: 'E-postanslutning förlorad' }
    case 'error':
      return { color: 'error', text: 'Anslutningsfel' }
    default:
      return null
  }
})

// Separate headers for desktop and mobile
const desktopHeaders = [
  { title: '', key: 'status', width: '48px' },
  { title: 'Ämne', key: 'subject' },
  { title: 'Från/Till', key: 'from' },
  { title: 'Datum', key: 'date' }
]

const mobileHeaders = [
  { title: 'Ämne', key: 'subject' },
  { title: 'Från/Till', key: 'from' },
  { title: 'Datum', key: 'date' }
]

function retryFetchEmails() {
  if (refreshEmails) {
    refreshEmails()
  }
}

// Handle auth callback and fetch emails
onMounted(async () => {
  // Check auth state
  checkingAuth.value = true
  
  try {
    const code = route.query.code
    if (code) {
      loading.value = true
      await authService.handleCallback(code)
      window.history.replaceState({}, document.title, '/')
      window.location.reload()
    }
  } catch (error) {
    console.error('Auth error:', error)
  } finally {
    checkingAuth.value = false
    loading.value = false
  }

  // Auto-redirect to SSO if not authenticated and not in OAuth callback
  if (!isAuthenticated.value && !isOAuthCallback.value) {
    // Small delay to avoid flash of content
    setTimeout(() => {
      authService.login()
    }, 100)
    return
  }

  // Fetch emails if user is authenticated
  if (isAuthenticated.value && refreshEmails) {
    refreshEmails()
  }
})

function formatDate(dateString) {
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('sv-SE', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date)
}

if (isAuthenticated.value) {
  wsService.onStatusChange((status) => {
    connectionState.value = status
  })
}
</script>

<style scoped>
.v-data-table :deep(tbody tr) {
  cursor: pointer;
  background-color: #f5f5f5;
}

.v-data-table :deep(tbody tr:not(:hover).v-data-table__tr:has(.font-weight-bold)) {
  background-color: white;
}

.font-weight-bold {
  font-weight: bold !important;
}

/* Remove duplicate mobile styles since we're using the same styling everywhere */
.mobile-table :deep(.font-weight-bold) {
  font-weight: bold !important;
}

/* Hide the sort menu in mobile view */
:deep(.v-data-table) thead {
  @media (max-width: 959px) { /* 595px = smAndDown */
    display: none !important;
  }
}

.email-list-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.thread-indented {
  margin-left: 20px;
  position: relative;
}

.thread-indented::before {
  content: '';
  position: absolute;
  left: -12px;
  top: 50%;
  width: 8px;
  height: 1px;
  background-color: #ccc;
  transform: translateY(-50%);
}

/* Reduce indentation on mobile */
@media (max-width: 600px) {
  .thread-indented {
    margin-left: 12px;
  }
  
  .thread-indented::before {
    left: -8px;
    width: 6px;
  }
}
</style> 