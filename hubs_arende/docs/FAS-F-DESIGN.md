<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Fas F — Durabel meddelande→ärende-koppling (design för godkännande)

Status: **DESIGN, ej byggd.** Underlag: design-workflow (3/5 områden + egen syntes).
Beslut redan fattat: **REFERENS, inte kopia** (NEVER-SoR).

## Kärninsikt (förenklar hela bygget)

sdkmc:s `ItslTagService::tagMessages($userId, 'case:{id}', $ids)`
(`hubs-code/sdkmc/.../Service/ItslTagService.php:1181`) är **REDAN** fullt
user-scopad och per-meddelande-authz:ad: `getMessage($userId,$id)` kastar om
meddelandet inte ägs/ses av `$userId`. **IDOR-blockeraren är INTE logiken — det är
TRANSPORTEN:** hubs_arende anropar routen server-till-server **som service-kontot**
(`SdkmcClient::tagMessage` + `ServiceAccountAuth`), så `$userId` blir service-kontot
och per-meddelande-gaten blir verkningslös. Fixen är att tagga i **slutanvändarens
kontext** — ingen ny säkerhetslogik behövs i sdkmc.

## Referensbeslut (NEVER-SoR)

Meddelandet **stannar i den säkra brevlådan** (källa/SoR-kandidat → facksystemet vid
commit). I ärenderummets groupfolder skrivs en **liten referens-fil**
`msg-<sha256(messageId)[0:12]>.url` som innehåller **ENDAST**: djuplänk till
mail-tråden (`mail.page.thread`, samma som `ItslTagService` bygger via
`IURLGenerator::linkToRouteAbsolute`) + Message-ID + hubsCaseId. **ALDRIG** body,
ämnesrad i klartext eller bilagor — det vore en kopia av verksamhetsdata över
sekretess­gräns (OSL 26 kap) = exakt det `hubs_arende` är byggd för att undvika.
Registreras som `Pekare(objektTyp='groupfolder_ref', objektId=filnamn)` så den
gallras/kompenseras symmetriskt med folder/talk_room.

> Det finns INGEN befintlig "mirra meddelande→fil"-mekanism i sdkmc.
> `TagFileController` taggar bara *befintliga* filer (loa3) — fel verktyg. Detta är
> en liten NY mekanism i hubs_arende (`ReferensFilService`), in-process via
> `IRootFolder` mot groupfolder-mounten.

## Dataflöde

1. Meddelande in → mottagningens funktionsbrevlåda (sdkmc, INBOX;
   `NewMessagesSynchronizedListener`).
2. Mottagningen **skapar ärende** (`createCase`) ELLER **kopplar till befintligt**
   (`koppla`) — **människo-bekräftat**. Felkoppling = sekretessincident; ingen tyst
   auto-koppling på svag signal; SSN/orgId-matchning fail-closed.
3. Meddelandet taggas `case:{hubsCaseId}` **som den inloggade handläggaren**
   (user-scopat) → durabelt, hittbart. Samma token för `skapa` (R9) och `koppla`.
4. **Referens-fil** skrivs i ärenderummets groupfolder (pekare-i-filform).
5. **"Behandlat" — SYNLIGA taggar i mail-klienten** (beslut: ej rent virtuellt). Två
   `ItslTag` med `display_name` + `color` sätts på meddelandet så att en handläggare
   som öppnar det i sdkmc-klienten SER kopplingen:
   - `case:{hubsCaseId}` → renderas som **"Ärende {kort-ref}"** (färgad etikett) — visar
     VILKET ärende meddelandet hör till.
   - `Behandlad` → färgad status-etikett — visar att meddelandet är triagat/hanterat.
   Detta undviker nackdelen med en osynlig/system-tag (att meddelandet hanteras igen
   eller att kopplingen inte syns). Ingen fysisk IMAP-flytt i V1; *tillval* V2: flytt
   till `Behandlat`-mapp.
