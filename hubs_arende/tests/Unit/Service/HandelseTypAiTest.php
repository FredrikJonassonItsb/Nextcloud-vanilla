<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Service\Brain\HandelseTypAi;
use PHPUnit\Framework\TestCase;

/**
 * HandelseTypAi — TYP_AI-underkategorierna (SPEC 8.0.4). Verifierar särskilt
 * ingestion-regeln: ENDAST utkast_godkant/utkast_avvisat speglas externt (flöde
 * 10-B); provisionerad/fryst/gallrad m.fl. är lokal livscykelmeta.
 */
final class HandelseTypAiTest extends TestCase {
    public function testEndastHitlUtfallArIngesterbara(): void {
        self::assertTrue(HandelseTypAi::arIngesterbar(HandelseTypAi::UTKAST_GODKANT));
        self::assertTrue(HandelseTypAi::arIngesterbar(HandelseTypAi::UTKAST_AVVISAT));
    }

    public function testLivscykelmetaIngesterasAldrig(): void {
        foreach ([HandelseTypAi::PROVISIONERAD, HandelseTypAi::FRYST, HandelseTypAi::GALLRAD,
            HandelseTypAi::UTKAST_SKAPAT, HandelseTypAi::NODATKOMST, HandelseTypAi::ATEROPPNAD] as $meta) {
            self::assertFalse(HandelseTypAi::arIngesterbar($meta), $meta . ' ska inte ingesteras');
        }
    }

    public function testTypVardeArAiUtanEntitetskonstant(): void {
        // Tills kärnintegrationen lägger Handelse::TYP_AI faller värdet till 'ai'.
        self::assertSame('ai', HandelseTypAi::typVarde());
        self::assertSame('ai', HandelseTypAi::TYP);
    }
}
