<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

/**
 * Den DELADE fyllningskärnan för HANDLING-FRÅN-MALL (Strategi B, återanvänds av
 * framtida Strategi A′; ANALYS-HANDLING-FRAN-MALL): tar råa .docx-bytes + en
 * karta [platshållartext => värde] och returnerar nya .docx-bytes där varje
 * literal förekomst av platshållaren i word/document.xml är ersatt med det
 * XML-escapade värdet.
 *
 * REN KLASS — inga Nextcloud-beroenden alls (bara \ZipArchive + en temp-fil).
 * Ingen loggning, ingen I/O utöver temp-filen, inga sidoeffekter: maximalt
 * testbar med phpunit utan NC-bootstrap. Anroparen (mall-servicen) äger allt
 * runtomkring — mall-läsning, skyddsgrind (K-NAV-6.1), journalföring och
 * skrivning till ärenderummets groupfolder.
 *
 * PII-NOT: klassen loggar ingenting och kastar aldrig undantag som innehåller
 * dokument- eller ersättningsinnehåll — undantagsmeddelanden är sakliga och
 * innehållsfria. PII-doktrinen (aldrig namn/pnr/adress i loggar) upprätthålls
 * därmed per konstruktion: det finns ingen kanal ut ur klassen utom returvärdet.
 *
 * KÄND BEGRÄNSNING (fas 1, medveten): motorn antar att varje platshållare
 * ligger komplett inom EN <w:t>-run i word/document.xml. Det är GARANTERAT för
 * husets pandoc-genererade mallar (mallbibliotekets build-docx.sh skriver varje
 * stycke som sammanhängande runs). Mallar som efterredigerats i Word kan få
 * platshållaren splittad över flera runs (t.ex. av stavningskontroll eller
 * formatbyten mitt i texten) — då HITTAS platshållaren inte och lämnas orörd.
 * Det är det ärliga felläget: ersatta=0 för den nyckeln, aldrig ett korrupt
 * dokument. Handläggaren ser den kvarlämnade platshållaren i dokumentredigeraren
 * och fyller själv.
 *
 * FLERRADIGA VÄRDEN (fas 1-begränsning): en <w:t>-run kan inte innehålla råa
 * radbrytningar (kräver <w:br/>-element, vilket vore XML-strukturändring, inte
 * textersättning). Radbrytningar i värden ersätts därför med ", " — läsbart och
 * riskfritt. Riktiga radbrytningar är ett fas 2-jobb.
 *
 * Ersättningarna körs sekventiellt i kartans ordning mot samma XML-buffert.
 * Värden är alltid XML-escapade innan de sätts in, så ett insatt värde kan
 * aldrig bilda ny XML-struktur — och råkar ett värde innehålla en senare
 * platshållartext ersätts även den (dokumenterat, i praktiken en icke-fråga
 * för fas 1-fälten: namn/pnr/dnr/enhet innehåller inte [hakparentes-mallar]).
 */
class DocxFyllningsMotor {
    /** Zip-postens sökväg till huvuddokumentet i en .docx (OOXML WordprocessingML). */
    private const DOCUMENT_XML = 'word/document.xml';

