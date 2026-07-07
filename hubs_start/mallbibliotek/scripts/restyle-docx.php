<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * restyle-docx.php v2 — MYNDIGHETSBLANKETT-transform (Konsumentverket-standard)
 * Körs efter pandoc i build-docx.sh (docker composer:2).
 *
 *   php restyle-docx.php <katalog-med-docx>
 *
 * Transformer (ANALYS-BLANKETTSTANDARD.md, S2+S3 ratificerade 2026-07-07):
 *
 *  S2a FÄLTTABELLER: konsekutiva fältstycken ("**Etikett:** [token]" och
 *      fristående "[Fritext-token]") byggs om till kantlinjerade w:tbl i
 *      Konsumentverket-stil — etikett + tom skrivyta per cell; korta
 *      etikettfält paras två-per-rad, långa/fritext får helradscell.
 *  S2b DOLD TOKEN: ifyllnadstokens görs OSYNLIGA (<w:vanish/>) — en utskriven
 *      blank blankett visar TOMMA celler, men DocxFyllningsMotor hittar och
 *      ersätter fortfarande (och tar bort vanish vid ifyllnad).
 *      UNDANTAG: (a) kryssrutor [ ]/[x], (b) [verifiera …]-markörer
 *      (redaktionella flaggor som SKA synas — behåller synlig grå ruta),
 *      (c) tokens i blå instruktionsblock (exempel, inte fält).
 *  S2c INGRESS-RAM: första blockquote-gruppen (Om mallen — bär lagrummet)
 *      får RAM i normal storlek och STÅR KVAR i handlingen (Konsumentverkets
 *      rättsliga ingress). Endast följande blockquote-grupper (Så här fyller
 *      du i) förblir blå 8pt klipp-text, liksom kursiv handledning.
 *  S2d Befintliga pandoc-tabeller (underskrift m.fl.) får kantlinjer om de
 *      saknar sådana; deras tokens döljs som övriga.
 *  S3  SIDHUVUD/SIDFOT injiceras: vänster "[Kommunens namn]" (brand-slot,
 *      text nu — bild per kommun är konfig senare), höger version+byggdatum
 *      och "Sida X (Y)"; sidfot: mallnamn + "Ärende: [hubsCaseId /
 *      Treserva-dnr]" (dold token — motorn fyller ärendereferensen).
 *
 * Idempotent: markören HUBS_BLANKETT_V2 i document.xml gör omkörning till no-op.
 */

const BLA = '1F6FC5';
const SZ_8PT = '16';
const GRA = '666666';
const MARKOR = '<!--HUBS_BLANKETT_V2-->';
const TABELL_BREDD = 9350;           // twips, pandoc Letter-layout innehållsbredd
const KORT_ETIKETT_MAX = 28;         // tecken — gräns för två-per-rad-parning
const VERSION_TEXT = 'Version 1.0 (utkast)';

const HANDLEDNING_RPR = '<w:color w:val="' . BLA . '" /><w:sz w:val="' . SZ_8PT . '" /><w:szCs w:val="' . SZ_8PT . '" />';
const BOX_RPR = '<w:bdr w:val="single" w:sz="6" w:space="2" w:color="7F7F7F" />'
    . '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2" />';
const VANISH_RPR = '<w:vanish />';
const FOOTER_MARKORER = ['Hubs mallbibliotek', 'Lagrumshänvisningar ska verifieras', 'Granskas av verksamhetsjurist'];

const TBL_BORDERS = '<w:tblBorders>'
    . '<w:top w:val="single" w:sz="4" w:space="0" w:color="' . GRA . '" />'
    . '<w:left w:val="single" w:sz="4" w:space="0" w:color="' . GRA . '" />'
    . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="' . GRA . '" />'
    . '<w:right w:val="single" w:sz="4" w:space="0" w:color="' . GRA . '" />'
    . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="' . GRA . '" />'
    . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="' . GRA . '" />'
    . '</w:tblBorders>';

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Användning: php restyle-docx.php <katalog>\n");
    exit(1);
}

