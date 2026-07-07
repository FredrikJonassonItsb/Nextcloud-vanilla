# Skript — bygga och rulla ut mallbiblioteket

Tre skript, i ordning. **Maskinrummet** = Filer + mallfunktionen (de riktiga .docx).
**Skyltfönstret** = Collectives-handboken (valfritt).

## 1. `build-docx.sh` — Markdown → .docx

Genererar de riktiga mallarna ur Markdown-källan till `Mallar/<profession>/NN Titel.docx`.

```bash
./build-docx.sh                 # kräver pandoc i PATH
./build-docx.sh --pandoc /sökväg/pandoc.exe
./build-docx.sh --dry-run
```

Kräver **pandoc** (https://pandoc.org). Portabelt utan systeminstallation: ladda ned
`pandoc-*-windows-x86_64.zip`, packa upp, kör med `--pandoc …\pandoc.exe`. Skriptet
tar bort YAML-frontmatter och GitHub-callout-markörer (`[!NOTE]`) och behåller rubriker,
fält, kryssrutor och tabeller.

## 2. `setup-template-folder.sh` — MASKINRUMMET (mallmappen, live)

Gör `Mallar/` tillgänglig via mallfunktionen med en **delad mapp som mallmapp** (live).

```bash
./setup-template-folder.sh --dry-run
./setup-template-folder.sh --container nextcloud-app --group mallar-anvandare --users "anna eva"
./setup-template-folder.sh --ssh dev15 --container hubs-php --all
```

Steg: (1) laddar upp `Mallar/` till ägaren (default `admin`) + `files:scan`, (2) skapar
gruppen, (3) delar `Mallar` **skrivskyddat** med gruppen (OCS Sharing-API), (4) pekar varje
användares mallmapp till `/Mallar` (`occ user:setting UID core templateDirectory /Mallar`).
Mallväljaren läser mappen **live och rekursivt** — uppdatera en .docx så ser alla ändringen.

Flaggor: `--owner`, `--group`, `--users "a b"`, `--all`, `--container`, `--ssh`, `--url`,
`--password` (eller env `NC_ADMIN_PASS`), `--dry-run`.

## 3. `publish-handbook.sh` — SKYLTFÖNSTRET (Collectives, valfritt)

Publicerar Markdown-handboken som Collective:t "Kunskapsbank och mallar" (översikt +
beskrivning per mall). Lagrar **inte** Office-mallarna — det är skyltfönstret.

```bash
./publish-handbook.sh --dry-run
./publish-handbook.sh --tree-only          # bygg bara build/collective-tree/
./publish-handbook.sh --container nextcloud-app --user admin
```

## Nextclouds mallmekanism (verifierat i koden)

- Mallväljaren listar de filer som ligger i användarens **mallmapp** (`core`/
  `templateDirectory`, per användare) och söker **rekursivt** (`searchByMime`) — undermappar
  fungerar.
- `templatedirectory` (system) är en **källa som kopieras** till användaren vid init
  (som skeleton) — bra för onboarding, dåligt för ett bibliotek som ändras. Därför använder
  vi en **delad mapp** som mallmapp (live).
- En mallmapp i en **Group folder/Teammapp** triggar inte förslagen tillförlitligt → vanlig
  delning används.
- Icke-destruktivt; `--dry-run` överallt. Containernamn/datakatalog kan skilja
  (`--container`, `--ssh`).
