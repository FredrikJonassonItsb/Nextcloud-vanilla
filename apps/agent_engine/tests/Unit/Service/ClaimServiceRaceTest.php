<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Tests\Unit\Service;

use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Db\EngineEvent;
use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Exception\ClaimConflictException;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Service\ClaimService;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\MirrorService;
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The smoke-03 scenario as a unit test: two concurrent claims on the same
 * engine card ⇒ exactly one winner (Deck ops run once), the loser gets
 * 409 {claimedBy} and triggers NO Deck mutations.
 */
class ClaimServiceRaceTest extends TestCase {
    private const CARD_ID = 217;
    private const BOARD_ID = 7;

    private IDBConnection $db;
    private DeckApiClient $deck;
    private EngineEventMapper $events;
    private CardLinkMapper $links;
    private EngineConfig $config;
    private MirrorService $mirror;

    protected function setUp(): void {
        parent::setUp();
        $this->db = $this->createMock(IDBConnection::class);
        $this->deck = $this->createMock(DeckApiClient::class);
        $this->events = $this->createMock(EngineEventMapper::class);
        $this->links = $this->createMock(CardLinkMapper::class);
        $this->config = $this->createMock(EngineConfig::class);
        $this->mirror = $this->createMock(MirrorService::class);

        $this->config->method('engineBoardId')->willReturn(self::BOARD_ID);
        $this->links->method('findOpenByEngineCard')->willReturn(null);
        $this->deck->method('findCard')->willReturn($this->eligibleCard());
        $this->deck->method('findStackIdByTitle')->willReturn(42);
    }

    private function service(): ClaimService {
        return new ClaimService(
            $this->db,
            $this->deck,
            $this->events,
            $this->links,
            $this->config,
            $this->mirror,
            new NullLogger(),
        );
    }

    /** @return array{card:array<string,mixed>,stackId:int,stackTitle:string} */
    private function eligibleCard(): array {
        return [
            'card' => [
                'id' => self::CARD_ID,
                'title' => '[agent instructions][reb-claude][task] Say hello from the queue',
                'labels' => [['id' => 1, 'title' => 'agent-instructions']],
            ],
            'stackId' => 10,
            'stackTitle' => 'Agent Todo',
        ];
    }

    private function uniqueViolation(): DBException {
        return new class('duplicate key') extends DBException {
            public function getReason(): ?int {
                return DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
            }
        };
    }

    public function testTwoConcurrentClaimsOneWinnerOneConflict(): void {
        // The mutex: first insert wins, second collides on the unique key.
        $call = 0;
        $violation = $this->uniqueViolation();
        $this->events->method('insertKey')
            ->willReturnCallback(function () use (&$call, $violation): EngineEvent {
                $call++;
                if ($call === 1) {
                    return new EngineEvent();
                }
                throw $violation;
            });

        // 409 body: who holds the row.
        $held = new EngineEvent();
        $held->setPayload(json_encode(['agentCode' => 'reb-claude']));
        $this->events->method('findByKey')->willReturn($held);

        // Deck mutations happen EXACTLY once across both claims.
        $this->deck->expects($this->once())
            ->method('moveCard')
            ->with(self::BOARD_ID, 10, self::CARD_ID, 42);
        $this->deck->expects($this->once())
            ->method('postComment')
            ->with(self::CARD_ID, 'AGENT CLAIMED');

        // Winner commits; loser rolls back.
        $this->db->expects($this->exactly(2))->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        $service = $this->service();

        // Claim 1 — reb wins.
        $result = $service->claim(self::CARD_ID, 'reb-claude');
        $this->assertSame(self::CARD_ID, $result['cardId']);
        $this->assertSame(
            '[agent instructions][reb-claude][task] Say hello from the queue',
            $result['reread']['title'],
        );

        // Claim 2 — the "simultaneous" loser (same Todo snapshot) gets 409.
        try {
            $service->claim(self::CARD_ID, 'reb-claude');
            $this->fail('expected ClaimConflictException');
        } catch (ClaimConflictException $e) {
            $this->assertSame('reb-claude', $e->getClaimedBy());
        }
    }

    public function testWrongAgentCodeIs422BeforeAnyMutex(): void {
        $this->events->expects($this->never())->method('insertKey');
        $this->deck->expects($this->never())->method('moveCard');
        $this->db->expects($this->never())->method('beginTransaction');

        $this->expectException(NotEligibleException::class);
        $this->service()->claim(self::CARD_ID, 'ada-claude');
    }

    public function testWrongStackIs422(): void {
        $this->deck = $this->createMock(DeckApiClient::class);
        $located = $this->eligibleCard();
        $located['stackTitle'] = 'Agent Working';
        $this->deck->method('findCard')->willReturn($located);
        $this->deck->expects($this->never())->method('moveCard');

        $this->expectException(NotEligibleException::class);
        $this->service()->claim(self::CARD_ID, 'reb-claude');
    }
}
