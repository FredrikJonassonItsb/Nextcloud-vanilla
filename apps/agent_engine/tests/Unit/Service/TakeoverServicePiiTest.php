<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Tests\Unit\Service;

use OCA\AgentEngine\Db\CardLink;
use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Db\EnrolledBoardMapper;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\LedgerService;
use OCA\AgentEngine\Service\MirrorService;
use OCA\AgentEngine\Service\NotificationService;
use OCA\AgentEngine\Service\PiiFirewall;
use OCA\AgentEngine\Service\PushService;
use OCA\AgentEngine\Service\RecallService;
use OCA\AgentEngine\Service\TakeoverService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * §2.3 step 1: the PII firewall runs FIRST on the copy path. A hit means the
 * refusal path — bot unassigned, ⇄ refusal comment, notification, refused
 * audit row — and NEVER an engine card.
 */
class TakeoverServicePiiTest extends TestCase {
    private const BOARD_ID = 55;
    private const STACK_ID = 3;
    private const CARD_ID = 900;

    private DeckApiClient&MockObject $deck;
    private CardLinkMapper&MockObject $links;
    private EnrolledBoardMapper&MockObject $boards;
    private EngineEventMapper&MockObject $events;
    private MirrorService&MockObject $mirror;
    private RecallService&MockObject $recall;
    private LedgerService&MockObject $ledger;
    private NotificationService&MockObject $notifications;
    private EngineConfig&MockObject $config;
    private PushService&MockObject $push;

    protected function setUp(): void {
        parent::setUp();
        $this->deck = $this->createMock(DeckApiClient::class);
        $this->links = $this->createMock(CardLinkMapper::class);
        $this->boards = $this->createMock(EnrolledBoardMapper::class);
        $this->events = $this->createMock(EngineEventMapper::class);
        $this->mirror = $this->createMock(MirrorService::class);
        $this->recall = $this->createMock(RecallService::class);
        $this->ledger = $this->createMock(LedgerService::class);
        $this->notifications = $this->createMock(NotificationService::class);
        $this->config = $this->createMock(EngineConfig::class);
        $this->push = $this->createMock(PushService::class);

        $this->config->method('engineBoardId')->willReturn(7);
        $this->config->method('ownerForAgentCode')->willReturn('rebecca');
        $this->config->method('piiPatternsPath')->willReturn('');
        $this->events->method('claimKey')->willReturn(true);
    }

    private function service(): TakeoverService {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_720_000_000);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn(null);

        return new TakeoverService(
            $this->deck,
            $this->links,
            $this->boards,
            $this->events,
            // REAL firewall — the test exercises the actual CONTRACTS §3 regexes.
            new PiiFirewall($this->config, new NullLogger()),
            $this->mirror,
            $this->recall,
            $this->ledger,
            $this->notifications,
            $this->config,
            $this->push,
            $userManager,
            $time,
            new NullLogger(),
        );
    }

    public function testPersonnummerInDescriptionRefusesTakeover(): void {
        $card = [
            'id' => self::CARD_ID,
            'title' => 'Följ upp klientmötet',
            'description' => 'Ring klienten, personnummer 19850312-1234, om onsdag.',
            'assignedUsers' => [['participant' => ['uid' => 'bot-reb']]],
        ];
        $this->deck->method('getAttachmentNames')->willReturn([]);

        // Refusal path — and NOTHING else:
        $this->deck->expects($this->once())
            ->method('unassignUser')
            ->with(self::BOARD_ID, self::STACK_ID, self::CARD_ID, 'bot-reb');
        $this->deck->expects($this->once())
            ->method('postComment')
            ->with(self::CARD_ID, $this->callback(
                static fn (string $msg): bool => str_starts_with($msg, '⇄ ')
                    && str_contains($msg, 'PII/secrets'),
            ));
        $this->deck->expects($this->never())->method('createCard');
        $this->links->expects($this->never())->method('insertOpen');
        $this->push->expects($this->never())->method('wake');

        // Audit row with state='refused', open_key NULL.
        $this->links->expects($this->once())
            ->method('insert')
            ->with($this->callback(
                static fn (CardLink $link): bool => $link->getState() === 'refused'
                    && $link->getOpenKey() === null
                    && $link->getOriginCard() === self::CARD_ID,
            ))
            ->willReturnArgument(0);

        // The assigner is told — never a silent drop.
        $this->notifications->expects($this->once())
            ->method('notify')
            ->with('rebecca', NotificationService::SUBJECT_REFUSED, $this->anything(), (string)self::CARD_ID);

        $this->service()->takeover(self::BOARD_ID, self::STACK_ID, $card, 'bot-reb', 'rebecca');
    }

    public function testSecretInAttachmentNameRefusesTakeover(): void {
        $card = [
            'id' => self::CARD_ID,
            'title' => 'Deploy-nyckelrotation',
            'description' => 'Helt ren beskrivning.',
            'assignedUsers' => [['participant' => ['uid' => 'bot-reb']]],
        ];
        $this->deck->method('getAttachmentNames')->willReturn(['backup-sk-ant-api03-secret.txt']);

        $this->deck->expects($this->once())->method('unassignUser');
        $this->deck->expects($this->never())->method('createCard');
        $this->links->expects($this->never())->method('insertOpen');
        $this->links->expects($this->once())->method('insert')->willReturnArgument(0);

        $this->service()->takeover(self::BOARD_ID, self::STACK_ID, $card, 'bot-reb', 'rebecca');
    }

    public function testCleanCardPassesTheFirewallAndCreatesTheEngineCard(): void {
        $card = [
            'id' => self::CARD_ID,
            'title' => 'Uppdatera kunddokumentationen',
            'description' => 'Se checklistan. Inget känsligt här.',
            'assignedUsers' => [['participant' => ['uid' => 'bot-reb']]],
            'duedate' => null,
        ];
        $this->deck->method('getAttachmentNames')->willReturn([]);
        $this->deck->method('findStackIdByTitle')->willReturn(11);
        $this->deck->method('resolveLabelId')->willReturn(4);
        $this->ledger->method('presence')->willReturn(['heartbeat' => 1_719_999_900, 'paused' => false]);
        $this->config->method('heartbeatStaleMinutes')->willReturn(60);

        $inserted = null;
        $this->links->expects($this->once())
            ->method('insertOpen')
            ->willReturnCallback(static function (CardLink $link) use (&$inserted): CardLink {
                $link->setId(1);
                $inserted = $link;
                return $link;
            });
        $this->links->expects($this->never())->method('deleteById');
        $this->deck->expects($this->once())->method('createCard')->with(
            7,
            11,
            $this->callback(static fn (string $title): bool => str_starts_with(
                $title,
                '[agent instructions][reb-claude][task] ',
            )),
            $this->callback(static fn (string $desc): bool => str_contains($desc, '## Boundaries')
                && str_contains($desc, 'Draft-only.')
                && str_contains($desc, '## Requester')),
            null,
        )->willReturn(['cardId' => 4711]);
        $this->push->expects($this->once())->method('wake')->with('reb-claude');

        $this->service()->takeover(self::BOARD_ID, self::STACK_ID, $card, 'bot-reb', 'rebecca');
        $this->assertNotNull($inserted);
        $this->assertSame('reb-claude', $inserted->getAgentCode());
        $this->assertSame('rebecca', $inserted->getReviewerUid());
    }
}
