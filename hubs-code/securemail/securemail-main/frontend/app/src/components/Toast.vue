<template>
  <v-snackbar
    :modelValue="toastService.show"
    @update:modelValue="toastService.show = $event"
    :color="toastService.color"
    :timeout="toastService.timeout"
    location="top"
  >
    {{ toastService.message }}
  </v-snackbar>
</template>

<script setup>
import { toastService } from '../services/toastService'
</script> 