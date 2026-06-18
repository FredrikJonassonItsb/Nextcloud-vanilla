<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Middleware;

use Exception;
use OCA\SdkMc\Exception\InternalAPIUnauthorizedAccessException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;
use OCP\IRequest;

class InternalAPIMiddleware extends Middleware {
    public function __construct(
        private IControllerMethodReflector $reflector,
        private IRequest $request,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function beforeController(Controller $controller, string $methodName): void {
        if ($this->reflector->hasAnnotation('InternalAPIAuth')) {
            $apiToken = $this->appConfig->getAppValueString('sdkmcmwSecretPassword');
            if ($apiToken === '') {
                $this->logger->error('Internal API token not configured. Rejecting request.');
                throw new InternalAPIUnauthorizedAccessException('Internal API token not configured.');
            }
            $requestToken = $this->request->getHeader('X-Api-Token');
            if (!hash_equals($apiToken, $requestToken)) {
                $this->logger->error('Unauthorized access. Invalid API token.');
                throw new InternalAPIUnauthorizedAccessException('Unauthorized access. Invalid API token.');
            }
        }
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function afterException(Controller $controller, string $methodName, Exception $exception): JSONResponse {
        if ($exception instanceof InternalAPIUnauthorizedAccessException) {
            return (new JSONResponse(
                [],
                Http::STATUS_UNAUTHORIZED
            ))->setData(['status' => 'Error', 'error' => $exception->getMessage()]); // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
        throw $exception;
    }
}
