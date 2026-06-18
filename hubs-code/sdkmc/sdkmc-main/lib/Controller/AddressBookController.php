<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;

class AddressBookController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function show(string $type): JSONResponse {
        $types = ['addresses' => 'addressBookAddresses', 'organizations' => 'addressBookOrganizations', 'codesystems' => 'addressBookCodesystems'];
        if (array_key_exists($type, $types)) {
            return (new JSONResponse([]))->setData($this->appConfig->getAppValueArray($types[$type], [], true)); // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
        return (new JSONResponse([]))->setData([]); // @phpstan-ignore argument.type (Error in JSONResponse type definition)
    }
}
