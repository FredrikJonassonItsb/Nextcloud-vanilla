<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Exception\PiiRejectedException;
use Psr\Log\LoggerInterface;

/**
 * The relay endpoint (INTERAKTIONSDESIGN §2.4): the ONLY path from an LLM to
 * a human board. The runner calls it (e.g. the non-blocking interpretation
 * checkpoint, step 12b); deterministic PHP writes as the bot via the shared
 * mirror write module. Guards, all mechanical:
 *
 *   - requires an OPEN link for the engine card,
 *   - PII firewall on the content,
 *   - rate limit: ONE note per link state/phase (idempotency key),
 *   - ≤900 chars + ⇄ marker via MirrorService (constraint 4).
 */
class OriginNoteService {
    public function __construct(
        private CardLinkMapper $linkMapper,
        private EngineEventMapper $eventMapper,
        private MirrorService $mirror,
        private PiiFirewall $firewall,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{linkId:int,originCard:int,commentId:int}
     * @throws NotEligibleException no open link / rate-limited
     * @throws PiiRejectedException firewall hit
     */
    public function post(int $engineCardId, string $text): array {
        $text = trim($text);
        if ($text === '') {
            throw new NotEligibleException('empty note');
        }
        $link = $this->linkMapper->findOpenByEngineCard($engineCardId);
        if ($link === null) {
            throw new NotEligibleException('no open link for this engine card');
        }

        $hit = $this->firewall->scan([$text]);
        if ($hit !== null) {
            throw new PiiRejectedException($hit, $this->firewall->commentRefusalMessage());
        }

        // Rate limit: one relay note per (link, state, phase, rework cycle).
        $rateKey = 'note:' . $link->getId() . ':' . $link->getState() . ':' . $link->getPhase()
            . ':' . $link->getReworkCycles();
        if (!$this->eventMapper->claimKey($rateKey, 'mirror', (int)$link->getId())) {
            throw new NotEligibleException('origin note already posted for this link state');
        }

        $commentId = $this->mirror->writeOriginComment($link, $text);
        return [
            'linkId' => (int)$link->getId(),
            'originCard' => $link->getOriginCard(),
            'commentId' => $commentId,
        ];
    }
}
