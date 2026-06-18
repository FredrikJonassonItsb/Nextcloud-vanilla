<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Integration;

use OCA\HubsArende\Integration\Port\Exception\IntegrationException;
use OCA\HubsArende\Integration\Port\FacksystemCommitPort;
use OCA\HubsArende\Service\FacksystemCommitService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * MODUL-FAILCLOSED: FacksystemCommitService::resolveModul must NOT silently default
 * an empty frends_modul to a clinical 'ifo_barn'. An empty modul means 'inherit the
 * host/beslut case's modul' (komplettering/verkställighet, frendsModul=null) — and
 * the engine must fail-closed rather than misroute into the wrong Treserva-modul
 * (felrouting = sekretessincident, spec §7.1).
 */
final class FacksystemCommitServiceModulTest extends TestCase {
    private const HUBS_CASE_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private FacksystemCommitPort&MockObject $port;
    private FacksystemCommitService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->port = $this->createMock(FacksystemCommitPort::class);
        $this->appConfig->method('getAppValueString')->willReturn('stub');
        $this->service = new FacksystemCommitService($this->appConfig, $this->logger, $this->port);
    }

    public function testCommitWithoutFrendsModulFailsClosed(): void {
        // No frends_modul on a facksystem-committable destination ⇒ fail-closed,
        // never a guessed modul; the port is never even reached.
        $this->port->expects(self::never())->method('commit');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('frends_modul saknas');

        $this->service->commit(self::HUBS_CASE_ID, [
            'commit_destination' => 'facksystem',
            'arendetyp' => 'verkstallighet',
        ]);
    }

    public function testCommitWithExplicitModulRoutesToThatModul(): void {
        // An explicit frends_modul still routes to exactly that modul (regression).
        $kvitto = [
            'ok' => true,
            'dnr' => '2026-FAM-0001',
            'committedAt' => '2026-06-18T08:00:00Z',
            'gallrasDatum' => '2026-09-16',
            'verifierad' => true,
            'hubsCaseId' => self::HUBS_CASE_ID,
            'modul' => 'familjeratt',
        ];
        $this->port->expects(self::once())
            ->method('commit')
            ->with(self::HUBS_CASE_ID, 'familjeratt', self::isType('array'))
            ->willReturn($kvitto);

        $result = $this->service->commit(self::HUBS_CASE_ID, [
            'commit_destination' => 'facksystem',
            'frends_modul' => 'familjeratt',
            'arendetyp' => 'familjeratt',
        ]);

        self::assertSame($kvitto, $result);
        self::assertSame('familjeratt', $result['modul']);
    }
}
