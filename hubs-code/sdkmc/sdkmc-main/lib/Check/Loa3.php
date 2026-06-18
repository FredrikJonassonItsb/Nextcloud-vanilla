<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 MohammadReza Vahedi <mr.vahedi68@gmail.com>
 * SPDX-FileCopyrightText: 2024 Pondersource <michiel@pondersource.com>
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Check;

use OCP\IL10N;
use OCP\WorkflowEngine\ICheck;
use UnexpectedValueException;
use OCA\SdkMc\Service\UpgradeLoginService;
use Exception;
use OCP\IRequest;
use OCP\AppFramework\Services\IAppConfig;

class Loa3 implements ICheck {
    public function __construct(
        private IAppConfig $appConfig,
        private IL10N $l,
        private UpgradeLoginService $service,
        private IRequest $request,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function executeCheck(mixed $operator, mixed $value): bool {
        try {
            $loginStrength = $this->service->getLoginStrength();
        } catch (Exception $e) {
            $tokens = $this->appConfig->getAppValueArray('secureTokens', [], true);
            $token = $this->request->getParam('access_token');

            if (!is_string($token)) {
                return $this->createReturn($operator, 'LOA-2');
            }

            if (!array_key_exists($token, $tokens) || !is_array($tokens[$token])) {
                return $this->createReturn($operator, 'LOA-2');
            }

            $tokenData = $tokens[$token];
            if (!array_key_exists('loginStrength', $tokenData) || !is_string($tokenData['loginStrength'])) {
                return $this->createReturn($operator, 'LOA-2');
            }
            $loginStrength = $tokenData['loginStrength'];
        }
        return $this->createReturn($operator, $loginStrength);
    }

    private function createReturn(mixed $operator, string $loginStrength): bool {
        if ($operator === 'is') {
            return $loginStrength === 'LOA-2';
        }
        return $loginStrength === 'LOA-3';
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @throws \UnexpectedValueException
     */
    public function validateCheck(mixed $operator, mixed $value): void {
        if (!in_array($operator, ['is', '!is'], true)) {
            throw new UnexpectedValueException($this->l->t('The given operator is invalid'), 1);
        }
    }

    /**
     * @return array<void>
     */
    public function supportedEntities(): array {
        return [];
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function isAvailableForScope(int $scope): bool {
        return true;
    }
}
