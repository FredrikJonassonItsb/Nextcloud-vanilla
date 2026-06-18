<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Integration\Client\CalendarClient;
use OCA\HubsArende\Integration\Client\DeckClient;
use OCA\HubsArende\Integration\Client\GroupfolderClient;
use OCA\HubsArende\Integration\Client\SdkmcClient;
use OCA\HubsArende\Integration\Client\SpreedClient;

/**
 * DEV/DEMO seedning av syntetiska demo-ärenden — återanvändbar service.
 *
 * Seedar en kurerad uppsättning SYNTETISKA demo-ärenden genom den RIKTIGA motorn
 * (createCase → lifecycle-transitioner → tilldelning → commit) så att admin-
 * statussidan och dashboarden visar en livfull spridning över ärendetyp / steg /
 * status / provenans.
 *
 * Allt är pseudonymt (objektRef/conversationId 'demo-…') — INGEN PII. Varje rad är
 * taggad via sin conversationId-prefix 'demo-' så att {@see purge()} kan ta bort
 * exakt de seedade raderna (och deras pekare) igen. IDEMPOTENT: en omkörning
 * återanvänder befintliga rader (createCase är idempotent på conversationId).
 *
 * Denna service RÖR ALDRIG sagan/ArendeService internt — den återanvänder bara
 * dess publika metoder (createCase/tilldela/commit) + lifecycle-transitionera.
 * Konsumeras både av occ-kommandot {@see \OCA\HubsArende\Command\SeedDemo} och av
 * admin-OCS-endpointen (AdminController#seedDemo) bakom "Återställ demo-data".
 */
class DemoSeedService {
    /** Prefix på alla demo-rader (conversationId/objektRef) — gör purge exakt. */
    public const PREFIX = 'demo-';

    /**
     * Demo-handläggare för tilldelade demo-ärenden. MÅSTE vara en RIKTIG NC-användare
     * (medlem i enhets-grupperna) för att handoff-avsmalningen ska kunna demonstreras:
     * vid tilldelning övergår per-case-gruppens åtkomst från mottagningskretsen till
     * denna handläggare. En icke-existerande uid degraderar graciöst (gruppen blir
     * tom — ingen riktig användare att granta). Dev15: '197411040293'.
     */
    public const DEMO_HANDLAGGARE = '197411040293';

    /**
     * Kurerad demo-uppsättning: [arendeTyp, enhet, ref, transitions[], tilldela, commit].
     * Endast facksystem-destinations-typer committas (håller stub-routingen enkel).
     *
     * @var array<int, array{0:string,1:string,2:string,3:list<string>,4:bool,5:bool}>
     */
    public const CASES = [
        ['orosanmalan',     'barn-familj@', 'demo-barn-001', [],                                                 false, false],
        ['orosanmalan',     'barn-familj@', 'demo-barn-002', ['forhandsbedomning'],                              true,  false],
        ['ansokan_bistand', 'ekonomi@',     'demo-ekon-001', ['forhandsbedomning', 'utredning'],                 true,  false],
        ['ekonomi',         'ekonomi@',     'demo-ekon-002', ['forhandsbedomning'],                              true,  true],
        ['vard_samverkan',  'vuxen@',       'demo-vux-001',  ['forhandsbedomning', 'utredning', 'beslut'],       true,  true],
        ['verkstallighet',  'barn-familj@', 'demo-barn-003', ['forhandsbedomning', 'utredning', 'beslut', 'uppfoljning'], true, false],
        ['familjeratt',     'familjeratt@', 'demo-fam-001',  [],                                                 false, false],
        ['komplettering',   'barn-familj@', 'demo-barn-004', ['forhandsbedomning', 'utredning'],                 true,  false],
        ['rattsligt_tvang', 'barn-familj@', 'demo-barn-005', ['forhandsbedomning'],                              true,  false],
        ['ansokan_bistand', 'ekonomi@',     'demo-ekon-003', ['forhandsbedomning', 'avslutat'],                  false, false],
    ];

    public function __construct(
        private ArendeService $arendeService,
        private ArendeLifecycleService $lifecycleService,
        private ArendeMapper $arendeMapper,
        private PekareMapper $pekareMapper,
        private GroupfolderClient $groupfolderClient,
        private SpreedClient $spreedClient,
        private DeckClient $deckClient,
        private CalendarClient $calendarClient,
        private SdkmcClient $sdkmcClient,
        private MemberMapper $memberMapper,
        private ArenderumGroupService $arenderumGroupService,
    ) {
    }

    /**
     * Seeda demo-ärenden genom den riktiga motorn. IDEMPOTENT: en omkörning
     * återanvänder befintliga rader (createCase är idempotent på conversationId).
     * Ett fel på en enskild rad fälls aldrig hela seedningen — det räknas i `fel`.
     *
     * @return array{skapade:int, fel:int}
     */
    public function seed(): array {
        $skapade = 0;
        $fel = 0;
        foreach (self::CASES as $i => [$typ, $enhet, $ref, $path, $tilldela, $commit]) {
            try {
                $arende = $this->arendeService->createCase([
                    'arendeTyp' => $typ,
                    'conversationId' => $ref,
                    'objektRef' => $ref,
                    'enhet' => $enhet,
                    'inkomDatum' => date('Y-m-d', strtotime('-' . ($i % 6) . ' days')),
                ]);
                $id = $arende->getHubsCaseId();

                foreach ($path as $steg) {
                    $this->lifecycleService->transitionera($id, $steg);
                }
                if ($tilldela) {
                    // Riktig handläggare → handoff: per-case-gruppen smalnar av från
                    // mottagningskretsen till denna uid (demonstrerar GAP-057).
                    $this->arendeService->tilldela($id, self::DEMO_HANDLAGGARE);
                }
                if ($commit) {
                    $this->arendeService->commit($id, ['typ' => 'demo-skyddsbedomning']);
                }

                $skapade++;
            } catch (\Throwable $e) {
                $fel++;
            }
        }

        return ['skapade' => $skapade, 'fel' => $fel];
    }

