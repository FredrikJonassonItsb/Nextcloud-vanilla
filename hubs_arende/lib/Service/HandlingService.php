<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\PekareMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * HANDLING-FRÅN-MALL fas 1 (design: hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md) —
 * orkestreringen: mall + fält → ifylld .docx i ärenderummets groupfolder + pekare
 * + journal.
 *
 * Flödet ({@see generera()}):
 *   authz ({@see ArendeService::show()}) → mall-byte ({@see MallService::lasMall()})
 *   → utkast/skyddsgrind ({@see ArendedataService::byggUtkast()})
 *   → ersättningskarta (fält-nyckel → mallens exakta platshållare,
 *     {@see ArendedataService::platshallareFor()})
 *   → ifyllnad ({@see DocxFyllningsMotor::fyll()})
 *   → skriv i groupfoldern (samma seam som {@see ReferensFilService}: Pekare(groupfolder)
 *     + jail-path __groupfolders/{id} + newFile)
 *   → Pekare(objektTyp='handling') → journal (Handelse::TYP_HANDLING).
 *
 * NEVER-SoR/retention: den genererade handlingen är ARBETSMATERIAL tills verifierad
 * commit gör den till allmän handling i facksystemet. Pekaren gör handlingen
 * enumererbar så att gallringen kan städa den MED ärendet ({@see taBortHandlingar()},
 * anropas av GallringService) — groupfolder-rivningen ensam räcker inte som spår.
 *
 * PII-DOKTRIN: fältVÄRDEN (personnummer/namn/adress) bor ENBART i det ifyllda
 * dokumentet (som ärver ärenderummets per-case-ACL) och i partsregistret. Loggar
 * och Handelse.detalj får ALDRIG innehålla värden — bara antal, nycklar och
 * mallnamn.
 *
 * SKYDDSGRIND (K-NAV-6.1): utkastet från ArendedataService UTELÄMNAR som default
 * namn-fält för barn-part med sekretessmarkering/skyddad folkbokföring (värde tomt
 * + varning). Fyller anroparen ÄNDÅ ett skydds-varnat fält är det ett AKTIVT
 * handläggarbeslut — det journalförs som skyddOverride:true (aldrig själva värdet).
 *
 * HÅRT FEL by design: detta är en ANVÄNDARINITIERAD handling — kan groupfoldern
 * inte resolvas är ett tydligt fel bättre än en tyst no-op (jfr ReferensFilService
 * som är graceful för att den ligger i ett automatiskt flöde).
 */
class HandlingService {
    /** objekt_typ för handling-pekaren — gallras med ärendet. */
    public const OBJEKT_TYP = 'handling';

    public function __construct(
        private ArendeService $arendeService,
        private MallService $mallService,
        private ArendedataService $arendedataService,
        private DocxFyllningsMotor $motor,
        private PekareMapper $pekareMapper,
        private LoggerInterface $logger,
        private ?IRootFolder $rootFolder = null,
        private ?HandelseMapper $handelseMapper = null,
        private ?IUserSession $userSession = null,
        // TRAILING OPTIONAL (autowired): SAKUPPGIFTSLAGRET — handläggarens
        // bekräftade fält sparas efter lyckad generering (dokumentkedjans
        // skrivsida, ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md §4). Best-effort.
        private ?SakuppgiftService $sakuppgiftService = null,
        // TRAILING OPTIONAL (autowired): KANONISKA dokumenttyps-registret. Stämplar
        // den semantiska dokumenttypen på TYP_HANDLING-händelsen så grindarna kan
        // matcha på ett kanoniskt fält i stället för att gissa ur mall-sluggen
        // (T4-rotfixen — dödar barnets_rost-buggklassen). Null i test-harness ⇒
        // ingen stämpel, konsumenten faller tillbaka på legacy-nyckelord.
        private ?DokumenttypRegistry $dokumenttypRegistry = null,
    ) {
    }

