import { useEffect, useState } from 'react';

interface NextcloudContext {
  isEmbedded: boolean;
  currentUser: string | null;
  requestToken: string | null;
  webroot: string;
  generateUrl: (path: string) => string;
  imagePath: (app: string, file: string) => string;
}

/**
 * Hook to detect and integrate with Nextcloud environment
 */
export function useNextcloudIntegration(): NextcloudContext {
  const [context, setContext] = useState<NextcloudContext>({
    isEmbedded: false,
    currentUser: null,
    requestToken: null,
    webroot: '',
    generateUrl: (path: string) => path,
    imagePath: (_app: string, file: string) => file,
  });

  useEffect(() => {
    // Check if running inside Nextcloud
    if (typeof window !== 'undefined' && window.OC) {
      const OC = window.OC;

      setContext({
        isEmbedded: true,
        currentUser: OC.currentUser || null,
        requestToken: OC.requestToken || null,
        webroot: OC.webroot || '',
        generateUrl: OC.generateUrl || ((path: string) => path),
        imagePath: OC.imagePath || ((_app: string, file: string) => file),
      });
    }
  }, []);

  return context;
}

/**
 * Hook to get initial state provided by Nextcloud
 */
export function useNextcloudInitialState<T>(app: string, key: string, defaultValue: T): T {
  const [state, setState] = useState<T>(defaultValue);

  useEffect(() => {
    if (typeof window !== 'undefined') {
      // Try to get initial state from Nextcloud
      const win = window as {
        OCP?: {
          InitialState?: {
            loadState: (app: string, key: string) => T;
          };
        };
      };

      try {
        const value = win.OCP?.InitialState?.loadState(app, key);
        if (value !== undefined) {
          setState(value);
        }
      } catch {
        // Initial state not available, use default
      }
    }
  }, [app, key]);

  return state;
}

/**
 * Hook to track online/offline status
 */
export function useOnlineStatus(): boolean {
  const [isOnline, setIsOnline] = useState(
    typeof navigator !== 'undefined' ? navigator.onLine : true
  );

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  return isOnline;
}

/**
 * Hook to detect user's preferred language from Nextcloud
 */
export function useNextcloudLanguage(): string {
  const [language, setLanguage] = useState('sv');

  useEffect(() => {
    if (typeof window !== 'undefined') {
      // Try to get language from Nextcloud
      const htmlLang = document.documentElement.lang;
      if (htmlLang) {
        setLanguage(htmlLang.split('-')[0]);
      }
    }
  }, []);

  return language;
}

/**
 * Hook to translate strings using Nextcloud's L10N
 */
export function useTranslation(app: string = 'govportal') {
  const t = (text: string, vars?: Record<string, string>): string => {
    if (typeof window !== 'undefined' && window.OC?.L10N) {
      let translated = window.OC.L10N.translate(app, text);

      // Replace variables
      if (vars) {
        Object.entries(vars).forEach(([key, value]) => {
          translated = translated.replace(`{${key}}`, value);
        });
      }

      return translated;
    }

    // Fallback: just replace variables in original text
    if (vars) {
      let result = text;
      Object.entries(vars).forEach(([key, value]) => {
        result = result.replace(`{${key}}`, value);
      });
      return result;
    }

    return text;
  };

  return { t };
}
