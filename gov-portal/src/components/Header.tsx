import { useState, useRef, useEffect } from 'react';
import { useAuthStore } from '../stores/authStore';
import { ChevronDown, LogOut, Settings, User, HelpCircle } from 'lucide-react';

export default function Header() {
  const { user, logout } = useAuthStore();
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  // Close menu when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        menuRef.current &&
        !menuRef.current.contains(event.target as Node) &&
        buttonRef.current &&
        !buttonRef.current.contains(event.target as Node)
      ) {
        setIsMenuOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Close menu on escape key
  useEffect(() => {
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && isMenuOpen) {
        setIsMenuOpen(false);
        buttonRef.current?.focus();
      }
    };

    document.addEventListener('keydown', handleEscape);
    return () => document.removeEventListener('keydown', handleEscape);
  }, [isMenuOpen]);

  return (
    <header className="bg-white border-b border-gov-gray-200 sticky top-0 z-40">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Logo and title */}
          <div className="flex items-center gap-3">
            <a
              href="/"
              className="flex items-center gap-3 text-gov-gray-800 hover:text-gov-blue-500 transition-colors"
            >
              <div className="w-10 h-10 bg-gov-blue-500 rounded-lg flex items-center justify-center">
                <svg
                  className="w-6 h-6 text-white"
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
              <div>
                <h1 className="text-lg font-semibold">Portal</h1>
                <p className="text-xs text-gov-gray-500 hidden sm:block">
                  Säker kommunikation
                </p>
              </div>
            </a>
          </div>

          {/* Navigation links (desktop) */}
          <nav className="hidden md:flex items-center gap-1" aria-label="Huvudnavigation">
            <NavLink href="/apps/files">Filer</NavLink>
            <NavLink href="/apps/calendar">Kalender</NavLink>
            <NavLink href="/apps/spreed">Talk</NavLink>
          </nav>

          {/* User menu */}
          <div className="relative">
            <button
              ref={buttonRef}
              onClick={() => setIsMenuOpen(!isMenuOpen)}
              className="flex items-center gap-2 p-1.5 rounded-lg hover:bg-gov-gray-100 transition-colors"
              aria-expanded={isMenuOpen}
              aria-haspopup="true"
              aria-label="Användarmeny"
            >
              {user?.avatar ? (
                <img
                  src={user.avatar}
                  alt=""
                  className="w-8 h-8 rounded-full"
                  onError={(e) => {
                    e.currentTarget.style.display = 'none';
                    e.currentTarget.nextElementSibling?.classList.remove('hidden');
                  }}
                />
              ) : null}
              <div
                className={`w-8 h-8 rounded-full bg-gov-blue-100 text-gov-blue-600 flex items-center justify-center text-sm font-semibold ${
                  user?.avatar ? 'hidden' : ''
                }`}
              >
                {user?.displayName?.charAt(0).toUpperCase() || 'U'}
              </div>
              <span className="hidden sm:block text-sm text-gov-gray-700 max-w-[150px] truncate">
                {user?.displayName || 'Användare'}
              </span>
              <ChevronDown
                className={`w-4 h-4 text-gov-gray-400 transition-transform ${
                  isMenuOpen ? 'rotate-180' : ''
                }`}
              />
            </button>

            {/* Dropdown menu */}
            {isMenuOpen && (
              <div
                ref={menuRef}
                className="absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-lg border border-gov-gray-200 py-1 animate-fade-in"
                role="menu"
                aria-orientation="vertical"
              >
                <div className="px-4 py-3 border-b border-gov-gray-100">
                  <p className="text-sm font-medium text-gov-gray-800 truncate">
                    {user?.displayName}
                  </p>
                  <p className="text-xs text-gov-gray-500 truncate">{user?.email}</p>
                </div>

                <div className="py-1">
                  <MenuLink href="/settings/user" icon={<User className="w-4 h-4" />}>
                    Min profil
                  </MenuLink>
                  <MenuLink href="/settings/user/security" icon={<Settings className="w-4 h-4" />}>
                    Inställningar
                  </MenuLink>
                  <MenuLink
                    href="https://docs.nextcloud.com/"
                    icon={<HelpCircle className="w-4 h-4" />}
                    external
                  >
                    Hjälp
                  </MenuLink>
                </div>

                <div className="border-t border-gov-gray-100 pt-1">
                  <button
                    onClick={() => {
                      setIsMenuOpen(false);
                      logout();
                    }}
                    className="w-full flex items-center gap-3 px-4 py-2 text-sm text-gov-gray-700 hover:bg-gov-gray-50 transition-colors"
                    role="menuitem"
                  >
                    <LogOut className="w-4 h-4" />
                    Logga ut
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
  );
}

// Navigation link component
function NavLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <a
      href={href}
      className="px-3 py-2 text-sm text-gov-gray-600 hover:text-gov-blue-500 hover:bg-gov-blue-50 rounded-md transition-colors"
    >
      {children}
    </a>
  );
}

// Menu link component
function MenuLink({
  href,
  icon,
  children,
  external = false,
}: {
  href: string;
  icon: React.ReactNode;
  children: React.ReactNode;
  external?: boolean;
}) {
  return (
    <a
      href={href}
      className="flex items-center gap-3 px-4 py-2 text-sm text-gov-gray-700 hover:bg-gov-gray-50 transition-colors"
      role="menuitem"
      {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
    >
      {icon}
      {children}
      {external && (
        <svg
          className="w-3 h-3 ml-auto text-gov-gray-400"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
          />
        </svg>
      )}
    </a>
  );
}
