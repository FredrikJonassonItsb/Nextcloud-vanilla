<template>
  <v-container>
    <v-row>
      <v-col>
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
          :reply-route="`/org/respond/${props.uuid}`"
          @attachment-download="handleAttachmentDownload"
        />

        <v-alert
          v-if="error"
          type="error"
          class="mt-4"
        >
          {{ error }}
        </v-alert>

        <VerificationModal
          v-model="showVerification"
          :email-id="props.uuid"
          :is-org-message="true"
          @verified="onVerified"
          @cancel="onVerificationCancel"
          @resend="fetchEmail"
        />
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { defineProps, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { emailService } from '../services/emailService'
import MessageDisplay from './MessageDisplay.vue'
import VerificationModal from './VerificationModal.vue'

const props = defineProps(['uuid'])
const router = useRouter()

const email = ref(null)
const loading = ref(true)
const error = ref(null)
const showVerification = ref(false)

function handleAttachmentDownload({ attachment, index }) {
  // For org emails, use the org-specific attachment download endpoint
  emailService.downloadOrgAttachment(props.uuid, index, attachment.filename)
}

async function fetchEmail() {
  try {
    error.value = null
    const fetchedEmail = await emailService.getOrgEmail(props.uuid)

    // LOA-2: Check if verification is required
    if (fetchedEmail.requiresVerification) {
      showVerification.value = true
      return
    }

    email.value = fetchedEmail
  } catch (err) {
    console.error('Failed to fetch org email:', err)
    if (err.message.includes('404')) {
      error.value = 'Meddelandet kunde inte hittas.'
    } else {
      error.value = 'Kunde inte hämta meddelandet. Försök igen senare.'
    }
  } finally {
    loading.value = false
  }
}

async function onVerified() {
  showVerification.value = false
  loading.value = true
  await fetchEmail()
}

function onVerificationCancel() {
  showVerification.value = false
  error.value = 'SMS-verifiering krävs för att läsa detta meddelande.'
  loading.value = false
}

onMounted(fetchEmail)
</script>

<style scoped>
/* OrgEmailView specific styles */
</style>