6. **Handoff:** case:-tagg + referens-fil + pekare sitter på **ÄRENDE-nivå** → följer
   med när ärendet "övergår till helt andra handläggare". **Per-case-gruppen (Fas E)**
   styr vem som ser referensen → narrowing vid tilldelning (krets revokas).
7. **Gallring:** referens-fil + `conversation`/`groupfolder_ref`-pekare + case-tagg
   städas med ärendet (`GallringService`, symmetriskt med Fas E grupp-radering).

## Komponenter

| App | Ändring | Kontrakt |
|---|---|---|
| **sdkmc** | *Väg A:* ingen ny route — frontend kallar befintlig `PUT /api/thread/tags/case:{id}` med user-session. *Väg C:* ny tunn `PUT /api/case/{hubsCaseId}/tags` som härleder label server-side + on-behalf-of-header. Liten utökning: **per-id landad-status** i `tagMessages`. | `{ids:[...]}` → `{ok, taggade, landade:[...], ejAtkomst:[...]}` |
| **hubs_arende** | Ny `ReferensFilService::skrivMeddelandeReferens(hubsCaseId, messageId, djuplank)`; `kopplaMeddelande` tar landad-status från user-scopad väg (admin-gaten kan tas bort); `GallringService` + SAGA-compensation städar referens-fil. | `Pekare(objektTyp='groupfolder_ref')` |
| **hubs_start** | Koppla-**väljare** (välj målärende) → skicka `{hubsCaseId, rad:{messageIds}}`; visa "kopplat" först på `verifierad=true`. | `POST /inflode/koppla {hubsCaseId, rad}` |

## Beslut (fattade)

1. **IDOR-väg: A** — frontend kallar sdkmc med användarens egen session (rätt `$userId`,
   per-meddelande-authz automatiskt, ingen service-konto-taggning).
2. **"Behandlat": SYNLIGA taggar** — case:-taggen + en `Behandlad`-tag, båda med
   `display_name` + `color` → syns som färgade etiketter i sdkmc-mail-klienten. Fysisk
   IMAP-flytt är V2-tillval.

## Öppen fråga (kvar)

3. **Inflöde-trigger:** `NewMessagesClassifier` är dokumenterad men **saknas** i sdkmc
   (eventet dispatchas ingenstans). Bygga den nu, eller koppla manuellt via UI först?
   (Påverkar bara F4 — inte F1–F3.)

## Föreslagen bygg-ordning (var och en verifierbar)

- **F1** `ReferensFilService` + `groupfolder_ref`-pekare + gallring/compensation.
  *Verifiera:* koppla → referens-fil i foldern (endast länk, **ingen PII**); gallra → städad.
- **F2** User-scopad tagg (Väg A) → ta bort admin-gaten. Case:-taggen + `Behandlad`-tag
  skapas med **`display_name` ("Ärende {kort-ref}" / "Behandlad") + `color`** så de syns i
  mail-klienten. *Verifiera:* per-meddelande-authz (annans meddelande → nekas);
  `verifierad=true` på eget meddelande; **etiketterna syns färgade i sdkmc**.
- **F3** Frontend koppla-väljare + `verifierad`-gating. *Verifiera:* välj ärende → koppla → "kopplat".
- **F4 (tillval)** Fysisk behandlat-flytt + inflöde-classifier i sdkmc.

## Sekretess-invarianter (måste hålla)

- Referens-filen: endast djuplänk + Message-ID + hubsCaseId. Filnamn **hashat** (ingen
  rå Message-ID/PII i klartext).
- Felkoppling = sekretessincident → människo-bekräftelse, ingen tyst auto-tagg.
- Referensen ärver ärenderummets per-case-ACL (Fas E) → mottagningskretsen ser den ej
  efter handoff.
- NEVER-SoR: inget meddelandeinnehåll i hubs_arende eller groupfoldern.
