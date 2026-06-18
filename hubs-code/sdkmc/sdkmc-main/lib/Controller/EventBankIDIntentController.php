<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;
use Psr\Log\LoggerInterface;
use DateTime;
use OCA\SdkMc\Utils\SSNHelper;

class EventBankIDIntentController extends Controller {
    private const BANKID_KEY = 'sdkmc_bankid_intents';

    public function __construct(
        string $appName,
        IRequest $request,
        private ISession $session,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @return JSONResponse<200, array{ok: true, count: int}, array{}>
     */
    public function store(): JSONResponse {
        $body = $this->request->getParams();

        $emailParam = $body['attendee_email'] ?? '';
        $email = $this->normalizeEmail(is_string($emailParam) ? $emailParam : '');

        // extract SSN if provided
        $ssnNumber = null;
        if (isset($body['ssn_number']) && is_string($body['ssn_number']) && trim($body['ssn_number']) !== '') {
            // $ssnNumber = trim($body['ssn_number']);
            $ssnNumber = SSNHelper::formatSsn(trim($body['ssn_number']));
            // quick fix to prevent mistyped SSN to mean 'allow for all'
            if ($ssnNumber === '') {
                $ssnNumber = '00000000-0000';
            }
        }

        // extract visibility options with defaults
        $showFirstName = true;
        if (isset($body['show_first_name'])) {
            $showFirstName = filter_var($body['show_first_name'], FILTER_VALIDATE_BOOLEAN);
        }

        $showLastName = true;
        if (isset($body['show_last_name'])) {
            $showLastName = filter_var($body['show_last_name'], FILTER_VALIDATE_BOOLEAN);
        }

        $showSsn = true;
        if (isset($body['show_ssn'])) {
            $showSsn = filter_var($body['show_ssn'], FILTER_VALIDATE_BOOLEAN);
        }

        $list = $this->getIntents();
        $list = $this->removeExistingEmail($list, $email);

        $intent = [
            'email' => $email,
            'ts' => (new DateTime())->format('c'),
            'show_first_name' => $showFirstName,
            'show_last_name' => $showLastName,
            'show_ssn' => $showSsn,
        ];

        // only add ssn_number if it was provided
        if ($ssnNumber !== null) {
            $intent['ssn_number'] = $ssnNumber;
        }

        $list[] = $intent;
        $this->saveIntents($list);

        $this->logger->warning('[BANKID] Intent stored', [
            'email' => $email,
            'ssn' => $ssnNumber,
            'visibility' => [
                'first_name' => $showFirstName,
                'last_name' => $showLastName,
                'ssn' => $showSsn,
            ],
        ]);

        return new JSONResponse(['ok' => true, 'count' => count($list)]);
    }

    /**
     * @NoAdminRequired
     * @return JSONResponse<200, array{ok: true, left: int}, array{}>|JSONResponse<400, array{ok: false, error: string}, array{}>
     */
    public function delete(): JSONResponse {
        $emailParam = $this->request->getParam('attendee_email') ?? '';
        $email = $this->normalizeEmail(is_string($emailParam) ? $emailParam : '');

        if ($email === '') {
            return new JSONResponse(['ok' => false, 'error' => 'missing email'], 400);
        }

        $list = $this->getIntents();
        $remaining = $this->removeExistingEmail($list, $email);
        $this->saveIntents($remaining);

        $this->logger->warning('[BANKID] Intent cancelled', ['email' => $email]);
        return new JSONResponse(['ok' => true, 'left' => count($remaining)]);
    }

    private function normalizeEmail(string $email): string {
        $cleaned = preg_replace('~^mailto:~i', '', $email);
        return strtolower(trim($cleaned ?? ''));
    }

    /**
     * @param array<int, array{email?: string, ts?: string, ssn_number?: string, show_first_name?: bool, show_last_name?: bool, show_ssn?: bool}> $list
     * @return array<int, array{email?: string, ts?: string, ssn_number?: string, show_first_name?: bool, show_last_name?: bool, show_ssn?: bool}>
     */
    private function removeExistingEmail(array $list, string $email): array {
        return array_values(array_filter(
            $list,
            function (array $item) use ($email): bool {
                $itemEmail = $item['email'] ?? '';
                return strtolower($itemEmail) !== strtolower($email);
            }
        ));
    }

    /**
     * @return array<int, array{email?: string, ts?: string, ssn_number?: string, show_first_name?: bool, show_last_name?: bool, show_ssn?: bool}>
     */
    private function getIntents(): array {
        $raw = $this->session->get(self::BANKID_KEY);
        if ($raw === null) {
            return [];
        }

        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $intent = [
                    'email' => isset($item['email']) && is_string($item['email']) ? $item['email'] : '',
                    'ts' => isset($item['ts']) && is_string($item['ts']) ? $item['ts'] : '',
                ];

                // add SSN if present
                if (isset($item['ssn_number']) && is_string($item['ssn_number'])) {
                    $intent['ssn_number'] = $item['ssn_number'];
                }

                // add visibility options with defaults
                $intent['show_first_name'] = isset($item['show_first_name']) && is_bool($item['show_first_name'])
                    ? $item['show_first_name']
                    : true;
                $intent['show_last_name'] = isset($item['show_last_name']) && is_bool($item['show_last_name'])
                    ? $item['show_last_name']
                    : true;
                $intent['show_ssn'] = isset($item['show_ssn']) && is_bool($item['show_ssn'])
                    ? $item['show_ssn']
                    : true;

                $result[] = $intent;
            }
        }

        return $result;
    }

    /**
     * @param array<int, array{email?: string, ts?: string, ssn_number?: string, show_first_name?: bool, show_last_name?: bool, show_ssn?: bool}> $list
     */
    private function saveIntents(array $list): void {
        $this->session->set(self::BANKID_KEY, json_encode($list));
    }
}
