# GovPortal - Nextcloud Dashboard för Offentlig Sektor

En professionell, skräddarsydd landningssida för Nextcloud, anpassad för socialsekreterare och handläggare inom offentlig sektor.

## Funktioner

- **Säkra meddelanden** - Krypterad kommunikation med klienter via Nextcloud Talk
- **Boka videomöte** - Enkel schemaläggning av videomöten med kalenderintegration
- **Intern chatt** - Snabb kommunikation med kollegor
- **Dokument** - Snabbåtkomst till senast använda filer

## Designprinciper

- Professionell design i linje med svenska myndighetsstandarder
- WCAG 2.1 AA tillgänglighet
- Responsiv design för alla enheter
- Minimalt antal klick för vanliga åtgärder
- Svenska som huvudspråk

## Teknisk Stack

### Frontend
- React 18 med TypeScript
- Vite som build-verktyg
- TailwindCSS för styling
- React Query för datahantering
- Zustand för state management

### Backend Integration
- Nextcloud OCS API
- WebDAV/CalDAV för filer och kalender
- Nextcloud Talk API för chatt och meddelanden
- OAuth2 för autentisering

## Installation

### Förutsättningar

- Nextcloud 28 eller senare
- Node.js 18 eller senare
- npm eller yarn
- Följande Nextcloud-appar installerade:
  - Talk (spreed)
  - Calendar
  - Files (standard)

### Steg 1: Bygg applikationen

```bash
# Klona eller kopiera projektet
cd gov-portal

# Installera beroenden
npm install

# Bygg för produktion
npm run build

# Eller använd build-scriptet
./build.sh  # Linux/macOS
.\build.ps1 # Windows PowerShell
```

### Steg 2: Installera i Nextcloud

1. Kopiera `nextcloud-app` mappen till din Nextcloud-installations `custom_apps` katalog:

```bash
cp -r nextcloud-app /path/to/nextcloud/custom_apps/govportal
```

2. Alternativt, extrahera det skapade arkivet:

```bash
tar -xzf govportal-v1.0.0.tar.gz -C /path/to/nextcloud/custom_apps/govportal
```

3. Aktivera appen i Nextcloud:
   - Gå till Admin > Appar
   - Hitta "Kommunportal" under Inaktiverade appar
   - Klicka "Aktivera"

### Steg 3: Konfigurera OAuth2 (valfritt för extern hosting)

Om du hostar frontend separat från Nextcloud:

1. Gå till Admin > Säkerhet > OAuth 2.0 klienter
2. Klicka "Lägg till klient"
3. Namn: `GovPortal`
4. Omdirigerings-URL: `https://din-nextcloud.se/apps/govportal/callback`
5. Kopiera klient-ID och konfigurera i `.env`

### Steg 4: Sätt som standardsida (valfritt)

För att visa portalen direkt efter inloggning, lägg till i `config.php`:

```php
'defaultapp' => 'govportal',
```

## Utveckling

### Starta utvecklingsserver

```bash
npm run dev
```

Öppna http://localhost:3000

### Miljövariabler

Kopiera `.env.example` till `.env` och anpassa:

```bash
cp .env.example .env
```

### Projektstruktur

```
gov-portal/
├── src/
│   ├── api/              # API-klienter för Nextcloud
│   │   ├── client.ts     # Axios-konfiguration
│   │   ├── messages.ts   # Säkra meddelanden API
│   │   ├── calendar.ts   # Kalender/möten API
│   │   ├── chat.ts       # Talk/chatt API
│   │   └── documents.ts  # Filer/dokument API
│   ├── components/       # React-komponenter
│   │   ├── widgets/      # Widget-komponenter
│   │   ├── Header.tsx    # Sidhuvud
│   │   └── LoadingScreen.tsx
│   ├── config/           # Konfiguration
│   │   └── oauth.ts      # OAuth2-konfiguration
│   ├── pages/            # Sidkomponenter
│   │   ├── Dashboard.tsx # Huvudsida
│   │   └── AuthCallback.tsx
│   ├── stores/           # Zustand stores
│   │   └── authStore.ts  # Autentisering
│   ├── types/            # TypeScript-typer
│   │   └── index.ts
│   ├── App.tsx           # Huvudapp
│   ├── main.tsx          # Entry point
│   └── index.css         # Globala stilar
├── nextcloud-app/        # Nextcloud PHP-app
│   ├── appinfo/
│   ├── lib/
│   ├── templates/
│   └── img/
├── package.json
├── tailwind.config.js
├── vite.config.ts
└── tsconfig.json
```

## API-integration

### Nextcloud Talk (Meddelanden & Chatt)

```typescript
// Hämta konversationer
const conversations = await getConversations(10);

// Skicka meddelande
await sendChatMessage(conversationToken, 'Hej!');
```

### Kalender (CalDAV)

```typescript
// Hämta kommande möten
const meetings = await getUpcomingMeetings(userId, 5);

// Skapa nytt möte
await createMeeting(userId, calendarId, {
  title: 'Möte med klient',
  startDateTime: '2025-01-27T10:00:00',
  endDateTime: '2025-01-27T11:00:00',
  isVideoMeeting: true,
  attendeeEmails: ['klient@example.se'],
});
```

### Filer (WebDAV)

```typescript
// Hämta senaste filer
const files = await getRecentFiles(userId, 10);

// Sök filer
const results = await searchFiles(userId, 'rapport', 20);
```

## Tillgänglighet

Appen följer WCAG 2.1 AA:

- Tangentbordsnavigation i alla widgetar
- ARIA-attribut för skärmläsare
- Färgkontrast minst 4.5:1
- Fokusindikatorer
- Stöd för reducerad rörelse

## Säkerhet

- All kommunikation via HTTPS
- OAuth2 med PKCE för autentisering
- CSRF-skydd via Nextclouds requestToken
- Ingen lagring av känslig data i localStorage

## Federation

Appen stödjer Nextclouds federationsfunktioner:

- Federerade filer visas i dokumentwidgeten
- Federerade Talk-chattar stöds (text)
- Externa användare markeras tydligt

## Licens

AGPL-3.0 - Se LICENSE-filen för detaljer.

## Support

- GitHub Issues: https://github.com/example/govportal/issues
- E-post: support@example.se
