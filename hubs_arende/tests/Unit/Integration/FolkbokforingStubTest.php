<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Integration;

use OCA\HubsArende\Integration\Port\Exception\FolkbokforingException;
use OCA\HubsArende\Integration\Port\FolkbokforingPort;
use OCA\HubsArende\Integration\Stub\FolkbokforingStub;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the 🔌 SEAM[navet] stub ({@see FolkbokforingStub}) — folkbokförings-
 * uppslaget (Skatteverket Navet via kommunens interna Frends-API) som matar
 * PARTSREGISTRET (oc_hubs_arende_part), motorns ENDA sanktionerade PII-tabell.
 *
 * Vad som bevisas här (KRAVSTALLNING-NAVET-FOLKBOKFORING.md):
 *  - FAIL-CLOSED SKYDD (K-NAV-3.3): VARJE post som lämnar porten bär ett giltigt
 *    `skydd`-fält (ingen|sekretessmarkering|skyddad_folkbokforing) — aldrig
 *    default "ingen", aldrig en post utan flagga.
 *  - Vid `skyddad_folkbokforing` lagras ALDRIG verklig adress: kontaktadress är
 *    null och endast sarskildPostadress (Skatteverkets förmedlingsadress) finns.
 *  - AUDIT (K-NAV-4.2): korrelationsId är OBLIGATORISKT i varje anrop — utan det
 *    kastas {@see FolkbokforingException}, uppslaget får aldrig ske "anonymt".
 *  - Batch-semantiken: map pnr => post|null; okänt pnr ger en ÄRLIG null-nyckel
 *    (aldrig tyst bortfall), flera pnr i samma anrop ger ALLA nycklar i svaret.
 *
 * FIXTUR-AGNOSTISKT: testet hårdkodar INGA personnummer (PII-doktrinen gäller
 * även testkod — inga verkliga pnr i repo, K-NAV-7.1). I stället upptäcks
 * stubbens kända fixtur-pnr via introspektion ({@see kandaPersonnummer()}) och
 * varje testfall letar upp sin kategori (skyddad, sekretessmarkerad, avliden,
 * nummerbytt, barn med vårdnadshavare) på dess INVARIANT — fixturen kan alltså
 * byta syntetiska pnr utan att testen ruttnar.
 */
final class FolkbokforingStubTest extends TestCase {
    private FolkbokforingStub $stub;

    /** @var string[] Stubbens kända fixtur-pnr (upptäckta, ej hårdkodade). */
    private array $kandaPnr = [];

    /** @var array<string, array<string,mixed>> Map pnr => personpost för alla kända pnr. */
    private array $poster = [];

    protected function setUp(): void {
        parent::setUp();

        $this->stub = new FolkbokforingStub();
        $this->kandaPnr = $this->kandaPersonnummer($this->stub);
        self::assertNotEmpty($this->kandaPnr, 'Fixtur-upptäckten fann inga kända pnr i stubben.');

        $svar = $this->stub->hamtaPerson($this->kandaPnr, $this->kontext());
        foreach ($svar as $pnr => $post) {
            if ($post !== null) {
                $this->poster[(string)$pnr] = $post;
            }
        }
        self::assertNotEmpty($this->poster, 'Kända fixtur-pnr gav inga personposter.');
    }

    // ================================================================== //
    //  (1) Stubben är alltid tillgänglig (graceful degradation gäller
    //      den skarpa klienten — mocken svarar alltid true)
    // ================================================================== //

    public function testIsAvailableIsTrue(): void {
        self::assertInstanceOf(FolkbokforingPort::class, $this->stub);
        self::assertTrue($this->stub->isAvailable());
    }

    // ================================================================== //
    //  (2) FAIL-CLOSED (K-NAV-3.3): ALLA fixtur-poster bär giltigt skydd
    // ================================================================== //

    public function testAllaFixturePosterHarGiltigtSkyddFalt(): void {
        $giltiga = ['ingen', 'sekretessmarkering', 'skyddad_folkbokforing'];

        foreach ($this->kandaPnr as $pnr) {
            $post = $this->poster[$pnr] ?? null;
            self::assertNotNull($post, 'Känt fixtur-pnr saknar post — fixturen är trasig.');
            self::assertArrayHasKey('skydd', $post, 'Post utan skydd-fält får ALDRIG lämna porten.');
            self::assertContains(
                $post['skydd'],
                $giltiga,
                'Okänt skydd-värde får ALDRIG lämna porten (fail-closed, aldrig default "ingen").',
            );
            // Grundshape: pnr-nyckeln matchar postens eget personnummer (12 siffror).
            self::assertSame($pnr, $post['personnummer']);
            self::assertMatchesRegularExpression('/^\d{12}$/', $post['personnummer']);
            self::assertArrayHasKey('fornamn', $post['namn']);
            self::assertArrayHasKey('efternamn', $post['namn']);
        }
    }

