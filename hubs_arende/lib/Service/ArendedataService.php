<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\Part;
use OCA\HubsArende\Db\PartMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * DATAKÄLLELAGRET för HANDLING-FRÅN-MALL (fas 1) — aggregerar fält→värde ur
 * registret + PARTSREGISTRET + användarkatalogen, med SKYDDSGRINDEN
 * (K-NAV-6.1) inbyggd. Se hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md §5.2.
 *
 * Rollen i kedjan: HandlingService binder mall↔ärendedata och skriver det
 * ifyllda dokumentet till ärenderummets groupfolder; DENNA service äger enbart
 * VARIFRÅN värdena kommer och VILKA värden som får lämna källan som default.
 * Utkastet ({@see byggUtkast()}) är därmed sanningen om både värde, källa och
 * varning per fält — frontenden visar det som förhandsgranskning, motorn
 * fyller mallen med det.
 *
 * GENERISKA FÄLT fas 1 ({@see PLATSHALLARE}): ärendereferens, enhet,
 * handläggare, barnets namn och barnets personnummer. Platshållarsträngarna
 * är de EXAKTA hakparentes-strängarna ur mallbiblioteket
 * (hubs_start/mallbibliotek/socialsekreterare-barn-familj) — verifierade mot
 * md-källorna, inklusive svenska tecken. Datum-platshållare ([ÅÅÅÅ-MM-DD]
 * m.fl., 66 förekomster) fylls MEDVETET INTE i fas 1: de bär olika betydelser
 * i olika mallar (mottagningsdatum, frist, beslutsdatum …) och en gissning
 * vore sämre än en tom ruta — ärlighet före täckning. Oersatta platshållare
 * lämnas åt handläggaren i dokumentredigeraren.
 *
 * SKYDDSGRINDEN (K-NAV-6.1, KRAVSTALLNING-NAVET-FOLKBOKFORING.md): har
 * barn-parten skydd = sekretessmarkering eller skyddad_folkbokföring UTELÄMNAS
 * namn-fältet som default (värde tomt) med en varning — personen identifieras
 * med personnummer (eSam-principen), så barnPnr fylls ALLTID. Fyller anroparen
 * ÄNDÅ ett skydds-varnat fält är det ett AKTIVT BESLUT som HandlingService
 * journalför med detalj `skyddOverride:true` (aldrig själva värdet).
 *
 * PII-DOKTRIN: personnummer/namn/adress ur partsregistret får ALDRIG nå loggar
 * (LoggerInterface) eller Händelse.detalj — här loggas enbart antal, nycklar
 * och skyddsnivå (roll/skydd är sanktionerat, identitet aldrig; jfr
 * {@see PartMapper}). Att VISA PII för en behörig handläggare är däremot
 * avsett — invarianten är behörighetsgränsen, inte PII-döljande.
 *
 * Authz: {@see byggUtkast()} går via {@see ArendeService::show()} (H1) — en
 * obehörig anropare får DoesNotExistException innan någon PII läses.
 */
class ArendedataService {
    /**
     * Fältkartan fas 1: fältnyckel → de EXAKTA platshållarsträngar (utan
     * hakparenteser vore de tvetydiga — strängarna inkluderar `[`/`]`) som
     * fältet ersätter i mallarna. Ordningen är presentationsordningen i
     * utkastet. Verifierad mot mallbibliotekets md-källor 2026-07-07.
     *
     * @var array<string, string[]>
     */
    public const PLATSHALLARE = [
        'arendeRef' => [
            '[hubsCaseId / Treserva-dnr om det finns]',
            '[hubsCaseId / Treserva-dnr]',
            '[Personakt / Treserva dnr]',
        ],
        'enhet' => [
            '[Mottagning / Barn och familj]',
            '[Barn och familj / utredningsgrupp]',
            '[Mottagning / Barn och familj / utredningsgrupp]',
        ],
        'handlaggare' => [
            '[Namn, titel]',
        ],
        'barnNamn' => [
            '[För- och efternamn]',
        ],
        'barnPnr' => [
            '[ÅÅÅÅMMDD-XXXX]',
        ],
        // --- VÅG A (ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md §5) — nya fält ur
        // partsregistret (anmälare), journalen (datum-ankare) och medlemmarna.
        // Endast GLOBALT ENTYDIGA platshållare (verifierade mot md-källorna
        // 2026-07-07: samma sträng = samma betydelse i varje mall den finns i).
        'anmalareNamn' => [
            '[Namn — eller "okänd/anonym", se handledning]',
            '[Namn/funktion — eller "anonym" om anmälaren är okänd/skyddad]',
        ],
        'anmalareKontakt' => [
            '[Telefon / e-post / adress om lämnad]',
        ],
        'inkomTidpunkt' => [
            '[ÅÅÅÅ-MM-DD klockslag]',
        ],
        'utredningInledd' => [
            '[ÅÅÅÅ-MM-DD, dagen utredning inleddes]',
        ],
        'medutredare' => [
            '[Namn, titel — utredningar görs ofta av två handläggare]',
        ],
        // --- S4: konfig-driven branding — sidhuvudets brand-slot (token bor i
        // word/header1.xml; motorn fyller header-/footer-parts sedan v0.12.0).
        'kommunNamn' => [
            '[Kommunens namn]',
        ],
    ];

