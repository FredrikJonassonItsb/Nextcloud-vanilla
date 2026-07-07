<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * HANDLING-FRÅN-MALL Fas 1 — läser dokumentmallarna (.docx) ur den DELADE
 * MALLMAPPEN: samma mapp som Filers inbyggda mallväljare pekar på (på dev15:
 * admin:/Mallar med undermappen "Socialsekreterare - barn och familj/").
 *
 * EN källa till sanning för mallarna: verksamheten underhåller mallbiblioteket
 * i Filer precis som vanligt; den här tjänsten läser SAMMA filer server-side.
 * Ingen egen mall-lagring, ingen kopia — mallmappen ägs av mall_agare och
 * resolvas via getUserFolder(ägare)->get(mapp). Server-side-läsningen är OK
 * eftersom motorn är behörighetsgrindad uppströms (HandlingService/
 * ArendeService avgör VEM som får skapa en handling i VILKET ärende) —
 * mallarna i sig innehåller inga personuppgifter, bara platshållare.
 *
 * ID-MODELL: en malls id är dess relativa path under mallmappen (t.ex.
 * "Socialsekreterare - barn och familj/02 Omedelbar skyddsbedömning.docx").
 * lasMall() validerar id:t mot den faktiska listningen (exakt match) —
 * ALDRIG fri path in i get() ⇒ ingen path-traversal ut ur mallmappen.
 *
 * KONFIG (app-värden med default, ändringsbara via occ config:app:set):
 *   mall_agare = "admin"   — uid som äger mallmappen
 *   mall_mapp  = "Mallar"  — mappnamn under ägarens hem
 *
 * GRACEFUL: ingen IRootFolder (testharness) eller mallmapp som inte går att
 * resolva ⇒ isAvailable() false och listMallar() [] (debug-logg, aldrig kast).
 * lasMall() på okänt id kastar däremot \InvalidArgumentException — det är ett
 * anropar-fel, inte en miljöbrist.
 *
 * PII-DOKTRIN: här finns inget PII att läcka (mallar är blanketter), men
 * loggarna håller ändå huset-linjen: antal/mallnamn/mappnamn — aldrig värden.
 */
class MallService {
    /** App-konfignyckel: uid för mallmappens ägare. */
    public const KONFIG_AGARE = 'mall_agare';

    /** App-konfignyckel: mallmappens namn under ägarens hem. */
    public const KONFIG_MAPP = 'mall_mapp';

    /** Default-ägare för mallmappen (dev15: admin). */
    public const DEFAULT_AGARE = 'admin';

