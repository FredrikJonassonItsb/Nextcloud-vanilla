<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Controller;

use OCA\HubsStart\Service\PreferencesService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * OCS API for per-user Hubs Start UI preferences.
 * Routes: GET/PUT /ocs/v2.php/apps/hubs_start/api/v1/preferences
 */
class PreferencesController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private PreferencesService $preferences,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function get(): DataResponse {
        return new DataResponse($this->preferences->get($this->userId));
    }

    /**
     * @param bool|null $onboardingSeen
     * @param bool|null $keyboardMode
     */
    #[NoAdminRequired]
    public function update(?bool $onboardingSeen = null, ?bool $keyboardMode = null): DataResponse {
        $partial = [];
        if ($onboardingSeen !== null) {
            $partial['onboardingSeen'] = $onboardingSeen;
        }
        if ($keyboardMode !== null) {
            $partial['keyboardMode'] = $keyboardMode;
        }
        return new DataResponse($this->preferences->update((string)$this->userId, $partial));
    }
}
