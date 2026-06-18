import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      retry: 1,
      refetchOnWindowFocus: true,
    },
  },
});

// Mount the app when DOM is ready
function mountApp() {
  const container = document.getElementById('govportal-root');
  if (container) {
    ReactDOM.createRoot(container).render(
      <React.StrictMode>
        <QueryClientProvider client={queryClient}>
          <BrowserRouter basename="/apps/govportal">
            <App />
          </BrowserRouter>
        </QueryClientProvider>
      </React.StrictMode>
    );
  } else {
    console.error('GovPortal: Could not find #govportal-root element');
  }
}

// Wait for DOM to be ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountApp);
} else {
  mountApp();
}
