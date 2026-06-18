<template>
  <ResponseFormCore
    :message-id="uuid"
    :get-email-function="getOrgEmailFunction"
    :send-reply-function="sendOrgReplyFunction"
    :success-redirect-path="`/org/email/${uuid}`"
  />
</template>

<script setup>
import { defineProps } from 'vue'
import { emailService } from '../services/emailService'
import ResponseFormCore from './ResponseFormCore.vue'

const props = defineProps(['uuid'])

// Organizational email functions — redirect to verify if LOA-2 ticket missing
const getOrgEmailFunction = async (uuid) => {
  const result = await emailService.getOrgEmail(uuid)
  if (result.requiresVerification) {
    window.location.href = `/org/email/${uuid}`
    return null
  }
  return result
}

const sendOrgReplyFunction = async (messageUuid, responseText, attachments) => {
  return await emailService.sendOrgReply(messageUuid, responseText, attachments)
}
</script>

<style scoped>
/* OrgResponseForm specific styles */
</style>
