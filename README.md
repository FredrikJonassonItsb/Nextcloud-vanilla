# ITSL Open Stack — kartläggning och byggplan

ITSL:s anpassning av Nate B. Jones "Open Stack" (Open Skills + Open Brain + Open Engine),
byggd på vår egen Nextcloud/Hubs-plattform i stället för Linear/Slack/Supabase.

**Mål:** fyra personliga agenter — Rebecca→**Reb**, Fredrik→**Atlas**, Sandra→**Ada**,
Mattias→**Marvin** — som arbetar i en delad kö på Deck, minns via varsin Open Brain
(Postgres + pgvector, self-hosted i Docker) och tränas över tid via auto-capture och
skill-extraktion. Allt flyttbart till skarp miljö med en compose-stack.

## Dokument

| Dokument | Innehåll |
| --- | --- |
| [docs/KARTLAGGNING.md](docs/KARTLAGGNING.md) | Komplett kartläggning av Nates system: filosofin, alla 40 skills + 10 runbooks, OB1:s datamodell och agent-memory, hela Open Engine-protokollet med kvittovokabulär, underhålls-/träningsloopen |
| [docs/BYGGPLAN.md](docs/BYGGPLAN.md) | **Den beslutade byggplanen**: arkitektur (compose-stack + `agent_engine`-app), Deck-mappningen, Talk-capture, runner, skills v1, säkerhet, milstolpar M0–M12 med smoke-grindar, öppna frågor till M0-kickoffen |
| [docs/INTERAKTIONSDESIGN.md](docs/INTERAKTIONSDESIGN.md) | **Människa↔agent-interaktionen**: Nates teamsamspelsmodell i detalj (inkl. 12 gap vi själva beslutat), intake-mekaniken "tilldela agenten → kortet tas över", tvåvägssynken, de 7 scenarierna genomspelade, deltan mot byggplanen (ny M4.5/M7.5), öppna frågor Ö11–Ö21 |
| [docs/arkitektur/](docs/arkitektur/) | De tre oberoende arkitektförslagen (A fidelity / B nextcloud-native / C ops-simple) som byggplanen syntetiserades ur |
| [docs/research/public-research.md](docs/research/public-research.md) | Publika källor om Nates system, URL-belagda |
| [docs/research/digests/](docs/research/digests/) | Tekniska digests av allt källmaterial (unlock-ai-sajten + OB1-repot + Deck/Talk-API-verifiering) |

## Källor

- Nates medlemssidor: <https://unlock-ai.natebjones.com> (open-skills, open-engine, open-stack field guide)
- Open Brain-repot: <https://github.com/NateBJones-Projects/OB1> — licens FSL-1.1-MIT (fritt för internt bruk; blir MIT efter 2 år)
- Deck-API-fakta verifierade juli 2026, se `docs/research/digests/deck-capabilities.md`

## Nästa steg

M0-kickoff enligt [BYGGPLAN §9](docs/BYGGPLAN.md): lås namnbeslut, fastställ routingkartans
ansvarsområden, besvara de öppna frågorna (§10), Fredrik skapar nycklarna (§5.3).
Bygget sker mot dev15; prod-cutover är M10.
