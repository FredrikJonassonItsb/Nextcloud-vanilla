<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * generera-malldefinitioner.php — S4: MALLEN SOM DATA.
 * Körs efter restyle i build-docx.sh (docker composer:2).
 *
 *   php generera-malldefinitioner.php <katalog-med-docx>
 *
 * Skannar varje blankettifierad .docx (document.xml + header/footer-parts) och
 * emitterar en STRUKTURERAD MALLDEFINITION till <katalog>/Definitioner/<mall>.json:
 *
 *   { "mallId": "<relativ path>", "titel", "byggd": "YYYY-MM-DD",
 *     "tokens": ["[token]", …],                    // alla ifyllbara tokens
 *     "falt": [{ "id", "etikett", "token" }, …] }  // etikettfält (id = slug)
 *
 * Definitionen är den kanoniska schema-artefakten (ANALYS-BLANKETTSTANDARD S4):
 * motorn/ArendedataService filtrerar förifyllnadsfälten per mall mot `tokens`,
 * och framtida generationer (content controls i native-fasen, andra professioner)
 * bygger vidare på samma fil. AUTOGENERERAD vid varje bygge ⇒ alltid i synk
 * med blanketterna — redigera aldrig för hand.
 *
 * [verifiera …]-markörer och kryssrutor är inte ifyllnadsfält och utelämnas.
 */

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Användning: php generera-malldefinitioner.php <katalog>\n");
    exit(1);
}

$antal = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $fil) {
    if (!$fil->isFile() || !str_ends_with(strtolower($fil->getFilename()), '.docx')) {
        continue;
    }
    $def = extraheraDefinition($fil->getPathname(), $dir);
    if ($def === null) {
        continue;
    }
    // Definitionen läggs i en Definitioner/-syskonmapp BREDVID mallen
    // (per profession) — .json syns inte i Filers docx-mallväljare men följer
    // med den delade mallmappen till motorn (MallService::lasDefinition).
    $utKatalog = dirname($fil->getPathname()) . '/Definitioner';
    if (!is_dir($utKatalog) && !mkdir($utKatalog, 0775, true)) {
        fwrite(STDERR, "Kunde inte skapa $utKatalog\n");
        continue;
    }
    $utfil = $utKatalog . '/' . preg_replace('/\.docx$/i', '', $fil->getFilename()) . '.json';
    file_put_contents($utfil, json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $antal++;
}
echo "Genererade $antal malldefinitioner (Definitioner/ per profession)\n";
exit(0);

/** @return array<string,mixed>|null */
function extraheraDefinition(string $path, string $rot): ?array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }

    // Alla fyllbara parts (samma mängd som DocxFyllningsMotor bearbetar).
    $xmlAllt = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $namn = (string)$zip->getNameIndex($i);
        if ($namn === 'word/document.xml' || preg_match('#^word/(header|footer)\d*\.xml$#', $namn) === 1) {
            $xmlAllt .= ($zip->getFromName($namn) ?: '') . "\n";
        }
    }
    $doc = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();

    // --- Ifyllbara tokens = DOLDA runs (w:vanish) — exakt de motorn fyller. ---
    $tokens = [];
    if (preg_match_all(
        '/<w:r><w:rPr>(?:(?!<\/w:rPr>).)*<w:vanish \/>(?:(?!<\/w:rPr>).)*<\/w:rPr><w:t[^>]*>(\[[^<]{2,}\])<\/w:t><\/w:r>/su',
        $xmlAllt,
        $tm,
    ) > 0) {
        foreach ($tm[1] as $t) {
            // XML-avescapa till mallens råform (motorns nål-logik hanterar båda).
            $tokens[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $tokens = array_values(array_unique($tokens));

    // --- Etikettfält ur fälttabellerna: cell = etikett-p följt av token-p. ---
    $falt = [];
    $seddaId = [];
    if (preg_match_all(
        '/<w:p><w:pPr><w:spacing w:before="40" w:after="20" \/><\/w:pPr>((?:(?!<\/w:p>).)*)<\/w:p>'
        . '<w:p><w:pPr><w:spacing w:before="0" w:after="[0-9]+" \/><\/w:pPr>'
        . '<w:r><w:rPr>(?:(?!<\/w:rPr>).)*<w:vanish \/>(?:(?!<\/w:rPr>).)*<\/w:rPr><w:t[^>]*>(\[[^<]{2,}\])<\/w:t><\/w:r>/su',
        $doc,
        $fm,
        PREG_SET_ORDER,
    ) > 0) {
        foreach ($fm as $m) {
            preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/u', $m[1], $et);
            $etikett = trim(rtrim(trim(implode('', $et[1])), ':'));
            if ($etikett === '') {
                continue;
            }
            $token = html_entity_decode($m[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $id = slug($etikett);
            if (isset($seddaId[$id])) {
                $seddaId[$id]++;
                $id .= '-' . $seddaId[$id];
            } else {
                $seddaId[$id] = 1;
            }
            $falt[] = ['id' => $id, 'etikett' => $etikett, 'token' => $token];
        }
    }

    $rel = str_replace('\\', '/', substr($path, strlen(rtrim($rot, '/\\')) + 1));

    return [
        'mallId' => $rel,
        'titel' => preg_replace('/\.docx$/i', '', basename($path)),
        'byggd' => date('Y-m-d'),
        'tokens' => $tokens,
        'falt' => $falt,
    ];
}

function slug(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e', 'ü' => 'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    return trim($s, '-') ?: 'falt';
}