    // ================================================================== //
    //  (3) Skyddad folkbokföring: verklig adress lagras ALDRIG —
    //      kontaktadress null, endast förmedlingsadressen finns
    // ================================================================== //

    public function testSkyddadPersonHarNullKontaktadressOchSarskildPostadress(): void {
        $post = $this->findFirst(
            static fn (array $p): bool => $p['skydd'] === 'skyddad_folkbokforing',
            'skyddad_folkbokforing',
        );

        self::assertNull(
            $post['kontaktadress'],
            'Vid skyddad_folkbokforing får verklig adress ALDRIG förekomma (kontaktadress måste vara null).',
        );
        self::assertNotNull(
            $post['sarskildPostadress'],
            'Skyddad person nås via Skatteverkets förmedlingsadress (sarskildPostadress).',
        );
        self::assertIsArray($post['sarskildPostadress']['rader']);
        self::assertNotEmpty($post['sarskildPostadress']['rader']);
        self::assertArrayHasKey('postnummer', $post['sarskildPostadress']);
        self::assertArrayHasKey('postort', $post['sarskildPostadress']);
    }

    // ================================================================== //
    //  (4) Sekretessmarkering: markörflaggan följer med men adressen
    //      levereras (till skillnad från skyddad folkbokföring)
    // ================================================================== //

    public function testSekretessmarkeradPersonHarKontaktadressOchRattSkydd(): void {
        $post = $this->findFirst(
            static fn (array $p): bool => $p['skydd'] === 'sekretessmarkering',
            'sekretessmarkering',
        );

        self::assertSame('sekretessmarkering', $post['skydd']);
        self::assertNotNull(
            $post['kontaktadress'],
            'Sekretessmarkering är en varningsflagga — adressen levereras (skillnad mot skyddad_folkbokforing).',
        );
        self::assertIsArray($post['kontaktadress']['rader']);
        self::assertNotEmpty($post['kontaktadress']['rader']);
    }

    // ================================================================== //
    //  (5) Avliden: avregistrering med kod AV + datum (K-NAV-7.2)
    // ================================================================== //

    public function testAvlidenHarAvregistreringMedKodAv(): void {
        $post = $this->findFirst(
            static fn (array $p): bool => ($p['avregistrering']['kod'] ?? null) === 'AV',
            'avregistrering kod AV (avliden)',
        );

        self::assertSame('AV', $post['avregistrering']['kod']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $post['avregistrering']['datum'],
            'Avregistreringsdatum ska vara YYYY-MM-DD.',
        );
    }

    // ================================================================== //
    //  (6) Nummerbyte: identitetshistoriken (tidigareBeteckningar) följer
    //      med så partsregistret kan knyta ihop gamla/nya beteckningar
    // ================================================================== //

    public function testNummerbyttHarTidigareBeteckningar(): void {
        $post = $this->findFirst(
            static fn (array $p): bool => $p['tidigareBeteckningar'] !== [],
            'tidigareBeteckningar ej tom (nummerbytt)',
        );

        self::assertNotEmpty($post['tidigareBeteckningar']);
        foreach ($post['tidigareBeteckningar'] as $tidigare) {
            self::assertMatchesRegularExpression('/^\d{12}$/', $tidigare);
            self::assertNotSame($post['personnummer'], $tidigare, 'Tidigare beteckning får inte vara den aktuella.');
        }
    }

    // ================================================================== //
    //  (7) Okänt pnr => ÄRLIG null-nyckel i mappen (aldrig tyst bortfall,
    //      aldrig en påhittad post)
    // ================================================================== //

    public function testOkantPersonnummerGerNullIMappen(): void {
        $okant = $this->okantPersonnummer();

        $svar = $this->stub->hamtaPerson([$okant], $this->kontext());

        self::assertArrayHasKey($okant, $svar, 'Okänt pnr ska finnas som nyckel i svaret.');
        self::assertNull($svar[$okant], 'Okänt pnr ska mappa till null — personen finns ej i folkbokföringen.');
    }

    // ================================================================== //
    //  (8) Barnet: två vårdnadshavare (typ V) med personnummer — grunden
    //      för partsregistrets vårdnadshavar-koppling (K-NAV-7.2)
    // ================================================================== //

    public function testBarnetHarTvaVardnadshavareRelationerMedPersonnummer(): void {
        $post = $this->findFirst(
            static function (array $p): bool {
                $v = array_filter(
                    $p['relationer'],
                    static fn (array $rel): bool => $rel['typ'] === 'V' && $rel['personnummer'] !== null,
                );
                return count($v) === 2;
            },
            'barn med två V-relationer med pnr',
        );

        $vardnadshavare = array_values(array_filter(
            $post['relationer'],
            static fn (array $rel): bool => $rel['typ'] === 'V' && $rel['personnummer'] !== null,
        ));
        self::assertCount(2, $vardnadshavare);
        foreach ($vardnadshavare as $rel) {
            self::assertMatchesRegularExpression('/^\d{12}$/', $rel['personnummer']);
            self::assertNotSame('', $rel['namn']);
        }
    }

