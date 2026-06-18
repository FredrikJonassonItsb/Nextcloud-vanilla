<template>
  <ResponseFormCore
    :message-id="parseInt(id)"
    :get-email-function="getEmailFunction"
    :send-reply-function="sendReplyFunction"
    :success-redirect-path="'/'"
  />
</template>

<script setup>
import { defineProps } from 'vue'
import { emailService } from '../services/emailService'
import ResponseFormCore from './ResponseFormCore.vue'

const props = defineProps(['id'])

// Regular email functions — redirect to verify if LOA-2 not yet verified
const getEmailFunction = async (id) => {
  const result = await emailService.getEmail(parseInt(id))
  if (result.requiresVerification) {
    window.location.href = `/email/${id}`
    return null
  }
  return result
}

const sendReplyFunction = async (messageId, responseText, attachments) => {
  return await emailService.sendReply(messageId, responseText, attachments)
}
</script>

<style scoped>
/* ResponseForm specific styles */
</style>