$antal = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $fil) {
    if ($fil->isFile() && str_ends_with(strtolower($fil->getFilename()), '.docx')) {
        blankettifiera($fil->getPathname());
        $antal++;
    }
}
echo "Blankettifierade $antal .docx (tabeller + dold token + ingress-ram + sidhuvud/sidfot)\n";
exit(0);

// ===========================================================================
//  Huvudflöde per fil
// ===========================================================================

function blankettifiera(string $path): void {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fwrite(STDERR, "HOPPAR (ej zip): $path\n");
        return;
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        fwrite(STDERR, "HOPPAR (ingen document.xml): $path\n");
        return;
    }
    if (str_contains($xml, MARKOR)) {
        $zip->close();
        echo '  = ' . basename($path) . " (redan blankettifierad)\n";
        return;
    }

    // -- Stycke-transformer utanför befintliga tabeller resp. inuti dem ----
    $xml = utanforTabeller($xml, static function (string $segment): string {
        $segment = stylaBlockquotesOchKursiv($segment);
        $segment = tokenBehandla($segment);
        $segment = byggFalttabeller($segment);
        return $segment;
    }, static function (string $tabell): string {
        // Befintliga (pandoc-)tabeller: kantlinjer + dolda tokens i cellerna.
        if (!str_contains($tabell, '<w:tblBorders>')) {
            $tabell = preg_replace('/<w:tblPr>/', '<w:tblPr>' . TBL_BORDERS, $tabell, 1) ?? $tabell;
        }
        return tokenBehandla($tabell);
    });

    // -- S3: sidhuvud/sidfot ------------------------------------------------
    $mallNamn = preg_replace('/\.docx$/i', '', basename($path)) ?? basename($path);
    $xml = kopplaHeaderFooter($xml);
    $xml .= MARKOR;
    // MARKOR måste ligga FÖRE </w:document> för att vara giltig XML.
    $xml = str_replace('</w:document>' . MARKOR, MARKOR . '</w:document>', $xml);

    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', $xml);
    skrivHeaderFooterParts($zip, $mallNamn);
    uppdateraRels($zip);
    uppdateraContentTypes($zip);
    $zip->close();
    echo '  + ' . basename($path) . "\n";
}

/**
 * Kör $utanfor på segmenten mellan befintliga <w:tbl>-element och $inuti på
 * själva tabellerna (pandoc nästlar aldrig tabeller i mallarna).
 */
