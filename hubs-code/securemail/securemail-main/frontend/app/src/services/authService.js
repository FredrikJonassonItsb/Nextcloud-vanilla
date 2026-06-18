// Unified SSO Authentication Service
// Uses provider-agnostic SSO variables from unified vault configuration
// Supports dynamic scopes and auth parameters per provider

const CLIENT_ID = import.meta.env.VITE_SSO_CLIENT_ID;
const REDIRECT_URI = import.meta.env.VITE_SSO_REDIRECT_URI;
const LOGOUT_REDIRECT_URI = import.meta.env.VITE_SSO_LOGOUT_REDIRECT_URI;

// Unified endpoints - work with any SSO provider
const ENDPOINTS = {
  auth: import.meta.env.VITE_SSO_ENDPOINT_AUTH,
  userinfo: import.meta.env.VITE_SSO_ENDPOINT_USERINFO,
  logout: import.meta.env.VITE_SSO_ENDPOINT_LOGOUT
};

// Provider-specific configurations from vault
const SSO_SCOPE = import.meta.env.VITE_SSO_SCOPE || 'openid profile';
const SSO_AUTH_PARAMS = JSON.parse(import.meta.env.VITE_SSO_AUTH_PARAMS || '{}');

// Validate that essential endpoints are configured
if (!ENDPOINTS.auth || !ENDPOINTS.userinfo || !ENDPOINTS.logout) {
  console.error("SSO endpoints missing. Please ensure SSO provider is properly configured in vault.");
}

class AuthService {
  constructor() {
    this.tokenRefreshTimeout = null
    this.tokenRefreshCallback = null
    this.refreshing = false
    this._refreshPromise = null
  }

  _isOpaqueToken(token) {
    return !token || !token.includes('.') || token.split('.').length !== 3
  }

  isTokenExpired() {
    const token = localStorage.getItem('access_token')
    if (!token) return true

    // Opaque tokens (non-JWT) can't be decoded — assume valid, let server reject if expired
    if (this._isOpaqueToken(token)) {
      return false
    }

    try {
      const payload = JSON.parse(atob(token.split('.')[1]))
      const currentTime = Math.floor(Date.now() / 1000)
      return payload.exp < currentTime + 30 // 30 second buffer
    } catch (error) {
      console.error('Error parsing token:', error)
      return true
    }
  }