    /** Etikett per fältnyckel — visningsnamnet i förhandsgranskningen. */
    private const ETIKETTER = [
        'arendeRef' => 'Ärende/dnr',
        'enhet' => 'Enhet',
        'handlaggare' => 'Handläggare',
        'barnNamn' => 'Barnets namn',
        'barnPnr' => 'Barnets personnummer',
        'anmalareNamn' => 'Anmälarens namn',
        'anmalareKontakt' => 'Anmälarens kontaktuppgifter',
        'inkomTidpunkt' => 'Anmälan inkom',
        'utredningInledd' => 'Utredning inleddes',
        'medutredare' => 'Medutredare',
        'kommunNamn' => 'Kommun',
    ];

    /** Varning när partsregistret saknar en barn-part (inget skyddsfall — bara tomt). */
    private const VARNING_INGEN_BARNPART =
        'Ingen barn-part i partsregistret — hämta via Parter-fliken eller fyll i manuellt.';

    /** SKYDDSGRINDEN: sekretessmarkering ⇒ namnet utelämnas som default. */
    private const VARNING_SEKRETESSMARKERING =
        'Sekretessmarkerad — namnet utelämnas som standard (skadeprövning krävs); '
        . 'ifyllnad är ett aktivt beslut som journalförs.';

    /** SKYDDSGRINDEN: skyddad folkbokföring ⇒ namnet utelämnas, adress ALDRIG. */
    private const VARNING_SKYDDAD_FOLKBOKFORING =
        'Skyddad folkbokföring — namnet utelämnas som standard; adress får aldrig '
        . 'anges i handlingen (Skatteverkets förmedlingstjänst).';

    public function __construct(
        private ArendeService $arendeService,
        private PartMapper $partMapper,
        private LoggerInterface $logger,
        // TRAILING OPTIONAL (autowired): resolvar ägar-uid → visningsnamn för
        // handläggar-fältet. Null (testharness/CLI) ⇒ graceful fallback till uid.
        private ?IUserManager $userManager = null,
        // TRAILING OPTIONAL (autowired): journalens datum-ankare (VÅG A) —
        // inkomTidpunkt (typ=skapad) och utredningInledd (typ=steg → utredning).
        private ?HandelseMapper $handelseMapper = null,
        // TRAILING OPTIONAL (autowired): medutredare (roll co_handlaggare).
        private ?MemberMapper $memberMapper = null,
        // TRAILING OPTIONAL (autowired): SAKUPPGIFTSLAGRET — dokumentkedjans
        // minne fyller LUCKOR (tomma fält) med tidigare bekräftade uppgifter.
        private ?SakuppgiftService $sakuppgiftService = null,
        // TRAILING OPTIONAL (autowired): S4 — kommunNamn ur app-konfig
        // (blankett_kommun) fyller sidhuvudets brand-slot.
        private ?IAppConfig $appConfig = null,
        // TRAILING OPTIONAL (autowired): S4 — malldefinitionen (Definitioner/
        // <mall>.json i mallmappen) filtrerar utkastet till mallens FAKTISKA
        // fält, så förhandsdialogen aldrig visar fält mallen saknar.
        private ?MallService $mallService = null,
    ) {
    }

    /**
     * Platshållarsträngarna för en fältnyckel — tom lista för okänd nyckel
     * (fail-soft: en okänd nyckel ersätter ingenting, den fyller aldrig fel
     * platshållare).
     *
     * @return string[]
     */
    public static function platshallareFor(string $nyckel): array {
        return self::PLATSHALLARE[$nyckel] ?? [];
    }

