<template>
  <v-card v-if="email">
    <v-card-title class="text-h5 pb-0">
      {{ email.subject }}
    </v-card-title>
    
    <v-card-subtitle class="metadata pt-4 pb-4">
      <div class="metadata-item">
        <span class="metadata-label">
          {{ email.isSelfSent ? 'Till:' : 'Från:' }}
        </span>
        <span class="metadata-value">{{ email.from }}</span>
      </div>
      <div class="metadata-item">
        <span class="metadata-label">Datum:</span>
        <span class="metadata-value">{{ formatDate(email.date) }}</span>
      </div>
    </v-card-subtitle>
    
    <v-divider></v-divider>
    
    <v-card-text class="pt-4">
      <p class="message-body">{{ email.content }}</p>
    </v-card-text>

    <!-- Show attachments section only if there are attachments -->
    <template v-if="email.attachments?.length > 0">
      <v-divider></v-divider>
      
      <v-card-text class="pt-4">
        <v-row dense>
          <v-col
            v-for="(attachment, index) in email.attachments"
            :key="index"
            cols="12"
            sm="auto"
          >
            <v-chip
              :disabled="attachment.size === 0"
              class="mr-2 mb-2"
              @click="attachment.size === 0 ? undefined : handleAttachmentDownload(attachment, index)"
              :style="attachment.size === 0 ? 'cursor: default' : 'cursor: pointer'"
            >
              <template v-slot:prepend>
                <v-icon>
                  {{ getAttachmentIcon(attachment) }}
                </v-icon>
              </template>
              {{ attachment.filename }}
            </v-chip>
          </v-col>
        </v-row>
      </v-card-text>
    </template>

    <!-- Action buttons slot -->
    <v-card-actions v-if="showActions && replyRoute && !email.isSelfSent" class="justify-end pa-4">
      <slot name="actions">
        <v-btn
          color="primary"
          variant="elevated"
          :to="replyRoute"
        >
          Svara
        </v-btn>
      </slot>
    </v-card-actions>
  </v-card>
</template>

<script setup>
import { defineProps, defineEmits } from 'vue'

const props = defineProps({
  email: {
    type: Object,
    required: true
  },
  showActions: {
    type: Boolean,
    default: true
  },
  replyRoute: {
    type: String,
    default: null
  }
})

const emit = defineEmits(['attachmentDownload'])

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

function getAttachmentIcon(attachment) {
  switch (attachment.type) {
    case 'application/pdf':
      return 'mdi-file-pdf-box'
    case 'image/jpeg':
    case 'image/jpg': 
    case 'image/png':
      return 'mdi-file-image'
    case 'application/vnd.oasis.opendocument.text': // ODT
    case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': // DOCX
      return 'mdi-file-document'
    default:
      return 'mdi-file'
  }
}

function handleAttachmentDownload(attachment, index) {
  emit('attachmentDownload', { attachment, index })
}
</script>

<style scoped>
.message-body {
  white-space: pre-line;
}

.metadata {
  background-color: #f8f8f8;
}

.metadata-item {
  margin: 4px 0;
  font-size: 1.1em;
}

.metadata-label {
  font-weight: 600;
  color: #000000;
  display: inline-block;
  width: 60px;
}

.metadata-value {
  font-weight: 500;
  color: #000000;
  padding-left: 8px;
}

.v-card-text {
  color: #000000 !important;
}

.text-subtitle-1 {
  color: rgba(0, 0, 0, 0.87);
  font-weight: 500;
}
</style>