    /**
     * Generera en handling ur en mall, förifylld med ärendedata, och skriv den
     * till ärenderummets groupfolder.
     *
     * @param string               $ref    hubsCaseId eller dnr (authz via ArendeService::show).
     * @param string               $mallId Mallens id i den delade mallmappen (t.ex. filnamn).
     * @param array<string,mixed>  $falt   Slutliga fältvärden (nyckel → värde) som anroparen
     *                                     bekräftat i dialogen; tomma värden hoppas. Oersatta
     *                                     platshållare lämnas åt handläggaren i dokument-
     *                                     redigeraren (ärlighet före täckning — datum-
     *                                     platshållare fylls MEDVETET inte i fas 1).
     * @return array{ok: bool, filnamn: string, antalErsatta: int, ersatta: array<string,int>}
     * @throws \OCP\AppFramework\Db\DoesNotExistException Okänt ärende/ej behörig (H1: 404, ingen existens-läcka).
     * @throws \RuntimeException Ärenderummet ej tillgängligt eller skrivningen misslyckades.
     */
    public function generera(string $ref, string $mallId, array $falt): array {
        // (1) Authz FÖRST — obehörig anropare får DoesNotExistException (H1).
        $arende = $this->arendeService->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();

        // (2) Mallens byte ur den delade mallmappen.
        $mallBytes = $this->mallService->lasMall($mallId);

        // (3) Utkast — bär skyddsgrindens varningar (K-NAV-6.1). Fyller anroparen
        //     ett varnat fält med ett icke-tomt värde är det ett aktivt beslut.
        $utkast = $this->arendedataService->byggUtkast($ref);
        $skyddOverride = $this->beraknaSkyddOverride($utkast, $falt);

        // (3b) S4 — KONFIG-FÄLT auto-fylls: fält med källa 'konfig' (kommunNamn
        //      → sidhuvudets brand-slot) ska alltid med, även när dialogen inte
        //      skickar dem (instans-branding, inte ärendedata). Anroparens
        //      explicita värde vinner alltid.
        foreach (($utkast['falt'] ?? []) as $rad) {
            if (($rad['kalla'] ?? '') === 'konfig'
                && ($rad['varde'] ?? '') !== ''
                && !isset($falt[$rad['nyckel']])) {
                $falt[$rad['nyckel']] = $rad['varde'];
            }
        }

        // (4) Ersättningskarta: fält-nyckel → mallens exakta platshållar-strängar.
        //     Bara icke-tomma inkomna värden; okända nycklar ger ingen platshållare
        //     och hoppas därmed naturligt.
        $ersattningar = [];
        foreach ($falt as $nyckel => $varde) {
            $strVarde = is_scalar($varde) ? trim((string)$varde) : '';
            if ($strVarde === '') {
                continue;
            }
            foreach (ArendedataService::platshallareFor((string)$nyckel) as $platshallare) {
                $ersattningar[$platshallare] = $strVarde;
            }
        }

        // (5) Fyll dokumentet (ren byte-in/byte-ut — motorn rör aldrig disk/loggar).
        $resultat = $this->motor->fyll($mallBytes, $ersattningar);
        $bytes = $resultat['bytes'];
        /** @var array<string,int> $ersatta per-platshållare ersättnings-räkning */
        $ersatta = $resultat['ersatta'];
        $antalErsatta = array_sum($ersatta);

        // (6)–(8) Filnamn + skriv i ärenderummets groupfolder.
        $mallBas = $this->mallBasnamn($mallId);
        $folder = $this->resolveGroupfolder($hubsCaseId);
        if ($folder === null) {
            // Användarinitierad handling — hårt fel bättre än tyst no-op.
            throw new \RuntimeException('Ärenderummet är inte tillgängligt');
        }
        $filnamn = $this->byggFilnamn($folder, $mallBas, $hubsCaseId);

        try {
            $folder->newFile($filnamn, $bytes);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende: HandlingService — kunde ej skriva handling i groupfoldern', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'fil' => $filnamn,
                'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Handlingen kunde inte skrivas till ärenderummet', 0, $e);
        }

        // (9) Pekare — gör handlingen enumererbar för gallringen (städas med ärendet).
        $this->pekareMapper->record($hubsCaseId, self::OBJEKT_TYP, $filnamn);

        // (10) Journal — BEST-EFFORT (får aldrig fälla den mutation den beskriver)
        //      och ALDRIG fältvärden: bara mallnamn, antal och skydds-flaggan.
        $this->loggaHandelse($hubsCaseId, $mallBas, $antalErsatta, $skyddOverride);

