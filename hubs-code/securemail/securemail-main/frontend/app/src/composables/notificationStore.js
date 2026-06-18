import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useNotificationStore = defineStore('notification', () => {
  const show = ref(false)
  const message = ref('')
  const color = ref('success')
  const timeout = ref(3000)

  function notify({ text, type = 'success', duration = 3000 }) {
    message.value = text
    color.value = type
    timeout.value = duration
    show.value = true
  }

  return { show, message, color, timeout, notify }
}) 