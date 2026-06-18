import { Loader2 } from 'lucide-react';

interface LoadingScreenProps {
  message?: string;
}

export default function LoadingScreen({ message = 'Laddar...' }: LoadingScreenProps) {
  return (
    <div
      className="min-h-screen flex flex-col items-center justify-center bg-gov-gray-50"
      role="status"
      aria-live="polite"
    >
      <div className="flex flex-col items-center gap-4">
        {/* Logo placeholder */}
        <div className="w-16 h-16 bg-gov-blue-500 rounded-xl flex items-center justify-center">
          <svg
            className="w-10 h-10 text-white"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M12 2L2 7L12 12L22 7L12 2Z"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <path
              d="M2 17L12 22L22 17"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <path
              d="M2 12L12 17L22 12"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </div>

        {/* Loading spinner */}
        <Loader2
          className="w-8 h-8 text-gov-blue-500 animate-spin"
          aria-hidden="true"
        />

        {/* Loading message */}
        <p className="text-gov-gray-600 text-sm font-medium">{message}</p>
      </div>

      {/* Screen reader text */}
      <span className="sr-only">{message}</span>
    </div>
  );
}