        // (10b) SAKUPPGIFTSLAGRET (dokumentkedjans minne): varje icke-tomt fält
        // handläggaren just bekräftade sparas strukturerat — nästa mall i kedjan
        // förifylls ur ärendets egna bekräftade uppgifter. Källan per fält tas
        // ur utkastet; avviker det bekräftade värdet från förslaget är källan
        // 'handlaggare' (människan skrev/ändrade det). BEST-EFFORT i servicen.
        if ($this->sakuppgiftService !== null) {
            $utkastKallor = [];
            $utkastVarden = [];
            foreach (($utkast['falt'] ?? []) as $rad) {
                if (is_array($rad) && isset($rad['nyckel'])) {
                    $utkastKallor[(string)$rad['nyckel']] = (string)($rad['kalla'] ?? '');
                    $utkastVarden[(string)$rad['nyckel']] = (string)($rad['varde'] ?? '');
                }
            }
            $kallor = [];
            $bekraftade = [];
            foreach ($falt as $nyckel => $varde) {
                $strVarde = is_scalar($varde) ? trim((string)$varde) : '';
                if ($strVarde === '') {
                    continue;
                }
                $nyckel = (string)$nyckel;
                $bekraftade[$nyckel] = $strVarde;
                $kallor[$nyckel] = ($strVarde === ($utkastVarden[$nyckel] ?? null) && ($utkastKallor[$nyckel] ?? '') !== '')
                    ? $utkastKallor[$nyckel]
                    : 'handlaggare';
            }
            $this->sakuppgiftService->sparaBekraftade($hubsCaseId, $bekraftade, $kallor, $mallBas);
        }

