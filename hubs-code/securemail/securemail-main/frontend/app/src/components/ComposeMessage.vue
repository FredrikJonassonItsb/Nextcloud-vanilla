<template>
  <v-container>
    <v-row>
      <v-col>
        <div class="d-flex align-center mb-4">
          <v-btn
            prepend-icon="mdi-arrow-left"
            color="primary"
            variant="text"
            @click="handleBack"
          >
            Tillbaka
          </v-btn>
        </div>

        <v-card>
          <v-card-title class="text-h5">
            Nytt meddelande
          </v-card-title>

          <v-card-text>
            <v-form v-model="valid" @submit.prevent="sendMessage" ref="form" class="mt-4">
              <v-row>
                <v-col cols="12" md="6">
                  <label class="field-label">Din e-postadress
                  <v-text-field
                    v-model="message.from"
                    type="email"
                    required
                    variant="outlined"
                    density="comfortable"
                    :rules="[rules.required, rules.email]"
                    validate-on="blur"
                  ></v-text-field></label>
                </v-col>
                <v-col cols="12" md="6">
                  <label class="field-label">Till
                  <v-select
                    v-model="message.to"
                    :items="recipients"
                    item-title="name"
                    item-value="email"
                    required
                    variant="outlined"
                    density="comfortable"
                    :loading="loadingRecipients"
                    :error-messages="recipientsError"
                    :rules="[rules.required]"
                  >
                    <template v-slot:item="{ props, item }">
                      <v-list-item v-bind="props">
                        <v-list-item-subtitle v-if="item.raw.description">
                          {{ item.raw.description }}
                        </v-list-item-subtitle>
                      </v-list-item>
                    </template>
                  </v-select></label>
                </v-col>
              </v-row>

              <label class="field-label">Ämne
              <v-text-field
                v-model="message.subject"
                required
                variant="outlined"
                density="comfortable"
                :rules="[rules.required]"
              ></v-text-field></label>

              <label class="field-label">Meddelande
              <v-textarea
                v-model="message.content"
                variant="outlined"
                rows="8"
                auto-grow
                :rules="[rules.required, rules.maxLength]"
                :counter="10000"
              ></v-textarea></label>

              <!-- File attachment section -->
              <div class="attachment-section">
                <div class="attachment-container">
                  <v-btn
                    variant="outlined"
                    prepend-icon="mdi-paperclip"
                    @click="triggerFileInput"
                    class="attach-button"
                  >
                    Bifoga fil
                  </v-btn>

                  <!-- Hidden file input -->
                  <input
                    ref="fileInput"
                    type="file"
                    multiple
                    accept=".jpg,.jpeg,.png,.pdf,.odt,.docx"
                    @change="handleFileSelection"
                    style="display: none"
                  />

                  <!-- Horizontal list of selected files -->
                  <div v-if="attachments.length > 0" class="attachment-list">
                    <v-chip
                      v-for="(file, index) in attachments"
                      :key="index"
                      closable
                      @click:close="removeAttachment(index)"
                      color="primary"
                      variant="outlined"
                    >
                      <v-icon start>{{ getFileIcon(file.name) }}</v-icon>
                      {{ file.name }} ({{ formatFileSize(file.size) }})
                    </v-chip>
                  </div>
                </div>

                <!-- File size error message -->
                <v-alert
                  v-if="fileSizeError"
                  type="error"
                  class="mt-2"
                  density="compact"
                >
                  {{ fileSizeError }}
                </v-alert>
              </div>
            </v-form>
          </v-card-text>

          <v-card-actions class="justify-end pa-4">
            <v-btn
              color="primary"
              variant="elevated"
              type="submit"
              :loading="sending"
              :disabled="!valid || sending || !!fileSizeError"
              @click="sendMessage"
            >
              {{ sending ? 'Skickar...' : 'Skicka' }}
            </v-btn>
          </v-card-actions>
        </v-card>

        <v-dialog v-model="showCancelDialog" max-width="400">
          <v-card>
            <v-card-title>Avbryt meddelande?</v-card-title>
            <v-card-text>
              Du har påbörjat att skriva ett meddelande. Är du säker på att du vill avbryta och gå tillbaka?
            </v-card-text>
            <v-card-actions>
              <v-spacer></v-spacer>
              <v-btn variant="text" @click="showCancelDialog = false">Nej</v-btn>
              <v-btn color="primary" variant="elevated" @click="confirmCancel">Ja, gå tillbaka</v-btn>
            </v-card-actions>
          </v-card>
        </v-dialog>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { emailService } from '../services/emailService'
import { useNotificationStore } from '../composables/notificationStore'

const router = useRouter()
const form = ref(null)
const valid = ref(false)
const notification = useNotificationStore()
const showCancelDialog = ref(false)
const fileInput = ref(null)
const attachments = ref([])
const fileSizeError = ref('')

