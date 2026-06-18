export const meta = {
	name: 'hubs-arkitektur-socialtjanst',
	description: 'Answer the big Hubs-usage questions (multi-korg, chat, assignment, cross-app ärende-tagging, unassigned messages) + UI evolution spec',
	phases: [
		{ title: 'Design' },
		{ title: 'Synthesize' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const HS = ROOT + '\\hubs_start'
const EXT = ROOT + '\\analysis-output\\extended'

const GROUNDING = `
PRODUKT: Hubs (ITSL) — Nextcloud-baserad säker myndighetskommunikation för svensk socialtjänst. PERSONA i centrum: socialsekreterare (barn & familj). Hubs är MELLANLAGRING; facksystemet (Treserva/Lifecare via Frends) är system of record. ANTAGANDE: alla blockers lösta (Treserva-commit via Frends, Inera-signering, lokal transkribering, Retention-paus).
DEN BEFINTLIGA VYN: en ärende-centrisk "Mina ärenden"-vy (se ${HS}\\docs\\UX-REDESIGN-SOCIALSEKRETERARE.md) med ärendekort + ProcessStepper + "Nästa åtgärd" + en triage-zon "Att ta emot" som idag mest visar orosanmälningar.
HUBS-KORGAR/KANALER (verkliga, ur sdkmc-modellen): personlig brevlåda (internpost + säker e-post), GRUPPKORGAR/funktionsadresser (internpost + säker e-post, t.ex. mottagningen@, barn-familj@), digital FAX, SDK-meddelanden (org-till-org), SMS. En socialsekreterare har tillgång till FLERA korgar samtidigt och de innehåller MÅNGA informationstyper (orosanmälan, komplettering, fråga från medborgare, remiss, internpost från kollega, fax från vårdcentral, SDK från annan myndighet …).
APPAR att resonera kring (riktiga NC-appar): sdkmc (kanaler/korgar/funktionsadress + taggar), mail/securemail (säker e-post), Talk/spreed (CHATT som Teams + video), Circles/Teams (grupper/team), Deck (uppgifter/kanban), Tables (register), Files/Groupfolders (ärenderum per dnr), Calendar, Flow/workflow_engine (regler/automation), Activity, Forms, libresign.
GRUNDA i: ${HS}\\docs\\SOCIALSEKRETERARE-WALKTHROUGH.md, ${HS}\\docs\\GAP-ANALYSIS.md, ${HS}\\docs\\WIDGET-APP-MAP.md, ${EXT}\\native-apps-map.md, ${EXT}\\arendehantering-map.md, ${EXT}\\persona-usage-socialsekreterare.md, ${EXT}\\middleware-architecture.md, ${HS}\\src\\services\\widgetApps.js. Använd WebSearch för svensk socialtjänst-process (mottagningsgrupp/utredningsgrupp, gruppledare/1:e socialsekreterare, fördelning), Talk-chatt, Circles/Teams, och Nextcloud Flow/workflow_engine-kapabiliteter (vad regler kan göra, taggar, fil-/mejlroutning). Ladda WebSearch via ToolSearch om deferred.
VARUMÄRKESREGEL: i produkt-/UI-text aldrig "Nextcloud"/"Talk"; här får app-id namnges.
SKRIVKRAV: konkret, svenskt, byggbart. Avsluta varje fil med "## Implementering" (vilka appar, vad i Flow, vad programmatiskt) och "## UI i socialsekreterarvyn" (hur det syns/funkar i Mina ärenden).`

function design(label, file, topic) {
	return () => agent(`${GROUNDING}\n\nDIN FRÅGA: ${topic}\n\nSkriv ett genomarbetat svar till ${file}. After writing, reply ONE line: file + kärninsikten.`, { label, phase: 'Design' })
}

phase('Design')
const designResults = await parallel([
	design('q1-multikorg', EXT + '\\ark-1-multikorg-triage.md',
		'MULTI-KORG & INFORMATIONSSORTERING: En socialsekreterare har flera korgar samtidigt — personlig (internpost + säker e-post), gruppkorgar/funktionsadresser (internpost + säker e-post), digital fax, SDK. Hur ska triagen ("Att ta emot"/"Att hantera") spänna över ALLA korgar och sortera inflödet meningsfullt? Definiera: korg-modellen (typer, behörighet/OSL), informationstyper (orosanmälan, komplettering till befintligt ärende, fråga/medborgarsvar, remiss, internpost, fax, SDK-myndighet, skräp/fel), en sorterings-/klassningsmodell (per korg, per typ, per ärendekoppling: hör-till-ärende vs ej-kopplat vs nytt-ärende), korg-väljare/filter, och hur en hög volym hanteras utan att drunkna (batch per typ/korg, prioritet, frist). Hur mappar detta på sdkmc-kanalklassning + funktionsadresser. Konkret: hur "Att ta emot" och en ny "Att hantera (mina korgar)" ser ut.'),
	design('q2-chatt', EXT + '\\ark-2-chatt-circles.md',
		'CHATT (Teams-likt) I LÖPANDE ARBETE: Hubs stora fördel är säker chatt som Teams (Talk/spreed) + grupper (Circles/Teams). Hur premierar/lyfter vi chattarbetet naturligt i socialsekreterarens vardag? Designa: (a) ÄRENDE-CHATT — en säker chattråd knuten till ett ärende (kollegor, gruppledare, ev. samverkan) som syns i ärendekortet; (b) TEAM-/ENHETS-CHATT via Circles/Teams (mottagningsgruppen, barn-familj-enheten); (c) 1:1 + omnämnanden + närvaro. Hur surfas chatt i dashboarden (en chatt-zon? omnämnanden i Dagspulsen? per-ärende-flik?) utan att bli "ännu en inkorg". Sekretess: är ärende-chatt allmän handling? hur committas relevanta beslut ur chatten till Treserva? Konkret: var och hur chatten syns och startas i Mina ärenden.'),
	design('q3-tilldelning', EXT + '\\ark-3-mottagning-tilldelning.md',
		'MOTTAGNING → TILLDELNING (chef delar ut): I socialtjänsten hanterar ofta en MOTTAGNINGSGRUPP inflödet och förhandsbedömningen; när beslut tas att inleda utredning FÖRDELAR en gruppledare/1:e socialsekreterare (chef) ärendet till en handläggare. Designa: rollerna (mottagning, gruppledare/chef, handläggare/utredare), var i flödet tilldelning sker (mottagning äger steg 1-8, chef tilldelar vid/efter beslut inleda, steg 9→handläggare), och HUR tilldelningen görs i Hubs (funktionsadress-grupp → "Otilldelat i mottagningen" → chef öppnar fördelningsvy → tilldelar handläggare → ärendet dyker upp i handläggarens "Mina ärenden"). Hur tilldelning bär ärende-identiteten (sätter assignee-tagg, skapar ärenderum/Deck-kort, ACL). Vad chefen ser (en tilldelnings-/fördelningsvy — egen lättviktig persona-yta eller läge). Konkret: tilldelnings-UI + hur det syns för både chef och handläggare.'),
	design('q4-ej-kopplat', EXT + '\\ark-4-ej-kopplade-meddelanden.md',
		'MEDDELANDEN UTAN ÄRENDE: Hur hanteras inkommande som INTE (ännu) hör till ett ärende? Definiera fallen: (a) hör till ett BEFINTLIGT ärende men ej kopplat än, (b) ska bli ett NYTT ärende, (c) ska ALDRIG bli ärende (allmän fråga, info, fel mottagare, skräp), (d) besvaras utan ärende. Designa: en "Ej ärendekopplat"-hink/vy, åtgärderna (Koppla till befintligt ärende [sök dnr/barn], Skapa nytt ärende, Besvara utan ärende, Vidarebefordra/fel mottagare, Gallra/arkivera utan ärende), och de juridiska gränserna (när är något allmän handling som ändå måste diarieföras även utan "ärende"? OSL 5:1 registreringsplikt; vad får gallras). Hur Hubs föreslår koppling automatiskt (avsändare, dnr i ämnet, tidigare trådar). Konkret: hur ej-kopplat syns och hanteras i vyn.'),
	design('q5-arende-identitet', EXT + '\\ark-5-arende-identitet-taggning.md',
		'ÄRENDE-IDENTITET SOM RÖD TRÅD ÖVER HELA HUBS (taggning, automatiskt): Detta är kärnfrågan. Hur kopplas ETT ärende ihop tvärs ALLA Hubs-appar så att meddelanden, filer, möten, uppgifter, chatt och beslut hänger ihop under samma ärende-identitet? Besvara konkret: (1) ÄRENDE-IDENTITETEN — en kanonisk Hubs-ärende-id/dnr-token; var den bor (Tables-register? sdkmc?), hur den mappar mot Treserva-dnr. (2) PER APP hur kopplingen realiseras: sdkmc/mail-meddelanden (taggar — itsl-tag-API:t finns), Files/Groupfolders (ärenderum-mapp per dnr), DECK (hur ärendet kopplas — board per enhet + kort per ärende taggat med dnr, ELLER board per ärende? ge ett tydligt svar; ärendekort↔Deck-kort-koppling som saknades i exemplen), Talk (chattrum per ärende), Calendar (event med dnr), Tables (ärenderegistret), libresign (signering taggad). (3) AUTOMATIK: vad ska Nextcloud FLOW (workflow_engine) göra (regler: auto-tagga inkommande med ärende-id när avsändare/dnr matchar, auto-skapa ärenderum + Deck-kort + chattrum vid tilldelning, routa fil till rätt mapp, sätt retention-tagg), och vad måste göras PROGRAMMATISKT i sdkmc/en orkestreringstjänst (det Flow inte klarar). (4) Hur "öppna ärende" i dashboarden samlar allt (en ärendevy som aggregerar alla appars objekt via ärende-taggen). Var tydlig: vilka appar, vad i Flow, vad programmatiskt, och Deck-kopplingen specifikt.'),
])

phase('Synthesize')
const synthesis = await agent(
	`Du är lead-arkitekt. Läs ALLA filer i ${EXT} som börjar med ark-* plus ${HS}\\docs\\UX-REDESIGN-SOCIALSEKRETERARE.md och ${HS}\\docs\\SOCIALSEKRETERARE-WALKTHROUGH.md. Skriv TVÅ dokument:

1) ${HS}\\docs\\HUBS-ARKITEKTUR-SOCIALTJANST.md — det sammanhållna svaret på de sex frågorna: multi-korg & sortering; chatt (Talk) + Circles/Teams; mottagning→tilldelning (chef); ej-ärendekopplade meddelanden; OCH den bärande modellen: ÄRENDE-IDENTITET SOM RÖD TRÅD — den kanoniska ärende-taggen, hur den propageras automatiskt över sdkmc/mail/Files/Deck/Talk/Calendar/Tables/libresign, exakt vad som görs i FLOW (workflow_engine-regler) vs PROGRAMMATISKT (sdkmc-orkestrering), och Deck-kopplingen specifikt. Inkludera en tabell "App → hur ärendet kopplas → Flow eller programmatiskt" och ett sekvensdiagram i text för "inkommande meddelande → klassas → kopplas/triageras → (tilldelas av chef) → ärende-tagg propageras till alla appar".

2) ${HS}\\docs\\UI-EVOLUTION-SOCIALSEKRETERARE.md — en byggbar spec för hur socialsekreterarvyn (Mina ärenden) evolveras för att stödja allt detta, med socialsekreteraren i centrum: (a) en KORG-spännande triage/"Att hantera" med korg-väljare + typ-sortering + ärendekopplings-status; (b) en CHATT-yta (ärende-chatt i kortets flik + team/omnämnanden i en lättviktig zon); (c) tilldelnings-stöd (hur en tilldelad/otilldelad-status och "från mottagningen"-ursprung syns; ev. ett enkelt chef-läge); (d) en synlig ÄRENDE-TAGG/koppling på objekt (meddelande/fil/uppgift visar "Kopplad till Barn 2026-0142"); (e) "Ej ärendekopplat"-hink med åtgärder. Specificera komponenter (PascalCase Vue), props/events, demodata-shapes, och hur det läggs in i den befintliga zon-layouten utan att öka kognitiv last. Håll varumärkesregeln.

Returnera en 8-10-meningars executive summary: kärnmodellen för ärende-identitet/taggning (Flow vs programmatiskt + Deck-kopplingen), hur multi-korg-triagen löses, hur chatt premieras, hur mottagning→tilldelning fungerar, hur ej-kopplade meddelanden hanteras, och de 4-6 UI-komponenter som ska byggas.`,
	{ label: 'synthesize-arkitektur', phase: 'Synthesize' },
)

return { design: designResults, synthesis }
