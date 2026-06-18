<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Middleware;

use Exception;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Services\IAppConfig;
use OCA\SdkMc\Service\UpgradeLoginService;
use OCP\Files\NotPermittedException;

class WopiTokenMiddleware extends Middleware {
    public function __construct(
        private IAppConfig $appConfig,
        private UpgradeLoginService $service,
    ) {
    }

    public function beforeOutput($controller, $methodName, $output): string {
        if (get_class($controller) !== 'OCA\\Richdocuments\\Controller\\DocumentController' || $methodName !== 'token') {
            return $output;
        }

        try {
            $outputArray = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($outputArray) || !array_key_exists('token', $outputArray)) {
                if (is_string($outputArray)) {
                    throw new NotPermittedException($output);
                }
                throw new Exception('Malformed response, missing token');
            }
            $token = $outputArray['token'];
        } catch (Exception $e) {
            throw $e;
        }

        $tokens = $this->appConfig->getAppValueArray('secureTokens', [], true);

        // this cleans out old tokens from the cache
        foreach ($tokens as $key => $data) {
            if (!is_array($data) || !array_key_exists('timestamp', $data) || !is_integer($data['timestamp'])) {
                unset($tokens[$key]);
                continue;
            }
            if (time() > $data['timestamp'] + 7200) {
                unset($tokens[$key]);
            }
        }

        $tokens[$token] = ['timestamp' => time(), 'loginStrength' => $this->service->getLoginStrength()];
        $this->appConfig->setAppValueArray('secureTokens', $tokens, true, false);

        return $output;
    }
}
