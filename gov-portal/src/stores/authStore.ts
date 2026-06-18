import { create } from 'zustand';
import type { User, AuthState } from '../types';
import {
  oauthConfig,
  buildAuthorizationUrl,
  TOKEN_STORAGE_KEY,
  REFRESH_TOKEN_STORAGE_KEY,
  TOKEN_EXPIRY_STORAGE_KEY,
  STATE_STORAGE_KEY,
  CODE_VERIFIER_STORAGE_KEY,
} from '../config/oauth';
// Note: oauthConfig is still used for OAuth endpoints, but avatar URL uses window.location.origin
import { apiClient } from '../api/client';

interface AuthActions {
  initializeAuth: () => Promise<void>;
  startOAuthFlow: () => Promise<void>;
  handleOAuthCallback: (code: string, state: string) => Promise<boolean>;
  refreshAccessToken: () => Promise<boolean>;
  logout: () => void;
  setUser: (user: User) => void;
  setError: (error: string | null) => void;
}

type AuthStore = AuthState & AuthActions;

// Check if we're running inside Nextcloud (embedded mode)
const isEmbeddedInNextcloud = (): boolean => {
  if (typeof window === 'undefined') return false;

  // Check for Nextcloud's global objects
  const win = window as {
    OC?: { currentUser?: string; requestToken?: string };
    OCA?: Record<string, unknown>;
  };

  return !!(win.OC?.currentUser && win.OC?.requestToken);
};

// Get Nextcloud session info if embedded
const getNextcloudSession = (): { userId: string; requestToken: string } | null => {
  if (!isEmbeddedInNextcloud()) return null;

  const win = window as {
    OC: { currentUser: string; requestToken: string };
  };

  return {
    userId: win.OC.currentUser,
    requestToken: win.OC.requestToken,
  };
};

