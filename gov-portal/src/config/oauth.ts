import type { OAuthConfig } from '../types';

// Get the Nextcloud URL - always use current origin when embedded
const getNextcloudUrl = (): string => {
  if (typeof window !== 'undefined' && window.location) {
    return window.location.origin;
  }
  return 'http://localhost:8080';
};

// OAuth2 client configuration
// These values should be configured in Nextcloud Admin > Security > OAuth2
export const oauthConfig: OAuthConfig = {
  clientId: import.meta.env.VITE_OAUTH_CLIENT_ID || 'gov-portal-client',
  redirectUri: `${getNextcloudUrl()}/apps/govportal/callback`,
  authorizationEndpoint: `${getNextcloudUrl()}/index.php/apps/oauth2/authorize`,
  tokenEndpoint: `${getNextcloudUrl()}/index.php/apps/oauth2/api/v1/token`,
  nextcloudUrl: getNextcloudUrl(),
};

// Generate a random state parameter for CSRF protection
export const generateState = (): string => {
  const array = new Uint8Array(32);
  crypto.getRandomValues(array);
  return Array.from(array, (byte) => byte.toString(16).padStart(2, '0')).join('');
};

// Generate PKCE code verifier
export const generateCodeVerifier = (): string => {
  const array = new Uint8Array(32);
  crypto.getRandomValues(array);
  return base64URLEncode(array);
};

// Generate PKCE code challenge from verifier
export const generateCodeChallenge = async (verifier: string): Promise<string> => {
  const encoder = new TextEncoder();
  const data = encoder.encode(verifier);
  const digest = await crypto.subtle.digest('SHA-256', data);
  return base64URLEncode(new Uint8Array(digest));
};

// Base64 URL encode (for PKCE)
const base64URLEncode = (buffer: Uint8Array): string => {
  return btoa(String.fromCharCode(...buffer))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
};

// Build authorization URL
export const buildAuthorizationUrl = async (): Promise<{
  url: string;
  state: string;
  codeVerifier: string;
}> => {
  const state = generateState();
  const codeVerifier = generateCodeVerifier();
  const codeChallenge = await generateCodeChallenge(codeVerifier);

  const params = new URLSearchParams({
    response_type: 'code',
    client_id: oauthConfig.clientId,
    redirect_uri: oauthConfig.redirectUri,
    state,
    code_challenge: codeChallenge,
    code_challenge_method: 'S256',
  });

  return {
    url: `${oauthConfig.authorizationEndpoint}?${params.toString()}`,
    state,
    codeVerifier,
  };
};

// Token storage keys
export const TOKEN_STORAGE_KEY = 'nc_gov_portal_token';
export const REFRESH_TOKEN_STORAGE_KEY = 'nc_gov_portal_refresh_token';
export const TOKEN_EXPIRY_STORAGE_KEY = 'nc_gov_portal_token_expiry';
export const STATE_STORAGE_KEY = 'nc_gov_portal_oauth_state';
export const CODE_VERIFIER_STORAGE_KEY = 'nc_gov_portal_code_verifier';
