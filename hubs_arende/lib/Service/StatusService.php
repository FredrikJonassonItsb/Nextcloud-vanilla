<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Db\ArendeMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Read-only, PII-FREE status of the ärende-motor for the admin status page and the
 * `occ hubs_arende:status` command.
 *
 * Returns ONLY aggregates (counts, groupings) and configuration (version, per-port
 * integration mode, the ärendetyp config-rows). It NEVER reads or returns objektRef,
 * triageRef, dnr or any case content — there is no per-case data here, only numbers
 * and the datadrivna typ-registret. Safe to render on an admin-only page.
 */
class StatusService {
    private const APP_ID = 'hubs_arende';

    /** The harness ports whose INTEGRATION_MODE (stub|live) is surfaced. */
    private const PORTAR = ['facksystem', 'signering', 'ediarium', 'folkbokforing'];

    public function __construct(
        private ArendeMapper $arendeMapper,
        private ArendeTypRegistry $typRegistry,
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ITimeFactory $timeFactory,
    ) {
    }

    /**
     * @return array<string,mixed> PII-free status snapshot.
     */
    public function status(): array {
        $now = $this->timeFactory->getDateTime();

        $integrationMode = [];
        foreach (self::PORTAR as $port) {
            $integrationMode[$port] = $this->appConfig->getAppValueString(
                Application::INTEGRATION_MODE_PREFIX . $port,
                'stub',
            );
        }

        $typer = [];
        foreach ($this->typRegistry->all() as $typ) {
            $typer[] = [
                'id' => $typ->getArendeTypId(),
                'displayName' => $typ->getDisplayName(),
                'commitDestination' => $typ->getCommitDestination(),
                'preSagaHook' => $typ->getPreSagaHook(),
                'postCommitHook' => $typ->getPostCommitHook(),
            ];
        }

        return [
            'version' => $this->appManager->getAppVersion(self::APP_ID),
            'integrationMode' => $integrationMode,
            'totalCases' => $this->arendeMapper->countAll(),
            'perSteg' => $this->arendeMapper->countByColumn('steg'),
            'perStatus' => $this->arendeMapper->countByColumn('status'),
            'perProvenance' => $this->arendeMapper->countByColumn('provenance_state'),
            'perCommitDestination' => $this->arendeMapper->countByColumn('commit_destination'),
            'arendetyper' => $typer,
            'gallringsbaraPending' => $this->arendeMapper->countGallringsbara($now),
        ];
    }
}
