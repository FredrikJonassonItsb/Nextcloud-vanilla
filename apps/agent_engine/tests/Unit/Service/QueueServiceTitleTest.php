<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Tests\Unit\Service;

use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\QueueService;
use OCA\AgentEngine\Service\TitleGrammar;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Title-grammar parsing (CONTRACTS §2, verbatim) + the server-side queue
 * filter: eligible = Agent Todo + agent-instructions + second bracket ==
 * agentCode, oldest first.
 */
class QueueServiceTitleTest extends TestCase {
    private DeckApiClient&MockObject $deck;
    private EngineEventMapper&MockObject $events;

    protected function setUp(): void {
        parent::setUp();
        $this->deck = $this->createMock(DeckApiClient::class);
        $this->events = $this->createMock(EngineEventMapper::class);
        $this->events->method('findByKey')->willReturn(null);
    }

    private function service(): QueueService {
        $config = $this->createMock(EngineConfig::class);
        $config->method('engineBoardId')->willReturn(7);
        return new QueueService($this->deck, $config, $this->events, new NullLogger());
    }

    // ---- TitleGrammar --------------------------------------------------- //

    public function testParseTaskTitle(): void {
        $parsed = TitleGrammar::parse('[agent instructions][reb-claude][task] Say hello from the queue');
        $this->assertNotNull($parsed);
        $this->assertSame('reb-claude', $parsed['audience']);
        $this->assertSame('task', $parsed['type']);
        $this->assertSame('Say hello from the queue', $parsed['title']);
    }

    public function testParseStandingTitles(): void {
        $parsed = TitleGrammar::parse('[agent instructions][all agents][standing_status] Agent Engine status ledger');
        $this->assertNotNull($parsed);
        $this->assertSame('all agents', $parsed['audience']);
        $this->assertSame('standing_status', $parsed['type']);

        $parsed = TitleGrammar::parse('[agent instructions][all agents][standing_routing] Agent routing map v1');
        $this->assertNotNull($parsed);
        $this->assertSame('standing_routing', $parsed['type']);
    }

    public function testParseRejectsNonGrammarTitles(): void {
        $this->assertNull(TitleGrammar::parse('Vanligt kort utan grammatik'));
        $this->assertNull(TitleGrammar::parse('[agent orders][reb-claude][task] fel prefix'));
        $this->assertNull(TitleGrammar::parse('[agent instructions][reb-claude] bara två grupper'));
        $this->assertNull(TitleGrammar::parse(''));
    }

    public function testIsTaskForMatchesExactAgentOnly(): void {
        $reb = TitleGrammar::parse('[agent instructions][reb-claude][task] x');
        $this->assertTrue(TitleGrammar::isTaskFor($reb, 'reb-claude'));
        $this->assertFalse(TitleGrammar::isTaskFor($reb, 'ada-claude'));
        // Standing cards are NEVER claimable tasks — even for 'all agents'.
        $standing = TitleGrammar::parse('[agent instructions][all agents][standing_skill] y');
        $this->assertFalse(TitleGrammar::isTaskFor($standing, 'reb-claude'));
        $this->assertFalse(TitleGrammar::isTaskFor(null, 'reb-claude'));
    }

    public function testBuildTaskTruncatesTo255(): void {
        $title = TitleGrammar::buildTask('reb-claude', str_repeat('å', 400));
        $this->assertLessThanOrEqual(255, mb_strlen($title));
        $this->assertStringStartsWith('[agent instructions][reb-claude][task] ', $title);
    }

    // ---- QueueService filtering ------------------------------------------ //

    public function testQueueFiltersByStackLabelAndAgentOldestFirst(): void {
        $label = [['id' => 1, 'title' => 'agent-instructions']];
        $this->deck->method('getStacks')->willReturn(['notModified' => false, 'etag' => '', 'stacks' => [
            [
                'id' => 10,
                'title' => 'Agent Todo',
                'cards' => [
                    // Newer reb task
                    ['id' => 202, 'title' => '[agent instructions][reb-claude][task] Nyare', 'labels' => $label, 'createdAt' => 2000],
                    // Oldest reb task → must be 'next'
                    ['id' => 201, 'title' => '[agent instructions][reb-claude][task] Äldst', 'labels' => $label, 'createdAt' => 1000],
                    // Other agent — filtered
                    ['id' => 203, 'title' => '[agent instructions][ada-claude][task] Annan agent', 'labels' => $label, 'createdAt' => 500],
                    // Grammar mismatch — filtered
                    ['id' => 204, 'title' => 'Handskrivet kort utan grammatik', 'labels' => $label, 'createdAt' => 100],
                    // Missing the label — filtered
                    ['id' => 205, 'title' => '[agent instructions][reb-claude][task] Utan label', 'labels' => [], 'createdAt' => 100],
                ],
            ],
            [
                'id' => 11,
                'title' => 'Standing',
                'cards' => [
                    ['id' => 206, 'title' => '[agent instructions][all agents][standing_status] Ledger', 'labels' => $label, 'createdAt' => 1],
                ],
            ],
            [
                'id' => 12,
                'title' => 'Agent Working',
                'cards' => [
                    // Already claimed by someone — wrong stack, filtered
                    ['id' => 207, 'title' => '[agent instructions][reb-claude][task] Pågår', 'labels' => $label, 'createdAt' => 50],
                ],
            ],
        ]]);

        $result = $this->service()->queue('reb-claude');

        $this->assertNotNull($result['next']);
        $this->assertSame(201, $result['next']['cardId']);
        $this->assertSame('Äldst', $result['next']['taskTitle']);
        $this->assertSame([201, 202], array_column($result['eligible'], 'cardId'));
        $this->assertSame([], $result['resumables']);
    }

    public function testBlockedCardWithAnswerIsResumable(): void {
        $label = [['id' => 1, 'title' => 'agent-instructions']];
        $this->deck->method('getStacks')->willReturn(['notModified' => false, 'etag' => '', 'stacks' => [
            [
                'id' => 13,
                'title' => 'Agent Needs Input',
                'cards' => [
                    ['id' => 301, 'title' => '[agent instructions][reb-claude][task] Väntar på svar', 'labels' => $label, 'createdAt' => 10],
                    ['id' => 302, 'title' => '[agent instructions][reb-claude][task] Obesvarad', 'labels' => $label, 'createdAt' => 20],
                ],
            ],
        ]]);
        $this->deck->method('getComments')->willReturnCallback(static function (int $cardId): array {
            if ($cardId === 301) {
                return [
                    // Mirrored origin answer AFTER the block ⇒ resumable.
                    ['id' => 3, 'actorId' => 'bot-engine', 'message' => '⇄ Från Fredrik (ursprungskortet, 09:31): "ta Q3"', 'creationDateTime' => '2026-07-04T10:00:00+00:00'],
                    ['id' => 2, 'actorId' => 'bot-reb', 'message' => "AGENT BLOCKED\nWhich quarter?", 'creationDateTime' => '2026-07-04T09:00:00+00:00'],
                ];
            }
            return [
                ['id' => 5, 'actorId' => 'bot-reb', 'message' => "AGENT BLOCKED\nWhich file?", 'creationDateTime' => '2026-07-04T09:00:00+00:00'],
            ];
        });

        $result = $this->service()->queue('reb-claude');

        $this->assertNull($result['next']);
        $this->assertCount(1, $result['resumables']);
        $this->assertSame(301, $result['resumables'][0]['cardId']);
        $this->assertSame('answer_on_card', $result['resumables'][0]['resumeReason']);
    }
}
