# GovPortal - Installationsguide

Denna guide beskriver hur du installerar och konfigurerar GovPortal som ersättning för Nextclouds standarddashboard.

## Förutsättningar

### Systemkrav
- Nextcloud 28.0 eller senare (testad med 32.0.1)
- Node.js 18 eller senare
- npm 9 eller senare
- PHP 8.1 eller senare

### Nextcloud-appar som krävs
Se till att följande appar är installerade och aktiverade i Nextcloud:

1. **Talk (spreed)** - För säkra meddelanden och intern chatt
2. **Calendar** - För mötesbokning
3. **Files** - Standard, alltid installerad

## Installation

### Metod 1: Docker-utveckling (rekommenderad för test)

Om du använder Docker Compose-konfigurationen i detta projekt:

1. **Bygg frontend-applikationen:**
   ```bash
   cd gov-portal
   npm install
   npm run build
   ```

2. **Starta Docker-miljön:**
   ```bash
   cd ..
   docker-compose up -d
   ```

3. **Aktivera appen i Nextcloud:**
   - Öppna http://localhost:8080
   - Logga in som admin
   - Gå till Inställningar > Appar > Inaktiverade appar
   - Hitta "Kommunportal" och klicka "Aktivera"

### Metod 2: Manuell installation

1. **Bygg applikationen:**
   ```bash
   cd gov-portal
   npm install
   npm run build

   # Windows
   .\build.ps1

   # Linux/macOS
   ./build.sh
   ```

2. **Kopiera till Nextcloud:**
   ```bash
   # Linux/macOS
   cp -r nextcloud-app /path/to/nextcloud/custom_apps/govportal

   # Windows
   xcopy /E nextcloud-app C:\path\to\nextcloud\custom_apps\govportal\
   ```

3. **Sätt rätt rättigheter (Linux):**
   ```bash
   chown -R www-data:www-data /path/to/nextcloud/custom_apps/govportal
   chmod -R 755 /path/to/nextcloud/custom_apps/govportal
   ```

4. **Aktivera appen via CLI:**
   ```bash
   # Docker
   docker exec -u www-data nextcloud-app php occ app:enable govportal

   # Direkt
   sudo -u www-data php /path/to/nextcloud/occ app:enable govportal
   ```

## Konfiguration

### Sätt GovPortal som standardsida efter inloggning

**Alternativ 1: Via config.php**

Lägg till följande i `/path/to/nextcloud/config/config.php`:

```php
'defaultapp' => 'govportal',
```

**Alternativ 2: Via CLI**

```bash
# Docker
docker exec -u www-data nextcloud-app php occ config:system:set defaultapp --value=govportal

# Direkt
sudo -u www-data php occ config:system:set defaultapp --value=govportal
```

### Konfigurera OAuth2 (för extern hosting)

Om du hostar frontend-applikationen separat från Nextcloud (ej rekommenderat för produktion):

1. Gå till **Admin > Säkerhet > OAuth 2.0 klienter**
2. Klicka **"Lägg till klient"**
3. Fyll i:
   - **Namn:** GovPortal
   - **Omdirigerings-URL:** `https://din-nextcloud.se/apps/govportal/callback`
4. Klicka **"Lägg till"**
5. Kopiera **klient-ID** som genereras
6. Skapa `.env` i `gov-portal`-mappen:
   ```env
   VITE_NEXTCLOUD_URL=https://din-nextcloud.se
   VITE_OAUTH_CLIENT_ID=<klient-id>
   ```

### Begränsa åtkomst till specifika grupper

I Nextcloud Admin-inställningarna:

1. Gå till **Inställningar > Appar**
2. Hitta **Kommunportal**
3. Klicka på **Begränsa till grupper**
4. Välj vilka grupper som ska ha tillgång

## Verifiering

### Kontrollera att appen fungerar

1. Logga ut och logga in igen
2. Du bör nu se GovPortal som startsida (om konfigurerat som default)
3. Eller gå till **Appar > Portal** i menyn

### Felsökning

**Appen syns inte i applistan:**
```bash
# Rensa cache
docker exec -u www-data nextcloud-app php occ maintenance:repair

# Kontrollera appens status
docker exec -u www-data nextcloud-app php occ app:list | grep govportal
```

**JavaScript-fel i konsolen:**
```bash
# Bygg om frontend
cd gov-portal
npm run build
.\build.ps1  # Windows
```

**Fel vid API-anrop:**
- Kontrollera att Talk, Calendar och Files-apparna är aktiverade
- Kontrollera webbläsarens nätverksflik för specifika felmeddelanden

## Uppdatering

1. Hämta senaste versionen
2. Bygg om:
   ```bash
   cd gov-portal
   npm install
   npm run build
   .\build.ps1
   ```
3. Kopiera över filerna
4. Rensa Nextclouds cache:
   ```bash
   docker exec -u www-data nextcloud-app php occ maintenance:repair
   ```

## Avinstallation

1. **Inaktivera appen:**
   ```bash
   docker exec -u www-data nextcloud-app php occ app:disable govportal
   ```

2. **Ta bort filerna:**
   ```bash
   rm -rf /path/to/nextcloud/custom_apps/govportal
   ```

3. **Återställ standardapp (om ändrad):**
   ```bash
   docker exec -u www-data nextcloud-app php occ config:system:set defaultapp --value=dashboard
   ```

## Support

- **GitHub Issues:** https://github.com/example/govportal/issues
- **E-post:** support@example.se

## Licens

AGPL-3.0 - Se LICENSE-filen för detaljer.