// File size constants
const MAX_TOTAL_SIZE = 10 * 1024 * 1024 // 10MB in bytes
const ALLOWED_TYPES = {
  'image/jpeg': true,
  'image/jpg': true,
  'image/png': true,
  'application/pdf': true,
  'application/vnd.oasis.opendocument.text': true, // ODT
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': true // DOCX
}

const message = ref({
  from: '',
  to: '',
  subject: '',
  content: ''
})

const recipients = ref([])
const loadingRecipients = ref(false)
const recipientsError = ref('')
const sending = ref(false)

const rules = {
  required: value => !!value || 'Fältet är obligatoriskt',
  email: value => {
    const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    return pattern.test(value) || 'Ogiltig e-postadress'
  },
  maxLength: value => value.length <= 10000 || 'Meddelandet kan inte vara längre än 10000 tecken'
}

const fetchRecipients = async () => {
  try {
    loadingRecipients.value = true
    recipientsError.value = ''
    recipients.value = await emailService.getRecipients()
  } catch (error) {
    console.error('Failed to fetch recipients:', error)
    recipientsError.value = 'Kunde inte hämta mottagare. Försök igen.'
  } finally {
    loadingRecipients.value = false
  }
}

onMounted(() => {
  fetchRecipients()
})

function isDirty() {
  return (
    message.value.from.trim() ||
    message.value.to ||
    message.value.subject.trim() ||
    message.value.content.trim() ||
    attachments.value.length > 0
  )
}

const handleBack = () => {
  if (isDirty()) {
    showCancelDialog.value = true
  } else {
    router.back()
  }
}

const confirmCancel = () => {
  showCancelDialog.value = false
  router.back()
}

const triggerFileInput = () => {
  fileInput.value.click()
}

const handleFileSelection = (event) => {
  const files = Array.from(event.target.files)
  
  // Filter files by type
  const validFiles = files.filter(file => {
    if (!ALLOWED_TYPES[file.type]) {
      alert(`Filtypen för "${file.name}" stöds inte. Endast bilder (JPEG, PNG) och dokument (PDF, ODT, DOCX) är tillåtna.`)
      return false
    }
    return true
  })
  
  // Add valid files to existing attachments
  attachments.value.push(...validFiles)
  
  // Validate total file size
  validateFileSize()
  
  // Clear the input so the same file can be selected again if needed
  event.target.value = ''
}

const removeAttachment = (index) => {
  attachments.value.splice(index, 1)
  validateFileSize()
}

const fileToBase64 = (file) => {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.readAsDataURL(file)
    reader.onload = () => {
      // Remove the data:type/subtype;base64, prefix
      const base64 = reader.result.split(',')[1]
      resolve(base64)
    }
    reader.onerror = error => reject(error)
  })
}

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const getFileIcon = (fileName) => {
  const extension = fileName.toLowerCase().split('.').pop()
  switch (extension) {
    case 'jpg':
    case 'jpeg':
    case 'png':
      return 'mdi-image'
    case 'pdf':
      return 'mdi-file-pdf-box'
    case 'odt':
    case 'docx':
      return 'mdi-file-document'
    default:
      return 'mdi-file'
  }
}

const validateFileSize = () => {
  const totalSize = attachments.value.reduce((sum, file) => sum + file.size, 0)
  if (totalSize > MAX_TOTAL_SIZE) {
    fileSizeError.value = `Totala filstorleken (${formatFileSize(totalSize)}) överstiger gränsen på 10 MB. Vänligen ta bort några filer.`
  } else {
    fileSizeError.value = ''
  }
}

const sendMessage = async () => {
  const { valid: isValid } = await form.value.validate()
  
  if (!isValid) {
    return
  }

  try {
    sending.value = true
    
    // Convert attachments to base64
    const attachmentData = await Promise.all(
      attachments.value.map(async (file) => {
        const base64Data = await fileToBase64(file)
        return {
          name: file.name,
          data: base64Data
        }
      })
    )
    
    await emailService.sendMessage(
      message.value.from,
      message.value.to,
      message.value.subject,
      message.value.content,
      attachmentData
    )
    notification.notify({ text: 'Ditt meddelande har skickats.', type: 'success' })
    router.push('/')
  } catch (error) {
    console.error('Failed to send message:', error)
    alert('Kunde inte skicka meddelandet. Försök igen.')
  } finally {
    sending.value = false
  }
}
</script>

<style scoped>
.field-label {
  display: block;
  margin-bottom: 4px;
  font-size: 14px;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.87);
}

.attachment-section {
  margin-top: 8px;
}

.attachment-container {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.attach-button {
  flex-shrink: 0;
}

.attachment-list {
  display: flex;
  flex-wrap: wrap;
  column-gap: 8px;
  max-width: 100%;
  flex: 1;
}

.attachment-list .v-chip {
  margin-bottom: 4px;
}

/* Mobile view - stack vertically */
@media (max-width: 600px) {
  .attachment-container {
    flex-direction: column;
    gap: 12px;
  }
  
  .attachment-list {
    margin-left: 0;
  }
}
</style>
