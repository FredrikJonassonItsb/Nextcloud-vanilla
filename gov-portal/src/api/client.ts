import axios, { AxiosInstance, AxiosRequestConfig, AxiosError } from 'axios';
import { TOKEN_STORAGE_KEY } from '../config/oauth';

// Get current base URL - evaluated at request time
const getBaseUrl = (): string => {
  if (typeof window !== 'undefined' && window.location) {
    return window.location.origin;
  }
  return '';
};

// Check if running embedded in Nextcloud
const isEmbedded = (): boolean => {
  if (typeof window === 'undefined') return false;
  const win = window as { OC?: { requestToken?: string } };
  return !!win.OC?.requestToken;
};

// Get Nextcloud request token for CSRF protection
const getRequestToken = (): string | null => {
  if (typeof window === 'undefined') return null;
  const win = window as { OC?: { requestToken: string } };
  return win.OC?.requestToken || null;
};

// Create axios instance
const createApiClient = (): AxiosInstance => {
  const client = axios.create({
    timeout: 30000,
    headers: {
      'Content-Type': 'application/json',
      'OCS-APIREQUEST': 'true',
    },
    withCredentials: true,
  });

  // Request interceptor to add auth headers and base URL
  client.interceptors.request.use(
    (config) => {
      // Set baseURL dynamically at request time
      if (!config.baseURL) {
        config.baseURL = getBaseUrl();
      }

      // If embedded, use session-based auth with CSRF token
      if (isEmbedded()) {
        const requestToken = getRequestToken();
        if (requestToken) {
          config.headers['requesttoken'] = requestToken;
        }
      } else {
        // Use OAuth token
        const token = localStorage.getItem(TOKEN_STORAGE_KEY);
        if (token) {
          config.headers['Authorization'] = `Bearer ${token}`;
        }
      }

      // Ensure OCS API header is set
      config.headers['OCS-APIREQUEST'] = 'true';

      // Add format=json for OCS endpoints
      if (config.url?.includes('/ocs/')) {
        try {
          const baseUrl = config.baseURL || getBaseUrl();
          const url = new URL(config.url, baseUrl);
          if (!url.searchParams.has('format')) {
            url.searchParams.set('format', 'json');
          }
          config.url = url.pathname + url.search;
        } catch {
          // If URL parsing fails, just add format as query param
          const separator = config.url.includes('?') ? '&' : '?';
          config.url = `${config.url}${separator}format=json`;
        }
      }

      return config;
    },
    (error) => Promise.reject(error)
  );

  // Response interceptor for error handling
  client.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
      const originalRequest = error.config as AxiosRequestConfig & { _retry?: boolean };

      // Handle 401 Unauthorized
      if (error.response?.status === 401 && !originalRequest._retry) {
        originalRequest._retry = true;

        // If embedded, redirect to login
        if (isEmbedded()) {
          window.location.href = '/login';
          return Promise.reject(error);
        }

        // Try to refresh token
        try {
          const { useAuthStore } = await import('../stores/authStore');
          const refreshed = await useAuthStore.getState().refreshAccessToken();

          if (refreshed) {
            const newToken = localStorage.getItem(TOKEN_STORAGE_KEY);
            if (newToken && originalRequest.headers) {
              originalRequest.headers['Authorization'] = `Bearer ${newToken}`;
            }
            return client(originalRequest);
          }
        } catch {
          // Refresh failed, redirect to login
          const { useAuthStore } = await import('../stores/authStore');
          useAuthStore.getState().logout();
        }
      }

      return Promise.reject(error);
    }
  );

  return client;
};

export const apiClient = createApiClient();

// Helper function for OCS API calls
export const ocsGet = async <T>(
  endpoint: string,
  params?: Record<string, string | number | boolean>
): Promise<T> => {
  const response = await apiClient.get(endpoint, { params: { ...params, format: 'json' } });
  return response.data.ocs.data;
};

// Helper function for OCS POST calls
export const ocsPost = async <T>(
  endpoint: string,
  data?: Record<string, unknown>,
  params?: Record<string, string | number | boolean>
): Promise<T> => {
  const response = await apiClient.post(endpoint, data, { params: { ...params, format: 'json' } });
  return response.data.ocs.data;
};

// Helper function for OCS DELETE calls
export const ocsDelete = async <T>(
  endpoint: string,
  params?: Record<string, string | number | boolean>
): Promise<T> => {
  const response = await apiClient.delete(endpoint, { params: { ...params, format: 'json' } });
  return response.data.ocs.data;
};

// WebDAV client for file operations
export const webdavClient = {
  // List files in a directory
  listFiles: async (path: string = '/'): Promise<Document> => {
    const response = await apiClient.request({
      method: 'PROPFIND',
      url: `/remote.php/dav/files/${encodeURIComponent(getCurrentUserId())}${path}`,
      headers: {
        'Content-Type': 'application/xml',
        Depth: '1',
      },
      data: `<?xml version="1.0"?>
        <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
          <d:prop>
            <d:getlastmodified />
            <d:getetag />
            <d:getcontenttype />
            <d:getcontentlength />
            <d:resourcetype />
            <oc:fileid />
            <oc:permissions />
            <oc:favorite />
            <nc:has-preview />
            <oc:share-types />
            <oc:owner-display-name />
          </d:prop>
        </d:propfind>`,
    });

    const parser = new DOMParser();
    return parser.parseFromString(response.data, 'application/xml');
  },

  // Get file content
  getFile: async (path: string): Promise<Blob> => {
    const response = await apiClient.get(
      `/remote.php/dav/files/${encodeURIComponent(getCurrentUserId())}${path}`,
      { responseType: 'blob' }
    );
    return response.data;
  },

  // Upload file
  uploadFile: async (path: string, content: Blob | string): Promise<void> => {
    await apiClient.put(
      `/remote.php/dav/files/${encodeURIComponent(getCurrentUserId())}${path}`,
      content,
      {
        headers: {
          'Content-Type': content instanceof Blob ? content.type : 'text/plain',
        },
      }
    );
  },

  // Delete file
  deleteFile: async (path: string): Promise<void> => {
    await apiClient.delete(
      `/remote.php/dav/files/${encodeURIComponent(getCurrentUserId())}${path}`
    );
  },
};

// Helper to get current user ID
function getCurrentUserId(): string {
  if (typeof window === 'undefined') return '';

  const win = window as { OC?: { currentUser: string } };
  if (win.OC?.currentUser) {
    return win.OC.currentUser;
  }

  // Fallback: get from auth store (using dynamic import pattern)
  try {
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    const authModule = (window as unknown as { __authStore?: { getState: () => { user?: { id: string } } } }).__authStore;
    return authModule?.getState()?.user?.id || '';
  } catch {
    return '';
  }
}
