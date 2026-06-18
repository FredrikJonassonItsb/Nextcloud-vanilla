import { useAuthStore } from '../stores/authStore';
import Header from '../components/Header';
import SecureMessagesWidget from '../components/widgets/SecureMessagesWidget';
import BookMeetingWidget from '../components/widgets/BookMeetingWidget';
import ChatWidget from '../components/widgets/ChatWidget';
import DocumentsWidget from '../components/widgets/DocumentsWidget';

export default function Dashboard() {
  const { user } = useAuthStore();

  // Get greeting based on time of day
  const getGreeting = (): string => {
    const hour = new Date().getHours();
    if (hour < 10) return 'God morgon';
    if (hour < 18) return 'Hej';
    return 'God kväll';
  };

  // Format current date in Swedish
  const formatDate = (): string => {
    return new Date().toLocaleDateString('sv-SE', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  return (
    <div className="min-h-screen bg-gov-gray-50">
      {/* Skip link for accessibility */}
      <a href="#main-content" className="skip-link">
        Hoppa till huvudinnehåll
      </a>

      {/* Header */}
      <Header />

      {/* Main content */}
      <main id="main-content" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Welcome section */}
        <div className="mb-8">
          <h1 className="text-2xl font-semibold text-gov-gray-800">
            {getGreeting()}, {user?.displayName?.split(' ')[0] || 'användare'}
          </h1>
          <p className="text-gov-gray-500 mt-1 capitalize">{formatDate()}</p>
        </div>

        {/* Widgets grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Left column */}
          <div className="space-y-6">
            {/* Säkra meddelanden widget */}
            <SecureMessagesWidget />

            {/* Boka videomöte widget */}
            <BookMeetingWidget />
          </div>

          {/* Right column */}
          <div className="space-y-6">
            {/* Dokument widget */}
            <DocumentsWidget />

            {/* Intern chatt widget */}
            <ChatWidget />
          </div>
        </div>

        {/* Quick actions section (optional) */}
        <div className="mt-8 pt-8 border-t border-gov-gray-200">
          <h2 className="text-sm font-medium text-gov-gray-500 mb-4">Snabbåtkomst</h2>
          <div className="flex flex-wrap gap-3">
            <QuickActionButton
              href="/apps/files"
              icon={
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                </svg>
              }
              label="Alla filer"
            />
            <QuickActionButton
              href="/apps/calendar"
              icon={
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              }
              label="Kalender"
            />
            <QuickActionButton
              href="/apps/spreed"
              icon={
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
              }
              label="Talk"
            />
            <QuickActionButton
              href="/apps/mail"
              icon={
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
              }
              label="E-post"
            />
            <QuickActionButton
              href="/settings/user"
              icon={
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              }
              label="Inställningar"
            />
          </div>
        </div>
      </main>

      {/* Footer */}
      <footer className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 mt-8 border-t border-gov-gray-200">
        <p className="text-center text-sm text-gov-gray-400">
          Säker kommunikationsportal för offentlig sektor
        </p>
      </footer>
    </div>
  );
}

// Quick action button component
function QuickActionButton({
  href,
  icon,
  label,
}: {
  href: string;
  icon: React.ReactNode;
  label: string;
}) {
  return (
    <a
      href={href}
      className="inline-flex items-center gap-2 px-3 py-2 text-sm text-gov-gray-600
                 bg-white border border-gov-gray-200 rounded-md
                 hover:bg-gov-gray-50 hover:border-gov-gray-300 transition-colors"
    >
      {icon}
      {label}
    </a>
  );
}
