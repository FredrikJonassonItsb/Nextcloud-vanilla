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

        return [
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
    }

    public function isSharedWithTeam(string $teamId, string $resourceId): bool {
        return $this->caseForTeam($teamId) === $resourceId;
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