export const useAuthStore = create<AuthStore>((set, get) => ({
  // Initial state
  isAuthenticated: false,
  isLoading: true,
  user: null,
  accessToken: null,
  refreshToken: null,
  tokenExpiry: null,
  error: null,

  // Initialize authentication
  initializeAuth: async () => {
    set({ isLoading: true, error: null });

    try {
      // Check if we're embedded in Nextcloud
      if (isEmbeddedInNextcloud()) {
        const session = getNextcloudSession();
        if (session) {
          // Use Nextcloud's session directly
          const user = await fetchUserInfo(null, session.requestToken);
          set({
            isAuthenticated: true,
            isLoading: false,
            user,
            accessToken: null, // Using session-based auth
          });
          return;
        }
      }

      // Check for stored tokens (OAuth flow)
      const storedToken = localStorage.getItem(TOKEN_STORAGE_KEY);
      const storedRefreshToken = localStorage.getItem(REFRESH_TOKEN_STORAGE_KEY);
      const storedExpiry = localStorage.getItem(TOKEN_EXPIRY_STORAGE_KEY);

      if (storedToken && storedExpiry) {
        const expiry = parseInt(storedExpiry, 10);

        // Check if token is expired or about to expire (within 5 minutes)
        if (Date.now() < expiry - 5 * 60 * 1000) {
          // Token is valid, fetch user info
          const user = await fetchUserInfo(storedToken);
          set({
            isAuthenticated: true,
            isLoading: false,
            user,
            accessToken: storedToken,
            refreshToken: storedRefreshToken,
            tokenExpiry: expiry,
          });
          return;
        } else if (storedRefreshToken) {
          // Token expired, try to refresh
          const refreshed = await get().refreshAccessToken();
          if (refreshed) {
            set({ isLoading: false });
            return;
          }
        }
      }

      // No valid auth, start OAuth flow
      await get().startOAuthFlow();
    } catch (error) {
      console.error('Auth initialization failed:', error);
      set({
        isLoading: false,
        isAuthenticated: false,
        error: 'Kunde inte ansluta till Nextcloud. Försök igen.',
      });
    }
  },

  // Start OAuth flow
  startOAuthFlow: async () => {
    try {
      const { url, state, codeVerifier } = await buildAuthorizationUrl();

      // Store state and code verifier for validation
      sessionStorage.setItem(STATE_STORAGE_KEY, state);
      sessionStorage.setItem(CODE_VERIFIER_STORAGE_KEY, codeVerifier);

      // Redirect to Nextcloud authorization
      window.location.href = url;
    } catch (error) {
      console.error('Failed to start OAuth flow:', error);
      set({
        isLoading: false,
        error: 'Kunde inte starta inloggning. Försök igen.',
      });
    }
  },

  // Handle OAuth callback
  handleOAuthCallback: async (code: string, state: string) => {
    set({ isLoading: true, error: null });

    try {
      // Validate state
      const storedState = sessionStorage.getItem(STATE_STORAGE_KEY);
      if (state !== storedState) {
        throw new Error('Invalid state parameter');
      }

      const codeVerifier = sessionStorage.getItem(CODE_VERIFIER_STORAGE_KEY);
      if (!codeVerifier) {
        throw new Error('Missing code verifier');
      }

      // Exchange code for tokens
      const response = await fetch(oauthConfig.tokenEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          grant_type: 'authorization_code',
          code,
          redirect_uri: oauthConfig.redirectUri,
          client_id: oauthConfig.clientId,
          code_verifier: codeVerifier,
        }),
      });

      if (!response.ok) {
        const errorData = await response.text();
        console.error('Token exchange failed:', errorData);
        throw new Error('Token exchange failed');
      }

      const tokenData = await response.json();

      // Calculate token expiry
      const expiry = Date.now() + tokenData.expires_in * 1000;

      // Store tokens
      localStorage.setItem(TOKEN_STORAGE_KEY, tokenData.access_token);
      if (tokenData.refresh_token) {
        localStorage.setItem(REFRESH_TOKEN_STORAGE_KEY, tokenData.refresh_token);
      }
      localStorage.setItem(TOKEN_EXPIRY_STORAGE_KEY, expiry.toString());

      // Clean up session storage
      sessionStorage.removeItem(STATE_STORAGE_KEY);
      sessionStorage.removeItem(CODE_VERIFIER_STORAGE_KEY);

      // Fetch user info
      const user = await fetchUserInfo(tokenData.access_token);

      set({
        isAuthenticated: true,
        isLoading: false,
        user,
        accessToken: tokenData.access_token,
        refreshToken: tokenData.refresh_token || null,
        tokenExpiry: expiry,
      });

      return true;
    } catch (error) {
      console.error('OAuth callback failed:', error);
      set({
        isLoading: false,
        isAuthenticated: false,
        error: 'Inloggning misslyckades. Försök igen.',
      });
      return false;
    }
  },

  // Refresh access token
  refreshAccessToken: async () => {
    const { refreshToken } = get();

    if (!refreshToken) {
      return false;
    }

    try {
      const response = await fetch(oauthConfig.tokenEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          grant_type: 'refresh_token',
          refresh_token: refreshToken,
          client_id: oauthConfig.clientId,
        }),
      });

      if (!response.ok) {
        throw new Error('Token refresh failed');
      }

      const tokenData = await response.json();
      const expiry = Date.now() + tokenData.expires_in * 1000;

      // Store new tokens
      localStorage.setItem(TOKEN_STORAGE_KEY, tokenData.access_token);
      if (tokenData.refresh_token) {
        localStorage.setItem(REFRESH_TOKEN_STORAGE_KEY, tokenData.refresh_token);
      }
      localStorage.setItem(TOKEN_EXPIRY_STORAGE_KEY, expiry.toString());

      // Fetch updated user info
      const user = await fetchUserInfo(tokenData.access_token);

      set({
        isAuthenticated: true,
        user,
        accessToken: tokenData.access_token,
        refreshToken: tokenData.refresh_token || refreshToken,
        tokenExpiry: expiry,
      });

      return true;
    } catch (error) {
      console.error('Token refresh failed:', error);
      get().logout();
      return false;
    }
  },

  // Logout
  logout: () => {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
    localStorage.removeItem(REFRESH_TOKEN_STORAGE_KEY);
    localStorage.removeItem(TOKEN_EXPIRY_STORAGE_KEY);
    sessionStorage.removeItem(STATE_STORAGE_KEY);
    sessionStorage.removeItem(CODE_VERIFIER_STORAGE_KEY);

    set({
      isAuthenticated: false,
      user: null,
      accessToken: null,
      refreshToken: null,
      tokenExpiry: null,
      error: null,
    });

    // Redirect to Nextcloud logout if embedded
    if (isEmbeddedInNextcloud()) {
      window.location.href = '/logout';
    }
  },

  // Set user
  setUser: (user: User) => {
    set({ user });
  },

  // Set error
  setError: (error: string | null) => {
    set({ error });
  },
}));

// Helper function to fetch user info
async function fetchUserInfo(accessToken: string | null, requestToken?: string): Promise<User> {
  const headers: Record<string, string> = {
    'OCS-APIREQUEST': 'true',
    Accept: 'application/json',
  };

  if (accessToken) {
    headers['Authorization'] = `Bearer ${accessToken}`;
  }

  if (requestToken) {
    headers['requesttoken'] = requestToken;
  }

  const response = await apiClient.get('/ocs/v2.php/cloud/user', { headers });

  const userData = response.data.ocs.data;
  const baseUrl = typeof window !== 'undefined' ? window.location.origin : '';

  return {
    id: userData.id,
    displayName: userData['display-name'] || userData.displayname || userData.id,
    email: userData.email || '',
    avatar: `${baseUrl}/avatar/${userData.id}/64`,
    groups: userData.groups || [],
    language: userData.language || 'sv',
  };
}
