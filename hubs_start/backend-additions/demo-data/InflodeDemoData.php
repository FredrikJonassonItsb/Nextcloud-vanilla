<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · DEMO-DATA · Target: lib/Service/DemoData/InflodeDemoData.php
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  ⚠⚠⚠  SYNTETISK DEMO-DATA — INGEN VERKLIG INFORMATION  ⚠⚠⚠                 ║
 * ║                                                                            ║
 * ║  Allt i denna fil är PÅHITTAT för demo-ändamål på dev15.                   ║
 * ║                                                                            ║
 * ║  • Personnummer är FIKTIVA men i rätt format (ÅÅÅÅMMDD-NNNN) med giltig    ║
 * ║    Luhn-kontrollsiffra. De är konstruerade ur barn-födelsedatum 2008–2020 ║
 * ║    plus sekventiella serienummer — de tillhör INGEN verklig person.       ║
 * ║  • Namn, skolor, avsändare och ärenden är uppdiktade men plausibla.       ║
 * ║  • Denna data får ALDRIG nå produktion eller en skarp ärende-motor.       ║
 * ║                                                                            ║
 * ║  SEKRETESS-ARKITEKTUR: PII (personnummer/barnnamn) hör hemma i INFLÖDE-    ║
 * ║  MEDDELANDEN (inkommande) — INTE i ärende-motorns register, som avvisar    ║
 * ║  personnummer by design. Denna fil bär därför PII enbart i inflöde-raderna.║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * PURPOSE
 *   A pure, dependency-free static provider of a realistic synthetic incoming
 *   feed ("inflöde") for a socialtjänst-IFO (individ- och familjeomsorg). It is
 *   consumed ONLY by InflodeFeedService when the app-config gate
 *   'sdkmc' / 'hubs_start_inflode_demo' is set to '1'. With the gate '0'
 *   (default) the real, empty dev15 feed is returned and this file is never
 *   touched.
 *
 * SHAPE (mirrors the SPA demo `fetchInflodeSummary()` → { korgar, inflode }):
 *   korgar:  list<{ addr, label, scope, otriagerat }>
 *   inflode: list<{
 *       id, kind:'inflode',
 *       korg:{ addr, label, scope },
 *       channel:{ channel, channelLabel, messageType },
 *       messageType,
 *       avsandare,                         // funktion + org (anonym för anonyma)
 *       identitet:{ badge, verifierad },   // SITHS·LOA3 | BankID·LOA3 | anonym
 *       titel,                             // BARNETS namn + FIKTIVT personnummer
 *       inkomDatum,                        // ISO-8601, spridda senaste 5 dagar
 *       frist,                             // 14-dgr förhandsbedömning ELLER null
 *       provenance:{ state:'ej_registrerad', dnr, gallrasDatum, bevarasDatum },
 *   }>
 *
 * The korgar list is DERIVED from the rows (each row carries a korg; counts are
 * computed), so the two halves can never drift apart.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */

namespace OCA\SdkMc\Service\DemoData;

/**
 * Static synthetic-data provider. No services, no DB, no I/O — every method is
 * pure so it is trivially safe to call from a graceful, never-throws context.
 */
final class InflodeDemoData {

    /**
     * Anchor "today" for the demo so the spread of inkom-datum and the derived
     * 14-day förhandsbedömnings-frister stay internally consistent regardless of
     * the wall clock on dev15.
     */
    private const DEMO_TODAY = '2026-06-17';

    /** Förhandsbedömning enligt 11 kap. 1 a § SoL — skyndsamt, senast 14 dagar. */
    private const FORHANDSBEDOMNING_DAGAR = 14;

    /**
     * The full synthetic summary in the exact { korgar, inflode } contract.
     *
     * @return array{korgar: list<array<string,mixed>>, inflode: list<array<string,mixed>>}
     */
    public static function summary(): array {
        $inflode = self::inflode();
        return [
            'korgar' => self::korgarFromRows($inflode),
            'inflode' => $inflode,
        ];
    }