    /**
     * Fyll en .docx med ersättningar: varje literal förekomst av platshållartexten
     * i word/document.xml byts mot det XML-escapade värdet.
     *
     * Ren funktion sett utifrån: bytes in, bytes ut. En temp-fil används internt
     * (\ZipArchive kan bara arbeta mot filsystem) och städas OVILLKORLIGEN i
     * finally, även vid undantag.
     *
     * Räkningen görs med substr_count FÖRE ersättningen, så 'ersatta' speglar
     * exakt vad som byttes — nycklar med 0 träffar INKLUDERAS (anroparen behöver
     * dem för ärlig återkoppling till handläggaren: "3 fält fylldes, 2 hittades
     * inte i mallen").
     *
     * @param string $docxBytes Råa bytes för en .docx-fil (zip-container).
     * @param array<string,string> $ersattningar Karta platshållartext => värde.
     *        Värdet XML-escapas alltid; radbrytningar i värdet blir ", "
     *        (fas 1-begränsning, se klassdoc). Tomt värde är tillåtet och
     *        raderar då platshållaren ur dokumentet.
     *
     * @return array{bytes: string, ersatta: array<string,int>} Fylld .docx +
     *         antal ersättningar per platshållare (0 inkluderas).
     *
     * @throws \InvalidArgumentException Om bytes inte är en giltig zip eller om
     *         word/document.xml saknas (dvs. inte en .docx). Meddelandet är
     *         sakligt och innehållsfritt — aldrig dokument- eller värdeinnehåll.
     * @throws \RuntimeException Om temp-filen inte kan skapas/skrivas eller
     *         zippen inte kan skrivas tillbaka (miljöfel, inte indatafel).
     */
    public function fyll(string $docxBytes, array $ersattningar): array {
        $tempPath = tempnam(sys_get_temp_dir(), 'hubs_docx_');
        if ($tempPath === false) {
            throw new \RuntimeException('Kunde inte skapa temp-fil för docx-fyllning.');
        }

        try {
            if (file_put_contents($tempPath, $docxBytes) === false) {
                throw new \RuntimeException('Kunde inte skriva docx-bytes till temp-fil.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($tempPath) !== true) {
                // Innehållsfritt meddelande: säg VAD som är fel (ogiltig zip),
                // aldrig något ur innehållet.
                throw new \InvalidArgumentException('Indata är inte en giltig zip-fil (förväntade .docx).');
            }

            try {
                if ($zip->getFromName(self::DOCUMENT_XML) === false) {
                    throw new \InvalidArgumentException('Zip-filen saknar word/document.xml (förväntade .docx).');
                }

                // FYLLBARA PARTS: brödtexten + sidhuvuden/sidfötter (S3 lägger
                // ärendereferens-token i sidfoten — word/footer1.xml). Iterera
                // zip-innehållet så alla header*/footer*-varianter täcks.
                $parts = [self::DOCUMENT_XML];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $namn = (string)$zip->getNameIndex($i);
                    if (preg_match('#^word/(header|footer)\d*\.xml$#', $namn) === 1) {
                        $parts[] = $namn;
                    }
                }

                /** @var array<string,int> $ersatta */
                $ersatta = [];
                foreach ($ersattningar as $platshallare => $varde) {
                    $ersatta[$platshallare] = 0;
                }

                foreach ($parts as $part) {
                    $partXml = $zip->getFromName($part);
                    if ($partXml === false) {
                        continue;
                    }

                    foreach ($ersattningar as $platshallare => $varde) {
                        // Sök på den XML-escapade formen av platshållaren — det är
                        // så texten ligger i XML:en. CITATTECKEN är valfria att
                        // escapa i textnoder och producenter skiljer sig: pandoc
                        // skriver &quot;, andra rått ". Matcha därför BÅDA formerna
                        // (buggen hittad 2026-07-07 via platshållaren
                        // [Namn — eller "okänd/anonym"…] som aldrig träffades).
                        $nalar = array_unique([
                            htmlspecialchars((string)$platshallare, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars((string)$platshallare, ENT_XML1 | ENT_NOQUOTES, 'UTF-8'),
                        ]);

                        // Fas 1-begränsning (se klassdoc): radbrytningar kan inte
                        // leva i en <w:t>-run som text — platta till ", ".
                        $plattat = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", ', '], (string)$varde);
                        $escapat = htmlspecialchars($plattat, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                        // Räkna FÖRE ersättning så siffran speglar exakt vad som
                        // byts. Nålarna kan inte överlappa (&quot;-formen och
                        // rå-formen är olika strängar) — summan är exakt.
                        foreach ($nalar as $nal) {
                            if ($nal === '') {
                                continue;
                            }
                            $traffar = substr_count($partXml, $nal);
                            if ($traffar > 0) {
                                $partXml = str_replace($nal, $escapat, $partXml);
                                $ersatta[$platshallare] += $traffar;
                            }
                        }
                    }

                    // REN IFYLLNAD (blankett-principen): en FYLLD run ska varken
                    // behålla fält-markering (grå ram/skuggning) eller vara DOLD
                    // (w:vanish — S2:s tomma-blankett-tokens måste bli SYNLIGA
                    // när de fyllts). Runs som fortfarande bär en [platshållare]
                    // lämnas orörda — de ÄR tomma fält.
                    $partXml = $this->stadaFyldaRuns($partXml);

                    if ($zip->addFromString($part, $partXml) === false) {
                        throw new \RuntimeException('Kunde inte skriva tillbaka ' . $part . ' i zip-filen.');
                    }
                }
            } finally {
                $zip->close();
            }

            $bytes = file_get_contents($tempPath);
            if ($bytes === false) {
                throw new \RuntimeException('Kunde inte läsa ut den fyllda docx-filen från temp-fil.');
            }

            return [
                'bytes' => $bytes,
                'ersatta' => $ersatta,
            ];
        } finally {
            // Registrerad cleanup: temp-filen städas OVILLKORLIGEN — den kan
            // innehålla partsdata (PII) och får aldrig lämnas kvar på disk.
            @unlink($tempPath);
        }
    }

    /**
     * Städa FYLLDA runs: ta bort fält-markering (grå ram <w:bdr> + skuggning
     * <w:shd>) OCH doldhet (<w:vanish/> — S2:s tomma-blankett-tokens) från
     * runs vars platshållare har ERSATTS. Det ifyllda värdet ska vara SYNLIG,
     * ren dokumenttext i utskrift/PDF (blankett-principen: ruta/doldhet
     * markerar ett TOMT fält som väntar på ifyllnad).
     *
     * En run som fortfarande innehåller en [platshållare] lämnas orörd — den
     * ÄR fortfarande ett fält. Idempotent.
     */
    private function stadaFyldaRuns(string $documentXml): string {
        $resultat = preg_replace_callback(
            '/<w:r><w:rPr>((?:(?!<\/w:rPr>).)*?)<\/w:rPr>(<w:t(?: [^>]*)?>([^<]*)<\/w:t>)<\/w:r>/su',
            static function (array $m): string {
                $rpr = $m[1];
                $harFaltMarkering = str_contains($rpr, '<w:bdr ')
                    || str_contains($rpr, '<w:shd ')
                    || str_contains($rpr, '<w:vanish');
                $arFortfarandeFalt = preg_match('/\[[^\[\]\n]{2,}\]/u', $m[3]) === 1;
                if (!$harFaltMarkering || $arFortfarandeFalt) {
                    return $m[0];
                }
                $rensad = preg_replace('/<w:bdr [^>]*\/>|<w:shd [^>]*\/>|<w:vanish ?\/>/u', '', $rpr) ?? $rpr;
                return $rensad === ''
                    ? '<w:r>' . $m[2] . '</w:r>'
                    : '<w:r><w:rPr>' . $rensad . '</w:rPr>' . $m[2] . '</w:r>';
            },
            $documentXml,
        );

        // preg-fel (katastrofal backtracking e.d.) ⇒ behåll originalet hellre
        // än att korrumpera dokumentet — markeringarna är kosmetik, innehållet heligt.
        return $resultat ?? $documentXml;
    }
}
