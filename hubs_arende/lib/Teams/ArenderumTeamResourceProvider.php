<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Teams;

use OCA\HubsArende\Db\PekareMapper;
use OCP\IURLGenerator;
use OCP\Teams\ITeamResourceProvider;
use OCP\Teams\TeamResource;
use Psr\Log\LoggerInterface;

/**
 * Team-resurs-provider: gör AKTEN (ärenderummets groupfolder) synlig på ärendets
 * TEAM-sida (Contacts team-vy, OCS /teams/{id}/resources).
 *
 * Talk listar redan ärendets diskussionsrum där (teamet är deltagare, Talks egen
 * provider plockar upp det). Akten är däremot bara "applicable" på groupfoldern
 * — ingen share — så ingen core-provider ser den. Denna provider stänger gapet
 * genom att slå upp teamet i motorns EGEN bokföring: pekare objekt_typ='team'
 * (objekt_id = circle singleId) → hubsCaseId → groupfolder-pekaren.
 *
 * AUTHZ: core:s TeamManager::getSharedWith gatar på att ANROPAREN kan se teamet
 * (circles-probe som användaren) innan providern frågas — bara teamets medlemmar
 * når hit. Svaret bär ENDAST koordinationsdata (pseudonymt hubsCaseId + länk),
 * aldrig PII/innehåll (NEVER-SoR).
 *
 * GRACEFUL: okänt team / ingen groupfolder-pekare ⇒ tom lista, aldrig ett kast.
 */
class ArenderumTeamResourceProvider implements ITeamResourceProvider {
    /** Mapp-ikon (Material Design 'folder-lock', 24x24) — samma symbolspråk som kortets Ärenderum-knapp. */
    private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V8C22 6.9 21.1 6 20 6H12L10 4M19 13H18.5V11.5C18.5 10.1 17.4 9 16 9S13.5 10.1 13.5 11.5V13H13C12.4 13 12 13.4 12 14V17C12 17.6 12.4 18 13 18H19C19.6 18 20 17.6 20 17V14C20 13.4 19.6 13 19 13M17.3 13H14.7V11.5C14.7 10.8 15.3 10.2 16 10.2S17.3 10.8 17.3 11.5V13Z"/></svg>';

    /** Chatt-ikon (Material Design 'message-lock', 24x24) — ärendets säkra rum/möten. */
    private const CHAT_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2M16.5 13H16V11.5C16 10.1 14.9 9 13.5 9S11 10.1 11 11.5V13H10.5C10.2 13 10 13.2 10 13.5V16.5C10 16.8 10.2 17 10.5 17H16.5C16.8 17 17 16.8 17 16.5V13.5C17 13.2 16.8 13 16.5 13M15 13H12V11.5C12 10.7 12.7 10 13.5 10S15 10.7 15 11.5V13Z"/></svg>';

    public function __construct(
        private PekareMapper $pekareMapper,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'hubs_arende';
    }

    public function getName(): string {
        // Brand-regel: aldrig 'Nextcloud'/'Talk'/'Circles' i användarsynliga strängar.
        return 'Ärenderum';
    }

    public function getIconSvg(): string {
        return self::ICON_SVG;
    }

    /**
     * @return TeamResource[]
     */
    public function getSharedWith(string $teamId): array {
        $hubsCaseId = $this->caseForTeam($teamId);
        if ($hubsCaseId === null) {
            return [];
        }

        try {
            // Akten finns bara när R4 skapade en groupfolder (saga-originalet;
            // mount_point = hubsCaseId — M2: pseudonymt namn).
            if ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') === []) {
                return [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        $resurser = [
            new TeamResource(
                $this,
                $hubsCaseId,
                'Akten – ärendets dokument',
                $this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->linkTo('files', '') . '?dir=/' . rawurlencode($hubsCaseId),
                ),
                iconSvg: self::ICON_SVG,
            ),
        ];

        // Ärendets EXTRA Talk-rum ur motorns egen bokföring (pekare talk_room, 1:n) —
        // t.ex. säkra möten med medborgare, där teamet inte är deltagare och Talks
        // egen provider därför inte listar rummet. Primärrummet (SAGA-originalet,
        // äldsta pekaren) hoppas över: det listas redan av Talk via teamets
        // deltagande — dubblering undviks. Registret är sanningskällan; Talk-namnet
        // slås upp best-effort (annars neutral etikett).
        try {
            $rum = $this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room');
            usort($rum, static fn ($a, $b) => $a->getId() <=> $b->getId());
            array_shift($rum); // primärrummet — Talks provider äger det
            foreach ($rum as $p) {
                $token = $p->getObjektId();
                if ($token === '') {
                    continue;
                }
                $resurser[] = new TeamResource(
                    $this,
                    'talk-' . $token,
                    $this->talkRoomName($token) ?? 'Ärendets diskussionsrum',
                    $this->urlGenerator->getAbsoluteURL('/call/' . rawurlencode($token)),
                    iconSvg: self::CHAT_ICON_SVG,
                );
            }
        } catch (\Throwable $e) {
            // Graceful: rumslistningen får aldrig fälla team-sidan.
        }

        return $resurser;
    }

    public function isSharedWithTeam(string $teamId, string $resourceId): bool {
        $hubsCaseId = $this->caseForTeam($teamId);
        if ($hubsCaseId === null) {
            return false;
        }
        if ($resourceId === $hubsCaseId) {
            return true;
        }
        if (str_starts_with($resourceId, 'talk-')) {
            $token = substr($resourceId, 5);
            try {
                foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room') as $p) {
                    if ($p->getObjektId() === $token) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }

    /** Best-effort: rummets visningsnamn via Talk (spreed) — null om otillgängligt. */
    private function talkRoomName(string $token): ?string {
        try {
            if (!class_exists(\OCA\Talk\Manager::class)) {
                return null;
            }
            $manager = \OCP\Server::get(\OCA\Talk\Manager::class);
            $room = $manager->getRoomByToken($token);
            // getName() är rummets faktiska namn; getDisplayName('') utan användare
            // faller tillbaka till "Privat konversation" och undviks därför.
            $namn = $room->getName();
            return $namn !== '' ? $namn : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return string[]
     */
    public function getTeamsForResource(string $resourceId): array {
        try {
            $teams = [];
            foreach ($this->pekareMapper->findByCaseAndTyp($resourceId, 'team') as $p) {
                if ($p->getObjektId() !== '') {
                    $teams[] = $p->getObjektId();
                }
            }
            return $teams;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Resolve teamId (circle singleId) → hubsCaseId via motorns pekare, eller null. */
    private function caseForTeam(string $teamId): ?string {
        if ($teamId === '') {
            return null;
        }
        try {
            foreach ($this->pekareMapper->findByTypAndObjektId('team', $teamId) as $p) {
                return $p->getHubsCaseId();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('hubs_arende: team-resurs-uppslag misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'teamId' => $teamId,
            ]);
        }
        return null;
    }
}