    /**
     * The ~12 synthetic incoming rows. Channels, senders, identity badges and
     * frist-tones are varied on purpose so the UI exercises every branch.
     *
     * inkomDatum is spread across the last 5 days (relative to DEMO_TODAY); each
     * förhandsbedömnings-frist is derived as inkom + 14 days so daysLeft/tone are
     * always coherent with the inkom-timestamp.
     *
     * @return list<array<string,mixed>>
     */
    public static function inflode(): array {
        // korg helpers (addr, label, scope) — match the demo korg vocabulary.
        $orosanmalan = ['addr' => 'orosanmalan@', 'label' => 'orosanmalan@', 'scope' => 'grupp'];
        $mottagningen = ['addr' => 'mottagningen@', 'label' => 'mottagningen@', 'scope' => 'grupp'];
        $barnFamilj = ['addr' => 'barn-familj@', 'label' => 'barn-familj@', 'scope' => 'grupp'];
        $vuxenEkonomi = ['addr' => 'vuxen-ekonomi@', 'label' => 'vuxen-ekonomi@', 'scope' => 'grupp'];
        $samverkan = ['addr' => 'samverkan@', 'label' => 'samverkan@', 'scope' => 'grupp'];
        $faxKorg = ['addr' => 'fax', 'label' => 'Fax', 'scope' => 'fax'];
        $personlig = ['addr' => 'personlig', 'label' => 'Personlig', 'scope' => 'personlig'];

        // channel helpers (channel, channelLabel, messageType).
        $sdk = static fn (string $type): array => ['channel' => 'sdk', 'channelLabel' => 'SDK-Meddelande', 'messageType' => $type];
        $fax = static fn (string $type): array => ['channel' => 'fax', 'channelLabel' => 'Fax', 'messageType' => $type];
        $secure = static fn (string $type): array => ['channel' => 'secure', 'channelLabel' => 'Säker E-post', 'messageType' => $type];
        $internal = static fn (string $type): array => ['channel' => 'internal', 'channelLabel' => 'Internpost', 'messageType' => $type];

        // identity helpers.
        $siths = ['badge' => 'SITHS · LOA3', 'verifierad' => true];
        $bankid = ['badge' => 'BankID · LOA3', 'verifierad' => true];
        $internt = ['badge' => 'Internt · LOA3', 'verifierad' => true];
        $anonym = ['badge' => 'Ej verifierad — anonym', 'verifierad' => false];

        $rows = [];

        // ── 1. Orosanmälan från skola (SITHS, SDK) — fersk, neutral frist ──────
        $rows[] = self::row([
            'id' => 'demo-inf-01',
            'korg' => $orosanmalan,
            'channel' => $sdk('orosanmalan'),
            'avsandare' => 'Kurator, Lindängsskolan',
            'identitet' => $siths,
            'titel' => 'Orosanmälan – Elsa Bergström (20140312-0411)',
            'inkomDatum' => self::day(0, '07:58'),
            'frist' => self::forhandsbedomning(self::day(0, '07:58')),
        ]);

        // ── 2. Orosanmälan från privatperson (anonym, fax) — neutral frist ─────
        $rows[] = self::row([
            'id' => 'demo-inf-02',
            'korg' => $faxKorg,
            'channel' => $fax('orosanmalan'),
            'avsandare' => 'Privat anmälare (anonym)',
            'identitet' => $anonym,
            'titel' => 'Orosanmälan – Hugo Lindqvist (20160521-1182)',
            'inkomDatum' => self::day(0, '06:40'),
            'frist' => self::forhandsbedomning(self::day(0, '06:40')),
        ]);

        // ── 3. Orosanmälan från BUP (BankID, säker e-post) — neutral frist ─────
        $rows[] = self::row([
            'id' => 'demo-inf-03',
            'korg' => $orosanmalan,
            'channel' => $secure('orosanmalan'),
            'avsandare' => 'Behandlare, BUP Malmö',
            'identitet' => $bankid,
            'titel' => 'Orosanmälan – Alva Nyström (20180830-0550)',
            'inkomDatum' => self::day(1, '08:14'),
            'frist' => self::forhandsbedomning(self::day(1, '08:14')),
        ]);

        // ── 4. Orosanmälan från polis (SITHS, SDK) — GUL (snart förfallen) ─────
        // Re-aktualiserad oro: förhandsbedömningen löper redan (start 12 dgr sen)
        // → ~2 dagar kvar → 'warning'.
        $rows[] = self::row([
            'id' => 'demo-inf-04',
            'korg' => $orosanmalan,
            'channel' => $sdk('orosanmalan'),
            'avsandare' => 'Polismyndigheten, region Syd',
            'identitet' => $siths,
            'titel' => 'Orosanmälan – Liam Andersson (20120907-0729)',
            'inkomDatum' => self::day(4, '16:20'),
            'frist' => self::forhandsbedomning(self::day(4, '16:20'), self::ymdDaysAgo(12)),
        ]);

        // ── 5. Orosanmälan från BVC (SITHS, SDK) — neutral frist ───────────────
        $rows[] = self::row([
            'id' => 'demo-inf-05',
            'korg' => $orosanmalan,
            'channel' => $sdk('orosanmalan'),
            'avsandare' => 'Sjuksköterska, BVC Rosengård',
            'identitet' => $siths,
            'titel' => 'Orosanmälan – Vera Holm (20191003-1440)',
            'inkomDatum' => self::day(2, '10:05'),
            'frist' => self::forhandsbedomning(self::day(2, '10:05')),
        ]);

        // ── 6. Orosanmälan från privatperson (anonym, fax) — RÖD (förfallen) ───
        // Liggetid: förhandsbedömningen startade 15 dgr sedan men är inte klar
        // → ~1 dag över frist → 'error' (kräver omedelbar åtgärd).
        $rows[] = self::row([
            'id' => 'demo-inf-06',
            'korg' => $faxKorg,
            'channel' => $fax('orosanmalan'),
            'avsandare' => 'Anhörig (anonym)',
            'identitet' => $anonym,
            'titel' => 'Orosanmälan – Noah Karlsson (20150708-2293)',
            'inkomDatum' => self::day(3, '21:11'),
            'frist' => self::forhandsbedomning(self::day(3, '21:11'), self::ymdDaysAgo(15)),
        ]);

        // ── 7. Biståndsansökan vuxen (BankID, säker e-post) — ingen 14-dgr-frist ─
        $rows[] = self::row([
            'id' => 'demo-inf-07',
            'korg' => $vuxenEkonomi,
            'channel' => $secure('bistandsansokan'),
            'avsandare' => 'Sökande (BankID)',
            'identitet' => $bankid,
            'titel' => 'Ansökan om bistånd enligt 4 kap. 1 § SoL – vuxen',
            'inkomDatum' => self::day(1, '11:42'),
            'frist' => null,
        ]);

        // ── 8. Ansökan ekonomiskt bistånd (BankID, SDK) — ingen 14-dgr-frist ───
        $rows[] = self::row([
            'id' => 'demo-inf-08',
            'korg' => $vuxenEkonomi,
            'channel' => $sdk('bistandsansokan'),
            'avsandare' => 'Sökande (BankID)',
            'identitet' => $bankid,
            'titel' => 'Ansökan om ekonomiskt bistånd (försörjningsstöd)',
            'inkomDatum' => self::day(2, '09:18'),
            'frist' => null,
        ]);

        // ── 9. Komplettering till pågående utredning (skola, SDK) — ingen frist ─
        $rows[] = self::row([
            'id' => 'demo-inf-09',
            'korg' => $barnFamilj,
            'channel' => $sdk('komplettering'),
            'avsandare' => 'Rektor, Augustenborgsskolan',
            'identitet' => $siths,
            'titel' => 'Komplettering – pedagogisk kartläggning, Olivia Ek (20100214-2030)',
            'inkomDatum' => self::day(0, '09:30'),
            'frist' => null,
        ]);

        // ── 10. Komplettering läkarutlåtande (BUP, säker e-post) — ingen frist ─
        $rows[] = self::row([
            'id' => 'demo-inf-10',
            'korg' => $barnFamilj,
            'channel' => $secure('komplettering'),
            'avsandare' => 'Läkare, BUP Lund',
            'identitet' => $bankid,
            'titel' => 'Komplettering – läkarutlåtande, William Berg (20081119-3176)',
            'inkomDatum' => self::day(3, '13:47'),
            'frist' => null,
        ]);

        // ── 11. Vård-samverkan / SIP-kallelse (region, säker e-post) — ingen frist ─
        $rows[] = self::row([
            'id' => 'demo-inf-11',
            'korg' => $samverkan,
            'channel' => $secure('samverkan'),
            'avsandare' => 'Samordnare, Region Skåne (SIP)',
            'identitet' => $bankid,
            'titel' => 'Kallelse till SIP – Maja Persson (20131217-0812)',
            'inkomDatum' => self::day(2, '14:55'),
            'frist' => null,
        ]);

        // ── 12. Vård-samverkan remiss (region, SDK) — ingen frist ──────────────
        $rows[] = self::row([
            'id' => 'demo-inf-12',
            'korg' => $samverkan,
            'channel' => $sdk('samverkan'),
            'avsandare' => 'Kurator, VC Kirseberg',
            'identitet' => $siths,
            'titel' => 'Samverkansförfrågan – Ebba Sandström (20171002-1930)',
            'inkomDatum' => self::day(4, '08:25'),
            'frist' => null,
        ]);

        // ── 13. Internpost från gruppledare (internt) — fördela inför beslut ───
        $rows[] = self::row([
            'id' => 'demo-inf-13',
            'korg' => $personlig,
            'channel' => $internal('internpost'),
            'avsandare' => 'Eva Lund (gruppledare)',
            'identitet' => $internt,
            'titel' => 'Internpost – fördelning inför beslut att inleda',
            'inkomDatum' => self::day(0, '08:05'),
            'frist' => null,
        ]);

        // ── 14. Orosanmälan från tandvård (SITHS, SDK) — neutral frist ─────────
        $rows[] = self::row([
            'id' => 'demo-inf-14',
            'korg' => $mottagningen,
            'channel' => $sdk('orosanmalan'),
            'avsandare' => 'Tandläkare, Folktandvården Möllevången',
            'identitet' => $siths,
            'titel' => 'Orosanmälan – Leo Johansson (20200115-0271)',
            'inkomDatum' => self::day(1, '15:33'),
            'frist' => self::forhandsbedomning(self::day(1, '15:33')),
        ]);

        return $rows;
    }

