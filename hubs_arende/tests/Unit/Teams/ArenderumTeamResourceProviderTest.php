<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Teams;

use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Teams\ArenderumTeamResourceProvider;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the team-resource provider: AKTEN (ärenderummets groupfolder) på
 * ärendets TEAM-sida. Uppslag går uteslutande via motorns pekare (team →
 * hubsCaseId → groupfolder) — svaret bär endast pseudonym koordinationsdata,
 * aldrig PII (NEVER-SoR). Okänt team / ingen akt ⇒ tomt, aldrig ett kast.
 */
final class ArenderumTeamResourceProviderTest extends TestCase {
    private PekareMapper&MockObject $pekareMapper;
    private IURLGenerator&MockObject $urlGenerator;
    private LoggerInterface&MockObject $logger;
    private ArenderumTeamResourceProvider $provider;

    protected function setUp(): void {
        parent::setUp();
        $this->pekareMapper = $this->createMock(PekareMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlGenerator->method('linkTo')->willReturn('/apps/files/');
        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(static fn (string $p): string => 'https://hubs.example' . $p);

        $this->provider = new ArenderumTeamResourceProvider(
            $this->pekareMapper,
            $this->urlGenerator,
            $this->logger,
        );
    }

    private function pekare(string $caseId, string $typ, string $objektId): Pekare {
        $p = new Pekare();
        $p->setHubsCaseId($caseId);
        $p->setObjektTyp($typ);
        $p->setObjektId($objektId);
        return $p;
    }

    public function testGetSharedWithResolvesAktenViaPekare(): void {
        $this->pekareMapper->method('findByTypAndObjektId')
            ->with('team', 'team-single-id')
            ->willReturn([$this->pekare('case-uuid-1', 'team', 'team-single-id')]);
        $this->pekareMapper->method('findByCaseAndTyp')
            ->with('case-uuid-1', 'groupfolder')
            ->willReturn([$this->pekare('case-uuid-1', 'groupfolder', '42')]);

        $resources = $this->provider->getSharedWith('team-single-id');

        self::assertCount(1, $resources);
        self::assertSame('case-uuid-1', $resources[0]->getId());
        self::assertSame('Akten – ärendets dokument', $resources[0]->getLabel());
        // Absolut URL till aktens mount (mount_point = pseudonymt hubsCaseId).
        self::assertSame('https://hubs.example/apps/files/?dir=/case-uuid-1', $resources[0]->getUrl());
    }

    public function testUnknownTeamYieldsEmptyNeverAThrow(): void {
        $this->pekareMapper->method('findByTypAndObjektId')->willReturn([]);

        self::assertSame([], $this->provider->getSharedWith('okant-team'));
        self::assertSame([], $this->provider->getSharedWith(''));
    }

    public function testCaseWithoutGroupfolderYieldsEmpty(): void {
        // Team finns men R4 skapade aldrig en akt (graceful skip) ⇒ inga resurser.
        $this->pekareMapper->method('findByTypAndObjektId')
            ->willReturn([$this->pekare('case-uuid-2', 'team', 'team-x')]);
        $this->pekareMapper->method('findByCaseAndTyp')
            ->with('case-uuid-2', 'groupfolder')
            ->willReturn([]);

        self::assertSame([], $this->provider->getSharedWith('team-x'));
    }

    public function testIsSharedWithTeamMatchesOnCaseId(): void {
        $this->pekareMapper->method('findByTypAndObjektId')
            ->willReturn([$this->pekare('case-uuid-3', 'team', 'team-y')]);

        self::assertTrue($this->provider->isSharedWithTeam('team-y', 'case-uuid-3'));
        self::assertFalse($this->provider->isSharedWithTeam('team-y', 'annat-case'));
    }

    public function testGetTeamsForResourceReturnsTeamPekare(): void {
        $this->pekareMapper->method('findByCaseAndTyp')
            ->with('case-uuid-4', 'team')
            ->willReturn([$this->pekare('case-uuid-4', 'team', 'team-z')]);

        self::assertSame(['team-z'], $this->provider->getTeamsForResource('case-uuid-4'));
    }
}
