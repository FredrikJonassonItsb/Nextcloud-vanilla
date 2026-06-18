<template>
  <v-app>
    <v-app-bar
      v-if="showHeader"
      color="primary"
      density="compact"
    >
      <v-app-bar-title>Säkra meddelanden</v-app-bar-title>
      
      <v-spacer></v-spacer>
      
      <v-btn
        v-if="showLogoutButton"
        variant="text"
        prepend-icon="mdi-logout"
        @click="signOut"
      >
        Logga ut
      </v-btn>
    </v-app-bar>

    <v-main>
      <emails-provider v-if="shouldUseEmailsProvider">
        <router-view></router-view>
      </emails-provider>
      <router-view v-else></router-view>
    </v-main>

    <Toast />
    <v-snackbar
      v-model="notification.show"
      :color="notification.color"
      location="top"
      :timeout="notification.timeout"
      elevation="6"
    >
      {{ notification.message }}
    </v-snackbar>
  </v-app>
</template>

<script setup>
import { ref, computed, defineComponent, provide, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { provideEmails } from './composables/useEmails'
import { authService } from './services/authService'
import { wsService } from './services/wsService'
import Toast from './components/Toast.vue'
import ResponseForm from './components/ResponseForm.vue'
import { useNotificationStore } from './composables/notificationStore'

const notification = useNotificationStore()
const route = useRoute()
const router = useRouter()

// Create a wrapper component for emails provider
const EmailsProvider = defineComponent({
  setup(_, { slots }) {
    provideEmails()
    return () => slots.default?.()
  }
})

const signingOut = ref(false)
provide('signingOut', signingOut)

const isAuthenticated = computed(() => {
  return localStorage.getItem('isAuthenticated') === 'true'
})

// Use ref instead of computed for better control
const isOrgEmailView = ref(false)
const isSignedOutView = ref(false)
const shouldUseEmailsProvider = ref(false)

// Update on route changes
const updateRouteFlags = () => {
  const currentPath = route.path || router.currentRoute.value.path
  const isOrgRoute = currentPath.startsWith('/org/')
  const isSignedOut = currentPath === '/signed-out'
  
  isOrgEmailView.value = isOrgRoute
  isSignedOutView.value = isSignedOut
  shouldUseEmailsProvider.value = isAuthenticated.value && !isOrgRoute
}

// Initialize on mount
updateRouteFlags()

// Watch for route changes
watch(() => route.path, updateRouteFlags, { immediate: true })
watch(isAuthenticated, updateRouteFlags)

const showHeader = computed(() => {
  // Show header when authenticated OR when in org views
  return isAuthenticated.value || isOrgEmailView.value
})

const showLogoutButton = computed(() => {
  // Show logout button for authenticated users OR when in org views (but not on signed-out page)
  return (isAuthenticated.value || isOrgEmailView.value) && !isSignedOutView.value
})

function signOut() {
  signingOut.value = true
  
  // Check if we're in an organizational view
  if (isOrgEmailView.value) {
    // For org views: just navigate to signed-out page with history replacement
    // No session cleanup needed since org views don't have sessions
    router.replace('/signed-out')
  } else {
    // For authenticated views: do full cleanup and logout
    // Clean up connections
    wsService.disconnect()
    
    // Initiate SSO logout (this will redirect via the /sign-out route)
    authService.logout()
  }
}
</script>

<style>
.v-application {
  font-family: 'Roboto', sans-serif;
  background-color: #f5f5f5;
}
</style>
