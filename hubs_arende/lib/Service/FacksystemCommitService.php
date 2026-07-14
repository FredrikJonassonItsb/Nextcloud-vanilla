<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Integration\Port\Exception\IntegrationException;
use OCA\HubsArende\Integration\Port\FacksystemCommitPort;
use OCA\HubsArende\Integration\Stub\FacksystemCommitStub;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * FacksystemCommitService — den enda vägen in i facksystem-commit för ärende-motorn.
 *
 * Ansvar:
 *  - KONSUMERAR den DI-resolverade {@see FacksystemCommitPort}. Port-VALET (stub vs live)
 *    görs på EN ENDA plats: {@see Application::register()} binder porten utifrån app-config-
 *    nyckeln `integration_mode_facksystem` (prefix {@see Application::INTEGRATION_MODE_PREFIX}).
 *    Denna service gör INGEN egen mode-resolution — den litar på den injicerade porten.
 *  - Routar till rätt facksystem-modul via commit_destination + arendeTyp.frends_modul.
 *  - Returnerar ett VERIFIERAT kvitto {ok,dnr,committedAt,gallrasDatum,verifierad:true}
 *    — samma kontrakt oavsett stub eller live, så att ArendeService::commit() är agnostisk.
 *
 * Kontraktet (delad spec): public function commit(string $hubsCaseId, array $payload): array.
 * Payloaden bär (minst) 'arendetyp', 'frends_modul'/'commit_destination', 'typ', 'artefakter'.
 *
 * INTEGRATION_MODE (canonical, EN nyckel): `integration_mode_facksystem` (app-config, app-id
 * hubs_arende), default 'stub'. Samma nyckel som {@see Application::resolvePort()} och
 * {@see StatusService} läser — tidigare läste denna service en AVVIKANDE nyckel
 * (`integration.facksystem`), vilket innebar att en config-ändring inte styrde läget. Fixat.
 * Värdet 'live' kräver att en live-FacksystemCommitPort registrerats i modeMap:en i
 * Application::register() (se Integration/README.md, seam D); tills dess resolverar DI till
 * stubben och `mode()` nedan är enbart en logg-etikett.
 */
class FacksystemCommitService {
    public const MODE_STUB = 'stub';
    public const MODE_LIVE = 'live';

    /** Canonical app-config-nyckel för facksystem-läget (= Application-prefix + portnamn). */
    public const CONFIG_KEY_MODE = Application::INTEGRATION_MODE_PREFIX . 'facksystem';

    /**
     * Giltiga commit_destination-värden (invariant: commit_destination NOT NULL).
     * triage_forward/karantan committar inte till facksystem — de avvisas här.
     *
     * @var array<int,string>
     */
    private const FACKSYSTEM_DESTINATIONS = ['facksystem', 'diarium', 'e_arkiv', 'extern_myndighet'];

