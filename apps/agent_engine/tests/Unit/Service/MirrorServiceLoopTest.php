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
use OCA\AgentEngine\Service\MirrorService;
use OCA\AgentEngine\Service\NotificationService;
use OCA\AgentEngine\Service\PiiFirewall;
use OCA\AgentEngine\Service\PushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Loop prevention is STRUCTURAL (§2.4): bot-authored comments are never
 * re-mirrored (actor filter), ⇄-marked comments are never re-mirrored
 * (marker), duplicates collapse on the idempotency key. A bot comment storm
 * must not self-amplify.
 */
class MirrorServiceLoopTest extends TestCase {
    private DeckApiClient&MockObject $deck;
    private CardLinkMapper&MockObject $links;
    private EngineEventMapper&MockObject $events;

    protected function setUp(): void {
        parent::setUp();
        $this->deck = $this->createMock(DeckApiClient::class);
        $this->links = $this->createMock(CardLinkMapper::class);
        $this->events = $this->createMock(EngineEventMapper::class);
    }

    private function service(): MirrorService {
        $config = $this->createMock(EngineConfig::class);
        $config->method('piiPatternsPath')->willReturn('');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_720_000_000);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn(null);

        return new MirrorService(
            $this->deck,
            $this->links,
            $this->events,
            $this->createMock(EnrolledBoardMapper::class),
            new PiiFirewall($config, new NullLogger()),
            $this->createMock(NotificationService::class),
            $config,
            $this->createMock(PushService::class),
            $userManager,
            $time,
            new NullLogger(),
        );
    }

    private function openLink(): CardLink {
        $link = new CardLink();
        $link->setId(12);
        $link->setOriginBoard(55);
        $link->setOriginCard(900);
        $link->setEngineBoard(7);
        $link->setEngineCard(4711);
        $link->setAgentCode('reb-claude');
        $link->setBotUid('bot-reb');
        $link->setOwnerUid('rebecca');
        $link->setRequesterUid('fredrik');
        $link->setReviewerUid('fredrik');
        $link->setState('open');
        $link->setPhase('working');
        return $link;
    }

    public function testBotAuthoredCommentIsNeverMirrored(): void {
        // Brake 1: every glue write is bot-authored ⇒ can never re-trigger.
        $this->deck->expects($this->never())->method('postComment');
        $this->events->expects($this->never())->method('claimKey');

        foreach (['bot-reb', 'bot-ada', 'bot-engine'] as $bot) {
            $this->service()->onOriginComment($this->openLink(), [
                'id' => 101,
                'actorId' => $bot,
                'message' => 'AGENT STATUS storm test',
                'timestamp' => 1_720_000_000,
            ]);
        }
        $this->addToAssertionCount(1); // the never()-expectations are the assertions
    }

    public function testMirrorMarkedCommentIsNeverReMirrored(): void {
        // Brake 2: ⇄ means "engine wrote this" — even if authored by a human
        // (copy-paste), it must not bounce.
        $this->deck->expects($this->never())->method('postComment');

        $this->service()->onOriginComment($this->openLink(), [
            'id' => 102,
            'actorId' => 'fredrik',
            'message' => '⇄ Från Rebecca (ursprungskortet, 09:31): "hej"',
            'timestamp' => 1_720_000_000,
        ]);
        $this->addToAssertionCount(1);
    }

    public function testClosedLinkIsNeverMirrored(): void {
        // Brake 3: events on non-live links are logged, never acted on.
        $this->deck->expects($this->never())->method('postComment');

        $link = $this->openLink();
        $link->setState('recalled');
        $link->setOpenKey(null);
        $this->service()->onOriginComment($link, [
            'id' => 103,
            'actorId' => 'fredrik',
            'message' => 'är du kvar?',
            'timestamp' => 1_720_000_000,
        ]);
        $this->addToAssertionCount(1);
    }

    public function testDuplicateDeliveryCollapsesToOneWrite(): void {
        // Brake 4: listener + sweep deliver the same comment id — the
        // idempotency key admits exactly one.
        $seen = [];
        $this->events->method('claimKey')
            ->willReturnCallback(static function (string $key) use (&$seen): bool {
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;
                return true;
            });
        $this->links->method('save')->willReturnArgument(0);

        $this->deck->expects($this->once())
            ->method('postComment')
            ->with(4711, $this->callback(
                static fn (string $msg): bool => str_starts_with($msg, '⇄ ')
                    && str_contains($msg, 'kan du ta med Q3-siffrorna?'),
            ))
            ->willReturn(1);

        $service = $this->service();
        $link = $this->openLink();
        $comment = [
            'id' => 104,
            'actorId' => 'fredrik',
            'message' => 'kan du ta med Q3-siffrorna?',
            'timestamp' => 1_720_000_000,
        ];
        $service->onOriginComment($link, $comment);   // listener delivery
        $service->onOriginComment($link, $comment);   // sweep delivery
    }

    public function testApprovalParserIsConservative(): void {
        $service = $this->service();
        $this->assertTrue($service->isApproval('ok'));
        $this->assertTrue($service->isApproval('OK, snyggt!'));
        $this->assertTrue($service->isApproval('godkänt'));
        $this->assertTrue($service->isApproval('Godkänn.'));
        // NOT approvals — anything else is rework feedback (worst case = one
        // extra cycle, never a false approval).
        $this->assertFalse($service->isApproval('okej men fixa rubriken'));
        $this->assertFalse($service->isApproval('det ser ok ut men ändra inledningen'));
        $this->assertFalse($service->isApproval(str_repeat('ok jättebra ', 10)));
        $this->assertFalse($service->isApproval('inte ok'));
    }
}
