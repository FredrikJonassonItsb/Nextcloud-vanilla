<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

/**
 * RETRYBAR infrastruktur-fault mot openbrain-svc:s provisioner-API (/provision/*).
 *
 * KONTRAKT (SPEC-BRAIN-PER-ÄRENDE kap 3.3): {@see BrainProvisionService::provision()}
 * kastar DENNA — och ENDAST denna — vid en RETRYBAR fault: connect-fel, timeout eller
 * HTTP 5xx. Ett PERMANENT fel (409/422 — tenant redan fryst/gallrad, ogiltigt
 * hubs_case_id, schema-kollision) kastar ALDRIG; det returneras i stället som
 * {@see BrainProvisionService::provision()}-arrayen `['permanent_fel' => true, 'kod' => ...]`.
 *
 * Poängen med en EGEN, snäv exception-typ: SAGA:ns R2b-hake (kap 1.2/3.3) fångar den
 * explicit och lägger ärendet i den durabla retry-kön ({@see BrainProvisionRetryService}),
 * UTAN att låta något annat kast nå createCase:s yttre catch — invarianten
 * "ärendeskapande blockeras ALDRIG av AI-infra" (kap 1.2) hålls. Ingen generisk
 * \Throwable ska maskeras som denna: den signalerar specifikt "provisionern var onåbar,
 * försök igen senare", inte "programfel".
 */
class BrainProvisionUnavailable extends \RuntimeException {
}
