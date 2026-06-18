<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HUBS-START BACKEND-ADDITION (demo-data) · Target: ej deploybar kod — seed-fixturer för dev15.
-->

# Favorit-kontakter — SYNTETISK DEMO-DATA (CardDAV-seed)

> **⚠️ ALLT HÄR ÄR PÅHITTAT / SYNTETISK DEMO-DATA.** Ingen rad motsvarar en verklig
> person, adress eller ett verkligt faxnummer. SDK-adresserna (`*@sdk`),
> `X-HUBS-SDK-REF`-org-nycklarna och faxnumren är **fiktiva men i rätt format**.
> Märkning per fil: `PRODID:-//ITSL//Hubs Start SYNTHETIC DEMO//SV` + `NOTE:SYNTETISK DEMO-DATA`.

Dessa `.vcf` är **funktionsadresser** (skolor, BUP, polis, socialjour, region, KFM,
Försäkringskassan) — **aldrig medborgar-PII**. De seedar den "Favoriter"-adressbok
som `FavoriterService` (sdkmc backend-addition) läser och resolvar till
`FavoritValjare`-vyn. Modellen och klasserna är låsta i
[`hubs_start/docs/KONTAKTER-FAVORITER.md`](../../../docs/KONTAKTER-FAVORITER.md) §2.2.

**Bärande princip (§2.1):** en favorit är en **PEKARE, inte en kopia**. vCard:et bär
nyckeln (`X-HUBS-SDK-REF` / `X-HUBS-USER-REF`) + icke-auktoritativ visningscache
(`FN`/`ORG`); den auktoritativa adressen/certet resolvas **färskt** ur DIGG vid
läsning och kopieras aldrig in. Klass (b) är enda undantaget där Hubs äger ett värde
(funktionsfaxet) — och kräver då en förvaltande funktions-`X-HUBS-OWNER`, aldrig en individ.

## Innehåll (11 vCard, vCard 3.0)

| Fil | Klass (`X-HUBS-FAVORIT-KLASS`) | Funktion | Adress / kanal |
|---|---|---|---|
| `fav-a-bup-malmo.vcf` | `sdk-pekare` (a) | Mottagningen BUP Malmö | `bup-malmo@sdk` |
| `fav-a-polis-syd.vcf` | `sdk-pekare` (a) | Polisen, ungdomssektionen Region Syd | `ungdom-syd@sdk` |
| `fav-a-socialjour-malmo.vcf` | `sdk-pekare` (a) | Socialjouren Malmö | `socialjour-malmo@sdk` |
| `fav-a-forsakringskassan.vcf` | `sdk-pekare` (a) | Försäkringskassan, samverkan kommun | `samverkan-kommun@sdk` |
| `fav-a-region-vuxenpsyk.vcf` | `sdk-pekare` (a) | Vuxenpsykiatrin, mottagning (prov-case inf-9) | `vuxenpsyk-mottagning@sdk` |
| `fav-b-lindangsskolan-fax.vcf` | `extern-funktion` (b) | Lindängsskolan, expedition | **fax** `+46 40 123 45 60` |
| `fav-b-kronofogden-fax.vcf` | `extern-funktion` (b) | Kronofogdemyndigheten, handläggning | **fax** `+46 771 73 73 01` |
| `fav-b-region-skane-fax.vcf` | `extern-funktion` (b) | Region Skåne, vårdcentral Rosengård (remisser) | **fax** `+46 40 623 99 00` |
| `fav-c-gruppledare-eva.vcf` | `intern-anvandare` (c) | Eva (gruppledare) | uid `eva` |
| `fav-c-funktionsteam-barn-familj.vcf` | `intern-anvandare` (c) | Barn & familj-enheten (team) | uid `barn-familj` |
| `fav-a-tombstone-gammal-mottagning.vcf` | `sdk-pekare` (a) | **Tombstone-testfixtur** (saknas i DIGG → `removed:true`) | `gammal-mottagning@sdk` |

- **Klass (a) sdk-pekare** — ren DIGG-pekare. Bär `X-HUBS-SDK-REF` + `EMAIL` (`*@sdk`-funktionsadress);
  inga kopierade auktoritativa fält. Adress/cert/LOA resolvas färskt.
- **Klass (b) extern-funktion** — myndighet/funktion **utan SDK**. Egen vCard med `TEL;TYPE=fax`
  (Hubs äger värdet) + obligatorisk `X-HUBS-OWNER:funktion:<korg>@` (förvaltande funktion, ej individ).
- **Klass (c) intern-anvandare** — pekare (`X-HUBS-USER-REF` = uid) till intern användar-/teamkatalog.
- **Tombstone** — avsiktligt en pekare vars `orgId` saknas i DIGG-spegeln, för att verifiera att
  resolvern returnerar `removed:true` och gör raden icke-väljbar i komponering (§2.3).

> **Skuggregister-guardrail (§4.2):** ingen av dessa poster innehåller medborgar-PII. Medborgares/klienters
> kontaktuppgifter hör hemma i ärendet/Treserva under `hubsCaseId`, **aldrig** i en fri favoritlista —
> `FavoriterService`/GallringsGrind blockerar sådan inmatning server-side. PII (giltiga men fiktiva
> personnummer i rätt format) genereras **enbart** för inflöde-feeden, inte här.

