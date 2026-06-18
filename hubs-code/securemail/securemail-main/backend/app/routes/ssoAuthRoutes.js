const express = require('express');
const axios = require('axios');
const router = express.Router();

require('dotenv').config(); // Ensure .env variables are loaded if not globally done

// Unified SSO configuration from environment variables
const CLIENT_ID = process.env.SSO_CLIENT_ID;
const CLIENT_SECRET = process.env.SSO_CLIENT_SECRET;

const ENDPOINTS = {
  token: process.env.SSO_ENDPOINT_TOKEN,
  userinfo: process.env.SSO_ENDPOINT_USERINFO,
};

// Validate configuration
if (!CLIENT_ID || !CLIENT_SECRET || !ENDPOINTS.token || !ENDPOINTS.userinfo) {
  console.error('[SSO Auth] Missing required SSO configuration. Please check SSO_* environment variables.');
}

// Unified authentication callback endpoint - works with any SSO provider
router.post('/callback', async (req, res) => {
  const { code, redirect_uri } = req.body;
  console.log(`[SSO Backend] /callback received code: ${code}, redirect_uri: ${redirect_uri}`);

  if (!code || !redirect_uri) {
    console.error('[SSO Backend] Missing code or redirect_uri in request body');
    return res.status(400).json({ error: 'Missing code or redirect_uri' });
  }

  const tokenPayload = {
    grant_type: 'authorization_code',
    code,
    redirect_uri,
    client_id: CLIENT_ID,
    client_secret: CLIENT_SECRET,
  };

  try {
    console.log(`[SSO Backend] Posting to token endpoint: ${ENDPOINTS.token}`);
    const tokenResponse = await axios.post(ENDPOINTS.token, tokenPayload, {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });

    const tokens = tokenResponse.data;
    console.log('[SSO Backend] Tokens received from SSO provider:', tokens);

    if (!tokens || !tokens.access_token) {
      console.error('[SSO Backend] Access token missing in response from SSO provider:', tokens);
      return res.status(500).json({ error: 'Failed to retrieve access token from SSO provider.' });
    }

    // Get UserInfo using the access token
    console.log(`[SSO Backend] Fetching user info from: ${ENDPOINTS.userinfo}`);
    const userInfoResponse = await axios.get(ENDPOINTS.userinfo, {
      headers: {
        'Authorization': `Bearer ${tokens.access_token}`
      }
    });

    const userInfo = userInfoResponse.data;
    console.log('[SSO Backend] User info received from SSO provider:', userInfo);

    if (!userInfo) {
      console.error('[SSO Backend] Failed to retrieve user info from SSO provider');
      return res.status(500).json({ error: 'Failed to retrieve user info from SSO provider.' });
    }

    // Return both tokens and user info to the frontend
    console.log('[SSO Backend] Successfully processed authentication, returning tokens and user info');
    res.json({
      tokens: tokens,
      userInfo: userInfo
    });

  } catch (error) {
    console.error('[SSO Backend] Error during token exchange or user info retrieval:', error.message);

    // Provide more specific error information
    if (error.response) {
      console.error('[SSO Backend] Error response from SSO provider:', {
        status: error.response.status,
        statusText: error.response.statusText,
        data: error.response.data
      });
      return res.status(500).json({
        error: 'SSO provider error',
        details: error.response.data || error.response.statusText
      });
    }

    return res.status(500).json({ error: 'Internal server error during authentication' });
  }
});

// Unified token refresh endpoint - works with any SSO provider
router.post('/refresh', async (req, res) => {
  const { refresh_token } = req.body;
  console.log('[SSO Backend] /refresh endpoint called');

  if (!refresh_token) {
    console.error('[SSO Backend] Missing refresh_token in request body');
    return res.status(400).json({ error: 'Missing refresh_token' });
  }

  const refreshPayload = {
    grant_type: 'refresh_token',
    refresh_token: refresh_token,
    client_id: CLIENT_ID,
    client_secret: CLIENT_SECRET,
  };

  try {
    console.log(`[SSO Backend] Posting refresh request to token endpoint: ${ENDPOINTS.token}`);
    const tokenResponse = await axios.post(ENDPOINTS.token, refreshPayload, {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });

    const tokens = tokenResponse.data;
    console.log('[SSO Backend] Refresh tokens received from SSO provider');

    if (!tokens || !tokens.access_token) {
      console.error('[SSO Backend] Access token missing in refresh response from SSO provider:', tokens);
      return res.status(500).json({ error: 'Failed to refresh access token from SSO provider.' });
    }

    console.log('[SSO Backend] Successfully refreshed tokens');
    res.json(tokens);

  } catch (error) {
    console.error('[SSO Backend] Error during token refresh:', error.message);

    // Provide more specific error information
    if (error.response) {
      console.error('[SSO Backend] Error response from SSO provider during refresh:', {
        status: error.response.status,
        statusText: error.response.statusText,
        data: error.response.data
      });
      return res.status(401).json({
        error: 'Token refresh failed',
        details: error.response.data || error.response.statusText
      });
    }

    return res.status(500).json({ error: 'Internal server error during token refresh' });
  }
});

module.exports = router;