    /** Default-mappnamn (samma mapp som Filers mallväljare). */
    public const DEFAULT_MAPP = 'Mallar';

    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private ?IRootFolder $rootFolder = null,
    ) {
    }

    /**
     * Är mallbiblioteket tillgängligt? (rootFolder finns + mallmappen resolverbar)
     * Graceful: false betyder "visa inte mall-funktionen", aldrig ett fel.
     */
    public function isAvailable(): bool {
        return $this->resolveMallmapp() !== null;
    }

    /**
     * Lista alla .docx-mallar i mallmappen, REKURSIVT genom undermappar.
     *
     * @return array<int, array{id: string, namn: string, mapp: string}>
     *         id   = relativ path under mallmappen (t.ex.
     *                "Socialsekreterare - barn och familj/02 Omedelbar skyddsbedömning.docx")
     *         namn = filnamnet utan .docx-ändelsen
     *         mapp = undermappens namn ('' för mallar direkt i mallmappen)
     *         Sorterad på id (stabil ordning för GUI + validering).
     */
    public function listMallar(): array {
        $mapp = $this->resolveMallmapp();
        if ($mapp === null) {
            return [];
        }

        $mallar = [];
        $this->samlaMallar($mapp, '', $mallar);

        usort($mallar, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $this->logger->debug('hubs_arende: MallService.listMallar', [
            'app' => 'hubs_arende',
            'antal' => count($mallar),
        ]);

        return $mallar;
    }

    /**
     * Läs en malls råa .docx-innehåll (bytes) för ifyllnad/kopiering.
     *
     * TRAVERSAL-GRIND: id:t valideras mot listMallar()-id:na med EXAKT match —
     * bara paths som den rekursiva listningen själv har producerat accepteras,
     * aldrig en fri sträng in i Folder::get().
     *
     * @param string $id Mall-id (relativ path under mallmappen, från listMallar()).
     * @return string Filens råa innehåll.
     * @throws \InvalidArgumentException okänt id (även när mappen är otillgänglig).
     * @throws \RuntimeException känd mall som ändå inte gick att läsa.
     */
    public function lasMall(string $id): string {
        $kand = false;
        foreach ($this->listMallar() as $mall) {
            if ($mall['id'] === $id) {
                $kand = true;
                break;
            }
        }
        if (!$kand) {
            // Mallnamn/paths är inte PII — ok att peka ut vilket id som saknas.
            throw new \InvalidArgumentException('Okänd mall: ' . $id);
        }

        $mapp = $this->resolveMallmapp();
        if ($mapp === null) {
            // Race: mappen försvann mellan listning och läsning.
            throw new \InvalidArgumentException('Okänd mall: ' . $id);
        }

        try {
            $node = $mapp->get($id);
            if (!($node instanceof File)) {
                throw new \RuntimeException('Mallen är inte en fil: ' . $id);
            }
            return $node->getContent();
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: MallService — kunde ej läsa mall', [
                'app' => 'hubs_arende',
                'mallId' => $id,
                'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Kunde inte läsa mallen: ' . $id, 0, $e);
        }
    }

    /**
     * S4 — läs mallens STRUKTURERADE DEFINITION (Definitioner/<mall>.json i
     * mallmappen, autogenererad av build-kedjan: mallId, titel, tokens[],
     * falt[]). Definitionen är schemat som per-mall-filtrerar utkastets fält
     * ({@see ArendedataService::byggUtkast()}) och grunden för framtida
     * professioner/content controls (ANALYS-BLANKETTSTANDARD S4).
     *
     * GRACEFUL: null vid okänt mallId, saknad definitionsfil eller trasig
     * JSON — anroparen faller tillbaka på ofiltrerat utkast. Traversal-skyddet
     * ärvs: mallId valideras mot listMallar() innan någon path byggs.
     *
     * @return array<string,mixed>|null
     */
    public function lasDefinition(string $mallId): ?array {
        $kand = false;
        foreach ($this->listMallar() as $mall) {
            if ($mall['id'] === $mallId) {
                $kand = true;
                break;
            }
        }
        $mapp = $kand ? $this->resolveMallmapp() : null;
        if ($mapp === null) {
            return null;
        }

        $defPath = (str_contains($mallId, '/') ? dirname($mallId) . '/' : '')
            . 'Definitioner/'
            . preg_replace('/\.docx$/i', '.json', basename($mallId));

        try {
            $node = $mapp->get($defPath);
            if (!($node instanceof File)) {
                return null;
            }
            $def = json_decode($node->getContent(), true);
            return is_array($def) ? $def : null;
        } catch (\Throwable $e) {
            $this->logger->debug('hubs_arende: MallService — definition saknas/oläsbar (graceful)', [
                'app' => 'hubs_arende',
                'mallId' => $mallId,
            ]);
            return null;
        }
    }

    // ------------------------------------------------------------------ //

    /**
     * Resolva mallmappen (ägarens hem → mall_mapp). Null = ej tillgänglig
     * (ingen rootFolder, okänd ägare eller mapp saknas) — graceful, aldrig kast.
     */
    private function resolveMallmapp(): ?Folder {
        if ($this->rootFolder === null) {
            return null;
        }

        $agare = $this->appConfig->getAppValueString(self::KONFIG_AGARE, self::DEFAULT_AGARE);
        $mapp = $this->appConfig->getAppValueString(self::KONFIG_MAPP, self::DEFAULT_MAPP);

        try {
            $node = $this->rootFolder->getUserFolder($agare)->get($mapp);
            return $node instanceof Folder ? $node : null;
        } catch (\Throwable $e) {
            $this->logger->debug('hubs_arende: MallService — mallmappen ej resolverbar (graceful)', [
                'app' => 'hubs_arende',
                'agare' => $agare,
                'mapp' => $mapp,
            ]);
            return null;
        }
    }

    /**
     * Rekursiv insamling av .docx-filer under en mapp.
     *
     * @param Folder                                                $folder  Mappen som listas.
     * @param string                                                $prefix  Relativ path-prefix under mallmappen ('' i roten).
     * @param array<int, array{id: string, namn: string, mapp: string}> $mallar Ackumulator (byref).
     */
    private function samlaMallar(Folder $folder, string $prefix, array &$mallar): void {
        try {
            $noder = $folder->getDirectoryListing();
        } catch (\Throwable $e) {
            // En oläsbar undermapp fäller inte hela listningen — graceful.
            $this->logger->debug('hubs_arende: MallService — undermapp ej läsbar (graceful)', [
                'app' => 'hubs_arende',
                'prefix' => $prefix,
            ]);
            return;
        }

        foreach ($noder as $node) {
            $namn = $node->getName();
            if ($node instanceof Folder) {
                $this->samlaMallar($node, $prefix . $namn . '/', $mallar);
                continue;
            }
            if (!($node instanceof File) || !str_ends_with(strtolower($namn), '.docx')) {
                continue;
            }
            $mallar[] = [
                'id' => $prefix . $namn,
                'namn' => substr($namn, 0, -strlen('.docx')),
                'mapp' => $prefix === '' ? '' : rtrim($prefix, '/'),
            ];
        }
    }
}
