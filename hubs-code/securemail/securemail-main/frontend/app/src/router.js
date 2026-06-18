import { createRouter, createWebHistory } from 'vue-router'
import EmailList from './components/EmailList.vue'
import EmailView from './components/EmailView.vue'
import ResponseForm from './components/ResponseForm.vue'
import ComposeMessage from './components/ComposeMessage.vue'
import OrgEmailView from './components/OrgEmailView.vue'
import OrgResponseForm from './components/OrgResponseForm.vue'
import SignedOut from './components/SignedOut.vue'
import { authService } from './services/authService'

const routes = [
  {
    path: '/',
    beforeEnter: (to, from, next) => {
      // Check for org authentication parameters
      if (to.query.org_auth_token && to.query.public_recipient) {
        // Redirect to org email view with the token
        next({
          path: `/org/email/${to.query.org_auth_token}`,
          query: {
            public_recipient: to.query.public_recipient
          }
        })
      } else {
        // Continue to normal email list
        next()
      }
    },
    component: EmailList
  },
  {
    path: '/email/:id',
    component: EmailView,
    props: true
  },
  {
    path: '/respond/:id',
    name: 'respond',
    component: ResponseForm,
    props: true
  },
  {
    path: '/compose',
    component: ComposeMessage,
    props: true
  },
  {
    path: '/org/email/:uuid',
    name: 'org-email',
    component: OrgEmailView,
    props: true
  },
  {
    path: '/org/respond/:uuid',
    name: 'org-respond',
    component: OrgResponseForm,
    props: true
  },
  {
    path: '/signed-out',
    name: 'signed-out',
    component: SignedOut
  },
  {
    path: '/sign-out',
    name: 'sign-out',
    component: {
      setup() {
        authService.handleLogout()
        // Redirect to signed-out page after logout
        router.push('/signed-out')
        return () => null
      }
    }
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes
})

// Navigation guard to protect email view
router.beforeEach((to, from, next) => {
  const isAuthenticated = localStorage.getItem('isAuthenticated')
  const isOrgRoute = to.path.startsWith('/org/')
  const isSignedOutRoute = to.path === '/signed-out'
  
  if (to.path !== '/' && !isAuthenticated && !isOrgRoute && !isSignedOutRoute) {
    next('/')
  } else {
    next()
  }
})
