import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import LoadingScreen from '../components/LoadingScreen';
import { AlertCircle } from 'lucide-react';

export default function AuthCallback() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { handleOAuthCallback } = useAuthStore();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const code = searchParams.get('code');
    const state = searchParams.get('state');
    const errorParam = searchParams.get('error');
    const errorDescription = searchParams.get('error_description');

    if (errorParam) {
      setError(errorDescription || errorParam || 'Inloggning avbröts.');
      return;
    }

    if (!code || !state) {
      setError('Ogiltig autentiseringsförfrågan.');
      return;
    }

    const completeAuth = async () => {
      const success = await handleOAuthCallback(code, state);
      if (success) {
        navigate('/', { replace: true });
      } else {
        setError('Kunde inte slutföra inloggningen. Försök igen.');
      }
    };

    completeAuth();
  }, [searchParams, handleOAuthCallback, navigate]);

  if (error) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center bg-gov-gray-50 p-4">
        <div className="bg-white rounded-widget shadow-widget p-8 max-w-md w-full text-center">
          <div className="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <AlertCircle className="w-8 h-8 text-gov-error" />
          </div>
          <h1 className="text-xl font-semibold text-gov-gray-800 mb-2">
            Inloggning misslyckades
          </h1>
          <p className="text-gov-gray-600 mb-6">{error}</p>
          <button
            onClick={() => navigate('/', { replace: true })}
            className="btn btn-primary"
          >
            Försök igen
          </button>
        </div>
      </div>
    );
  }

  return <LoadingScreen message="Slutför inloggning..." />;
}
