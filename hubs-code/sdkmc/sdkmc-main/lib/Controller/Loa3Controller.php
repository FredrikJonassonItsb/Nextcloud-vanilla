<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCA\SdkMc\Service\UpgradeLoginService;
use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;

class Loa3Controller extends Controller {
    public function __construct(
        private UpgradeLoginService $service,
        string $appName,
        IRequest $request,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return RedirectResponse<302, array{}>
     */
    public function upgrade(string $returnUrl): RedirectResponse {
        $this->service->upgradeLogin($returnUrl);
        return new RedirectResponse($returnUrl); // @phpstan-ignore return.type (Redirect type has broken definition)
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @return TemplateResponse<200, array{}>
     */
    public function redirect(): TemplateResponse {
        return new TemplateResponse(
            $this->appName,
            'RedirectToLoa3',
            ['url' => $this->service->getLoginUrl()]
        );
    }
}
