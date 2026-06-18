import { ref } from 'vue'

class ToastService {
  constructor() {
    this._show = ref(false)
    this._message = ref('')
    this._color = ref('success')
    this._timeout = ref(3000)
  }

  get show() {
    return this._show.value
  }

  set show(value) {
    this._show.value = value
  }

  get message() {
    return this._message.value
  }

  get color() {
    return this._color.value
  }

  get timeout() {
    return this._timeout.value
  }

  success(message, timeout = 3000) {
    this._message.value = message
    this._color.value = 'success'
    this._timeout.value = timeout
    this._show.value = true
  }

  error(message, timeout = 5000) {
    this._message.value = message
    this._color.value = 'error'
    this._timeout.value = timeout
    this._show.value = true
  }

  info(message, timeout = 3000) {
    this._message.value = message
    this._color.value = 'info'
    this._timeout.value = timeout
    this._show.value = true
  }
}

export const toastService = new ToastService() 