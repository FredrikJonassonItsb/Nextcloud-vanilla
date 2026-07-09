<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Db;

use OCA\HubsArende\Db\AiUtkast;
use OCA\HubsArende\Db\AiUtkastMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AiUtkastMapper — persistensen för AI-utkastregistret (SPEC-BRAIN-PER-ARENDE kap 8.0.4).
 *
 * Appen har av konvention inga DB-fixture-baserade mapper-tester (mappern övas i
 * praktiken via AiUtkastServiceTest med mockad mapper). Dessa fall är därför RENA
 * enhetstester: IDBConnection + query-buildern mockas och entiteterna hydreras på
 * riktigt via {@see AiUtkast::fromRow()} (QBMapper::mapRowToEntity). De låser
 * mapperns egna kontrakt utan att röra en databas:
 *
 *  - TABELLKONTRAKTET: getTableName() === 'hubs_arende_ai_utkast' (samma tabell som
 *    migrationen skapar och som GallringService/entiteten pekar på);
 *  - findByCaseId hydrerar riktiga AiUtkast-entiteter, nyast först (orderBy id DESC);
 *  - findById returnerar entiteten; okänt id ⇒ DoesNotExistException (kontraktet
 *    AiUtkastService::laddaForArende() förlitar sig på för H1-existensskyddet);
 *  - deleteByCaseId returnerar antalet raderade rader (idempotent gallringssvep —
 *    0 träffar är ett giltigt utfall, inget kast).
 */
final class AiUtkastMapperTest extends TestCase {
    private const CASE = 'caseid-aiu-00000001';

    private IDBConnection&MockObject $db;
    private IQueryBuilder&MockObject $qb;
    private AiUtkastMapper $mapper;

    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        // IExpressionBuilder kan INTE mockas i standalone-läget: dess klass-konstanter
        // pekar på Doctrine\DBAL (ej installerat utan NC-checkout, vilket är just varför
        // appen saknar mapper-tester). expr() saknar dessutom returtyp — därför räcker en
        // Doctrine-fri liten stub som bara exponerar eq(), det enda mappern anropar på den.
        $this->qb->method('expr')->willReturn(new class {
            public function eq(mixed $x, mixed $y, mixed $type = null): string {
                return 'kolumn = :p';
            }
        });
        $this->qb->method('createNamedParameter')->willReturn(':p');
        // Fluenta byggmetoder som vi INTE asserterar på returnerar $qb så kedjan håller.
        // (orderBy/delete stubbas per-test där deras argument asserteras.)
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();

        $this->mapper = new AiUtkastMapper($this->db);
    }

    // ================================================================== //
    //  Tabellkontrakt
    // ================================================================== //

    public function testTabellnamnetArKontraktet(): void {
        // En tyst tabellnamnstypo skulle bryta både migration, gallring och service.
        self::assertSame('hubs_arende_ai_utkast', $this->mapper->getTableName());
    }

    // ================================================================== //
    //  findByCaseId — hydrering + ordning
    // ================================================================== //

    public function testFindByCaseIdHydrerarEntiteterNyastForst(): void {
        // "nyast först" är HITL-listans kontrakt (SPEC 8.0.7 steg 2).
        $this->qb->expects($this->once())->method('orderBy')->with('id', 'DESC')->willReturnSelf();
        $this->qb->method('executeQuery')->willReturn($this->resultatMed([
            ['id' => 5, 'hubs_case_id' => self::CASE, 'funktion' => 'fn_draft_journal', 'status' => AiUtkast::STATUS_UTKAST],
            ['id' => 4, 'hubs_case_id' => self::CASE, 'funktion' => 'fn_lage', 'status' => AiUtkast::STATUS_GODKANT],
        ]));

        $rader = $this->mapper->findByCaseId(self::CASE);

        self::assertCount(2, $rader);
        self::assertContainsOnlyInstancesOf(AiUtkast::class, $rader);
        self::assertSame(5, $rader[0]->getId());
        self::assertSame(AiUtkast::STATUS_UTKAST, $rader[0]->getStatus());
        self::assertSame(4, $rader[1]->getId());
        self::assertSame(AiUtkast::STATUS_GODKANT, $rader[1]->getStatus());
    }

    public function testFindByCaseIdUtanTraffarGerTomLista(): void {
        $this->qb->method('orderBy')->willReturnSelf();
        $this->qb->method('executeQuery')->willReturn($this->resultatMed([]));

        self::assertSame([], $this->mapper->findByCaseId('okant-case'));
    }

    // ================================================================== //
    //  findById — hydrering + DoesNotExist
    // ================================================================== //

    public function testFindByIdReturnerarEntitet(): void {
        $this->qb->method('executeQuery')->willReturn($this->resultatMed([
            ['id' => 7, 'hubs_case_id' => self::CASE, 'funktion' => 'fn_draft_kommunicering', 'status' => AiUtkast::STATUS_UTKAST],
        ]));

        $utkast = $this->mapper->findById(7);

        self::assertInstanceOf(AiUtkast::class, $utkast);
        self::assertSame(7, $utkast->getId());
        self::assertSame(self::CASE, $utkast->getHubsCaseId());
        self::assertSame('fn_draft_kommunicering', $utkast->getFunktion());
    }

    public function testFindByIdOkantIdKastarDoesNotExist(): void {
        // Tom rad-uppsättning ⇒ QBMapper::findOneQuery kastar DoesNotExistException.
        $this->qb->method('executeQuery')->willReturn($this->resultatMed([]));

        $this->expectException(DoesNotExistException::class);
        $this->mapper->findById(999);
    }

    // ================================================================== //
    //  deleteByCaseId — antal raderade (idempotent gallring)
    // ================================================================== //

    public function testDeleteByCaseIdRaderarPaRattTabellOchReturnerarAntal(): void {
        $this->qb->expects($this->once())->method('delete')->with('hubs_arende_ai_utkast')->willReturnSelf();
        $this->qb->expects($this->once())->method('executeStatement')->willReturn(3);

        self::assertSame(3, $this->mapper->deleteByCaseId(self::CASE));
    }

    public function testDeleteByCaseIdNollTraffarArGiltigt(): void {
        // Idempotent teardown: inga rader att radera ⇒ 0, aldrig ett kast.
        $this->qb->method('delete')->willReturnSelf();
        $this->qb->method('executeStatement')->willReturn(0);

        self::assertSame(0, $this->mapper->deleteByCaseId('redan-gallrat-case'));
    }

    // ------------------------------------------------------------------ //

    /**
     * Bygg en IResult-mock vars fetch() lämnar ut raderna i tur och ordning och
     * därefter false — precis som QBMapper::findEntities()/findOneQuery() itererar.
     *
     * @param array<int,array<string,mixed>> $rader
     */
    private function resultatMed(array $rader): IResult&MockObject {
        $result = $this->createMock(IResult::class);
        $ko = $rader;
        $result->method('fetch')->willReturnCallback(static function () use (&$ko) {
            if ($ko === []) {
                return false;
            }
            return array_shift($ko);
        });
        $result->method('closeCursor')->willReturn(true);
        return $result;
    }
}
