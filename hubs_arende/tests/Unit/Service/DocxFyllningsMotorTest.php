<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Service\DocxFyllningsMotor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DocxFyllningsMotor::fyll()} — den delade fyllningskärnan
 * för HANDLING-FRÅN-MALL fas 1 (ANALYS-HANDLING-FRAN-MALL §5.2b).
 *
 * REN PHP, INGA MOCKS: motorn har inga beroenden alls (bara \ZipArchive + en
 * temp-fil internt), så testerna bygger riktiga minimala .docx-zippar i minnet
 * och verifierar bytes-in/bytes-ut-kontraktet på riktigt — ingen NC-bootstrap.
 *
 * Kontraktet som låses här:
 *  - literal platshållartext i word/document.xml byts mot XML-escapat värde;
 *  - 'ersatta' är en karta platshållare => antal, räknad FÖRE bytet, och
 *    0-träffar INKLUDERAS (ärlig återkoppling: "hittades inte i mallen");
 *  - flerradiga värden plattas till ", " (fas 1-begränsning — en <w:t>-run
 *    kan inte bära råa radbrytningar);
 *  - ogiltig zip => InvalidArgumentException med innehållsfritt meddelande;
 *  - allt som INTE står i ersättningskartan lämnas orört — oersatta
 *    platshållare (t.ex. de 66 datum-platshållarna som fas 1 MEDVETET inte
 *    fyller) lämnas åt handläggaren i dokumentredigeraren.
 *
 * PII-NOT: alla person-lika värden i testerna är fabricerade testdata.
 * Platshållartexterna är de EXAKTA strängarna ur mallbiblioteket
 * (hubs_start/mallbibliotek/socialsekreterare-barn-familj).
 */
final class DocxFyllningsMotorTest extends TestCase {
    private DocxFyllningsMotor $motor;

    protected function setUp(): void {
        parent::setUp();
        $this->motor = new DocxFyllningsMotor();
    }

    // ================================================================== //
    //  (1) Enkel ersättning: en platshållare, ett värde, count 1
    // ================================================================== //

    public function testEnkelErsattningByterPlatshallarenOchRaknarEn(): void {
        $docx = $this->byggDocx(
            '<w:p><w:r><w:t>Barnets namn: [För- och efternamn]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, [
            '[För- och efternamn]' => 'Elsa Teststrom',
        ]);

        self::assertSame(['[För- och efternamn]' => 1], $resultat['ersatta']);