    /**
     * Build a complete inflöde row from the variable parts, filling the fixed
     * fields (kind, messageType mirror, provenance) so every row is shaped
     * identically. provenance is always 'ej_registrerad' — these are incoming
     * messages that have NOT yet been turned into a registered ärende (that is
     * the ärende-motorn's job, and it never stores PII).
     *
     * @param array{
     *   id:string,
     *   korg:array{addr:string,label:string,scope:string},
     *   channel:array{channel:string,channelLabel:string,messageType:string},
     *   avsandare:string,
     *   identitet:array{badge:string,verifierad:bool},
     *   titel:string,
     *   inkomDatum:string,
     *   frist:?array<string,mixed>
     * } $p
     * @return array<string,mixed>
     */
    private static function row(array $p): array {
        return [
            'id' => $p['id'],
            'kind' => 'inflode',
            'korg' => [
                'addr' => $p['korg']['addr'],
                'label' => $p['korg']['label'],
                'scope' => $p['korg']['scope'],
            ],
            'channel' => $p['channel'],
            'messageType' => $p['channel']['messageType'],
            'avsandare' => $p['avsandare'],
            'identitet' => $p['identitet'],
            'titel' => $p['titel'],
            'inkomDatum' => $p['inkomDatum'],
            'frist' => $p['frist'],
            // Incoming, not yet registered — no dnr / retention dates yet.
            'provenance' => [
                'state' => 'ej_registrerad',
                'dnr' => null,
                'gallrasDatum' => null,
                'bevarasDatum' => null,
            ],
        ];
    }

