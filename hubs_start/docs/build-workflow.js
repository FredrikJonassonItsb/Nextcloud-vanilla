export const meta = {
	name: 'hubs-start-build',
	description: 'Implement the Hubs Start app leaves (Vue components + sdkmc backend) against fixed contracts, then verify',
	phases: [
		{ title: 'Frontend' },
		{ title: 'Backend' },
		{ title: 'Config' },
		{ title: 'Verify' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const HS = ROOT + '\\hubs_start'

const CONTRACT_FILES = [
	HS + '\\docs\\CONTRACTS.md',
	HS + '\\src\\services\\api.js',
	HS + '\\src\\services\\channels.js',
	HS + '\\src\\services\\sections.js',
	HS + '\\src\\services\\deepLinks.js',
	HS + '\\src\\store\\index.js',
	HS + '\\src\\views\\Start.vue',
]

const FRONT_RULES = `
HARD RULES (non-negotiable):
- Vue 2.7 + @nextcloud/vue v8. Import per-component builds, e.g. import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'.
- Every visible string wrapped in t('hubs_start', '...') (import { translate as t } from '@nextcloud/l10n', or use this.t in templates). Source language is Swedish.
- BRAND RULE: never render the words "Nextcloud" or "Talk" in any user-visible string. Use Hubs terms (Säkert möte, Video & Chatt, SDK-Meddelande, Internpost, Säker E-post, funktionsbrevlåda, kvittens, tillitsnivå/LOA, grön/lila bock).
- Channels arrive already classified from the server: render via channelMeta() from services/channels.js. NEVER re-derive a channel from an address suffix on the client.
- WCAG 2.2 AA: interactive targets >=24x24px, focus never hidden, full keyboard path, no drag-only interactions.
- Read data from props exactly as wired in Start.vue; emit exactly the events named in CONTRACTS.md. Do not invent prop/event names.
- Scoped <style lang="scss">. Use the .hs-card shell and tokens from css/variables.scss where relevant.
- Components are options API: export default { name, components, props, data, computed, methods }.
- Do NOT run npm/webpack/builds. Do NOT edit any file other than your target file(s).
After writing, reply with ONE line: target file(s) + any place you had to deviate from CONTRACTS.md.`

const BACK_RULES = `
HARD RULES (non-negotiable):
- These are NEW files for the existing sdkmc app (namespace OCA\\SdkMc). PHP 8.1, declare(strict_types=1), AGPL header like other sdkmc files.
- Study the REAL sdkmc code for correct signatures BEFORE writing:
  ${ROOT}\\hubs-code\\sdkmc\\sdkmc-main\\lib  (Db mappers: ItslMailboxMapper, AccountItslMailboxMapper, ItslTagMapper, MessageReceiptMapper, MessageThreadMapper, ConversationBankIDAuthMapper; Controllers; appinfo/routes.php; AppInfo/Application.php).
- For IAPIWidgetV2 dashboard widgets follow EXACTLY the pattern in ${ROOT}\\hubs-code\\spreed-itsl\\spreed-itsl-main\\lib\\Dashboard\\TalkWidget.php (implements IAPIWidget, IIconWidget, IButtonWidget, IConditionalWidget, IReloadableWidget; WidgetItem/WidgetItems/WidgetButton from OCP\\Dashboard\\Model).
- The channel classifier already exists: ${HS}\\backend-additions\\sdkmc\\lib\\Service\\ChannelClassificationService.php (OCA\\SdkMc\\Service\\ChannelClassificationService). Reuse it, do not duplicate suffix logic.
- OCS controllers extend OCP\\AppFramework\\OCSController, namespace OCA\\SdkMc\\Controller\\OCS, return OCP\\AppFramework\\Http\\DataResponse, methods annotated #[NoAdminRequired]. Return the EXACT JSON shapes documented in CONTRACTS.md / api.js typedefs.
- Constructor DI only (no Server::get). If a dependency from Mail/Talk/DAV may be absent, guard with interfaces/class_exists where appropriate.
- Where real cross-app data wiring is uncertain, implement the method end-to-end with the documented shape and mark genuine integration gaps with a // TODO(hubs-start): note — but the returned shape MUST always match the contract so the frontend renders.
- Do NOT run anything. Do NOT edit existing files; only create your target file(s).
After writing, reply with ONE line: target file(s) + any TODO/integration gap left.`

function frontAgent(label, files, spec) {
	return () => agent(
		`Implement part of the Hubs Start app. REPO ROOT: ${ROOT}.
FIRST read these contract files IN FULL: ${CONTRACT_FILES.join(' ; ')}.
${FRONT_RULES}

YOUR TASK — create ${files.join(' AND ')} :
${spec}`,
		{ label, phase: 'Frontend' },
	)
}

function backAgent(label, files, spec, phase = 'Backend') {
	return () => agent(
		`Implement a backend part of Hubs Start (new sdkmc files). REPO ROOT: ${ROOT}.
FIRST read these contract files IN FULL: ${CONTRACT_FILES.join(' ; ')}.
${BACK_RULES}

YOUR TASK — create ${files.join(' AND ')} :
${spec}`,
		{ label, phase },
	)
}

// --- WAVE 1: implementation ------------------------------------------------

const wave1 = [
	frontAgent('HeaderBar+LoaChip', [HS + '\\src\\components\\HeaderBar.vue', HS + '\\src\\components\\LoaChip.vue'],
		'HeaderBar: props loa(String), profile(String); greeting + localised today date; contains LoaChip; static badge t("hubs_start","All data lagras i er driftmiljö"); emits @upgrade-loa bubbled from LoaChip. LoaChip: prop loa; LOA3 green chip "Inloggad med BankID — Tillitsnivå 3"; LOA2/LOA1 amber chip + NcButton "Legitimera med BankID" emitting @upgrade.'),

	frontAgent('ActionBar', [HS + '\\src\\components\\ActionBar.vue'],
		'Props apps(Object). Four primary NcButtons >=44px tall: "Nytt säkert meddelande", "Boka säkert möte", "Starta möte nu", "Sök motpart". Hide message button if !apps.mail; hide meeting buttons if !apps.spreed. Emit @new-message, @book-meeting, @start-meeting, @search-recipient. Responsive flex/grid row.'),

	frontAgent('AttHanteraQueue', [HS + '\\src\\components\\AttHanteraQueue.vue'],
		'The main triage widget. Props items(Array), counts(Object), activeChannel(String), channelCoverage(Array), keyboardMode(Boolean). Render coverage line "Bevakade kanaler:" + localised labels (channelMeta). Channel filter tabs: Alla + each CHANNEL_ORDER present in channelCoverage, each with NcCounterBubble of filtered count. Then the five fixed SECTIONS in order, each a <QueueSection :section :items> with items filtered by activeChannel and grouped by item.section. Whole-queue empty -> NcEmptyContent "Allt hanterat — inga ägarlösa ärenden". Wrap sections in aria-live="polite". NcCheckboxRadioSwitch "Tangentbordsläge" -> emit @toggle-keyboard(Boolean). When keyboardMode, support j/k focus move + a(take)+o(open)+e(done) on focused item (no traps). Re-bubble @change-channel,@take,@open,@done. Import QueueSection.'),

	frontAgent('QueueSection+QueueItem', [HS + '\\src\\components\\QueueSection.vue', HS + '\\src\\components\\QueueItem.vue'],
		'QueueSection: props section(Object one of SECTIONS), items(Array). Header sectionLabel(section.id)+count; empty -> one muted line (otilldelat: "Inga ägarlösa ärenden"). Renders QueueItem rows; re-bubble @take,@open,@done. QueueItem: prop item(QueueItem). Use NcListItem-style row: channel icon channelMeta(item.channel.channel).icon, verb-first item.title, mailbox+dnr line, status tag (statusLabel/statusTone), optional LOA badge. Actions: primary "Öppna"(@open), "Ta ärendet"(@take, only when item.assignment && section is otilldelat), "Klart"(@done, when item.doneTag). Targets >=24px. Titles are pre-anonymised — render as-is, add nothing sensitive.'),

	frontAgent('DagensMoten', [HS + '\\src\\components\\DagensMoten.vue'],
		'Props meetings(Array {token,title,start,end,participants,bankIdRequired,verificationBadge:green|purple|null,lobbyState,hasCall}). .hs-card title "Dagens säkra möten". Per meeting: time+countdown, BankID badge (green=BankID+personnummer, purple=enbart BankID), lobby line via this.n("hubs_start","%n verifierad deltagare väntar i lobbyn","%n verifierade deltagare väntar i lobbyn",count) using fetchLobbyStatus(token) for soon-starting meetings, "Kontrollera kamera & mikrofon" link, "Gå till mötet" button -> @join(meeting). Empty -> NcEmptyContent "Inga möten idag".'),

	frontAgent('KvittensWidget', [HS + '\\src\\components\\KvittensWidget.vue'],
		'Props receipts(Array {messageId,recipient,channel,state:skickat|levererat|last|besvarat|problem,updatedAt,deepLink}). .hs-card title "Skickat — kvittenser". 4-step status pill Skickat→Levererat→Läst→Besvarat; state=problem sorted first with error tone. Row click -> @open({deepLink}). Friendly empty state.'),

	frontAgent('Funktionsbrevlador+Bevakningar', [HS + '\\src\\components\\FunktionsbrevladorWidget.vue', HS + '\\src\\components\\BevakningarWidget.vue'],
		'FunktionsbrevladorWidget: props mailboxes(Array {id,name,unread,unassigned}). .hs-card title "Funktionsbrevlådor"; per mailbox name+unread+"Ej tilldelad" count, deep link deepLinks.mailboxLink("unassigned"); navigates directly (no emits). BevakningarWidget: props watching(Array {mailbox,owner,untilDate,direction}). .hs-card title "Bevakningar"; lines "Du bevakar {owner}s brevlåda t.o.m. {date}" and reverse per direction.'),

	frontAgent('BokningsbaraTider', [HS + '\\src\\components\\BokningsbaraTider.vue'],
		'Props configs(Array {id,name,token,bookingUrl,totalBookings}). .hs-card title "Bokningsbara tider"; per config name+totalBookings+"Kopiera bokningslänk" (navigator.clipboard.writeText(bookingUrl)+showSuccess from @nextcloud/dialogs). Friendly empty state.'),

	frontAgent('NyttaWidget', [HS + '\\src\\components\\NyttaWidget.vue'],
		'No props. .hs-card title "Nytta hittills". Show replaced fax/letters, volume per channel, estimated saved time using Diggs 30-min schablon. Clearly mark values as indicative until the stats endpoint lands (a small muted note). Keep layout clean; placeholder numbers are fine but label them indicative.'),

	frontAgent('SystemHalsa', [HS + '\\src\\components\\SystemHalsa.vue'],
		'No props (förvaltare-only; Start.vue gates it). .hs-card title "Systemhälsa". Cards: SDK-loggstatus (GET /apps/sdkmc/api/v2/iipax/sdkLog via @nextcloud/axios+generateUrl), adressbokssynk age, bakgrundsjobb health, notifieringsstatus (/api/v2/admin/activityNotificationStatus), komponentversioner. Gallring "Kör nu" MUST be guarded: an NcTextField where the user types a confirmation word (e.g. "RADERA") before the destructive NcButton (calls GET /apps/sdkmc/api/v2/admin/runExpungeNow) becomes enabled. Use try/catch and showError/showSuccess. Read-only widgets degrade gracefully on error.'),

	frontAgent('SmartMottagare', [HS + '\\src\\components\\SmartMottagare.vue'],
		'Modal (NcModal). Debounced search field -> api.searchRecipients(query). Render candidates grouped, each with server-resolved channel chip via channelMeta(candidate.classification.channel). Allow a free typed value -> api.classifyRecipient(value) to show resolved channel before choosing. Selecting a candidate emits @chosen(recipient) where recipient has {address, classification:{channel,messageType}}. Also @close. Full keyboard nav, focus trap inside modal, escape closes. Show the chosen channel prominently ("Systemet väljer kanal: <chip>") to teach the rule.'),

	frontAgent('MeetingWizard', [HS + '\\src\\components\\MeetingWizard.vue'],
		'Modal, 3 steps. Step A "Vem": name, personnummer (BankID binding), mobil (SMS), valfri säker e-post; optional intern kollega (NcSelect of users is fine to stub). Step B "När": time selection (NcDateTimePickerNative or text) + nearest-slot hint. Step C "Skydd": NcCheckboxRadioSwitch BankID required (default ON for external), SMS toggle, säker e-post-inbjudan toggle, each with plain-language helper text about what the citizen sees. "Boka" -> api.createSecureMeeting(payload) with SecureMeetingRequest shape from CONTRACTS.md; on success show confirmation (time, deltagare, skyddsnivå, smsStatus) and emit @booked(result). @close. Support a "start now" mode (prefill start=now) when opened for instant meeting.'),

	frontAgent('CommandPalette', [HS + '\\src\\components\\CommandPalette.vue'],
		'Ctrl+K palette (NcModal + NcTextField + filtered combobox list). Static actions "Nytt säkert meddelande","Boka säkert möte","Starta möte nu" plus live results via api.searchRecipients(query). Full keyboard: arrow up/down to move, Enter to choose, Esc to close. Choosing an action emits the matching event (@new-message/@book-meeting/@start-meeting); choosing a recipient emits @new-message. Also @close. Autofocus the field on open.'),

	frontAgent('Onboarding', [HS + '\\src\\components\\Onboarding.vue'],
		'5-step NcModal replacing firstrunwizard. Props loa(String), apps(Object), mailboxes(Array). Steps: (1) välkommen + vad Hubs Start är, (2) tillitsnivåer förklarade (LOA1/2/3, varför BankID) — if loa!="LOA3" include a gentle "Legitimera med BankID" hint, (3) dina funktionsbrevlådor (list mailboxes), (4) rundtur av de fyra primära åtgärderna, (5) "Kom igång"-checklista. Next/Back buttons, progress dots. Final step button emits @finish. Esc/skip also emits @finish.'),
]

const wave1Back = [
	backAgent('SummaryService', [HS + '\\backend-additions\\sdkmc\\lib\\Service\\SummaryService.php'],
		'OCA\\SdkMc\\Service\\SummaryService with getSummary(string $userId, ?string $sinceIds): array returning the Summary shape from CONTRACTS.md/api.js: {loa, counts{kravAtgard,otilldelat,nytt,bevakas,klartIdag,problem}, items: QueueItem[], mailboxes:[{id,name,unread,unassigned}], watching:[{mailbox,owner,untilDate,direction}], channelCoverage:[...], maxSinceId}. Compose existing mappers (ItslMailboxMapper, AccountItslMailboxMapper, ItslTagMapper, MessageReceiptMapper, MessageThreadMapper) + OutOfOffice data; provide REAL server-side counters for the virtual mailboxes (Mina meddelanden/Otilldelade). Build each QueueItem with verb-first anonymised title, section, status, channel (use ChannelClassificationService on the account address), deepLink {app:"thread",params:{itslMailboxId,mid}} and assignment{imapLabel,accountId,threadRootId} for otilldelat items. Add a private buildReceipts() used by the controller. Cache per-user with a short TTL via ICacheFactory. Mark genuine Mail-API wiring gaps with // TODO(hubs-start) but always return the full shape.'),

	backAgent('SummaryController(OCS)', [HS + '\\backend-additions\\sdkmc\\lib\\Controller\\OCS\\SummaryController.php'],
		'OCA\\SdkMc\\Controller\\OCS\\SummaryController extends OCSController. Actions: summary(?string $sinceIds=null):DataResponse -> SummaryService->getSummary(userId,sinceIds); receipts(?string $status=null,?int $limit=20):DataResponse -> list of {messageId,recipient,channel,state,updatedAt,deepLink} from MessageReceiptMapper+MessageThreadMapper (state in skickat|levererat|last|besvarat|problem — real MW state, NOT the 10-min heuristic; mark PENDING-semantics clarification as // TODO). Inject IUserSession to resolve userId. #[NoAdminRequired] on both. Route names OCS\\Summary#summary and #receipts.'),

	backAgent('RecipientController(OCS)', [HS + '\\backend-additions\\sdkmc\\lib\\Controller\\OCS\\RecipientController.php'],
		'OCA\\SdkMc\\Controller\\OCS\\RecipientController extends OCSController. search(string $query):DataResponse -> Recipient[] each {id,displayName,address,classification:{channel,channelLabel,messageType},ssn?,sms?} — query the SDK address book (reuse AddressBookController/its service or the same data source it uses), internal mailboxes (MailBoxController->internalMailboxesAB data source), and produce free-value candidates; classify each via ChannelClassificationService. classify(string $value):DataResponse -> ChannelClassificationService->classifyRecipientValue(value). #[NoAdminRequired].'),

	backAgent('SecureMeeting(Service+OCS)', [HS + '\\backend-additions\\sdkmc\\lib\\Service\\SecureMeetingService.php', HS + '\\backend-additions\\sdkmc\\lib\\Controller\\OCS\\SecureMeetingController.php'],
		'SecureMeetingService->create(string $userId, array $request): array orchestrates: create a Talk room via spreed OCS v4 (POST /ocs/v2.php/apps/spreed/api/v4/room) — use OCP\\Http\\Client\\IClientService against the local instance OR the spreed Manager if available (guard with class_exists); PUT a CalDAV event with the call URL in LOCATION (use the existing calendar/DAV approach already used by sdkmc SmsNotifyListener as reference); register BankID/SMS/securemail intents bound to the EVENT UID (reuse the Event*IntentController persistence path) — NOT PHP session; add the e-mail participant with ?resend-invitations. Return {token,eventUid,start,end,smsStatus,protection}. Mark cross-app calls that need wiring with // TODO(hubs-start). SecureMeetingController(OCS) create():DataResponse reads the JSON body params (citizen,colleagueUserId,start,end,title,dnr,requireBankId,sendSms,sendSecureEmailInvite,fromMailboxId) and delegates. #[NoAdminRequired].'),

	backAgent('MeetingController(OCS)', [HS + '\\backend-additions\\sdkmc\\lib\\Controller\\OCS\\MeetingController.php'],
		'OCA\\SdkMc\\Controller\\OCS\\MeetingController extends OCSController. today():DataResponse -> array of {token,title,start,end,participants,bankIdRequired,verificationBadge,lobbyState,hasCall} merged from CalDAV (today\'s events whose LOCATION contains /call/{token}) + spreed room state (lobbyState,hasCall) + ConversationBankIDAuthMapper for bankIdRequired/verificationBadge. lobby(string $token):DataResponse -> {waiting:[{actorId,displayName,verified}],verifiedCount} from spreed participants (guests/emails) + guest-identity verification. Guard spreed/DAV with class_exists; return empty arrays gracefully. #[NoAdminRequired]. Route names OCS\\Meeting#today and #lobby.'),

	backAgent('DashboardWidgets', [HS + '\\backend-additions\\sdkmc\\lib\\Dashboard\\AttHanteraWidget.php', HS + '\\backend-additions\\sdkmc\\lib\\Dashboard\\KvittenserWidget.php', HS + '\\backend-additions\\sdkmc\\lib\\Dashboard\\DagensMotenWidget.php'],
		'Three IAPIWidgetV2 widgets (namespace OCA\\SdkMc\\Dashboard) mirroring the dashboard data into the standard NC dashboard. Follow TalkWidget.php EXACTLY for the interface set (IAPIWidget,IIconWidget,IButtonWidget,IConditionalWidget,IReloadableWidget), getId/getTitle/getOrder/getIconClass/getIconUrl/getUrl/isEnabled/getReloadInterval(30)/getWidgetButtons/getItemsV2. AttHanteraWidget(id "hubs_att_hantera", title "Att hantera", reuse SummaryService->getSummary, map QueueItems to WidgetItem with link via IURLGenerator to /apps/sdkmc/mailbox-link, button TYPE_MORE to /apps/hubs_start/, empty msg "Allt hanterat — inga ägarlösa ärenden"). KvittenserWidget(id "hubs_kvittenser", title "Skickat — kvittenser", from SummaryService receipts). DagensMotenWidget(id "hubs_moten", title "Dagens säkra möten"). isEnabled returns true when sdkmc usable. Titles via IL10N. Brand rule applies.'),

	backAgent('MailRouterHook', [HS + '\\backend-additions\\mail\\initITSL-additions.js'],
		'A drop-in snippet for mail overlay (overlay/src/itsl/utils/initITSL.js). Read the real file at ' + ROOT + '\\hubs-code\\mail\\mail-main\\overlay\\src\\itsl\\utils\\initITSL.js and ' + ROOT + '\\hubs-code\\mail\\mail-main\\overlay\\src\\itsl\\components\\message\\NewMessageButtonHeaderItsl.vue and ComposerItsl.vue to learn how a new message with a preselected MESSAGE_TYPE is opened. Provide: (1) an exported function initComposerDeepLink() that, on app load, checks window.location for /apps/mail/new with ?type= and ?to= query, and opens the ITSL composer with that message type + recipient preselected (use the same mechanism NewMessageButtonHeaderItsl uses — e.g. emit/route/store action), handling SPA-internal navigation via router too; (2) exact integration instructions: the import line, and the single call to add inside initITSL() after initInterceptors(). Output as a clearly-commented .js file with the snippet and an integration note at the top. Mark any uncertain mechanism with // TODO(hubs-start).',
		'Backend'),
]

const wave1Config = [
	(() => agent(
		`Create build/lint/test config + docs for the Hubs Start app. REPO ROOT: ${ROOT}. App dir: ${HS}. Read ${HS}\\package.json and ${ROOT}\\hubs-code\\sdkmc\\sdkmc-main\\.eslintrc.js for conventions. Create these files (do not run anything):
- ${HS}\\.eslintrc.js : extend @nextcloud/eslint-config/vue + @nextcloud (Vue 2), env jest.
- ${HS}\\stylelint.config.js : extend @nextcloud/stylelint-config.
- ${HS}\\babel.config.js : @nextcloud/babel-config or @babel/preset-env (for jest).
- ${HS}\\jest.config.js : jsdom env, moduleFileExtensions js+vue, transform vue-jest + babel-jest, moduleNameMapper for @nextcloud/* mocks pointing to tests/mocks, testMatch tests/unit.
- ${HS}\\tests\\mocks\\nextcloud.js : minimal mocks for @nextcloud/l10n (t/n identity), @nextcloud/router (generateUrl/generateOcsUrl), @nextcloud/axios, @nextcloud/dialogs, @nextcloud/initial-state (loadState).
- ${HS}\\.gitignore : node_modules, js/, .build, vendor.
- ${HS}\\README.md : what Hubs Start is, architecture (standalone, data owner is sdkmc), how to build (make webpack / npm run build), how to set defaultapp, link to docs/CONTRACTS.md and backend-additions/MANIFEST.md.
- ${HS}\\docs\\INSTALL.md : install steps (register in setup-apps.list, apply backend-additions per MANIFEST, occ app:enable hubs_start, set defaultapp), prerequisites (sdkmc, mail, spreed, calendar), and the critical-path ordering (sdkmc OCS endpoints + mail routerhook BEFORE dashboard).
Reply with one line listing files created.`,
		{ label: 'config+docs', phase: 'Config' },
	)),
]

phase('Frontend')
const implResults = await parallel([...wave1, ...wave1Back, ...wave1Config])

// --- WAVE 2: l10n, tests, verification --------------------------------------

const REVIEW_SCHEMA = {
	type: 'object',
	additionalProperties: false,
	required: ['summary', 'findings'],
	properties: {
		summary: { type: 'string' },
		findings: {
			type: 'array',
			items: {
				type: 'object',
				additionalProperties: false,
				required: ['severity', 'file', 'issue', 'fix'],
				properties: {
					severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
					file: { type: 'string' },
					issue: { type: 'string' },
					fix: { type: 'string' },
				},
			},
		},
	},
}

phase('Verify')

const wave2 = [
	// l10n: scan all written components for t('hubs_start', ...) strings
	() => agent(
		`Generate Swedish l10n for Hubs Start. REPO ROOT: ${ROOT}. App dir: ${HS}.
Scan ALL files under ${HS}\\src for t('hubs_start', '...') and n('hubs_start', ...) calls and collect the unique source strings (they are Swedish). Create:
- ${HS}\\l10n\\sv.js : OC.L10N.register("hubs_start", { "<source>": "<source>", ... }, "nplurals=2; plural=(n != 1);")  (identity map; for plural strings provide ["singular","plural"]).
- ${HS}\\l10n\\sv.json : { "translations": { ... }, "pluralForm": "nplurals=2; plural=(n != 1);" } matching the same keys.
Also create ${HS}\\l10n\\.gitkeep is NOT needed. Be exhaustive — miss no string. Reply with the count of strings collected.`,
		{ label: 'l10n-sv', phase: 'Verify' },
	),

	// Frontend unit tests for the pure service modules + a couple components
	() => agent(
		`Write Jest unit tests for Hubs Start pure logic. REPO ROOT: ${ROOT}. App dir: ${HS}. Read ${HS}\\src\\services\\channels.js, sections.js, deepLinks.js, store\\index.js and ${HS}\\tests\\mocks\\nextcloud.js (may have just been created — if absent, create minimal mocks). Create:
- ${HS}\\tests\\unit\\channels.spec.js : channelMeta returns correct label/id for each channel + unknown fallback; CHANNEL_TO_MESSAGE_TYPE mapping; CHANNEL_ORDER contents.
- ${HS}\\tests\\unit\\sections.spec.js : SECTIONS order + ids; statusTone mapping; sectionLabel/statusLabel.
- ${HS}\\tests\\unit\\deepLinks.spec.js : threadLink/composerLink/mailboxLink/callLink/loa3UpgradeLink/resolve produce expected URL fragments (mock @nextcloud/router generateUrl as path passthrough).
- ${HS}\\tests\\unit\\store.spec.js : applySummary cold load replaces items; incremental (maxSinceId set) upserts by id; setActiveChannel; removeItem.
Use the mocks. Do NOT run jest. Reply one line with files created.`,
		{ label: 'tests', phase: 'Verify' },
	),

	// Reviewer 1: frontend contract conformance
	() => agent(
		`REVIEW the Hubs Start FRONTEND for contract conformance. REPO ROOT: ${ROOT}. Read ${HS}\\docs\\CONTRACTS.md, ${HS}\\src\\views\\Start.vue, and every file under ${HS}\\src\\components. Check: prop names & types match Start.vue wiring; emitted event names match exactly; channelMeta used (no client-side suffix re-derivation); imports resolve (@nextcloud/vue per-component paths, services paths); Vue 2 options API correctness; no obvious template/script syntax errors. Return findings (file, issue, fix, severity).`,
		{ label: 'review:frontend', phase: 'Verify', schema: REVIEW_SCHEMA },
	),

	// Reviewer 2: PHP / Nextcloud backend correctness
	() => agent(
		`REVIEW the Hubs Start BACKEND additions. REPO ROOT: ${ROOT}. Read ${HS}\\docs\\CONTRACTS.md, ${HS}\\backend-additions\\MANIFEST.md, every file under ${HS}\\backend-additions, and cross-check against real sdkmc code at ${ROOT}\\hubs-code\\sdkmc\\sdkmc-main\\lib and TalkWidget at ${ROOT}\\hubs-code\\spreed-itsl\\spreed-itsl-main\\lib\\Dashboard\\TalkWidget.php. Check: namespaces (OCA\\SdkMc...), OCSController usage + DataResponse, return shapes match api.js typedefs, IAPIWidgetV2 interface completeness, constructor DI validity, mapper method names actually exist, route-name/class alignment with MANIFEST, PHP 8.1 syntax. Return findings (file, issue, fix, severity).`,
		{ label: 'review:backend', phase: 'Verify', schema: REVIEW_SCHEMA },
	),

	// Reviewer 3: brand rule + i18n + WCAG
	() => agent(
		`REVIEW Hubs Start for the BRAND RULE, i18n and WCAG 2.2. REPO ROOT: ${ROOT}. Read every file under ${HS}\\src and ${HS}\\backend-additions. Flag: any user-visible literal containing "Nextcloud" or "Talk"; any visible string NOT wrapped in t()/n('hubs_start',...); interactive targets that could be <24px; drag-only interactions without keyboard alternative; missing aria-live on the queue; focus traps. Return findings (file, issue, fix, severity).`,
		{ label: 'review:brand+a11y', phase: 'Verify', schema: REVIEW_SCHEMA },
	),
]

const verifyResults = await parallel(wave2)

return {
	implementation: implResults,
	verification: verifyResults,
}