        $xml = $this->lasDocumentXml($resultat['bytes']);
        self::assertStringContainsString('Barnets namn: Elsa Teststrom', $xml);
        self::assertStringNotContainsString('[För- och efternamn]', $xml);
    }

    // ================================================================== //
    //  (2) XML-specialtecken i värdet escapas — zippen förblir giltig XML
    // ================================================================== //

    public function testVardeMedXmlSpecialteckenEscapasOchXmlForblirGiltig(): void {
        $docx = $this->byggDocx(
            '<w:p><w:r><w:t>[För- och efternamn]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, [
            '[För- och efternamn]' => 'Barn & <Test>',
        ]);

        self::assertSame(['[För- och efternamn]' => 1], $resultat['ersatta']);

        // lasDocumentXml öppnar resultat-bytes som zip — passerar den är
        // containern fortfarande en giltig zip.
        $xml = $this->lasDocumentXml($resultat['bytes']);

        // Rått i XML:en: escapat, aldrig litteralt '<' / '&'.
        self::assertStringContainsString('Barn &amp; &lt;Test&gt;', $xml);
        self::assertStringNotContainsString('<Test>', $xml);

        // Och XML:en är fortfarande välformad (parsern hade kvävts av rått '&').
        self::assertNotFalse(
            simplexml_load_string($xml),
            'word/document.xml ska vara välformad XML efter ersättning',
        );
    }

    // ================================================================== //
    //  (3) Två förekomster av samma platshållare => count 2, båda byts
    // ================================================================== //

    public function testTvaForekomsterErsattsBadaOchRaknasTva(): void {
        $docx = $this->byggDocx(
            '<w:p><w:r><w:t>Ärende: [hubsCaseId / Treserva-dnr]</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Kopia avser [hubsCaseId / Treserva-dnr]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, [
            '[hubsCaseId / Treserva-dnr]' => 'HUBS-2026-0042',
        ]);

        self::assertSame(['[hubsCaseId / Treserva-dnr]' => 2], $resultat['ersatta']);

        $xml = $this->lasDocumentXml($resultat['bytes']);
        self::assertSame(2, substr_count($xml, 'HUBS-2026-0042'));
        self::assertStringNotContainsString('[hubsCaseId / Treserva-dnr]', $xml);
    }

    // ================================================================== //
    //  (4) Platshållare som inte finns => count 0, dokumentet oförändrat
    // ================================================================== //

    public function testSaknadPlatshallareGerNollOchLamnarDokumentetOrort(): void {
        $body = '<w:p><w:r><w:t>Enhet: [Mottagning / Barn och familj]</w:t></w:r></w:p>';
        $docx = $this->byggDocx($body);
        $xmlFore = $this->lasDocumentXml($docx);

        $resultat = $this->motor->fyll($docx, [
            '[ÅÅÅÅMMDD-XXXX]' => '20180101-TEST',
        ]);

        // 0-träffen INKLUDERAS i kartan — anroparen behöver den för ärlig
        // återkoppling ("fältet hittades inte i mallen").
        self::assertSame(['[ÅÅÅÅMMDD-XXXX]' => 0], $resultat['ersatta']);

        // Byte-för-byte samma document.xml — ingen smygändring vid 0 träffar.
        self::assertSame($xmlFore, $this->lasDocumentXml($resultat['bytes']));
    }

    // ================================================================== //
    //  (5) Flerradigt värde plattas till ", " (fas 1: ingen <w:br/>)
    // ================================================================== //

    public function testFlerradigtVardeSammanslasMedKommaMellanslag(): void {
        $docx = $this->byggDocx(
            '<w:p><w:r><w:t>Handläggare: [Namn, titel]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, [
            '[Namn, titel]' => "Rad1\nRad2",
        ]);

        self::assertSame(['[Namn, titel]' => 1], $resultat['ersatta']);

        $xml = $this->lasDocumentXml($resultat['bytes']);
        self::assertStringContainsString('Handläggare: Rad1, Rad2', $xml);
        // Ingen rå radbrytning får smyga in i <w:t>-runnen.
        self::assertStringNotContainsString("Rad1\nRad2", $xml);
    }

    // ================================================================== //
    //  (6) Ogiltiga zip-bytes => InvalidArgumentException
    // ================================================================== //

    public function testOgiltigaZipBytesKastarInvalidArgumentException(): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->motor->fyll('detta är definitivt inte en zip-fil', [
            '[För- och efternamn]' => 'Elsa Teststrom',
        ]);
    }

    // ================================================================== //
    //  (7) Svenska tecken i platshållare + värde överlever (UTF-8 roundtrip)
    // ================================================================== //

    public function testSvenskaTeckenOverleverUtf8Roundtrip(): void {
        $docx = $this->byggDocx(
            '<w:p><w:r><w:t>Personnummer: [ÅÅÅÅMMDD-XXXX]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, [
            '[ÅÅÅÅMMDD-XXXX]' => 'Åsa Öberg-Änglund, född i Växjö',
        ]);

        self::assertSame(['[ÅÅÅÅMMDD-XXXX]' => 1], $resultat['ersatta']);

        $xml = $this->lasDocumentXml($resultat['bytes']);
        self::assertStringContainsString('Åsa Öberg-Änglund, född i Växjö', $xml);
        self::assertStringNotContainsString('[ÅÅÅÅMMDD-XXXX]', $xml);
        // Byte-nivå-kontroll: å/ä/ö ligger kvar som giltig UTF-8, ingen
        // mojibake ur zip-roundtrippen.
        self::assertTrue(mb_check_encoding($xml, 'UTF-8'));
    }

    // ================================================================== //
    //  (8) Övriga platshållare i dokumentet lämnas orörda
    // ================================================================== //

    public function testOvrigaPlatshallareLamnasOrorda(): void {
        // Ett mall-likt dokument med tre platshållare: en fylls, två ska stå
        // kvar — bl.a. datum-platshållaren som fas 1 MEDVETET inte fyller
        // (ärlighet före täckning; handläggaren fyller i redigeraren).
        $docx = $this->byggDocx(
            '<w:p><w:r><w:t>Barn: [För- och efternamn]</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Datum: [ÅÅÅÅ-MM-DD]</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Enhet: [Mottagning / Barn och familj]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, [
            '[För- och efternamn]' => 'Elsa Teststrom',
        ]);

        self::assertSame(['[För- och efternamn]' => 1], $resultat['ersatta']);

        $xml = $this->lasDocumentXml($resultat['bytes']);
        self::assertStringContainsString('Barn: Elsa Teststrom', $xml);
        // De icke-beställda platshållarna står kvar EXAKT som i mallen.
        self::assertStringContainsString('Datum: [ÅÅÅÅ-MM-DD]', $xml);
        self::assertStringContainsString('Enhet: [Mottagning / Barn och familj]', $xml);
    }

    // ================================================================== //
    //  (9) Citattecken i platshållaren — BÅDA escapnings-formerna matchas
    // ================================================================== //

    public function testPlatshallareMedCitatteckenMatcharBadaEscapningsformerna(): void {
        // Producenter skiljer sig: pandoc skriver &quot; i w:t-textnoder,
        // andra XML-writers skriver rått ". Motorn ska träffa BÅDA (buggen
        // hittad 2026-07-07 — ENT_QUOTES-nålen missade pandoc-form före fixen
        // och rå-formen efter första fixförsöket).
        $platshallare = '[Namn — eller "okänd/anonym", se handledning]';

        // Rå-formen (") och pandoc-formen (&quot;) i samma dokument.
        $docx = $this->byggDocx(
            '<w:p><w:r><w:t>Rå: [Namn — eller "okänd/anonym", se handledning]</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Pandoc: [Namn — eller &quot;okänd/anonym&quot;, se handledning]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, [$platshallare => 'Rektor Rut Testsson']);

        self::assertSame([$platshallare => 2], $resultat['ersatta'], 'båda formerna ska träffas och räknas');

        $xml = $this->lasDocumentXml($resultat['bytes']);
        self::assertStringContainsString('Rå: Rektor Rut Testsson', $xml);
        self::assertStringContainsString('Pandoc: Rektor Rut Testsson', $xml);
        self::assertStringNotContainsString('okänd/anonym', $xml, 'ingen platshållar-rest får stå kvar');
    }

    // ================================================================== //
    //  (10) Ren ifyllnad — ifylld run tappar rutan, ofylld behåller den
    // ================================================================== //

    public function testIfylldRunTapparFaltRutanMenOfylldBehallerDen(): void {
        // Blankett-principen (2026-07-07): den grå rutan (bdr+shd ur
        // restyle-docx.php) markerar ett TOMT fält. Ett IFYLLT värde ska vara
        // ren dokumenttext i PDF-exporten — inte en grå ruta.
        $boxRpr = '<w:bdr w:val="single" w:sz="6" w:space="2" w:color="7F7F7F" />'
            . '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2" />';
        $docx = $this->byggDocx(
            '<w:p><w:r><w:rPr>' . $boxRpr . '</w:rPr>'
            . '<w:t xml:space="preserve">[För- och efternamn]</w:t></w:r></w:p>'
            . '<w:p><w:r><w:rPr>' . $boxRpr . '</w:rPr>'
            . '<w:t xml:space="preserve">[ÅÅÅÅ-MM-DD]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, ['[För- och efternamn]' => 'Elsa Teststrom']);
        $xml = $this->lasDocumentXml($resultat['bytes']);

        // Extrahera respektive STYCKE (inte byte-fönster — styckena ligger
        // tätt och ett fönster läcker in grannens legitima ruta).
        self::assertSame(1, preg_match('/<w:p>(?:(?!<\/w:p>).)*Elsa Teststrom(?:(?!<\/w:p>).)*<\/w:p>/su', $xml, $ifylld));
        self::assertSame(1, preg_match('/<w:p>(?:(?!<\/w:p>).)*\[ÅÅÅÅ-MM-DD\](?:(?!<\/w:p>).)*<\/w:p>/su', $xml, $ofylld));

        // Den ifyllda runnen: värdet på plats, ruta+skuggning BORTA.
        self::assertStringNotContainsString('<w:bdr ', $ifylld[0], 'ifyllt värde får inte behålla fält-ramen');
        self::assertStringNotContainsString('<w:shd ', $ifylld[0], 'ifyllt värde får inte behålla grå skuggning');

        // Den OFYLLDA datum-platshållaren behåller sin ruta (den ÄR ett fält).
        self::assertStringContainsString('<w:bdr ', $ofylld[0], 'ofyllt fält ska behålla sin ruta');
    }

    // ================================================================== //
    //  (11) Dold token (w:vanish, S2) — fylld blir SYNLIG, ofylld förblir dold
    // ================================================================== //

    public function testDoldTokenBlirSynligVidIfyllnadMenForblirDoldOfylld(): void {
        // S2-blankettmodellen: tokens är DOLDA (tom blankett skrivs ut ren).
        // När motorn fyller ska värdet bli SYNLIGT (vanish bort); en ofylld
        // dold token ska förbli dold (fältet är fortfarande tomt).
        $vanishRpr = '<w:vanish />';
        $docx = $this->byggDocx(
            '<w:p><w:r><w:rPr>' . $vanishRpr . '</w:rPr>'
            . '<w:t xml:space="preserve">[För- och efternamn]</w:t></w:r></w:p>'
            . '<w:p><w:r><w:rPr>' . $vanishRpr . '</w:rPr>'
            . '<w:t xml:space="preserve">[ÅÅÅÅ-MM-DD]</w:t></w:r></w:p>',
        );

        $resultat = $this->motor->fyll($docx, ['[För- och efternamn]' => 'Elsa Teststrom']);
        $xml = $this->lasDocumentXml($resultat['bytes']);

        self::assertSame(1, preg_match('/<w:p>(?:(?!<\/w:p>).)*Elsa Teststrom(?:(?!<\/w:p>).)*<\/w:p>/su', $xml, $ifylld));
        self::assertStringNotContainsString('<w:vanish', $ifylld[0], 'ifyllt värde måste bli SYNLIGT (vanish bort)');

        self::assertSame(1, preg_match('/<w:p>(?:(?!<\/w:p>).)*\[ÅÅÅÅ-MM-DD\](?:(?!<\/w:p>).)*<\/w:p>/su', $xml, $ofylld));
        self::assertStringContainsString('<w:vanish', $ofylld[0], 'ofylld dold token ska förbli dold (rent tomt fält)');
    }

    // ================================================================== //
    //  (12) Sidfots-part (S3) — tokens i word/footer*.xml fylls också
    // ================================================================== //

    public function testTokenISidfotsPartFyllsOchRaknas(): void {
        // S3 lägger ärendereferens-token i word/footer1.xml — motorn ska
        // iterera header-/footer-parts, inte bara document.xml.
        $docx = $this->byggDocx('<w:p><w:r><w:t>Brödtext utan fält.</w:t></w:r></w:p>');

        // Injicera en sidfot i den byggda docx:en (zip-nivå — motorn hittar
        // parts via namnlistan, inga rels behövs för fyllnadslogiken).
        $tempPath = tempnam(sys_get_temp_dir(), 'hubs_docx_footer_');
        self::assertNotFalse($tempPath);
        try {
            file_put_contents($tempPath, $docx);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($tempPath));
            $zip->addFromString(
                'word/footer1.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:p><w:r><w:t xml:space="preserve">Ärende: </w:t></w:r>'
                . '<w:r><w:rPr><w:vanish /></w:rPr><w:t xml:space="preserve">[hubsCaseId / Treserva-dnr]</w:t></w:r>'
                . '</w:p></w:ftr>',
            );
            $zip->close();
            $medFooter = file_get_contents($tempPath);
            self::assertNotFalse($medFooter);
        } finally {
            @unlink($tempPath);
        }

        $resultat = $this->motor->fyll($medFooter, ['[hubsCaseId / Treserva-dnr]' => '2026-IFO-0502']);

        self::assertSame(['[hubsCaseId / Treserva-dnr]' => 1], $resultat['ersatta'], 'sidfotens token ska räknas');

        // Läs ut sidfoten ur resultatet och verifiera fylld + synlig.
        $tempUt = tempnam(sys_get_temp_dir(), 'hubs_docx_footer_ut_');
        self::assertNotFalse($tempUt);
        try {
            file_put_contents($tempUt, $resultat['bytes']);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($tempUt));
            $footer = $zip->getFromName('word/footer1.xml');
            $zip->close();
        } finally {
            @unlink($tempUt);
        }
        self::assertIsString($footer);
        self::assertStringContainsString('2026-IFO-0502', $footer);
        self::assertStringNotContainsString('<w:vanish', $footer, 'ifylld sidfots-referens ska vara synlig');
    }

    // ================================================================== //
    //  Helpers — minimal giltig .docx i minnet + utpackning av resultatet
    // ================================================================== //

    /**
     * Bygg en MINIMAL giltig .docx (zip-container) i minnet med given
     * WordprocessingML-body: [Content_Types].xml + _rels/.rels + word/document.xml
     * — det minsta en .docx behöver för att vara strukturellt korrekt.
     * \ZipArchive kan bara arbeta mot filsystem, så en temp-fil används och
     * städas innan bytes returneras.
     *
     * @param string $bodyXml Innehållet i <w:body> (w:p/w:r/w:t-element).
     * @return string Råa .docx-bytes.
     */
    private function byggDocx(string $bodyXml): string {
        $tempPath = tempnam(sys_get_temp_dir(), 'hubs_docx_test_');
        self::assertNotFalse($tempPath, 'kunde inte skapa temp-fil för test-docx');

        try {
            $zip = new \ZipArchive();
            // tempnam skapar en TOM fil (ogiltig zip) — OVERWRITE ersätter den.
            self::assertTrue(
                $zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE),
                'kunde inte öppna temp-fil som zip',
            );

            $zip->addFromString(
                '[Content_Types].xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
            );
            $zip->addFromString(
                '_rels/.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            );
            $zip->addFromString(
                'word/document.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body>' . $bodyXml . '</w:body>'
                . '</w:document>',
            );
            $zip->close();

            $bytes = file_get_contents($tempPath);
            self::assertNotFalse($bytes, 'kunde inte läsa tillbaka test-docx-bytes');
            return $bytes;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Packa upp resultat-bytes och läs word/document.xml. Att öppningen lyckas
     * är i sig en giltighets-assertion på zip-containern — går den sönder av
     * en ersättning faller testet HÄR med tydligt meddelande.
     *
     * @param string $docxBytes Råa .docx-bytes (t.ex. motorns resultat).
     * @return string Innehållet i word/document.xml.
     */
    private function lasDocumentXml(string $docxBytes): string {
        $tempPath = tempnam(sys_get_temp_dir(), 'hubs_docx_test_');
        self::assertNotFalse($tempPath, 'kunde inte skapa temp-fil för utpackning');

        try {
            self::assertNotFalse(
                file_put_contents($tempPath, $docxBytes),
                'kunde inte skriva docx-bytes till temp-fil',
            );

            $zip = new \ZipArchive();
            self::assertTrue(
                $zip->open($tempPath),
                'resultat-bytes ska vara en giltig zip (öppningsbar .docx)',
            );

            try {
                $xml = $zip->getFromName('word/document.xml');
                self::assertNotFalse($xml, 'word/document.xml saknas i resultat-zippen');
                return $xml;
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tempPath);
        }
    }
}