function utanforTabeller(string $xml, callable $utanfor, callable $inuti): string {
    $delar = preg_split('/(<w:tbl>.*?<\/w:tbl>)/su', $xml, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($delar === false) {
        return $xml;
    }
    $ut = '';
    foreach ($delar as $del) {
        $ut .= str_starts_with($del, '<w:tbl>') ? $inuti($del) : $utanfor($del);
    }
    return $ut;
}

// ===========================================================================
//  S2c + handledning: blockquote-grupper och kursiv
// ===========================================================================

/**
 * Blockquote-grupp #1 (Om mallen) ⇒ INRAMAD ingress i normal storlek (KVAR i
 * handlingen). Grupp #2+ (Så här fyller du i) ⇒ blå 8pt klipp-text. Kursiva
 * runs i vanliga stycken ⇒ blå 8pt (handlednings-konventionen). Sidfoten
 * (kursiv, markör-igenkänd) lämnas orörd.
 */
function stylaBlockquotesOchKursiv(string $xml): string {
    $grupp = 0;
    $iGrupp = false;

    return preg_replace_callback('/<w:p\b.*?<\/w:p>/su', static function (array $m) use (&$grupp, &$iGrupp): string {
        $p = $m[0];
        $arBlock = str_contains($p, 'w:val="BlockText"');

        if ($arBlock && !$iGrupp) {
            $grupp++;
            $iGrupp = true;
        } elseif (!$arBlock) {
            $iGrupp = false;
        }

        // Om-mallen-ingressen och ifyllnadsnoten ligger som ANGRÄNSANDE
        // BlockText-stycken (inget mellanrum i pandoc-outputen) — gap-baserad
        // gruppräkning slår ihop dem. Innehållsmarkören ur MALL-STANDARD §2.4
        // ("Så här fyller du i") startar därför ALLTID instruktionsgruppen.
        if ($arBlock && $grupp < 2 && mb_strpos($p, 'Så här fyller du i') !== false) {
            $grupp = 2;
        }

        foreach (FOOTER_MARKORER as $mark) {
            if (mb_strpos($p, $mark) !== false) {
                return $p; // sidfoten lämnas orörd
            }
        }

        if ($arBlock && $grupp === 1) {
            // INGRESS: ram runt stycket, normal storlek — står kvar i handlingen.
            $ram = '<w:pBdr>'
                . '<w:top w:val="single" w:sz="8" w:space="4" w:color="404040" />'
                . '<w:left w:val="single" w:sz="8" w:space="4" w:color="404040" />'
                . '<w:bottom w:val="single" w:sz="8" w:space="4" w:color="404040" />'
                . '<w:right w:val="single" w:sz="8" w:space="4" w:color="404040" />'
                . '</w:pBdr>';
            if (str_contains($p, '<w:pBdr>')) {
                return $p;
            }
            if (preg_match('/<w:pPr>/', $p) === 1) {
                // pBdr måste ligga EFTER pStyle för giltig ordning — lägg direkt
                // efter pStyle-elementet om det finns, annars först i pPr.
                if (str_contains($p, '</w:pStyle>') || preg_match('/<w:pStyle [^>]*\/>/', $p) === 1) {
                    return preg_replace('/(<w:pStyle [^>]*\/>)/', '$1' . $ram, $p, 1) ?? $p;
                }
                return preg_replace('/<w:pPr>/', '<w:pPr>' . $ram, $p, 1) ?? $p;
            }
            return preg_replace('/<w:p\b([^>]*)>/', '<w:p$1><w:pPr>' . $ram . '</w:pPr>', $p, 1) ?? $p;
        }

        $blaHela = $arBlock && $grupp >= 2;
        return preg_replace_callback(
            '/<w:r>(<w:rPr>(.*?)<\/w:rPr>)?(<w:t\b)/su',
            static function (array $r) use ($blaHela): string {
                $rpr = $r[2] ?? '';
                $harRpr = ($r[1] ?? '') !== '';
                $arKursiv = str_contains($rpr, '<w:i ') || str_contains($rpr, '<w:i/');
                if (!$blaHela && !$arKursiv) {
                    return $r[0];
                }
                if (str_contains($rpr, '<w:color ')) {
                    return $r[0];
                }
                return $harRpr
                    ? '<w:r><w:rPr>' . $rpr . HANDLEDNING_RPR . '</w:rPr>' . $r[3]
                    : '<w:r><w:rPr>' . HANDLEDNING_RPR . '</w:rPr>' . $r[3];
            },
            $p,
        ) ?? $p;
    }, $xml) ?? $xml;
}

// ===========================================================================
//  S2b: token-behandling (dold / verifiera-synlig / kryssruta orörd)
// ===========================================================================

/**
 * Splitta runs runt [tokens] och behandla varje token:
 *  - [verifiera …] ⇒ SYNLIG grå ruta (redaktionell flagga som ska ses/lösas).
 *  - kryssrutor [ ]/[x] ⇒ orörda.
 *  - tokens i blå instruktionsrun (redan HANDLEDNING-färgad) ⇒ orörda exempel.
 *  - övriga ⇒ DOLD (<w:vanish/>) — tomma fält i utskrift; motorn fyller.
 */
function tokenBehandla(string $xml): string {
    return preg_replace_callback(
        '/<w:r>(?:<w:rPr>(.*?)<\/w:rPr>)?<w:t(?: [^>]*)?>([^<]*)<\/w:t><\/w:r>/su',
        static function (array $r): string {
            $rpr = $r[1] ?? '';
            $text = $r[2];
            if (str_contains($rpr, '<w:vanish ') || str_contains($rpr, '<w:bdr ')) {
                return $r[0]; // redan behandlad (idempotens)
            }
            if (str_contains($rpr, 'w:val="' . BLA . '"')) {
                return $r[0]; // blå instruktionstext — tokens är exempel
            }
            $delar = preg_split('/(\[[^\[\]\n]{2,}\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            if ($delar === false || $delar === []) {
                return $r[0];
            }
            $harToken = false;
            foreach ($delar as $del) {
                if (arToken($del)) {
                    $harToken = true;
                    break;
                }
            }
            if (!$harToken) {
                return $r[0];
            }
            $ut = '';
            foreach ($delar as $del) {
                if (!arToken($del)) {
                    $ut .= run($rpr, $del);
                } elseif (preg_match('/^\[verifiera/ui', $del) === 1) {
                    $ut .= run($rpr . BOX_RPR, $del);
                } else {
                    $ut .= run($rpr . VANISH_RPR, $del);
                }
            }
            return $ut;
        },
        $xml,
    ) ?? $xml;
}

function arToken(string $s): bool {
    return preg_match('/^\[[^\[\]\n]{2,}\]$/u', $s) === 1
        && preg_match('/^\[\s*x?\s*\]$/iu', $s) !== 1;
}

function run(string $rpr, string $text): string {
    return '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '')
        . '<w:t xml:space="preserve">' . $text . '</w:t></w:r>';
}

// ===========================================================================
//  S2a: fälttabeller (Konsumentverket-mönstret)
// ===========================================================================

/**
 * Konsekutiva FÄLTSTYCKEN ⇒ en kantlinjerad tabell.
 *  Etikettfält:  <p>fet "Etikett:" + dold token</p> ⇒ cell (korta paras 2/rad).
 *  Fritextfält:  <p>ENBART dold token</p>          ⇒ helradscell m. skrivyta.
 * Allt annat bryter gruppen och lämnas orört (säker degradering).
 */
function byggFalttabeller(string $xml): string {
    $stycken = preg_split('/(<w:p\b.*?<\/w:p>)/su', $xml, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($stycken === false) {
        return $xml;
    }

    $ut = '';
    $grupp = [];

    $flush = static function () use (&$grupp, &$ut): void {
        if ($grupp !== []) {
            $ut .= renderaTabell($grupp);
            $grupp = [];
        }
    };

    foreach ($stycken as $bit) {
        if (!str_starts_with($bit, '<w:p')) {
            if (trim($bit) !== '' && $grupp !== []) {
                $flush();
            }
            $ut .= $bit;
            continue;
        }
        $falt = klassaFaltstycke($bit);
        if ($falt !== null) {
            $grupp[] = $falt;
        } else {
            $flush();
            $ut .= $bit;
        }
    }
    $flush();
    return $ut;
}

/**
 * Klassa ett stycke som fältstycke.
 * @return array{typ:'etikett'|'fritext', etikettRuns:string, tokenRun:string, etikettText:string}|null
 */
function klassaFaltstycke(string $p): ?array {
    if (str_contains($p, 'w:val="BlockText"') || str_contains($p, 'Heading')) {
        return null;
    }
    // Exakt EN dold token i stycket.
    if (preg_match_all('/<w:r><w:rPr>[^<]*(?:<[^\/][^>]*\/>[^<]*)*<w:vanish \/>[^<]*(?:<[^\/][^>]*\/>[^<]*)*<\/w:rPr><w:t[^>]*>\[[^<]*\]<\/w:t><\/w:r>/u', $p, $tok) !== 1) {
        return null;
    }
    $tokenRun = $tok[0][0];

    // Synlig text = all w:t utom token-runnens.
    $utanToken = str_replace($tokenRun, '', $p);
    preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/u', $utanToken, $tm);
    $synlig = trim(implode('', $tm[1]));

    if ($synlig === '') {
        return ['typ' => 'fritext', 'etikettRuns' => '', 'tokenRun' => $tokenRun, 'etikettText' => ''];
    }
    // Etikettfält: synlig text = "Etikett:" (fetstil i mallkonventionen).
    if (preg_match('/^.{1,80}:$/su', $synlig) === 1 && str_contains($utanToken, '<w:b ')) {
        // Extrahera styckets inner-XML minus pPr — etikettens runs.
        $inner = preg_replace('/^<w:p\b[^>]*>(?:<w:pPr>.*?<\/w:pPr>)?/su', '', $p) ?? $p;
        $inner = preg_replace('/<\/w:p>$/', '', $inner) ?? $inner;
        $etikettRuns = str_replace($tokenRun, '', $inner);
        return ['typ' => 'etikett', 'etikettRuns' => $etikettRuns, 'tokenRun' => $tokenRun, 'etikettText' => $synlig];
    }
    return null;
}

/** Rendera en fältgrupp som kantlinjerad tabell (korta etikettfält paras 2/rad). */
function renderaTabell(array $falt): string {
    $hel = TABELL_BREDD;
    $halv = intdiv(TABELL_BREDD, 2);

    $rader = '';
    $i = 0;
    $n = count($falt);
    while ($i < $n) {
        $a = $falt[$i];
        $kortA = $a['typ'] === 'etikett' && mb_strlen($a['etikettText']) <= KORT_ETIKETT_MAX;
        $b = $falt[$i + 1] ?? null;
        $kortB = $b !== null && $b['typ'] === 'etikett' && mb_strlen($b['etikettText']) <= KORT_ETIKETT_MAX;

        if ($kortA && $kortB) {
            $rader .= '<w:tr>' . cell($a, $halv, false) . cell($b, $halv, false) . '</w:tr>';
            $i += 2;
        } else {
            $rader .= '<w:tr>' . cell($a, $hel, true) . '</w:tr>';
            $i += 1;
        }
    }

    return '<w:tbl><w:tblPr>'
        . '<w:tblW w:w="' . $hel . '" w:type="dxa" />'
        . TBL_BORDERS
        . '<w:tblLayout w:type="fixed" />'
        . '<w:tblCellMar><w:left w:w="108" w:type="dxa" /><w:right w:w="108" w:type="dxa" /></w:tblCellMar>'
        . '</w:tblPr>'
        . '<w:tblGrid><w:gridCol w:w="' . $halv . '" /><w:gridCol w:w="' . $halv . '" /></w:tblGrid>'
        . $rader
        . '</w:tbl><w:p><w:pPr><w:spacing w:before="0" w:after="60" /></w:pPr></w:p>';
}

/** En blankettcell: etikettrad + (dold token + skrivyta). */
function cell(array $falt, int $bredd, bool $helrad): string {
    $span = $helrad ? '<w:gridSpan w:val="2" />' : '';
    $skrivyta = $falt['typ'] === 'fritext'
        ? '<w:p><w:pPr><w:spacing w:before="0" w:after="0" /></w:pPr>' . $falt['tokenRun'] . '</w:p>'
            . '<w:p><w:pPr><w:spacing w:before="0" w:after="0" /></w:pPr></w:p>'
            . '<w:p><w:pPr><w:spacing w:before="0" w:after="120" /></w:pPr></w:p>'
        : '<w:p><w:pPr><w:spacing w:before="0" w:after="120" /></w:pPr>' . $falt['tokenRun'] . '</w:p>';

    $etikett = $falt['etikettRuns'] !== ''
        ? '<w:p><w:pPr><w:spacing w:before="40" w:after="20" /></w:pPr>' . $falt['etikettRuns'] . '</w:p>'
        : '';

    return '<w:tc><w:tcPr><w:tcW w:w="' . $bredd . '" w:type="dxa" />' . $span
        . '<w:vAlign w:val="top" /></w:tcPr>'
        . $etikett . $skrivyta
        . '</w:tc>';
}

// ===========================================================================
//  S3: sidhuvud/sidfot
// ===========================================================================

function kopplaHeaderFooter(string $xml): string {
    if (str_contains($xml, 'w:headerReference')) {
        return $xml;
    }
    $ref = '<w:headerReference w:type="default" r:id="rId901" />'
        . '<w:footerReference w:type="default" r:id="rId902" />';
    return preg_replace('/<w:sectPr>/', '<w:sectPr>' . $ref, $xml, 1) ?? $xml;
}

function skrivHeaderFooterParts(ZipArchive $zip, string $mallNamn): void {
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $byggdatum = date('Y-m-d');
    $mallEsc = htmlspecialchars($mallNamn, ENT_XML1, 'UTF-8');

    $tabb = '<w:pPr><w:tabs><w:tab w:val="right" w:pos="' . TABELL_BREDD . '" /></w:tabs>'
        . '<w:spacing w:before="0" w:after="40" /></w:pPr>';
    $liten = '<w:rPr><w:color w:val="595959" /><w:sz w:val="18" /><w:szCs w:val="18" /></w:rPr>';
    $litenKursiv = '<w:rPr><w:i /><w:iCs /><w:color w:val="595959" /><w:sz w:val="18" /><w:szCs w:val="18" /></w:rPr>';

    // Sidhuvud: "[Kommunens namn]" vänster (brand-slot; bild = konfig senare),
    // version + byggdatum höger; rad 2: "Sida X (Y)" höger.
    $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:hdr ' . $ns . '>'
        . '<w:p>' . $tabb
        . '<w:r>' . $litenKursiv . '<w:t xml:space="preserve">[Kommunens namn]</w:t></w:r>'
        . '<w:r>' . $liten . '<w:tab /><w:t xml:space="preserve">' . VERSION_TEXT . ' · ' . $byggdatum . '</w:t></w:r>'
        . '</w:p>'
        . '<w:p><w:pPr><w:jc w:val="right" /><w:spacing w:before="0" w:after="120" /></w:pPr>'
        . '<w:r>' . $liten . '<w:t xml:space="preserve">Sida </w:t></w:r>'
        . '<w:fldSimple w:instr=" PAGE "><w:r>' . $liten . '<w:t>1</w:t></w:r></w:fldSimple>'
        . '<w:r>' . $liten . '<w:t xml:space="preserve"> (</w:t></w:r>'
        . '<w:fldSimple w:instr=" NUMPAGES "><w:r>' . $liten . '<w:t>1</w:t></w:r></w:fldSimple>'
        . '<w:r>' . $liten . '<w:t>)</w:t></w:r>'
        . '</w:p>'
        . '</w:hdr>';

    // Sidfot: mallnamn + ärendereferens (DOLD token — motorn fyller vid
    // generering; blank mall skriver ut rent).
    $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:ftr ' . $ns . '>'
        . '<w:p><w:pPr><w:spacing w:before="120" w:after="0" /></w:pPr>'
        . '<w:r>' . $liten . '<w:t xml:space="preserve">' . $mallEsc . ' · Ärende: </w:t></w:r>'
        . '<w:r><w:rPr><w:color w:val="595959" /><w:sz w:val="18" /><w:szCs w:val="18" />' . VANISH_RPR . '</w:rPr>'
        . '<w:t xml:space="preserve">[hubsCaseId / Treserva-dnr]</w:t></w:r>'
        . '</w:p>'
        . '</w:ftr>';

    $zip->deleteName('word/header1.xml');
    $zip->deleteName('word/footer1.xml');
    $zip->addFromString('word/header1.xml', $header);
    $zip->addFromString('word/footer1.xml', $footer);
}

function uppdateraRels(ZipArchive $zip): void {
    $rels = $zip->getFromName('word/_rels/document.xml.rels');
    if ($rels === false || str_contains($rels, '"rId901"')) {
        return;
    }
    $nya = '<Relationship Id="rId901" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
        . '<Relationship Id="rId902" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
    $rels = str_replace('</Relationships>', $nya . '</Relationships>', $rels);
    $zip->deleteName('word/_rels/document.xml.rels');
    $zip->addFromString('word/_rels/document.xml.rels', $rels);
}

function uppdateraContentTypes(ZipArchive $zip): void {
    $ct = $zip->getFromName('[Content_Types].xml');
    if ($ct === false || str_contains($ct, 'header1.xml')) {
        return;
    }
    $nya = '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';
    $ct = str_replace('</Types>', $nya . '</Types>', $ct);
    $zip->deleteName('[Content_Types].xml');
    $zip->addFromString('[Content_Types].xml', $ct);
}