    // ================================================================== //
    //  (9) AUDIT (K-NAV-4.2): anrop utan korrelationsId => exception —
    //      uppslag utan audit-koppling får aldrig ske
    // ================================================================== //

    public function testAnropUtanKorrelationsIdKastarFolkbokforingException(): void {
        $kontext = $this->kontext();
        unset($kontext['korrelationsId']);

        $this->expectException(FolkbokforingException::class);
        $this->stub->hamtaPerson([$this->kandaPnr[0]], $kontext);
    }

    // ================================================================== //
    //  (10) Batch: flera pnr i samma anrop => ALLA nycklar i svaret
    //       (kända => post, okända => null)
    // ================================================================== //

    public function testFleraPersonnummerISammaAnropGerAllaNycklar(): void {
        $okant = $this->okantPersonnummer();
        $tvaKanda = array_slice($this->kandaPnr, 0, 2);
        $fraga = array_merge($tvaKanda, [$okant]);

        $svar = $this->stub->hamtaPerson($fraga, $this->kontext());

        self::assertEqualsCanonicalizing($fraga, array_map('strval', array_keys($svar)));
        foreach ($tvaKanda as $pnr) {
            self::assertNotNull($svar[$pnr], 'Känt pnr ska ge en post i batch-svaret.');
        }
        self::assertNull($svar[$okant]);
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /**
     * Standard-anropskontext med OBLIGATORISKT korrelationsId (K-NAV-4.2).
     *
     * @return array<string,mixed>
     */
    private function kontext(): array {
        return [
            'korrelationsId' => 'corr-test-' . bin2hex(random_bytes(6)),
            'arendeRef' => 'case-test-folkbokforing',
            'andamal' => 'unit-test',
        ];
    }

    /**
     * Första fixtur-post som matchar invarianten — annars ärligt fel med
     * kategori-namn (aldrig ett kryptiskt null-deref längre ner i testet).
     *
     * @param callable(array<string,mixed>):bool $invariant
     * @return array<string,mixed>
     */
    private function findFirst(callable $invariant, string $kategori): array {
        foreach ($this->poster as $post) {
            if ($invariant($post)) {
                return $post;
            }
        }
        self::fail('Fixturen saknar en post i kategorin: ' . $kategori);
    }

    /**
     * Ett syntetiskt pnr som garanterat INTE finns i fixturen (varken som
     * aktuell beteckning eller i identitetshistoriken).
     */
    private function okantPersonnummer(): string {
        $upptagna = $this->kandaPnr;
        foreach ($this->poster as $post) {
            foreach ($post['tidigareBeteckningar'] as $tidigare) {
                $upptagna[] = $tidigare;
            }
        }
        for ($i = 0; $i < 10000; $i++) {
            $kandidat = '19000101' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
            if (!in_array($kandidat, $upptagna, true)) {
                return $kandidat;
            }
        }
        self::fail('Kunde inte generera ett okänt pnr.');
    }

    /**
     * Upptäck stubbens kända fixtur-pnr utan att hårdkoda dem (PII-doktrin +
     * fixtur-agnostik):
     *  1. Föredra en explicit introspektionsmetod om stubben exponerar en
     *     (samma mönster som EdiariumStub::getDiarium()).
     *  2. Fallback: reflektera fram fixtur-arrayen — den största arrayen
     *     (egenskap eller klasskonstant) vars nycklar är 12-siffriga pnr.
     *
     * @return string[]
     */
    private function kandaPersonnummer(FolkbokforingStub $stub): array {
        foreach (['getKandaPersonnummer', 'kandaPersonnummer', 'getFixturePersonnummer', 'getKandaPnr'] as $metod) {
            if (method_exists($stub, $metod)) {
                /** @var string[] $pnr */
                $pnr = $stub->$metod();
                return array_values(array_map('strval', $pnr));
            }
        }

        $basta = [];
        $ref = new \ReflectionClass($stub);

        $kandidater = [];
        foreach ($ref->getProperties() as $prop) {
            $prop->setAccessible(true);
            $kandidater[] = $prop->isStatic() ? $prop->getValue() : $prop->getValue($stub);
        }
        foreach ($ref->getConstants() as $konstant) {
            $kandidater[] = $konstant;
        }

        foreach ($kandidater as $varde) {
            if (!is_array($varde)) {
                continue;
            }
            $pnrNycklar = array_values(array_filter(
                array_map('strval', array_keys($varde)),
                static fn (string $nyckel): bool => preg_match('/^\d{12}$/', $nyckel) === 1,
            ));
            if (count($pnrNycklar) >= 3 && count($pnrNycklar) > count($basta)) {
                $basta = $pnrNycklar;
            }
        }

        return $basta;
    }
}