    /**
     * Rensa alla demo-rader (conversationId LIKE 'demo-%') till ett RENT
     * utgångsläge: för varje demo-case rivs FÖRST de RIKTIGA externa objekten ner
     * via respektive klients compensation-metod (groupfolder/talk_room/deck_card/
     * calendar/case_tag/conversation), SEDAN raderas pekarna + case-raden.
     *
     * Sagan skapar numera riktiga groupfolders (R4) + Spreed-rum (R6) m.fl.; en
     * purge som bara raderade pekarna skulle lämna de externa objekten kvar som
     * skräp. Här speglas SAGA:ns kanoniska compensation-mappning (se
     * {@see \OCA\HubsArende\Service\ArendeService}) per objekt_typ.
     *
     * GRACEFUL: varje extern teardown sväljer fel och fortsätter (isAvailable()-
     * gating + graceful sker i klienterna), så en saknad granne eller pending
     * credential fäller aldrig purge. IDEMPOTENT — en omkörning hittar färre/inga
     * rader. Returnerar antalet borttagna case-rader.
     */
    public function purge(): int {
        $rader = $this->arendeMapper->findByConversationIdLike(self::PREFIX . '%');
        $antal = 0;
        foreach ($rader as $rad) {
            $id = $rad->getHubsCaseId();
            // Funktionsadressen (enhet, t.ex. 'barn-familj@') löser sdkmc-account
            // för case_tag-borttagningen — speglar ArendeService::inflowEmail().
            $email = (string)($rad->getEnhet() ?? '');
            foreach ($this->pekareMapper->findByCaseId($id) as $pekare) {
                // FÖRST: riv det riktiga externa objektet (graceful), SEDAN pekaren.
                $this->tearDownExternal($id, $email, $pekare);
                $this->pekareMapper->delete($pekare);
            }
            // Riv ärenderummets förstaklassiga medlemmar (mottagningskrets + ev.
            // handläggare/co-handläggare) så purge ger ett RENT utgångsläge.
            $this->memberMapper->deleteByCaseId($id);
            // Riv per-case-åtkomstgruppen så ingen tom grupp blir kvar.
            $this->arenderumGroupService->delete($id);
            $this->arendeMapper->delete($rad);
            $antal++;
        }
        return $antal;
    }

    /**
     * Riv ett enskilt riktigt externt objekt baserat på pekarens objekt_typ, via
     * rätt klients compensation-metod. Speglar SAGA:ns compensations:
     *   groupfolder   → GroupfolderClient::removeFolder((int)objektId)
     *   talk_room     → SpreedClient::deleteRoom(objektId)   (HARD delete — rent utgångsläge)
     *   deck_card     → DeckClient::deleteCard((int)riktning=boardId, (int)objektId=cardId)
     *   calendar      → CalendarClient::removeCalendar(objektId)
     *   case_tag      → SdkmcClient::deleteCaseTag(hubsCaseId, email, objektId=tagId)
     *   conversation  → SdkmcClient::untagMessage(hubsCaseId, []) (demo har inga
     *                    riktiga message-ids; untag är då en graceful no-op)
     *
     * GRACEFUL: alla fel sväljs så purge fortsätter. Okänd objekt_typ ignoreras.
     */
    private function tearDownExternal(string $hubsCaseId, string $email, Pekare $pekare): void {
        $objektId = $pekare->getObjektId();
        try {
            switch ($pekare->getObjektTyp()) {
                case 'groupfolder':
                    $this->groupfolderClient->removeFolder((int)$objektId);
                    break;
                case 'talk_room':
                    $this->spreedClient->deleteRoom($objektId);
                    break;
                case 'deck_card':
                    // boardId stashas i riktning, cardId i objektId (R5-konventionen).
                    $this->deckClient->deleteCard((int)$pekare->getRiktning(), (int)$objektId);
                    break;
                case 'calendar':
                    // riktning carries the owner uid ('' = service account).
                    $this->calendarClient->removeCalendar($objektId, $pekare->getRiktning() ?: null);
                    break;
                case 'case_tag':
                    $this->sdkmcClient->deleteCaseTag($hubsCaseId, $email, $objektId);
                    break;
                case 'conversation':
                    $this->sdkmcClient->untagMessage($hubsCaseId, []);
                    break;
                default:
                    // Okänd typ — ingen teardown att göra; pekaren raderas ändå.
                    break;
            }
        } catch (\Throwable $e) {
            // Klienterna är redan graceful; detta är en sista skyddsnät så att en
            // oväntad throw på en pekare aldrig fäller hela purge.
        }
    }

    /**
     * Återställ demo-data till utgångsläge: purge först, sedan seed. Backar
     * admin-knappen "Återställ demo-data till utgångsläge". IDEMPOTENT.
     *
     * @return array{raderade:int, skapade:int, fel:int}
     */
    public function reseed(): array {
        $raderade = $this->purge();
        $seed = $this->seed();
        return [
            'raderade' => $raderade,
            'skapade' => $seed['skapade'],
            'fel' => $seed['fel'],
        ];
    }
}
