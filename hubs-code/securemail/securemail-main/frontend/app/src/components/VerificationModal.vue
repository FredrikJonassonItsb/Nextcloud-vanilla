<template>
  <v-dialog :model-value="modelValue" persistent max-width="420">
    <v-card>
      <v-card-title class="text-h6">
        SMS-verifiering krävs
      </v-card-title>

      <v-card-text>
        <p class="mb-4">
          Det här meddelandet kräver verifiering. En engångskod har skickats via SMS.
          Ange koden nedan för att läsa meddelandet.
        </p>

        <v-text-field
          v-model="code"
          label="Verifieringskod"
          placeholder="123456"
          maxlength="6"
          inputmode="numeric"
          variant="outlined"
          autofocus
          :error-messages="errorMessage"
          :disabled="verifying"
          @keyup.enter="submit"
        />
      </v-card-text>

      <v-card-actions>
        <v-btn
          variant="text"
          :disabled="verifying"
          @click="$emit('cancel')"
        >
          Tillbaka
        </v-btn>

        <v-spacer />

        <v-btn
          variant="text"
          :disabled="verifying || resendCooldown > 0"
          @click="resend"
        >
          {{ resendCooldown > 0 ? `Skicka ny kod (${resendCooldown}s)` : 'Skicka ny kod' }}
        </v-btn>

        <v-btn
          color="primary"
          variant="flat"
          :loading="verifying"
          :disabled="!code || code.length < 6 || !/^\d{6}$/.test(code)"
          @click="submit"
        >
          Verifiera
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, onUnmounted } from 'vue'
import { emailService } from '../services/emailService'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  emailId: { type: [Number, String], required: true },
  isOrgMessage: { type: Boolean, default: false }
})

const emit = defineEmits(['update:modelValue', 'verified', 'cancel', 'resend'])

const code = ref('')
const errorMessage = ref('')
const verifying = ref(false)
const resendCooldown = ref(0)
let cooldownTimer = null

onUnmounted(() => {
  if (cooldownTimer) clearInterval(cooldownTimer)
})

function startCooldown() {
  resendCooldown.value = 30
  cooldownTimer = setInterval(() => {
    resendCooldown.value--
    if (resendCooldown.value <= 0) {
      clearInterval(cooldownTimer)
      cooldownTimer = null
    }
  }, 1000)
}

async function submit() {
  if (!code.value || code.value.length < 6) return

  verifying.value = true
  errorMessage.value = ''

  try {
    const result = props.isOrgMessage
      ? await emailService.verifyOrgCode(props.emailId, code.value)
      : await emailService.verifyCode(props.emailId, code.value)
    if (result.verified) {
      emit('verified')
    }
  } catch (err) {
    if (err.response) {
      if (err.response.error === 'Too many attempts') {
        errorMessage.value = err.response.message || 'För många försök. Vänta och försök igen.'
      } else if (err.response.error === 'Code expired') {
        errorMessage.value = err.response.message || 'Koden har gått ut. Skicka en ny kod.'
      } else {
        errorMessage.value = err.response.message || 'Verifiering misslyckades.'
      }
    } else {
      errorMessage.value = 'Verifiering misslyckades. Försök igen.'
    }
  } finally {
    verifying.value = false
  }
}

function resend() {
  code.value = ''
  errorMessage.value = ''
  startCooldown()
  emit('resend')
}
</script>