## CardDAV-seed via admin

Seedas med en admin-app-token genom rena CardDAV-PUT:ar — samma mönster som resten av
`hubs_start` (memory: *CardDAV-seed*). Inga `occ`-anrop, ingen Kontakter-fork; det är
vanlig SabreDAV som bevarar `X-HUBS-*`, `CATEGORIES` och `TEL;TYPE=fax` (servern
normaliserar vCard 4.0→3.0 — filerna här är redan 3.0 för att undvika omskrivning).

### 1. Förutsättningar (engångs)

```bash
# App-lösenord/-token för admin (genereras i Inställningar → Säkerhet, eller via occ).
# Läggs i en .seed-token-fil bredvid skriptet (git-ignorerad, se hubs_start/.gitignore).
NC_BASE="http://localhost:8080"          # dev15: https://<host>
NC_USER="admin"
NC_TOKEN="$(cat .seed-token)"
BOOK="favoriter"                          # favorit-adressbokens uri (display name innehåller 'Favoriter')

# Skapa favorit-adressboken om den saknas (MKCOL mot CardDAV-collection).
curl -sS -u "$NC_USER:$NC_TOKEN" -X MKCOL \
  "$NC_BASE/remote.php/dav/addressbooks/users/$NC_USER/$BOOK/"
```

### 2. Seed varje vCard (PUT, ett anrop per fil)

`<uid>` = filens `UID`. Mönstret är **PUT** mot
`/remote.php/dav/addressbooks/users/<user>/<book>/<uid>.vcf`:

```bash
for f in fav-*.vcf; do
  uid="$(grep -m1 '^UID:' "$f" | cut -d: -f2 | tr -d '\r')"
  curl -sS -u "$NC_USER:$NC_TOKEN" \
    -X PUT \
    -H "Content-Type: text/vcard; charset=utf-8" \
    --data-binary "@$f" \
    "$NC_BASE/remote.php/dav/addressbooks/users/$NC_USER/$BOOK/$uid.vcf"
done
```

Enskild post (exempel BUP Malmö):

```bash
curl -sS -u "$NC_USER:$NC_TOKEN" -X PUT \
  -H "Content-Type: text/vcard; charset=utf-8" \
  --data-binary "@fav-a-bup-malmo.vcf" \
  "$NC_BASE/remote.php/dav/addressbooks/users/$NC_USER/$BOOK/hubs-demo-fav-a-bup-malmo.vcf"
```

### 3. Funktions-delad lista (klass b/c gemensamma)

För den funktions-delade favorit-adressboken (§2.4) seedas mot funktions-/team-ägarens
användare (eller en `sdk-katalog`-systemanvändare), och delas read-only till teamet via
Kontakter-delning. Samma PUT-mönster, annan `<user>`/`<book>`:

```bash
curl -sS -u "$NC_USER:$NC_TOKEN" -X PUT \
  -H "Content-Type: text/vcard; charset=utf-8" \
  --data-binary "@fav-b-lindangsskolan-fax.vcf" \
  "$NC_BASE/remote.php/dav/addressbooks/users/$NC_USER/$BOOK/hubs-demo-fav-b-lindangsskolan-fax.vcf"
```

### 4. Verifiera

```bash
# Lista posterna i adressboken (PROPFIND, depth 1).
curl -sS -u "$NC_USER:$NC_TOKEN" -X PROPFIND -H "Depth: 1" \
  "$NC_BASE/remote.php/dav/addressbooks/users/$NC_USER/$BOOK/" | grep -o 'fav-[a-z0-9-]*\.vcf'

# Hämta en post och bekräfta att X-HUBS-* + CATEGORIES + TEL;TYPE=fax bevarats.
curl -sS -u "$NC_USER:$NC_TOKEN" \
  "$NC_BASE/remote.php/dav/addressbooks/users/$NC_USER/$BOOK/hubs-demo-fav-b-lindangsskolan-fax.vcf"
```

`FavoriterService` (sdkmc) läser sedan favorit-adressboken över `OCP\Contacts\IManager`
(ett anrop, ingen klient-fan-out) och resolvar pekarna → `FavoritValjare`. Config-gate
samma mönster som inflöde-demon (`hubs_start_inflode_demo`): i live-läge med tom källa
returneras en ärligt tom lista, aldrig en fabricerad favorit.

## Rensa (avseeda)

```bash
for uid in \
  hubs-demo-fav-a-bup-malmo hubs-demo-fav-a-polis-syd hubs-demo-fav-a-socialjour-malmo \
  hubs-demo-fav-a-forsakringskassan hubs-demo-fav-a-region-vuxenpsyk \
  hubs-demo-fav-b-lindangsskolan-fax hubs-demo-fav-b-kronofogden-fax hubs-demo-fav-b-region-skane-fax \
  hubs-demo-fav-c-gruppledare-eva hubs-demo-fav-c-funktionsteam-barn-familj \
  hubs-demo-fav-a-tombstone-gammal-mottagning; do
  curl -sS -u "$NC_USER:$NC_TOKEN" -X DELETE \
    "$NC_BASE/remote.php/dav/addressbooks/users/$NC_USER/$BOOK/$uid.vcf"
done
```
