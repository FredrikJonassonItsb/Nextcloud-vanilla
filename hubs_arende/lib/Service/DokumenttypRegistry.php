<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

/**
 * DokumenttypRegistry — motorns KANONISKA mall→dokumenttyp-karta.
 *
 * STRUKTURELL ROT-FIX (agentsvärmen T4, "filnamnsmatchning som handläggningsbevis"):
 * tidigare fanns samma nyckelords-/mallmatchning HANDKOPIERAD på (minst) fem
 * ställen som skulle hållas i synk för hand — och gjorde det inte:
 *   1. arendeFlow.js STEG_INNEHALL[*].delmoment[*].klarNar.match   (grind, frontend)
 *   2. EvidensService::KLASS_NYCKELORD                              (grind, backend)
 *   3. HandlingModal.vue KLASS_NYCKELORD                           (mallväljare)
 *   4. arende-bot RAD_KATALOG-regex                                (bot-råd)
 *   5. journalens detalj.mall-slug                                 (som de matchar mot)
 * Klassiska konsekvensen: `barnets_rost` kunde ALDRIG bli grön eftersom grinden
 * matchade nyckelordet "barnsamtal" mot mall-sluggen
 * "08-barnets-installning-och-delaktighet" — som aldrig innehåller "barnsamtal".
 *
 * Nu finns kartan på ETT ställe. {@see HandlingService} stämplar den kanoniska
 * `dokumenttyp` på TYP_HANDLING-händelsens detalj vid generering; alla konsumenter
 * (frontendens harledStatus, {@see EvidensService}, boten via pekaren) matchar på
 * det STÄMPLADE fältet i stället för att gissa ur ett filnamn. Bakåtkompatibelt:
 * äldre journalrader utan `dokumenttyp` faller tillbaka på nyckelords-matchning.
 *
 * PRIMÄR IDENTITET = mallens NUMMER-prefix (01–18 är den stabila kanoniska
 * mall-identiteten i ITSL:s mallbibliotek). Nyckelords-fallback används bara när
 * ett mallnamn saknar nummer (avvikande bibliotek).
 *
 * Ren, sidoeffektsfri, injicerbar (autowire). Ingen PII.
 */
class DokumenttypRegistry {
    /**
     * Kanonisk mall-NUMMER → dokumenttyp (semantisk klass, = frontendens
     * STEG_INNEHALL[*].delmoment[*].artefakt). EN sanningskälla.
     *
     * @var array<int, string>
     */
    private const NUMMER_TYP = [
        1 => 'mottagen-orosanmalan',
        2 => 'skyddsbedomning',
        3 => 'forhandsbedomning',
        4 => 'utredningsplan',
        5 => 'bbic-utredning',
        6 => 'journalanteckning',
        7 => 'samtalsanteckning',
        8 => 'barnsamtal',
        9 => 'samtycke',
        10 => 'kallelse',
        13 => 'genomforandeplan',
        15 => 'beslut',
        16 => 'kommunicering',
        17 => 'underrattelse',
        18 => 'avslutsanteckning',
    ];

    /**
     * dokumenttyp → distinkta slug-nyckelord. Används (a) som fallback när mallen
     * saknar nummer-prefix, och (b) av grindarna för LEGACY-journalrader som bär
     * `detalj.mall` men inte `detalj.dokumenttyp`. Nyckelorden är valda specifika
     * nog att inte kollidera (mall-numret är ändå primärnyckeln).
     *
     * @var array<string, list<string>>
     */
    private const TYP_NYCKELORD = [
        'mottagen-orosanmalan' => ['mottagen-orosanmalan'],
        'skyddsbedomning' => ['skyddsbedom'],
        'forhandsbedomning' => ['forhandsbedom', 'förhandsbedöm'],
        'utredningsplan' => ['utredningsplan'],
        'bbic-utredning' => ['barnavardsutredning', 'bbic'],
        'journalanteckning' => ['journalanteckning', 'lopande-dokumentation'],
        'samtalsanteckning' => ['samtals-och-motesanteckning', 'motesanteckning'],
        'barnsamtal' => ['barnets-installning', 'barnets-rost', 'delaktighet', 'barnsamtal'],
        'samtycke' => ['samtycke'],
        'kallelse' => ['kallelse'],
        'genomforandeplan' => ['genomforande', 'genomförande'],
        'beslut' => ['beslut-om-bistand', 'beslut-om-insats'],
        'kommunicering' => ['kommunicer'],
        'underrattelse' => ['underrattelse', 'underrättelse'],
        'avslutsanteckning' => ['avslut'],
    ];

    /**
     * Härled den kanoniska dokumenttypen för en mall (mall-id, filnamn eller den
     * sanerade mall-sluggen — alla former godtas). Nummer-prefix vinner; annars
     * distinkt-nyckelords-fallback. null = okänd mall (konsumenten faller tillbaka
     * på sitt legacy-beteende).
     */
    public function klassForMall(string $mall): ?string {
        $bas = mb_strtolower(trim($mall));
        if ($bas === '') {
            return null;
        }
        // Primär: mallens NUMMER-prefix (ev. med ledande noll: "02", "8", "13-...").
        if (preg_match('/(^|[^0-9])0*([0-9]{1,2})(?![0-9])/', $bas, $m) === 1) {
            $nr = (int)$m[2];
            if (isset(self::NUMMER_TYP[$nr])) {
                return self::NUMMER_TYP[$nr];
            }
        }
        // Fallback: distinkt slug-nyckelord (mall utan nummer / avvikande bibliotek).
        foreach (self::TYP_NYCKELORD as $typ => $nyckelord) {
            foreach ($nyckelord as $kw) {
                if (str_contains($bas, mb_strtolower($kw))) {
                    return $typ;
                }
            }
        }
        return null;
    }

    /**
     * LEGACY-nyckelord för en dokumenttyp-klass — används av grindarna för att
     * matcha äldre journalrader som saknar det stämplade `dokumenttyp`-fältet.
     * Okänd klass ⇒ klassnamnet självt (bakåtkompatibelt med tidigare beteende).
     *
     * @return list<string>
     */
    public function nyckelordForKlass(string $klass): array {
        return self::TYP_NYCKELORD[$klass] ?? [$klass];
    }

    /**
     * Bär registret en dokumenttyp för denna mall? (bekvämlighet för konsumenter.)
     */
    public function arKand(string $mall): bool {
        return $this->klassForMall($mall) !== null;
    }
}
