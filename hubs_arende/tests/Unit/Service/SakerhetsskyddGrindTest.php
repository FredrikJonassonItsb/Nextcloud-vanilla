<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fail-closed contract for the säkerhetsskydds-/visselblåsnings-grind (R0).
 *
 * The gate's whole reason for existing is that the DEFAULT is to REJECT: empty
 * input, any keyword/struktur-signal, and any detector error must all isolate the
 * inflow. Only an explicitly clean row may pass (avvisad=false).
 */
final class SakerhetsskyddGrindTest extends TestCase {
    private LoggerInterface&MockObject $logger;
    private ArendeMapper&MockObject $arendeMapper;
    private SakerhetsskyddGrind $grind;

    protected function setUp(): void {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->grind = new SakerhetsskyddGrind($this->logger, $this->arendeMapper);
    }

    public function testEmptyRowFailsClosed(): void {
        $result = $this->grind->evaluate([]);

        self::assertTrue($result['avvisad']);
        self::assertSame(SakerhetsskyddGrind::REASON_INPUT_SAKNAS, $result['reason']);
        self::assertSame('karantan', $result['kvitto']['commitDestination']);
    }

    public function testSakerhetsskyddKeywordIsRejected(): void {
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-kw-1',
            'subject' => 'Handlar om rikets säkerhet och totalförsvar',
            'body' => 'Bifogat material.',
        ]);

        self::assertTrue($result['avvisad']);
        self::assertSame(SakerhetsskyddGrind::REASON_SAKERHETSSKYDD, $result['reason']);
        self::assertSame(SakerhetsskyddGrind::IND_SAKERHETSSKYDD, $result['indikator']);
    }

    public function testStructuredSakerhetsklassHemligIsRejected(): void {
        // Structured signal is authoritative — sdkFields.sakerhetsklass=hemlig.
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-struct-1',
            'subject' => 'Helt vanligt ämne utan nyckelord',
            'sdkFields' => ['sakerhetsklass' => 'hemlig'],
        ]);

        self::assertTrue($result['avvisad']);
        self::assertSame(SakerhetsskyddGrind::REASON_SAKERHETSSKYDD, $result['reason']);
        self::assertSame(SakerhetsskyddGrind::IND_SAKERHETSSKYDD, $result['indikator']);
    }

    public function testVisselblasningIsRejected(): void {
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-vb-1',
            'subject' => 'Anmälan via visselblåsarfunktionen',
            'body' => 'Vill rapportera missförhållanden.',
        ]);

        self::assertTrue($result['avvisad']);
        self::assertSame(SakerhetsskyddGrind::REASON_VISSELBLASNING, $result['reason']);
        self::assertSame(SakerhetsskyddGrind::IND_VISSELBLASNING, $result['indikator']);
    }

    public function testCleanOrosanmalanPasses(): void {
        // A plain orosanmälan with no säkerhets-/visselblåsnings-signal must pass.
        $this->arendeMapper->method('findByConversationId')->willReturn(null);

        $result = $this->grind->evaluate([
            'conversationId' => 'conv-clean-1',
            'subject' => 'Orosanmälan gällande barn',
            'from' => 'forskola@example.se',
            'body' => 'Vi är oroliga för ett barns hemförhållanden.',
            'arendeTyp' => 'orosanmalan',
        ]);

        self::assertFalse($result['avvisad']);
        self::assertSame(SakerhetsskyddGrind::REASON_OK, $result['reason']);
        self::assertSame(SakerhetsskyddGrind::IND_NONE, $result['indikator']);
        self::assertFalse($result['retroaktiv']);
    }

    public function testRejectionFlagsRetroaktivWhenCaseAlreadyExists(): void {
        // An indicator arriving on a conversation that already produced a case
        // must flag retroaktiv so the caller quarantines it.
        $existing = new \OCA\HubsArende\Db\Arende();
        $existing->setHubsCaseId('caseid-existing');
        $this->arendeMapper->method('findByConversationId')
            ->with('conv-late')->willReturn($existing);

        $result = $this->grind->evaluate([
            'conversationId' => 'conv-late',
            'subject' => 'Nu visar det sig vara säkerhetsskyddsklassificerad',
        ]);

        self::assertTrue($result['avvisad']);
        self::assertTrue($result['retroaktiv']);
    }
}
