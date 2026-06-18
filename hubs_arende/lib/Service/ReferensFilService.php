<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\PekareMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Fas F (referens-modell) — skriver en REFERENS till ett meddelande i ärenderummets
 * groupfolder. En POINTER, ALDRIG en kopia.
 *
 * NEVER-SoR: Hubs är aldrig System of Record. Meddelandet (verksamhetsdatat) stannar
 * i den säkra brevlådan; här läggs bara en liten .url-genväg i akten som innehåller
 * ENDAST djuplänk + meddelande-id (DB-id) + hubsCaseId — aldrig body, ämne i klartext
 * eller bilagor. Att skriva innehåll hit vore en kopia av verksamhetsdata över
 * sekretessgräns (OSL 26 kap) = exakt det motorn är byggd för att undvika.
 *
 * Filen ligger i ärenderummets groupfolder och ärver därmed dess per-case-ACL (Fas E)
 * → bara ärendets medlemmar ser referensen, och den FÖLJER MED vid handoff (den sitter
 * på ärende-/folder-nivå, inte handläggar-nivå).
 *
 * Registreras som Pekare(objektTyp='groupfolder_ref') så den gallras/kompenseras
 * symmetriskt; filen försvinner ändå när groupfoldern rivs (R4-compensation / gallring
 * / purge), detta är ett extra spår för enumererbarhet.
 *
 * GRACEFUL: ingen IRootFolder (testharness) eller groupfoldern ej resolverbar ⇒ no-op
 * + null; koppling fungerar ändå (taggen är den durabla bäraren).
 */
class ReferensFilService {
    /** objekt_typ för referens-fil-pekaren. */
    public const OBJEKT_TYP = 'groupfolder_ref';

    public function __construct(
        private PekareMapper $pekareMapper,
        private LoggerInterface $logger,
        private ?IRootFolder $rootFolder = null,
        private ?IURLGenerator $urlGenerator = null,
    ) {
    }

    /**
     * Skriv en referens-fil för ett meddelande i ärenderummets groupfolder.
     * IDEMPOTENT: samma meddelande-id ⇒ samma (hashade) filnamn, skrivs över.
     *
     * @param string      $hubsCaseId Kanonisk ärende-token (UUID v4).
     * @param string      $messageId  sdkmc/mail meddelande-DB-id (heltal som sträng).
     * @param string|null $djuplank   Färdig djuplänk till mail-tråden; null ⇒ mail-app-rot.
     * @return string|null Filnamnet (= pekarens objekt_id), eller null (no-op/fel).
     */
    public function skrivMeddelandeReferens(string $hubsCaseId, string $messageId, ?string $djuplank = null): ?string {
        if ($this->rootFolder === null || $messageId === '') {
            return null;
        }

        $folder = $this->resolveGroupfolder($hubsCaseId);
        if ($folder === null) {
            return null;
        }

        // Hashat filnamn — ingen rå PII/Message-ID i klartext (jfr safeRef-mönstret).
        $filnamn = 'msg-' . substr(hash('sha256', $messageId), 0, 12) . '.url';
        $innehall = $this->byggReferensInnehall($hubsCaseId, $messageId, $djuplank);

        try {
            if ($folder->nodeExists($filnamn)) {
                $node = $folder->get($filnamn);
                $node->putContent($innehall);
            } else {
                $folder->newFile($filnamn, $innehall);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: ReferensFilService — kunde ej skriva referens-fil (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }

        // Registrera pekaren idempotent (för gallring/enumererbarhet).
        if (!$this->refPekareFinns($hubsCaseId, $filnamn)) {
            $this->pekareMapper->record($hubsCaseId, self::OBJEKT_TYP, $filnamn);
        }

        $this->logger->info('hubs_arende: ReferensFilService.skrivMeddelandeReferens', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'fil' => $filnamn,
        ]);

        return $filnamn;
    }

    /**
     * Ta bort ALLA referens-filer (+ deras pekare) för ett ärende. Anropas av
     * gallring/purge/compensation. Graceful: en saknad fil/folder hoppas.
     */
    public function taBortReferenser(string $hubsCaseId): void {
        $folder = $this->resolveGroupfolder($hubsCaseId);
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, self::OBJEKT_TYP) as $p) {
            $filnamn = $p->getObjektId();
            if ($folder !== null) {
                try {
                    if ($folder->nodeExists($filnamn)) {
                        $folder->get($filnamn)->delete();
                    }
                } catch (\Throwable $e) {
                    // Filen försvinner ändå när groupfoldern rivs — graceful.
                }
            }
        }
        $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, self::OBJEKT_TYP);
    }

    // ------------------------------------------------------------------ //

    /**
     * Resolva ärenderummets groupfolder-Node via Pekare(objektTyp='groupfolder') och
     * groupfolders' interna jail-path '__groupfolders/{folderId}'. Null = ej resolverbar.
     */
    private function resolveGroupfolder(string $hubsCaseId): ?Folder {
        if ($this->rootFolder === null) {
            return null;
        }
        $folderId = null;
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') as $p) {
            $folderId = (int)$p->getObjektId();
            break;
        }
        if ($folderId === null) {
            return null;
        }
        try {
            $node = $this->rootFolder->get('__groupfolders/' . $folderId);
            return $node instanceof Folder ? $node : null;
        } catch (\Throwable $e) {
            $this->logger->debug('hubs_arende: ReferensFilService — groupfolder ej resolverbar (graceful)', [
                'app' => 'hubs_arende',
                'folderId' => $folderId,
            ]);
            return null;
        }
    }

    /**
     * Bygg .url-innehållet (InternetShortcut). ENDAST pekar-metadata — ingen PII.
     */
    private function byggReferensInnehall(string $hubsCaseId, string $messageId, ?string $djuplank): string {
        $url = $djuplank ?? $this->malllank();
        // CRLF — Windows .url-format. [Hubs]-sektionen ignoreras av webbläsare men
        // ger maskinläsbar koppling tillbaka till meddelandet/ärendet.
        $rader = [
            '[InternetShortcut]',
            'URL=' . $url,
            '[Hubs]',
            'HubsCaseId=' . $hubsCaseId,
            'MessageId=' . $messageId,
        ];
        return implode("\r\n", $rader) . "\r\n";
    }

    /** Fallback-länk till mail-appen (F3 förser den precisa tråd-länken). */
    private function malllank(): string {
        if ($this->urlGenerator === null) {
            return '#';
        }
        try {
            return $this->urlGenerator->linkToRouteAbsolute('mail.page.index');
        } catch (\Throwable $e) {
            return $this->urlGenerator->getBaseUrl();
        }
    }

    /** Finns redan en referens-pekare med detta filnamn? (idempotens) */
    private function refPekareFinns(string $hubsCaseId, string $filnamn): bool {
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, self::OBJEKT_TYP) as $p) {
            if ($p->getObjektId() === $filnamn) {
                return true;
            }
        }
        return false;
    }
}
