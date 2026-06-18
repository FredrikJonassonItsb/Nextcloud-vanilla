import { useEffect } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from './stores/authStore';
import Dashboard from './pages/Dashboard';
import AuthCallback from './pages/AuthCallback';
import LoadingScreen from './components/LoadingScreen';

function App() {
  const { isAuthenticated, isLoading, initializeAuth } = useAuthStore();

  useEffect(() => {
    initializeAuth();
  }, [initializeAuth]);

  if (isLoading) {
    return <LoadingScreen />;
  }

  return (
    <Routes>
      <Route path="/callback" element={<AuthCallback />} />
      <Route
        path="/"
        element={
          isAuthenticated ? (
            <Dashboard />
          ) : (
            <LoadingScreen message="Ansluter till Nextcloud..." />
          )
        }
      />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default App;