    /**
     * Derive the korgar list (addr/label/scope + raw incoming count) from the
     * rows, so korgar can never drift from the feed. otriagerat = number of
     * rows that landed in that korg (these are all "ej registrerade", i.e.
     * untriaged by definition).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function korgarFromRows(array $rows): array {
        $byAddr = [];
        $order = [];
        foreach ($rows as $row) {
            $korg = is_array($row['korg'] ?? null) ? $row['korg'] : [];
            $addr = (string)($korg['addr'] ?? '');
            if ($addr === '') {
                continue;
            }
            if (!isset($byAddr[$addr])) {
                $byAddr[$addr] = [
                    'addr' => $addr,
                    'label' => (string)($korg['label'] ?? $addr),
                    'scope' => (string)($korg['scope'] ?? 'grupp'),
                    'otriagerat' => 0,
                ];
                $order[] = $addr;
            }
            $byAddr[$addr]['otriagerat']++;
        }

        $korgar = [];
        foreach ($order as $addr) {
            $korgar[] = $byAddr[$addr];
        }
        return $korgar;
    }

    /**
     * A 14-day förhandsbedömnings-frist object, derived from the inkom-timestamp
     * so daysLeft and tone are always coherent with when the message arrived.
     * Mirrors the demo's `frist(typ,label,due,start,daysLeft,tone)` shape.
     *
     * tone: 'error' if overdue, 'warning' if ≤ 3 days left, else 'neutral'.
     *
     * @return array<string,mixed>
     */
    private static function forhandsbedomning(string $inkomIso, ?string $startOverride = null): array {
        // Normally the förhandsbedömnings-klocka starts when the anmälan kom in.
        // For a re-aktualiserad/vidarebefordrad oro the clock may already be
        // running (start earlier than this message's inkom) — pass $startOverride
        // (YYYY-MM-DD) to model that, which yields a realistic warning/error tone.
        $start = $startOverride !== null ? $startOverride : self::dateOnly($inkomIso);
        $due = self::addDays($start, self::FORHANDSBEDOMNING_DAGAR);
        $daysLeft = self::daysBetween(self::DEMO_TODAY, $due);

        if ($daysLeft < 0) {
            $tone = 'error';
        } elseif ($daysLeft <= 3) {
            $tone = 'warning';
        } else {
            $tone = 'neutral';
        }

        return [
            'typ' => 'forhandsbedomning',
            'label' => 'Förhandsbedömning',
            'due' => $due,
            'start' => $start,
            'daysLeft' => $daysLeft,
            'tone' => $tone,
        ];
    }