    /**
     * @param IAppConfig $appConfig Läser canonical INTEGRATION_MODE-nyckeln (logg-etikett).
     * @param LoggerInterface $logger Spårbarhet (avvisade commits, preliminära kvitton).
     * @param FacksystemCommitPort|null $port Den DI-resolverade porten (stub i v1, live i seam D).
     *        Null endast i den körbara-utan-DI-vägen (positionell testharness) → lazy stub-fallback.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private ?FacksystemCommitPort $port = null,
    ) {
    }

    /**
     * Committa en handling till facksystemet och returnera ett VERIFIERAT kvitto.
     *
     * @param string $hubsCaseId Kanonisk ärende-token (UUID v4).
     * @param array<string,mixed> $payload Minst:
     *        ['arendetyp' => string, 'frends_modul' => string|null,
     *         'commit_destination' => string, 'typ' => string, 'artefakter' => array, ...].
     *
     * @return array<string,mixed> {ok, dnr, committedAt, gallrasDatum, verifierad:true, hubsCaseId, modul, receipt}.
     *
     * @throws IntegrationException om commit_destination är otillåtet eller porten felar
     *         (ArendeService fångar och kör sagans kompensering).
     */
    public function commit(string $hubsCaseId, array $payload): array {
        $destination = (string)($payload['commit_destination'] ?? '');
        $this->assertCommittable($hubsCaseId, $destination);

        $modul = $this->resolveModul($hubsCaseId, $payload);
        $port = $this->port();

        $this->logger->info('hubs_arende: facksystem-commit', [
            'hubsCaseId'  => $hubsCaseId,
            'destination' => $destination,
            'modul'       => $modul,
            'mode'        => $this->mode(),
            'port'        => $port::class,
        ]);

        try {
            $kvitto = $port->commit($hubsCaseId, $modul, $payload);
        } catch (IntegrationException $e) {
            $this->logger->warning('hubs_arende: facksystem-commit FEL', [
                'hubsCaseId' => $hubsCaseId,
                'modul'      => $modul,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }

        // Kontraktsgaranti: ett kvitto som returneras härifrån MÅSTE vara verifierat.
        // I async-läge får ArendeService inte starta retention förrän callbacken kört;
        // tjänsten markerar tydligt om kvittot ännu är preliminärt.
        if (($kvitto['verifierad'] ?? false) !== true) {
            $this->logger->info('hubs_arende: preliminärt (ej verifierat) kvitto — väntar callback', [
                'hubsCaseId'    => $hubsCaseId,
                'callbackToken' => $kvitto['callbackToken'] ?? null,
            ]);
        }

        return $kvitto;
    }

    /**
     * Aktuellt INTEGRATION_MODE för facksystem-porten ('stub'|'live') — läst ur den
     * CANONICAL nyckeln (samma som Application::resolvePort + StatusService). Detta är en
     * logg-/status-ETIKETT; det faktiska port-valet görs i Application::register().
     */
    public function mode(): string {
        $mode = $this->appConfig->getAppValueString(self::CONFIG_KEY_MODE, self::MODE_STUB);
        return $mode === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_STUB;
    }

    /**
     * A12: spara PERMANENT provenans om ett moment (rättskälla som överlever gallring).
     *
     * Delegerar till den DI-resolverade porten. BEST-EFFORT — samma disciplin som en
     * fristående provenansnot ska ha: den får ALDRIG fälla den redan verifierade commiten.
     * Alla fel (även oväntade) fångas och loggas PII-fritt; metoden returnerar false i
     * stället för att kasta så att ArendeService kan fortsätta. Facksystem-commiten är
     * sanningen; provenansnoten är sekundär, gallrings-överlevande spårbarhet.
     *
     * PII-invariant: $moment bär enbart enum-koder + referenser (lagrum/utfall/aktorUid/
     * dnr/artefaktRef) — aldrig fri text eller personuppgifter.
     *
     * @param string $hubsCaseId Kanonisk ärende-token (UUID v4).
     * @param array<string,mixed> $moment {moment,lagrum,utfall,aktorUid,tid?,artefaktRef?,harCommit?,dnr?}.
     *
     * @return bool true om provenansen sparades, annars false (aldrig kast).
     */
    public function sparaProvenans(string $hubsCaseId, array $moment): bool {
        try {
            $ok = $this->port()->sparaProvenans($hubsCaseId, $moment);
            if (!$ok) {
                $this->logger->warning('hubs_arende: sparaProvenans returnerade false (best-effort)', [
                    'hubsCaseId' => $hubsCaseId,
                    'moment'     => (string)($moment['moment'] ?? ''),
                ]);
            }
            return $ok;
        } catch (\Throwable $e) {
            // Best-effort: får aldrig fälla commiten. Logga PII-fritt och fortsätt.
            $this->logger->warning('hubs_arende: sparaProvenans FEL (best-effort, ignoreras)', [
                'hubsCaseId' => $hubsCaseId,
                'moment'     => (string)($moment['moment'] ?? ''),
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ------------------------------------------------------------------ //

    /**
     * Den DI-resolverade porten. Lazy stub-fallback ENBART för den körbara-utan-DI-vägen
     * (positionell testharness); i drift injicerar Application::register() alltid porten.
     */
    private function port(): FacksystemCommitPort {
        return $this->port ??= new FacksystemCommitStub();
    }

    /**
     * Säkerställ att destinationen är en facksystem-committbar destination.
     * Invariant: commit_destination NOT NULL; triage_forward/karantan committar aldrig.
     */
    private function assertCommittable(string $hubsCaseId, string $destination): void {
        if ($destination === '') {
            throw new IntegrationException(
                'hubs_arende: commit_destination saknas (NOT NULL-invariant) för ' . $hubsCaseId
            );
        }
        if (!in_array($destination, self::FACKSYSTEM_DESTINATIONS, true)) {
            throw new IntegrationException(
                'hubs_arende: commit_destination "' . $destination
                . '" är inte facksystem-committbar (' . $hubsCaseId . ').'
            );
        }
    }

    /**
     * Routa till facksystem-modul ur payload.frends_modul. FAIL-CLOSED: en tom
     * modul betyder 'ärv värd-/beslut-ärendets modul' (komplettering/verkställighet,
     * frendsModul=null i registret) — INTE 'använd en klinisk default'. Att tyst
     * defaulta (tidigare 'ifo_barn') kunde routa t.ex. en LSS-/ek_bistånd-uppföljning
     * in i fel Treserva-modul = felrouting = sekretessincident (spec §7.1). Den
     * ärvda modulen trådas in av (framtida) attach-vägen via payload['frends_modul'];
     * saknas den kastar vi hellre än gissar.
     *
     * @param array<string,mixed> $payload
     * @throws IntegrationException när ingen modul kan resolvas (ingen gissning).
     */
    private function resolveModul(string $hubsCaseId, array $payload): string {
        $modul = (string)($payload['frends_modul'] ?? '');
        if ($modul === '') {
            throw new IntegrationException(
                'hubs_arende: frends_modul saknas — ärv-typ (komplettering/verkställighet) kräver '
                . 'värd-ärendets modul; motorn gissar aldrig en facksystem-modul (' . $hubsCaseId . ').'
            );
        }
        return $modul;
    }
}
