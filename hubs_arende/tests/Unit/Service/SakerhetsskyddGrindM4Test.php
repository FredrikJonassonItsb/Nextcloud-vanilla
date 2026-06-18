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
 * M4 — detector must catch class markings under more envelope keys, fail-closed.
 *
 * CONTRACT (granskningsrapport M4, "Åtgärd" + explicit unit-test line "handlingskod=
 * HEMLIG utan nyckelord → avvisad=true"): detectIndicator() currently reads a
 * class signal only from sdkFields.sakerhetsklass/securityClass. A class marking
 * under another known envelope key — `handlingskod`, `classification`,
 * `x-protective-marking` — slips through to IND_NONE today. The fix treats the
 * PRESENCE of a class/handlingskod field as a säkerhetsskydd indicator regardless
 * of value (except an explicit oklassad/öppen value), failing closed.
 *
 * This is a SEAM finding (no live feed wired), so the tests drive the gate the same
 * way production will: through evaluate() with structured sdkFields, asserting the
 * fail-closed REJECTION (avvisad=true), not internal method shape.
 *
 * ── RECONCILIATION NOTES ──
 *   * Driven through the public evaluate() (the only public entry); detectIndicator()
 *     is private. The asserted contract is `evaluate(...)['avvisad'] === true` +
 *     `reason === REASON_SAKERHETSSKYDD`. If the build classifies handlingskod under
 *     a NEW reason code (e.g. a REASON_HANDLINGSKOD constant), only the reason
 *     assertion needs reconciling — the avvisad=true contract is stable.
 *   * The classification VALUES used ('HEMLIG', 'KONFIDENTIELL', 'NATO SECRET') are
 *     chosen to NOT collide with the existing keyword list where it matters: the
 *     handlingskod test deliberately uses no keyword in the human-readable fields,
 *     so a PASS would prove the structured-key gap is still open. ('hemlig' IS in
 *     the keyword list, so it is placed ONLY in the structured handlingskod field
 *     with a neutral subject — see testHandlingskodHemligIsRejected.)
 *   * The "explicit oklassad/öppen value passes" expectation mirrors the existing
 *     sakerhetsklass handling (klass==='oklassad'|'oppen'|'open' → not an indicator).
 *     If the build does not extend the oklassad-exception to handlingskod, the
 *     {@see testHandlingskodOppenPasses} expectation must be reconciled.
 */
final class SakerhetsskyddGrindM4Test extends TestCase {
    private LoggerInterface&MockObject $logger;
    private ArendeMapper&MockObject $arendeMapper;
    private SakerhetsskyddGrind $grind;

    protected function setUp(): void {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        // No existing case for any conversationId (keeps retroaktiv=false).
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->grind = new SakerhetsskyddGrind($this->logger, $this->arendeMapper);
    }

    // ================================================================== //
    //  handlingskod=HEMLIG (no human-readable keyword) → avvisad=true.
    //  This is the explicit unit-test the report's M4 "Åtgärd" line asks for.
    // ================================================================== //

    public function testHandlingskodHemligIsRejected(): void {
        // Neutral subject/body — NO keyword present in the free-text fields, so the
        // ONLY signal is the structured handlingskod. If the gate passes this, the
        // M4 gap (handlingskod invisible to the detector) is still open.
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-m4-handlingskod',
            'subject' => 'Vanligt ärende utan markörord',
            'body' => 'Bifogad handling enligt rutin.',
            'sdkFields' => ['handlingskod' => 'HEMLIG'],
        ]);

        self::assertTrue(
            $result['avvisad'],
            'handlingskod=HEMLIG måste avvisas fail-closed även utan nyckelord i fritext.',
        );
        // RECONCILE: reason may be a dedicated handlingskod-code in the build.
        self::assertSame(SakerhetsskyddGrind::REASON_SAKERHETSSKYDD, $result['reason']);
        self::assertSame(SakerhetsskyddGrind::IND_SAKERHETSSKYDD, $result['indikator']);
    }

    public function testClassificationFieldPresentIsRejected(): void {
        // A `classification` envelope key with any non-open value must reject.
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-m4-classification',
            'subject' => 'Helt neutralt ämne',
            'sdkFields' => ['classification' => 'KONFIDENTIELL'],
        ]);

        self::assertTrue(
            $result['avvisad'],
            'Närvaron av ett classification-fält (icke-öppet) måste avvisas.',
        );
        self::assertSame(SakerhetsskyddGrind::IND_SAKERHETSSKYDD, $result['indikator']);
    }

    public function testXProtectiveMarkingHeaderIsRejected(): void {
        // The `x-protective-marking` header carrier must also be covered.
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-m4-xpm',
            'subject' => 'Neutralt',
            'sdkFields' => ['x-protective-marking' => 'NATO SECRET'],
        ]);

        self::assertTrue(
            $result['avvisad'],
            'x-protective-marking måste behandlas som klassmarkering (fail-closed).',
        );
        self::assertSame(SakerhetsskyddGrind::IND_SAKERHETSSKYDD, $result['indikator']);
    }

    public function testHandlingskodPresenceIsRejectedRegardlessOfUnknownValue(): void {
        // "Treat PRESENCE as indicator regardless of value (except explicit
        // oklassad/öppen)": an unknown/untranslatable handlingskod value fails closed.
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-m4-unknown',
            'subject' => 'Neutralt ämne',
            'sdkFields' => ['handlingskod' => 'KH-7'],
        ]);

        self::assertTrue(
            $result['avvisad'],
            'Okänt/icke-tolkbart handlingskod-värde måste fail-closa till avvisning.',
        );
    }

    // ================================================================== //
    //  Negative control: an explicit OPEN/oklassad marking must still pass.
    // ================================================================== //

    public function testHandlingskodOppenPasses(): void {
        // An explicitly open/unclassified marking must NOT be a false positive —
        // mirrors the existing sakerhetsklass 'oklassad'/'oppen' exception.
        // RECONCILE: if the build does not extend the open-exception to handlingskod,
        // this expectation must change to assertTrue(avvisad).
        $result = $this->grind->evaluate([
            'conversationId' => 'conv-m4-open',
            'subject' => 'Orosanmälan gällande barn',
            'from' => 'forskola@example.se',
            'body' => 'Vanlig anmälan utan markörer.',
            'sdkFields' => ['handlingskod' => 'oppen'],
        ]);

        self::assertFalse(
            $result['avvisad'],
            'Ett explicit öppet/oklassat handlingskod-värde får inte ge falsk positiv.',
        );
        self::assertSame(SakerhetsskyddGrind::REASON_OK, $result['reason']);
    }
}
