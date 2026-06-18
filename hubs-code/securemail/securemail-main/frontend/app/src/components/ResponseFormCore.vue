<template>
  <v-container>
    <v-row>
      <v-col>
        <div class="d-flex align-center mb-4">
          <v-btn
            prepend-icon="mdi-arrow-left"
            color="primary"
            variant="text"
            @click="router.back()"
          >
            Tillbaka
          </v-btn>
        </div>

        <!-- Show error if trying to reply to self-sent message -->
        <v-alert
          v-if="message && message.isSelfSent"
          type="warning"
          class="mb-4"
        >
          Du kan inte svara på dina egna meddelanden.
        </v-alert>

        <!-- Show error if message not found -->
        <v-alert
          v-else-if="error"
          type="error"
          class="mb-4"
        >
          {{ error }}
        </v-alert>

        <!-- Show loading state -->
        <v-progress-circular
          v-else-if="loading"
          indeterminate
          color="primary"
          class="d-block mx-auto my-4"
        ></v-progress-circular>

        <!-- Show reply form only for valid received messages -->
        <v-card v-else-if="message && !message.isSelfSent">
          <v-card-title class="text-h5">
            Svara på meddelande
          </v-card-title>

          <v-card-text>
            <v-form class="mt-4">
              <label class="field-label">Till</label>
              <v-text-field
                v-model="recipient"
                disabled
                variant="outlined"
                density="comfortable"
              ></v-text-field>

              <label class="field-label">Ämne</label>
              <v-text-field
                v-model="subject"
                disabled
                variant="outlined"
                density="comfortable"
              ></v-text-field>

              <label class="field-label">Ditt svar</label>
              <v-textarea
                v-model="responseText"
                variant="outlined"
                rows="8"
                auto-grow
                :rules="[rules.required, rules.maxLength]"
                :counter="10000"
              ></v-textarea>

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
              :loading="sending"
              :disabled="!responseText.trim() || sending || !!fileSizeError"
              @click="handleSubmit"
            >
              {{ sending ? 'Skickar...' : 'Skicka' }}
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { toastService } from '../services/toastService'

const props = defineProps({
  messageId: {
    type: [String, Number],
    required: true
  },
  getEmailFunction: {
    type: Function,
    required: true
  },
  sendReplyFunction: {
    type: Function,
    required: true
  },
  successRedirectPath: {
    type: String,
    default: '/'
  }
})

const router = useRouter()

const recipient = ref('')
const subject = ref('')
const responseText = ref('')
const sending = ref(false)
const message = ref(null)
const loading = ref(true)
const error = ref(null)
const fileInput = ref(null)
const attachments = ref([])
const fileSizeError = ref('')

const rules = {
  required: value => !!value.trim() || 'Svaret kan inte vara tomt',
  maxLength: value => value.length <= 10000 || 'Svaret kan inte vara längre än 10000 tecken'
}

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

onMounted(async () => {
  try {
    loading.value = true
    error.value = null
    
    const originalEmail = await props.getEmailFunction(props.messageId)
    
    if (!originalEmail) {
      error.value = 'Meddelandet kunde inte hittas.'
      return
    }
    
    message.value = originalEmail
    
    // Only set form fields if it's not a self-sent message
    if (!originalEmail.isSelfSent) {
      recipient.value = originalEmail.from
      subject.value = originalEmail.subject.startsWith('Re:') 
        ? originalEmail.subject 
        : `Re: ${originalEmail.subject}`
    }
  } catch (err) {
    console.error('Failed to fetch original email:', err)
    error.value = 'Kunde inte hämta meddelandeinformation. Försök igen senare.'
  } finally {
    loading.value = false
  }
})

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

async function handleSubmit() {
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
    
    await props.sendReplyFunction(props.messageId, responseText.value, attachmentData)
    
    toastService.success('Svaret har skickats')
    router.push(props.successRedirectPath)
  } catch (error) {
    console.error('Failed to send reply:', error)
    toastService.error('Kunde inte skicka svaret. Försök igen senare.')
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