    /**
     * An ISO-8601 timestamp $daysAgo days before DEMO_TODAY at the given HH:MM
     * (local, no timezone suffix — matches the demo's naive timestamps).
     */
    private static function day(int $daysAgo, string $hm): string {
        $d = self::addDays(self::DEMO_TODAY, -$daysAgo);
        return $d . 'T' . $hm . ':00';
    }

    /** YYYY-MM-DD for a date $daysAgo days before DEMO_TODAY (frist-start helper). */
    private static function ymdDaysAgo(int $daysAgo): string {
        return self::addDays(self::DEMO_TODAY, -$daysAgo);
    }

    /** YYYY-MM-DD portion of an ISO timestamp. */
    private static function dateOnly(string $iso): string {
        return substr($iso, 0, 10);
    }

    /** Add (or subtract) whole days to a YYYY-MM-DD date, returning YYYY-MM-DD. */
    private static function addDays(string $ymd, int $days): string {
        $dt = self::mkDate($ymd);
        $dt = $dt->modify(($days >= 0 ? '+' : '') . $days . ' day');
        return $dt->format('Y-m-d');
    }

    /** Whole days from $fromYmd to $toYmd (positive if $to is later). */
    private static function daysBetween(string $fromYmd, string $toYmd): int {
        $from = self::mkDate($fromYmd);
        $to = self::mkDate($toYmd);
        $diff = $from->diff($to);
        return (int)$diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    /** A UTC-midnight DateTimeImmutable for a YYYY-MM-DD string. */
    private static function mkDate(string $ymd): \DateTimeImmutable {
        return new \DateTimeImmutable($ymd . 'T00:00:00', new \DateTimeZone('UTC'));
    }
}