    /**
     * Bygg ifyllnads-UTKASTET för ett ärende: fält→värde med källa och
     * varningar, redo för förhandsgranskning och mallfyllning.
     *
     * Flöde: (1) authz-grindad register-läsning ({@see ArendeService::show()},
     * H1 — obehörig ⇒ DoesNotExistException, existens läcker inte), (2) fälten
     * byggs i fältkartans ordning ur registret + användarkatalogen +
     * PARTSREGISTRET med SKYDDSGRINDEN, (3) sammanställd retur.
     *
     * Fältvärden:
     *   - arendeRef: dnr om satt, annars "Ärende " + {@see ArendeService::kortRef()}
     *     (pseudonym kortreferens — hela UUID:t är brus för ögat).
     *   - enhet: registrets enhet som den är.
     *   - handlaggare: ägarens visningsnamn (IUserManager), fallback uid.
     *   - barnNamn/barnPnr: FÖRSTA part med roll=barn ur partsregistret
     *     (listan är nyast först ⇒ senast tillagda/berikade parten vinner).
     *     Pnr formateras ÅÅÅÅMMDD-XXXX. Saknas barn-part ⇒ tomma värden +
     *     varning på barnNamn. Vid skydd utelämnas namnet (grinden ovan) men
     *     barnPnr fylls ALLTID — personen identifieras med personnummer.
     *
     * Varje fält: ['nyckel','etikett','varde','kalla','sparrad','varning'] där
     * kalla ∈ "register"|"anvandare"|"partsregister"|"" (tomt = inget värde
     * levererat) och sparrad är fas 1 alltid false (grinden varnar och tömmer,
     * den hårdspärrar inte — ifyllnad är anroparens journalförda beslut).
     *
     * @param string $ref hubsCaseId eller dnr.
     *
     * @return array{
     *     falt: array<int, array{nyckel:string, etikett:string, varde:string, kalla:string, sparrad:bool, varning:?string}>,
     *     skyddsniva: string,
     *     varningar: string[]
     * } skyddsniva = barnets skydd (ingen|sekretessmarkering|skyddad_folkbokforing)
     *   eller "ingen" utan barn-part; varningar = alla fält-varningar i fältordning.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException okänt ärende ELLER obehörig (H1).
     * @throws \OCP\DB\Exception
     */
    public function byggUtkast(string $ref, ?string $mallId = null): array {
        // (1) Authz FÖRST (H1): show() kör assertEnhetAtkomst — ingen PII-läsning
        // sker för en anropare som inte får se ärendet.
        $arende = $this->arendeService->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();

        // (2a) Registerfälten.
        $dnr = $arende->getDnr();
        $arendeRef = ($dnr !== null && $dnr !== '')
            ? $dnr
            : 'Ärende ' . ArendeService::kortRef($hubsCaseId);
        $enhet = $arende->getEnhet() ?? '';

        // (2b) Handläggaren: ägarens visningsnamn ur användarkatalogen,
        // graceful fallback till uid (testharness utan userManager) resp. tomt
        // (ej tilldelat ärende).
        $agareUid = $arende->getAgareUid();
        $handlaggare = '';
        if ($agareUid !== null && $agareUid !== '') {
            $handlaggare = $this->userManager?->get($agareUid)?->getDisplayName() ?? $agareUid;
        }

        // (2c) PARTSREGISTRET: första part med roll=barn (nyast först ⇒ den
        // senast tillagda/berikade barn-parten vinner).
        $barn = null;
        foreach ($this->partMapper->findByCaseId($hubsCaseId) as $part) {
            if ($part->getRoll() === Part::ROLL_BARN) {
                $barn = $part;
                break;
            }
        }

        $barnNamn = '';
        $barnPnr = '';
        $barnNamnVarning = null;
        $skyddsniva = Part::SKYDD_INGEN;

        if ($barn === null) {
            // Inget skyddsfall — bara tomt: handläggaren pekas till Parter-fliken.
            $barnNamnVarning = self::VARNING_INGEN_BARNPART;
        } else {
            $skydd = $barn->getSkydd();
            if ($skydd !== '') {
                $skyddsniva = $skydd;
            }

            // barnPnr fylls ALLTID — personen identifieras med personnummer
            // (eSam-principen), även (särskilt!) vid skyddad identitet.
            $barnPnr = self::formateraPnr($barn->getPersonnummer());

            // SKYDDSGRINDEN (K-NAV-6.1): vid skydd lämnar namnet ALDRIG källan
            // som default — värdet töms och varningen förklarar varför. Att ändå
            // fylla fältet är anroparens aktiva beslut (journal: skyddOverride:true).
            if ($skydd === Part::SKYDD_SEKRETESSMARKERING) {
                $barnNamnVarning = self::VARNING_SEKRETESSMARKERING;
            } elseif ($skydd === Part::SKYDD_SKYDDAD_FOLKBOKFORING) {
                $barnNamnVarning = self::VARNING_SKYDDAD_FOLKBOKFORING;
            } else {
                $barnNamn = $barn->getNamn();
            }
        }

        // (2d) VÅG A — anmälaren ur partsregistret (roll=anmalare, nyast först).
        $anmalareNamn = '';
        $anmalareKontakt = '';
        foreach ($this->partMapper->findByCaseId($hubsCaseId) as $part) {
            if ($part->getRoll() === Part::ROLL_ANMALARE) {
                $anmalareNamn = $part->getNamn();
                $anmalareKontakt = $part->getKontakt() ?? '';
                break;
            }
        }

        // (2e) VÅG A — journalens datum-ankare: när ärendet föddes (= anmälan
        // mottogs/aktualiserades) och när utredning inleddes (stegövergången).
        // HÄRLEDDA värden — förslag som handläggaren bekräftar i dialogen.
        $inkomTidpunkt = '';
        $utredningInledd = '';
        if ($this->handelseMapper !== null) {
            try {
                foreach ($this->handelseMapper->findByCaseId($hubsCaseId) as $h) {
                    if ($h->getTyp() === Handelse::TYP_SKAPAD && $h->getTid() !== null) {
                        // Journalen är nyast först — sista träffen är den äldsta,
                        // men skapad förekommer bara en gång per ärende.
                        $inkomTidpunkt = $h->getTid()->format('Y-m-d H:i');
                    }
                    if ($h->getTyp() === Handelse::TYP_STEG && $h->getTid() !== null && $utredningInledd === '') {
                        $detalj = json_decode($h->getDetalj() ?? '', true);
                        if (is_array($detalj) && ($detalj['till'] ?? '') === 'utredning') {
                            // Nyast först ⇒ första träffen = senaste övergången
                            // till utredning (om steget gåtts om gäller senaste).
                            $utredningInledd = $h->getTid()->format('Y-m-d');
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Graceful — journalfälten lämnas tomma, fyllnaden degraderar.
            }
        }

        // (2f) VÅG A — medutredare ur medlemsliggaren (co-handläggare, äldst
        // först = den först tillagda medutredaren), visningsnamn m. fallback uid.
        $medutredare = '';
        if ($this->memberMapper !== null) {
            try {
                $coRader = $this->memberMapper->findByCaseAndRoll($hubsCaseId, Member::ROLL_CO_HANDLAGGARE);
                $co = $coRader !== [] ? $coRader[count($coRader) - 1] : null;
                if ($co !== null) {
                    $medutredare = $this->userManager?->get($co->getUid())?->getDisplayName() ?? $co->getUid();
                }
            } catch (\Throwable $e) {
                // Graceful — fältet lämnas tomt.
            }
        }

        // (2g) Fält-listan i fältkartans ordning. kalla är tom när inget värde
        // levererats (tomt fält ur en källa är inte en leverans).
        $falt = [
            self::falt('arendeRef', $arendeRef, 'register'),
            self::falt('enhet', $enhet, 'register'),
            self::falt('handlaggare', $handlaggare, 'anvandare'),
            self::falt('barnNamn', $barnNamn, 'partsregister', $barnNamnVarning),
            self::falt('barnPnr', $barnPnr, 'partsregister'),
            self::falt('anmalareNamn', $anmalareNamn, 'partsregister'),
            self::falt('anmalareKontakt', $anmalareKontakt, 'partsregister'),
            self::falt('inkomTidpunkt', $inkomTidpunkt, 'journal'),
            self::falt('utredningInledd', $utredningInledd, 'journal'),
            self::falt('medutredare', $medutredare, 'anvandare'),
            // S4: konfig-driven branding — fylls i sidhuvudets brand-slot.
            self::falt(
                'kommunNamn',
                $this->appConfig?->getAppValueString('blankett_kommun', '') ?? '',
                'konfig',
            ),
        ];

        // (2h) SAKUPPGIFTSLAGRET fyller LUCKOR: ett fält som ingen levande
        // källa kunde fylla får den senast BEKRÄFTADE uppgiften ur ärendets
        // dokumentkedja (§4) — med ursprungsdokumentet synligt i källtexten.
        // Levande källor vinner alltid över minnet; SKYDDSGRINDEN respekteras
        // (ett skydds-varnat fält fylls inte ur minnet heller — varningen står).
        if ($this->sakuppgiftService !== null) {
            $minne = $this->sakuppgiftService->hamta($hubsCaseId);
            foreach ($falt as $i => $rad) {
                if ($rad['varde'] !== '' || $rad['varning'] !== null) {
                    continue;
                }
                $sak = $minne[$rad['nyckel']] ?? null;
                if ($sak !== null && $sak->getVarde() !== '') {
                    $falt[$i]['varde'] = $sak->getVarde();
                    $falt[$i]['kalla'] = 'akten_tidigare_handling';
                    $falt[$i]['varning'] = null;
                }
            }
        }

        // (2i) S4 — PER-MALL-FILTRERING: när anroparen anger vilken mall som
        // ska fyllas begränsas utkastet till fält vars platshållare faktiskt
        // FINNS i mallen (malldefinitionens token-lista, autogenererad vid
        // bygget). Förhandsdialogen visar då bara relevanta fält. Graceful:
        // saknad definition/tjänst ⇒ ofiltrerat utkast (ärligt övervisande).
        if ($mallId !== null && $mallId !== '' && $this->mallService !== null) {
            $definition = $this->mallService->lasDefinition($mallId);
            $tokens = is_array($definition['tokens'] ?? null) ? $definition['tokens'] : null;
            if ($tokens !== null) {
                $falt = array_values(array_filter(
                    $falt,
                    static fn (array $rad): bool =>
                        array_intersect(self::platshallareFor($rad['nyckel']), $tokens) !== [],
                ));
            }
        }

        // (3) Sammanställ varningarna i fältordning.
        $varningar = [];
        foreach ($falt as $rad) {
            if ($rad['varning'] !== null) {
                $varningar[] = $rad['varning'];
            }
        }

        // PII-DOKTRIN: ENBART antal/nycklar/skyddsnivå + pseudonymt hubsCaseId —
        // ALDRIG namn/pnr/dnr eller andra fältvärden.
        $this->logger->info('hubs_arende: arendedata-utkast byggt', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'antalFalt' => count($falt),
            'skyddsniva' => $skyddsniva,
            'antalVarningar' => count($varningar),
        ]);

        return [
            'falt' => $falt,
            'skyddsniva' => $skyddsniva,
            'varningar' => $varningar,
        ];
    }

    /**
     * Bygg en fält-rad. Källan nollas till "" när värdet är tomt — ett tomt
     * fält är ingen leverans, oavsett var vi letade. sparrad är fas 1 alltid
     * false (SKYDDSGRINDEN varnar och tömmer, den hårdspärrar inte).
     *
     * @return array{nyckel:string, etikett:string, varde:string, kalla:string, sparrad:bool, varning:?string}
     */
    private static function falt(string $nyckel, string $varde, string $kalla, ?string $varning = null): array {
        return [
            'nyckel' => $nyckel,
            'etikett' => self::ETIKETTER[$nyckel],
            'varde' => $varde,
            'kalla' => $varde !== '' ? $kalla : '',
            'sparrad' => false,
            'varning' => $varning,
        ];
    }

    /**
     * Formatera ett personnummer till mallformatet ÅÅÅÅMMDD-XXXX: bindestreck
     * efter 8 siffror. Lagringsformatet är 12 siffror utan bindestreck
     * ({@see Part}); redan bindestreckade/avvikande värden lämnas orörda
     * (ärlighet före täckning — hellre källans form än en felgissad).
     */
    private static function formateraPnr(?string $pnr): string {
        if ($pnr === null || $pnr === '') {
            return '';
        }
        $pnr = trim($pnr);
        if (preg_match('/^\d{12}$/', $pnr) === 1) {
            return substr($pnr, 0, 8) . '-' . substr($pnr, 8);
        }
        return $pnr;
    }
}
