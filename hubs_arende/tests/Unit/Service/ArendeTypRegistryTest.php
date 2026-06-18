<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Db\ArendeTypMapper;
use OCA\HubsArende\Service\ArendeTypRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The datadriven ärendetyp-registry: idempotent seeding + the invariants every
 * one of the 8 config-rows must satisfy (NON-NULL commit_destination), plus the
 * two declared hooks (§2.5): kat 6 pre_saga_hook=diariefor_direkt and kat 8
 * post_commit_hook set.
 */
final class ArendeTypRegistryTest extends TestCase {
    private ArendeTypMapper&MockObject $mapper;
    private LoggerInterface&MockObject $logger;
    private ArendeTypRegistry $registry;

    protected function setUp(): void {
        parent::setUp();
        $this->mapper = $this->createMock(ArendeTypMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new ArendeTypRegistry($this->mapper, $this->logger);
    }

    // ================================================================== //
    //  seedDefaults idempotent — when every row already exists, no inserts
    // ================================================================== //

    public function testSeedDefaultsIsIdempotentWhenAllExist(): void {
        // exists() => true for every id: a re-run inserts nothing.
        $this->mapper->method('exists')->willReturn(true);
        $this->mapper->expects(self::never())->method('insert');

        self::assertSame(0, $this->registry->seedDefaults());
    }

    public function testSeedDefaultsInsertsTheEightRowsOnFirstRun(): void {
        // Fresh install: nothing exists yet -> all 8 rows are inserted exactly once.
        $this->mapper->method('exists')->willReturn(false);
        $this->mapper->expects(self::exactly(8))
            ->method('insert')
            ->willReturnArgument(0);

        self::assertSame(8, $this->registry->seedDefaults());
    }

    // ================================================================== //
    //  All 8 rows carry a NON-NULL commit_destination (the engine invariant)
    // ================================================================== //

    public function testAllSeededRowsHaveCommitDestination(): void {
        $inserted = $this->captureSeededRows();

        self::assertCount(8, $inserted, 'Expected exactly 8 seeded ärendetyper.');
        foreach ($inserted as $typ) {
            self::assertNotSame(
                '',
                $typ->getCommitDestination(),
                'commit_destination NOT NULL-invariant bruten för ' . $typ->getArendeTypId(),
            );
        }
    }

    // ================================================================== //
    //  Hooks (§2.5): kat 6 pre_saga_hook + kat 8 post_commit_hook
    // ================================================================== //

    public function testKat6RattsligtTvangHasDiariaforDirektPreSagaHook(): void {
        $rows = $this->indexByTypId($this->captureSeededRows());

        self::assertArrayHasKey('rattsligt_tvang', $rows);
        self::assertSame('diariefor_direkt', $rows['rattsligt_tvang']->getPreSagaHook());
        // Kat 6 commits directly to the diarium (omvänd ordning).
        self::assertSame('diarium', $rows['rattsligt_tvang']->getCommitDestination());
    }

    public function testKat8FamiljerattHasPostCommitHook(): void {
        $rows = $this->indexByTypId($this->captureSeededRows());

        self::assertArrayHasKey('familjeratt', $rows);
        self::assertNotNull($rows['familjeratt']->getPostCommitHook());
        self::assertSame('familjeratt_yttrande', $rows['familjeratt']->getPostCommitHook());
        self::assertSame('flerpartsarende', $rows['familjeratt']->getPartsModell());
    }

    public function testGetReturnsNullForUnknownTyp(): void {
        $this->mapper->method('findByTypId')
            ->with('finns_inte')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('nope'));

        self::assertNull($this->registry->get('finns_inte'));
    }

    public function testGetReturnsNullForEmptyId(): void {
        // Empty id short-circuits without touching the mapper.
        $this->mapper->expects(self::never())->method('findByTypId');
        self::assertNull($this->registry->get(''));
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /**
     * Drive seedDefaults with exists()=false and capture each inserted entity.
     *
     * @return list<ArendeTyp>
     */
    private function captureSeededRows(): array {
        $this->mapper->method('exists')->willReturn(false);

        /** @var list<ArendeTyp> $captured */
        $captured = [];
        $this->mapper->method('insert')->willReturnCallback(
            static function (ArendeTyp $typ) use (&$captured): ArendeTyp {
                $captured[] = $typ;
                return $typ;
            },
        );

        $this->registry->seedDefaults();
        return $captured;
    }

    /**
     * @param list<ArendeTyp> $rows
     * @return array<string, ArendeTyp>
     */
    private function indexByTypId(array $rows): array {
        $out = [];
        foreach ($rows as $typ) {
            $out[$typ->getArendeTypId()] = $typ;
        }
        return $out;
    }
}
