<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# GUI-analys: Min dag-vyn för socialsekreteraren (2026-07-02)

> **STATUS 2026-07-03: P1 + P2 BYGGDA OCH DEPLOYADE** (hubs_arende 0.8.0 + hubs_start 1.3.2,
> jest 95/phpunit 87, GUI-verifierat live). Se HANDOVER-FORTSATTNING §SESSION 5 för leveransen.
> Kvar: P3-besluten (§4) + de GUI-verifieringar som kräver användarsession (Meddelanden-flik
> mot riktig post, dnr-märkt bokning i Möten-fliken, frist-notisen).

> Utgår från användarens (Fredriks) GUI-genomgång efter live-testet av team-lagret,
> korsläst mot faktisk kod (MinaArenden.vue, ArendeKort.vue, ArendeService::mapToFullCard/
> dashboardSummary). Mockup av förslaget: se Artifact "hubs-gui-forslag"
> (https://claude.ai/code/artifact/d98bcfee-35be-43dd-bb6e-ecd1fa37c6b7).

## 0. Grundinsikt — var vyn står idag

Kortet är byggt "åtgärd-först" (nästa-åtgärd + grindar) och det fungerar — hela
livscykeln kan köras därifrån. Men kortets **innehållsytor är ärliga skal**:
motorn returnerar medvetet `meddelanden: []`, `moten: []`, `bevakningar: []`,
`beslut: null` (NEVER-SoR — motorn lagrar pekare, inte innehåll). Nästa naturliga
fas är att fylla ytorna **via pekarna** (läs-projektioner, aldrig kopior).
Användarens analys pekar exakt dit.

## 1. Rotorsaken till "Mina ärenden är tomt" (viktigaste fyndet)

- `dashboardArenden()` (ArendeService) listar **hela enhetens** ärenden
  (findAll + enhetTillaten) — inte "mina".
- Frontend delar upp i zoner via `zonOf()` (MinaArenden.vue:433): frist
  error/warning eller okvitterad plikt ⇒ `het` ⇒ kortet **flyttas** till
  "Kräver åtgärd nu"; "Mina ärenden" = resten.
- Konsekvens: med ett enda (hett) ärende är "Mina ärenden" tomt. Samma ärende
  kan aldrig ses i sin arbetslista när det brinner = tvärtemot arbetssättet.

**Beslut (förslag):**
1. **Mina ärenden = medlemsbaserat.** Summaryn filtreras på
   `uid ∈ hubs_arende_member` (mottagningskrets/handläggare/co/observatör).
   Ledgern finns; motorn behöver ett `mineOnly`-läge i dashboardArenden
   (join mot member) + ev. flik "Enhetens ärenden" för överblick.
2. **"Kräver åtgärd nu" blir varsel-lista, inte kortcontainer.** Kompakta rader
   ("Frist förfallen — färdigställ utredning") som scrollar till/highlightar
   kortet i Mina ärenden (anchor + fokus). Ett ärende = ett kort = en arbetsyta.

## 2. Användarens punkter — bedömning + teknisk förankring

| # | Punkt | Bedömning | Vad som krävs |
|---|---|---|---|
| 1 | Flikarna alltid synliga (idag först vid expand) | JA — visa flikraden alltid, med **räknare** per flik; innehåll lazy-laddas som idag | Frontend-only; räknare kräver counts i mapToCard (billigt för akten/rum; meddelanden/möten kräver nya läsytor, se nedan) |
| 2 | Filer i Akten klickbara | JA — data finns (arenderumDokument bär namn + fileid) | Frontend: rendera med `deepLinks.fileLink` |
| 3 | Meddelandereferenser (`msg-*.url`) som meddelanden | JA — visa som "Startmeddelandet …" med tråd-djuplänk, inte filnamn | Motorn särskiljer redan groupfolder_ref-pekare; frontend renderar dem som meddelanderad |
| 4 | "Säkra meddelanden" = alla länkade meddelanden, alla typer | JA — case:-taggen ÄR kopplingen; bygg sdkmc-läsyta `GET /api/v1/case-messages?ref=` (sök på tagg, ACL-buren, returnerar rader med threadLink + kanal-badge) | Ny sdkmc-endpoint (backend-additions) + flik-rendering |
| 5 | "Diskussion" → **Rum**: alla samtalsrum som inte är bokade möten | JA — motorn har 1:n talk_room-pekare; mapToFullCard bör returnera `pekare.talkRooms[]` (alla) i st.f. bara första token; mötesrum filtreras bort (mötesrum bokförs ej som talk_room-pekare — de skapas av SecureMeetingService) | Liten motorutökning + flik med spreedRoomLink + "Ny chatt"-knapp (finns) |
| 6 | Möten: genomförda + kommande, länk till bokning och mötets chatt | JA — kalenderobjekt per ärende finns (CATEGORIES=hubsCaseId, handläggarägd); bygg läsyta som listar VEVENTs per kategori + koppla till Talk-länk (LOCATION). Kräver även **gap17** (MeetingWizard skickar dnr) så bokningar knyts till ärendet | Ny läsyta (motorn via CalendarClient eller sdkmc MeetingService med dnr-filter — dnr-i-ICS finns redan: X-HUBS-DNR) |
| 7 | Bevakning öppnar Deck-panelen för ärendet | DELVIS — djuplänken finns men **Deck-boarden saknar ACL för handläggare** (Permission denied, verifierat i test). Kort sikt: visa Deck-kortets innehåll i fliken via motorn (DeckClient läser kortet — läs-projektion). Lång sikt: produktbeslut om board-per-ärende eller Deck-ACL till per-case-grupp/team (boarden är per enhet ⇒ ACL dit = cross-case-exponering av korttitlar/frister) | Kort sikt: motor-läsyta. Lång sikt: BESLUT |
| 8 | Beslut = beslutslogg (Tables?) | JA till beslutslogg, **NEJ till Tables** (underkänt som datalager i BESLUT-01). Beslutet i sak bor i Treserva (NEVER-SoR) — Hubs visar SPEGELN: tidslinje ur motorns egen bokföring (steg-övergångar, commit-kvittenser m. dnr/tid, signeringsbekräftelser, tilldelningar). Kräver att motorn får en händelselogg-tabell (`hubs_arende_handelse`) eller härleder ur befintligt (kvitton + pekare + medlemsledger) | Ny flik "Historik & beslut"; hänger ihop med GAP-056/BESLUT-19 (reconciliation/audit) — bygg som EN journal |
| 9 | Övre raden = modulöppnare; **Team först** | JA — och ge raderna tydliga roller: modulraden ÖPPNAR moduler (Team, Akten, Meddelanden, Kalender, Signering), flikraden visar innehåll PÅ PLATS. Idag blandas verb (Skicka/Möte/Signera är åtgärder) — åtgärderna bor kvar i Nästa åtgärd-menyn | Frontend-only omordning; Signering-modulen = libresign/Inera-ytan (idag = CommitGrind-flödet, ärligt tills Inera) |
| 10 | Tomma paneler kollapsade | JA — konsekvent med "Att hantera": en rad + räknare 0, expanderar vid klick/innehåll. Gäller Ej ärendekopplat, Mina möten, Kvittenser | Frontend-only |
| 11 | Kräver åtgärd nu: varsla + länka ned | JA — se §1 beslut 2 | Frontend-only |

## 3. Aspekter användaren inte tog upp (komplettering)

**Arbetsflöde & ägarskap**
- **"Ta ärendet"** saknas i handläggarvyn (tilldela finns bara i gruppledarens
  fördelningsvy — som dessutom är demo-gated: lage-växeln renderas bara i
  demoMode!). Kortet bör visa Otilldelad-badge + Ta ärendet (motorns
  tilldela-API finns). Roll-läget (utredning/fördelning) måste styras av riktig
  roll/grupp, inte demoflaggan.
- **Medlemspanel på kortet**: vilka är anslutna + roll (ur ledgern) +
  "Lägg till kollega" (laggTillMedlem-API:t finns). Idag syns medlemmar bara i
  Teams-vyn.
- **Sök**: hitta ärende på dnr/pseudonym — CommandPalette (Ctrl+K) finns men
  söker inte motor-data.

**Varsel & realtid**
- **NC-notifikationer** saknas helt: nytt inflöde på mitt ärende, frist T-3/T-0,
  @-omnämnande, tilldelning. Utan dem måste handläggaren polla vyn själv.
- 30 s-polling → notify_push (känd punkt) för att nya rader/varsel ska landa live.

**Ärlighet & döda ytor (delvis kända)**
- Dagspulsens 4 av 5 räknare är hårdkodade 0 i motorn — koppla eller dölj
  (döda nollor lär användaren att ignorera pulsen).
- "Behörighet: · 0 olästa" i Akten-fliken: olästa är en ärlig nolla utan källa,
  behörighetsfältet tomt — ta bort tills källa finns.
- Hälsningen "Anna" + TilldelningBands Anna-jämförelse (kritiskt: fel
  ägar-attribution) — ersätt med inloggad användare.
- GallringsGrindens inbäddade handlingstypslista (demo-fallback) används vid
  skarpa gallringsbeslut — wira DHP eller märk tydligt.

**Interaktionsbuggar (sedda i live-test)**
- **NcActions-menyn stängs inte** när ett menyval öppnar en modal (Ny chatt,
  Boka möte…) — menyn ligger kvar ovanpå modalen. Fixa close-before-emit.
- Deck-djuplänk ⇒ Permission denied för handläggare (se §2.7).

**Tillgänglighet & form**
- Frist-chip enbart färg+text: lägg aria-live på zonbyte till "het", och
  sortera Mina ärenden på frist (närmast först) som default.
- Kortets flikar behöver tab-ordning/roving tabindex; varsel-raderna behöver
  fokusmål när "Gå till ärendet" landar.
- Smala fönster: pill-/flikrader ska wrappa (idag ok) men modulraden + flikraden
  tillsammans blir hög — överväg ikon-läge < 900 px.

**Konsistens & språk**
- Tre chattbegrepp (Diskussion-flik, Enhetschatt-fot, Team-chattar) — samla:
  "Rum" på kortet (ärendets), "Enhetschatt" (enhetens), Teams-sidan (samlingen).
- "Ärenderum"-fliken vs "Ärenderum"-pillret betyder olika saker idag (innehåll
  vs Files-länk) — förslaget separerar (flik "Akten", modul "Akten").

## 4. Prioriterad plan

**P1 — frontend-only, inga nya beroenden (dagar):**
kollapsade tomma paneler; flikar alltid synliga; klickbara filer;
referens-rader som meddelandelänkar; modulrad omordnad (Team först) +
åtgärder till menyn; varsel-lista med anchor-länk; riktigt namn (Anna bort);
NcActions-close-fix; dölj döda räknare/nolltexter.

**P2 — motor-/sdkmc-läsytor via pekare (dagar–vecka per yta):**
medlemsbaserad Mina ärenden (member-join i summary); `pekare.talkRooms[]` →
Rum-fliken; case-messages-endpoint (sdkmc, tagg-sökning) → Meddelanden-fliken;
möten per ärende (kalender-kategori + X-HUBS-DNR) + gap17; Deck-kortets innehåll
som läs-projektion i Bevakningar-fliken; medlemspanel + Ta ärendet;
Historik & beslut-tidslinje (första version ur kvitton+steg — koppla till
BESLUT-19/GAP-056-journalen); NC-notifikationer för frist/inflöde/tilldelning.

**P3 — produktbeslut (fixa EJ ensidigt):**
Deck-ACL-modellen (board-per-ärende vs läs-projektion permanent);
beslutsloggens juridiska form (vad Hubs får visa/lagra — NEVER-SoR-spegel);
roll-styrning av fördelningsläget; "Enhetens ärenden"-flik (vem får se);
signeringsmodulens yta före Inera.

## 5. Måttstock

Vyn är klar när handläggaren kan: se allt hen är ansluten till (utan att veta
vad "het zon" är), förstå på 5 sekunder vad som brinner och klicka sig till
åtgärden, och utföra HELA flödet (ta emot → utreda → besluta → följa upp →
avsluta) utan att lämna sitt ärendekort annat än via medvetna modulhopp
(Team/Akten/Meddelanden/Kalender/Signering) som alltid leder tillbaka.