        $this->logger->info('hubs_arende: HandlingService.generera', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'mall' => $mallBas,
            'fil' => $filnamn,
            'antalErsatta' => $antalErsatta,
        ]);

        // (11) Kvitto till anroparen (dialogen visar vad som faktiskt fylldes).
        return [
            'ok' => true,
            'filnamn' => $filnamn,
            'antalErsatta' => $antalErsatta,
            'ersatta' => $ersatta,
        ];
    }

    /**
     * Ta bort ALLA genererade handlingar (+ deras pekare) för ett ärende. Anropas
     * av gallring/purge/compensation — speglar {@see ReferensFilService::taBortReferenser()}
     * exakt. Graceful: en saknad fil/folder hoppas (filen försvinner ändå när
     * groupfoldern rivs).
     */
    public function taBortHandlingar(string $hubsCaseId): void {
        $folder = $this->resolveGroupfolder($hubsCaseId);
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, self::OBJEKT_TYP) as $p) {
            $filnamn = $p->getObjektId();
            if ($folder !== null) {
                try {
                    if ($folder->nodeExists($filnamn)) {
                        $folder->get($filnamn)->delete();
                    }
                } catch (\Throwable $e) {
                    // Filen försvinner ändå när groupfoldern rivs — graceful.
                }
            }
        }
        $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, self::OBJEKT_TYP);
    }

    // ------------------------------------------------------------------ //

    /**
     * SKYDDSGRINDEN (K-NAV-6.1): true om något utkast-fält med icke-null varning
     * fick ett icke-tomt inkommet värde — dvs. anroparen fyllde AKTIVT ett fält
     * som skyddsgrinden utelämnat (barn-part med sekretessmarkering/skyddad
     * folkbokföring). Journalförs som flagga — aldrig själva värdet.
     *
     * @param array<int|string,mixed> $utkast Utkast-fält från ArendedataService::byggUtkast.
     * @param array<string,mixed>     $falt   Inkomna fältvärden.
     */
    private function beraknaSkyddOverride(array $utkast, array $falt): bool {
        foreach ($utkast as $key => $def) {
            if (!is_array($def)) {
                continue;
            }
            $nyckel = (string)($def['nyckel'] ?? (is_string($key) ? $key : ''));
            if ($nyckel === '' || ($def['varning'] ?? null) === null) {
                continue;
            }
            $inkommet = $falt[$nyckel] ?? null;
            if (is_scalar($inkommet) && trim((string)$inkommet) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Mallens basnamn utan .docx, sanerat till [a-z0-9-] lowercase — blir både
     * filnamnets stam och journalens mallnamn (mallnamn är inte PII).
     */
    private function mallBasnamn(string $mallId): string {
        $bas = basename($mallId);
        if (str_ends_with(mb_strtolower($bas), '.docx')) {
            $bas = mb_substr($bas, 0, mb_strlen($bas) - 5);
        }
        $bas = mb_strtolower($bas);
        // Svenska tecken translittereras innan allt utanför [a-z0-9] blir '-'.
        $bas = strtr($bas, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e']);
        $bas = preg_replace('/[^a-z0-9]+/', '-', $bas) ?? '';
        $bas = trim($bas, '-');
        return $bas !== '' ? $bas : 'handling';
    }

    /**
     * Bygg filnamnet <mallbas>-<kortRef>-<Ymd>.docx; vid kollision i foldern
     * läggs suffix -2, -3, … på stammen. kortRef är pseudonym (M2) — aldrig PII.
     */
    private function byggFilnamn(Folder $folder, string $mallBas, string $hubsCaseId): string {
        $stam = $mallBas . '-' . ArendeService::kortRef($hubsCaseId) . '-' . date('Ymd');
        $filnamn = $stam . '.docx';
        $n = 2;
        while ($folder->nodeExists($filnamn)) {
            $filnamn = $stam . '-' . $n . '.docx';
            $n++;
        }
        return $filnamn;
    }

    /**
     * Journalför handlingen (Handelse::TYP_HANDLING). BEST-EFFORT: journal-
     * skrivningen får ALDRIG fälla genereringen den beskriver (mönstret ligger i
     * {@see ArendeService::loggaHandelse()}). detalj: {handling, mall, antalErsatta,
     * skyddOverride?} — ALDRIG fältvärden.
     */
    private function loggaHandelse(string $hubsCaseId, string $mallBas, int $antalErsatta, bool $skyddOverride): void {
        if ($this->handelseMapper === null) {
            return;
        }
        $detalj = [
            'handling' => 'skapad',
            'mall' => $mallBas,
            'antalErsatta' => $antalErsatta,
        ];
        // KANONISK dokumenttyp (T4-rotfix): stämpla den semantiska klassen så
        // grindarna matchar på ett exakt fält, inte på mall-sluggens innehåll.
        // Okänd mall ⇒ ingen stämpel (konsumenten faller tillbaka på nyckelord).
        $dokumenttyp = $this->dokumenttypRegistry?->klassForMall($mallBas);
        if ($dokumenttyp !== null && $dokumenttyp !== '') {
            $detalj['dokumenttyp'] = $dokumenttyp;
        }
        if ($skyddOverride) {
            $detalj['skyddOverride'] = true;
        }
        try {
            $aktorUid = $this->userSession?->getUser()?->getUID() ?? '';
            $this->handelseMapper->record($hubsCaseId, Handelse::TYP_HANDLING, $detalj, $aktorUid);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: HandlingService — journal-skrivning misslyckades (best-effort)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolva ärenderummets groupfolder-Node via Pekare(objektTyp='groupfolder')
     * och groupfolders' interna jail-path '__groupfolders/{folderId}'. Null = ej
     * resolverbar — privat kopia av {@see ReferensFilService}-mönstret (anroparen
     * i {@see generera()} gör null till hårt fel; {@see taBortHandlingar()} är graceful).
     */
    private function resolveGroupfolder(string $hubsCaseId): ?Folder {
        if ($this->rootFolder === null) {
            return null;
        }
        $folderId = null;
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') as $p) {
            $folderId = (int)$p->getObjektId();
            break;
        }
        if ($folderId === null) {
            return null;
        }
        // groupfolders >= 20 lägger de användarsynliga filerna under
        // '__groupfolders/{id}/files' (jämte versions/trash); äldre versioner har
        // dem direkt under '__groupfolders/{id}'. Prova files-subkatalogen FÖRST så
        // handlingen hamnar i handläggarens synliga ärenderum, med fallback till den
        // äldre platsen. (Skrivning till lagringsroten hamnar utanför mount:en och
        // blir osynlig för handläggaren — därav ordningen.)
        foreach (['__groupfolders/' . $folderId . '/files', '__groupfolders/' . $folderId] as $path) {
            try {
                $node = $this->rootFolder->get($path);
                if ($node instanceof Folder) {
                    return $node;
                }
            } catch (\Throwable $e) {
                // prova nästa kandidat
            }
        }
        $this->logger->debug('hubs_arende: HandlingService — groupfolder ej resolverbar (graceful)', [
            'app' => 'hubs_arende',
            'folderId' => $folderId,
        ]);
        return null;
    }
}
