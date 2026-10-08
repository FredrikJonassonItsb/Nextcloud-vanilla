<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Service\DokumenttypRegistry;
use PHPUnit\Framework\TestCase;

/**
 * DokumenttypRegistry — motorns KANONISKA mall→dokumenttyp-karta (T4-rotfix).
 * Ren, beroendefri klass: mall-NUMMER primärt, distinkt slug-nyckelord som fallback.
 */
final class DokumenttypRegistryTest extends TestCase {
    private DokumenttypRegistry $registry;

    protected function setUp(): void {
        parent::setUp();
        $this->registry = new DokumenttypRegistry();
    }

    /**
     * @dataProvider mallProvider
     */
    public function testKlassForMall(string $mall, ?string $forvantat): void {
        self::assertSame($forvantat, $this->registry->klassForMall($mall));
    }

    /**
     * @return array<string, array{0:string, 1:?string}>
     */
    public static function mallProvider(): array {
        return [
            // KÄRNFYNDET: barnets_rost — mallen innehåller aldrig "barnsamtal".
            'barnets inställning (mall-slug)' => ['08-barnets-installning-och-delaktighet', 'barnsamtal'],
            'barnets inställning (fullt filnamn)' => ['08-barnets-installning-373537-20260711.docx', 'barnsamtal'],
            'skyddsbedömning' => ['02-omedelbar-skyddsbedomning', 'skyddsbedomning'],
            // Nummer löser bbic-tvetydigheten (04 vs 05 innehåller båda "bbic").
            'utredningsplan (nr 04, ej bbic)' => ['04-utredningsplan-bbic', 'utredningsplan'],
            'bbic-utredning (nr 05)' => ['05-barnavardsutredning-enligt-socialtjanstlagen-bbic', 'bbic-utredning'],
            // Nummer löser beslut-tvetydigheten (03/17 innehåller "beslut").
            'förhandsbedömning (nr 03, ej beslut)' => ['03-forhandsbedomning-och-beslut-att-inleda', 'forhandsbedomning'],
            'beslut om bistånd (nr 15)' => ['15-beslut-om-bistand-eller-insats', 'beslut'],
            'underrättelse (nr 17, ej beslut)' => ['17-underrattelse-om-beslut-och-overklagandehanvisning', 'underrattelse'],
            'mottagen orosanmälan' => ['01-mottagen-orosanmalan', 'mottagen-orosanmalan'],
            'journalanteckning' => ['06-journalanteckning-lopande-dokumentation', 'journalanteckning'],
            'samtalsanteckning' => ['07-samtals-och-motesanteckning', 'samtalsanteckning'],
            'samtycke' => ['09-samtycke-till-informationsinhamtning', 'samtycke'],
            'kallelse' => ['10-kallelse-till-mote-eller-samtal', 'kallelse'],
            'genomförandeplan' => ['13-genomforandeplan', 'genomforandeplan'],
            'kommunicering' => ['16-kommunicering-infor-beslut', 'kommunicering'],
            'avslutsanteckning' => ['18-avslutsanteckning-med-utfall', 'avslutsanteckning'],
            // Okänd mall utan nummer/nyckelord ⇒ null (konsumenten faller tillbaka).
            'okänd mall' => ['nagot-helt-annat-utan-match', null],
            'tom sträng' => ['', null],
        ];
    }

    public function testNyckelordFallbackNarNummerSaknas(): void {
        // Utan nummer-prefix används distinkt slug-nyckelord.
        self::assertSame('barnsamtal', $this->registry->klassForMall('barnets-installning-och-delaktighet'));
        self::assertSame('skyddsbedomning', $this->registry->klassForMall('omedelbar-skyddsbedomning'));
    }

    public function testNyckelordForKlass(): void {
        self::assertContains('barnets-installning', $this->registry->nyckelordForKlass('barnsamtal'));
        // Okänd klass ⇒ klassnamnet självt (bakåtkompatibelt).
        self::assertSame(['nagot'], $this->registry->nyckelordForKlass('nagot'));
    }

    public function testArKand(): void {
        self::assertTrue($this->registry->arKand('08-barnets-installning'));
        self::assertFalse($this->registry->arKand('helt-okand-mall'));
    }
}
