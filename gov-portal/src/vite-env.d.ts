/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_NEXTCLOUD_URL: string;
  readonly VITE_OAUTH_CLIENT_ID: string;
  readonly VITE_DEBUG: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

// Nextcloud global types
declare global {
  interface Window {
    OC?: {
      currentUser: string;
      requestToken: string;
      getHost?: () => string;
      generateUrl?: (path: string) => string;
      linkTo?: (app: string, file: string) => string;
      imagePath?: (app: string, file: string) => string;
      filePath?: (app: string, file: string) => string;
      webroot?: string;
      L10N?: {
        translate: (app: string, text: string) => string;
      };
    };
    OCA?: Record<string, unknown>;
    _oc_debug?: boolean;
  }
}

export {};