  async refreshToken() {
    // Concurrent callers await the same in-flight refresh instead of returning stale token
    if (this.refreshing && this._refreshPromise) {
      console.log('[AuthService] Token refresh already in progress, awaiting result')
      return this._refreshPromise
    }

    this.refreshing = true
    console.log('[AuthService] Refreshing access token via unified backend endpoint')

    this._refreshPromise = (async () => {
      try {
        const refreshToken = localStorage.getItem('refresh_token')
        if (!refreshToken) {
          throw new Error('No refresh token available')
        }

        // Use unified refresh endpoint - works with any provider
        const response = await fetch('/api/auth/refresh', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ refresh_token: refreshToken }),
        });

        if (!response.ok) {
          throw new Error('Failed to refresh token')
        }

        const tokens = await response.json()
        localStorage.setItem('access_token', tokens.access_token)

        if (tokens.refresh_token) {
          localStorage.setItem('refresh_token', tokens.refresh_token)
        }
        if (tokens.id_token) {
          localStorage.setItem('id_token', tokens.id_token)
        }

        console.log('[AuthService] Token refreshed successfully')

        // Notify callback if set
        if (this.tokenRefreshCallback) {
          this.tokenRefreshCallback(tokens.access_token)
        }

        // Schedule next refresh
        this.scheduleTokenRefresh()

        return tokens.access_token

      } catch (error) {
        console.error('[AuthService] Token refresh failed:', error)
        this.logout()
        throw error
      } finally {
        this.refreshing = false
        this._refreshPromise = null
      }
    })()

    return this._refreshPromise
  }

  scheduleTokenRefresh() {
    if (this.tokenRefreshTimeout) {
      clearTimeout(this.tokenRefreshTimeout)
    }

    const token = localStorage.getItem('access_token')
    if (!token) return

    // Opaque tokens can't be decoded for expiry — skip scheduling (server handles expiry)
    if (this._isOpaqueToken(token)) {
      console.log('[AuthService] Opaque token detected, skipping refresh scheduling')
      return
    }

    try {
      const payload = JSON.parse(atob(token.split('.')[1]))
      const expirationTime = payload.exp * 1000
      const currentTime = Date.now()
      const refreshTime = expirationTime - currentTime - 60000 // Refresh 1 minute before expiry

      if (refreshTime > 0) {
        console.log(`[AuthService] Scheduling token refresh in ${Math.round(refreshTime / 1000)} seconds`)
        this.tokenRefreshTimeout = setTimeout(() => {
          this.refreshToken().catch(console.error)
        }, refreshTime)
      }
    } catch (error) {
      console.error('[AuthService] Error scheduling token refresh:', error)
    }
  }

  onTokenRefresh(callback) {
    this.tokenRefreshCallback = callback
  }

  // Initiates logout and redirects the user
  logout() {
    // Clear any refresh timers before redirecting
    if (this.tokenRefreshTimeout) {
      clearTimeout(this.tokenRefreshTimeout)
      this.tokenRefreshTimeout = null
    }

    // Clear all auth-related data from localStorage
    const idToken = localStorage.getItem('id_token')
    localStorage.removeItem('access_token')
    localStorage.removeItem('refresh_token')
    localStorage.removeItem('id_token')
    localStorage.removeItem('isAuthenticated')
    localStorage.removeItem('user_info')

    // Clear callback references
    this.tokenRefreshCallback = null
    this.refreshing = false

    // If OIDC logout endpoint is configured, redirect there to terminate provider session
    // (provider redirects back to /signed-out via post_logout_redirect_uri)
    if (ENDPOINTS.logout) {
      const logoutUrl = new URL(ENDPOINTS.logout)
      logoutUrl.searchParams.append('client_id', CLIENT_ID)
      logoutUrl.searchParams.append('post_logout_redirect_uri', LOGOUT_REDIRECT_URI)
      if (idToken) {
        logoutUrl.searchParams.append('id_token_hint', idToken)
      }
      window.location.href = logoutUrl.toString()
      return
    }

    // No OIDC logout endpoint — fall back to local-only logout
    window.location.href = '/signed-out'
  }

  // Handles local cleanup of authentication data and redirects to the home page
  handleLogout() {
    // Clear all auth-related data
    localStorage.removeItem('access_token')
    localStorage.removeItem('refresh_token')
    localStorage.removeItem('id_token')
    localStorage.removeItem('isAuthenticated')
    localStorage.removeItem('user_info')

    // Clear callback
    this.tokenRefreshCallback = null
    this.refreshing = false

    // Redirect to signed-out page
    window.location.href = '/signed-out'
  }

  login() {
    // Clear any existing auth data before starting new login
    this.handleLogout()

    // Build authorization URL using unified endpoint
    const authUrl = new URL(ENDPOINTS.auth)
    authUrl.searchParams.append('client_id', CLIENT_ID)
    authUrl.searchParams.append('redirect_uri', REDIRECT_URI)
    authUrl.searchParams.append('response_type', 'code')

    // Use dynamic scope from vault (e.g., 'openid profile bankid' for EID, 'openid profile email' for Keycloak)
    authUrl.searchParams.append('scope', SSO_SCOPE)

    // Add provider-specific auth parameters (e.g., kc_idp_hint=oidc for Keycloak)
    Object.entries(SSO_AUTH_PARAMS).forEach(([key, value]) => {
      authUrl.searchParams.append(key, value)
    })

    console.log('[AuthService] Starting login with scope:', SSO_SCOPE, 'and auth params:', SSO_AUTH_PARAMS)

    // Use replace instead of href to avoid browser history issues
    window.location.replace(authUrl.toString())
  }

  async handleCallback(code) {
    console.log('[AuthService] Processing auth callback with unified backend endpoint')

    try {
      let tokens;
      let userInfo;

      // Use unified callback endpoint - works with any provider
      console.log('[AuthService] Exchanging code via unified backend /api/auth/callback')
      const backendResponse = await fetch('/api/auth/callback', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ code: code, redirect_uri: REDIRECT_URI }),
      });
      console.log('[AuthService] Backend /api/auth/callback response status:', backendResponse.status);

      if (!backendResponse.ok) {
        const errorData = await backendResponse.text();
        console.error('[AuthService] Backend token exchange failed. Status:', backendResponse.status, 'Response Data:', errorData);
        throw new Error('Backend token exchange failed: ' + errorData);
      }

      const backendData = await backendResponse.json();
      tokens = backendData.tokens;
      userInfo = backendData.userInfo;
      console.log('[AuthService] Data from backend received:', backendData);

      if (!tokens || !tokens.access_token) {
        console.error('[AuthService] Access token missing in response from backend:', backendData);
        throw new Error('Access token missing in response from backend.');
      }
      if (!userInfo) {
        console.error('[AuthService] User info missing in response from backend:', backendData);
        throw new Error('User info missing in response from backend.');
      }

      // Store tokens
      localStorage.setItem('access_token', tokens.access_token);
      if (tokens.refresh_token) {
        localStorage.setItem('refresh_token', tokens.refresh_token);
      }
      if (tokens.id_token) {
        localStorage.setItem('id_token', tokens.id_token);
      }
      localStorage.setItem('isAuthenticated', 'true');
      localStorage.setItem('user_info', JSON.stringify(userInfo));

      console.log('[AuthService] Authentication successful, stored tokens and user info');

      // Schedule automatic token refresh
      this.scheduleTokenRefresh();

      return { tokens, userInfo };
    } catch (error) {
      console.error('[AuthService] Authentication failed:', error);
      throw error;
    }
  }

  isAuthenticated() {
    const token = localStorage.getItem('access_token')
    const isAuth = localStorage.getItem('isAuthenticated')
    return !!(token && isAuth === 'true' && !this.isTokenExpired())
  }

  getUserInfo() {
    const userInfoStr = localStorage.getItem('user_info')
    return userInfoStr ? JSON.parse(userInfoStr) : null
  }

  getAccessToken() {
    return localStorage.getItem('access_token')
  }
}

export const authService = new AuthService()