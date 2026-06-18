<template>
  <v-container>
    <v-row>
      <v-col>
        <div class="d-flex align-center mb-4">
          <v-btn
            prepend-icon="mdi-arrow-left"
            color="primary"
            variant="text"
            @click="router.push('/')"
          >
            Tillbaka
          </v-btn>
          
          <v-btn
            prepend-icon="mdi-email"
            color="primary"
            variant="text"
            class="ml-2"
            :loading="toggleLoading"
            :disabled="loading || showVerification"
            @click="markAsUnread"
          >
            Markera som oläst
          </v-btn>
        </div>
        
        <v-progress-circular
          v-if="loading"
          indeterminate
          color="primary"
          class="d-block mx-auto my-4"
        ></v-progress-circular>

        <MessageDisplay
          v-else-if="email"
          :email="email"
          :show-actions="true"
          :reply-route="`/respond/${email.id}`"
          @attachment-download="handleAttachmentDownload"
        />

        <VerificationModal
          v-model="showVerification"
          :email-id="props.id"
          @verified="onVerified"
          @cancel="router.push('/')"
          @resend="onResend"
        />

        <v-alert
          v-if="error"
          type="error"
          class="mt-4"
        >
          {{ error }}
        </v-alert>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { emailService } from '../services/emailService'
import { useEmails } from '../composables/useEmails'
import { toastService } from '../services/toastService'
import { useDisplay } from 'vuetify'
import MessageDisplay from './MessageDisplay.vue'
import VerificationModal from './VerificationModal.vue'

const props = defineProps(['id'])
const router = useRouter()
const email = ref(null)
const loading = ref(true)
const toggleLoading = ref(false)
const error = ref(null)
const showVerification = ref(false)
const { refreshEmails } = useEmails()
const { smAndDown } = useDisplay()

async function markAsUnread() {
  try {
    toggleLoading.value = true
    await emailService.toggleRead(props.id)
    await refreshEmails()
    router.push('/')
  } catch (error) {
    console.error('Failed to mark as unread:', error)
    toastService.error('Kunde inte ändra lässtatus. Försök igen senare.')
  } finally {
    toggleLoading.value = false
  }
}

function handleAttachmentDownload({ attachment, index }) {
  emailService.downloadAttachment(email.value.id, index, attachment.filename)
}

async function onVerified() {
  showVerification.value = false
  loading.value = true
  try {
    const fullEmail = await emailService.getEmail(parseInt(props.id))
    await emailService.markAsRead(props.id)
    email.value = fullEmail
    await refreshEmails()
  } catch (err) {
    console.error('Failed to fetch email after verification:', err)
    error.value = 'Kunde inte hämta meddelandet. Försök igen senare.'
  } finally {
    loading.value = false
  }
}

async function onResend() {
  try {
    const result = await emailService.getEmail(parseInt(props.id))
    if (result.smsSent) {
      toastService.success('Ny kod har skickats.')
    } else {
      toastService.info('En aktiv kod finns redan. Vänta tills den går ut.')
    }
  } catch (err) {
    toastService.error('Kunde inte skicka ny kod. Försök igen.')
  }
}

onMounted(async () => {
  try {
    error.value = null
    const fetchedEmail = await emailService.getEmail(parseInt(props.id))

    // LOA-2: Check if message requires SMS verification
    if (fetchedEmail.requiresVerification) {
      showVerification.value = true
      loading.value = false
      return
    }

    // Mark as read automatically when opening
    await emailService.markAsRead(props.id)

    email.value = fetchedEmail
    await refreshEmails()
  } catch (err) {
    console.error('Failed to fetch email:', err)
    error.value = 'Kunde inte hämta meddelandet. Försök igen senare.'
    toastService.error('Kunde inte hämta meddelandet. Försök igen senare.')
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
/* EmailView specific styles */
</style> 
