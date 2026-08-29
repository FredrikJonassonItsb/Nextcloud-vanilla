<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * STANDALONE-SHIM: appens tester körs i två lägen (tests/bootstrap.php) — i en riktig
 * NC-checkout (mode 1, Doctrine finns) och fristående (mode 2, endast nextcloud/ocp).
 * `OCP\DB\QueryBuilder\IQueryBuilder` initierar sina PARAM_*-konstanter ur Doctrine-typer
 * (`ParameterType`/`ArrayParameterType`/`Types`), som INTE ingår i nextcloud/ocp. För att
 * kunna mocka `IDBConnection` fristående deklareras minimala stubbar HÄR — guardade med
 * class_exists(...,false) så de ALDRIG kolliderar när Doctrine finns (mode 1). Värdena är
 * irrelevanta (ingen riktig SQL körs); endast laddbarheten behövs för mock-generering.
 */

namespace Doctrine\DBAL {
    if (!\class_exists(ParameterType::class, false)) {
        class ParameterType {
            public const NULL = 0;
            public const INTEGER = 1;
            public const STRING = 2;
            public const LARGE_OBJECT = 3;
            public const BOOLEAN = 5;
            public const BINARY = 16;
            public const ASCII = 17;
        }
    }
    if (!\class_exists(ArrayParameterType::class, false)) {
        class ArrayParameterType {
            public const INTEGER = 101;
            public const STRING = 102;
            public const ASCII = 117;
            public const BINARY = 116;
        }
    }
}

namespace Doctrine\DBAL\Types {
    if (!\class_exists(Types::class, false)) {
        class Types {
            public const BOOLEAN = 'boolean';
            public const DATE_MUTABLE = 'date';
            public const DATE_IMMUTABLE = 'date_immutable';
            public const DATETIME_MUTABLE = 'datetime';
            public const DATETIME_IMMUTABLE = 'datetime_immutable';
            public const DATETIMETZ_MUTABLE = 'datetimetz';
            public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
            public const TIME_MUTABLE = 'time';
        }
    }
}

namespace OCA\HubsArende\Tests\Unit\Brain {

    use OCA\HubsArende\Service\Brain\BrainProvisionRetryService;
    use OCP\AppFramework\Utility\ITimeFactory;
    use OCP\DB\Exception as DBException;
    use OCP\IDBConnection;
    use PHPUnit\Framework\MockObject\MockObject;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\LoggerInterface;

    /**
     * BrainProvisionRetryService — den durabla retry-kön (kap 3.3).
     *
     * Verifierar den bindande IDEMPOTENSEN: enqueue använder `insertIfNotExist` med
     * jämförelse på PRIMÄRNYCKELN `hubs_case_id` ⇒ en om-enqueue ger ALDRIG en dubblettrad
     * eller nollställer försöksräknaren, och ett unik-brott sväljs (idempotent-kapp).
     *
     * De QueryBuilder-baserade skrivvägarna (neutralisera/markKlar/schemalaggAterforsok)
     * täcks BETEENDEMÄSSIGT av {@see BrainProvisionRetryJobTest} (som mockar denna tjänst) —
     * de kan inte enhetstestas fristående eftersom mock av `IQueryBuilder`/`IExpressionBuilder`
     * drar in Doctrine-DBAL som inte ingår i nextcloud/ocp.
     */
    final class BrainProvisionRetryServiceTest extends TestCase {
        private const CASE = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        private IDBConnection&MockObject $db;
        private ITimeFactory&MockObject $time;
        private LoggerInterface&MockObject $logger;
        private BrainProvisionRetryService $service;

        protected function setUp(): void {
            parent::setUp();
            $this->db = $this->createMock(IDBConnection::class);
            $this->time = $this->createMock(ITimeFactory::class);
            $this->time->method('getTime')->willReturn(1_700_000_000);
            $this->logger = $this->createMock(LoggerInterface::class);
            $this->service = new BrainProvisionRetryService($this->db, $this->time, $this->logger);
        }

        /** Idempotent enqueue: insertIfNotExist på PK, status=pending, förfaller direkt. */
        public function testEnqueueAnvanderInsertIfNotExistPaPrimarnyckel(): void {
            $this->db->expects(self::once())
                ->method('insertIfNotExist')
                ->with(
                    '*PREFIX*' . BrainProvisionRetryService::TABLE,
                    self::callback(function (array $input): bool {
                        self::assertSame(self::CASE, $input['hubs_case_id']);
                        self::assertSame(BrainProvisionRetryService::STATUS_PENDING, $input['status']);
                        self::assertSame('orosanmalan', $input['arende_typ']);
                        self::assertSame(0, $input['forsok']);
                        self::assertSame(1_700_000_000, $input['nasta_forsok']);
                        self::assertNull($input['sista_forsok']);
                        return true;
                    }),
                    ['hubs_case_id'],
                )
                ->willReturn(1);

            $this->service->enqueue(self::CASE, 'orosanmalan');
        }

        /** En samtidig/upprepad enqueue som ger unik-brott sväljs (idempotent-kapp). */
        public function testEnqueueSvaljerUnikBrott(): void {
            $this->db->method('insertIfNotExist')->willThrowException(new DBException('duplicate key'));

            // Får INTE kasta.
            $this->service->enqueue(self::CASE, 'orosanmalan');
            self::assertTrue(true);
        }
    }
}
