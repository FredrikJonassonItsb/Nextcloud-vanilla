<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use DateTime;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;
use Psr\Log\LoggerInterface;
use OCA\SdkMc\Utils\SSNHelper;

class EventSecuremailInviteIntentController extends Controller {
    private const SECUREMAIL_KEY = 'sdkmc_securemail_invite_intents';

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
        $ssnParam = $body['ssn_number'] ?? '';
        $accountIdParam = $body['account_id'] ?? null;

        $email = $this->normalizeEmail(is_string($emailParam) ? $emailParam : '');
        $ssn = SSNHelper::formatSsn(is_string($ssnParam) ? $ssnParam : '');

        $accountId = is_int($accountIdParam) ? $accountIdParam : null;

        $list = $this->getIntents();
        $list = $this->removeExistingEmail($list, $email);

        $intent = [
            'email' => $email,
            'ssn' => $ssn,
            'ts'    => (new DateTime())->format('c'),
        ];

        if ($accountId !== null) {
            $intent['accountId'] = $accountId;
        }

        $list[] = $intent;
        $this->saveIntents($list);

        $this->logger->warning('[SECUREMAIL] Intent stored', [
            'email' => $email,
            'ssn' => $ssn,
            'accountId' => $accountId,
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

        $this->logger->warning('[SECUREMAIL] Intent cancelled', ['email' => $email]);

        return new JSONResponse(['ok' => true, 'left' => count($remaining)]);
    }

    private function normalizeEmail(string $email): string {
        $cleaned = preg_replace('~^mailto:~i', '', $email);
        return strtolower(trim($cleaned ?? ''));
    }

    /**
     * @param array<int, array{email: string, ssn: string, ts: string, accountId?: int}> $list
     * @return array<int, array{email: string, ssn: string, ts: string, accountId?: int}>
     */
    private function removeExistingEmail(array $list, string $email): array {
        return array_values(array_filter(
            $list,
            fn (array $item): bool => strtolower($item['email']) !== $email
        ));
    }

    /**
     * @NoAdminRequired
     * @return array<int, array{email: string, ssn: string, ts: string, accountId?: int}>
     */
    private function getIntents(): array {
        $raw = $this->session->get(self::SECUREMAIL_KEY);
        $jsonString = is_string($raw) ? $raw : '[]';
        $decoded = json_decode($jsonString, true);

        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['email']) && isset($item['ssn']) && isset($item['ts'])) {
                $email = $item['email'];
                $ssn = $item['ssn'];
                $ts = $item['ts'];
                $accountId = $item['accountId'] ?? null;

                $intent = [
                    'email' => is_string($email) ? $email : '',
                    'ssn' => is_string($ssn) ? $ssn : '',
                    'ts'    => is_string($ts) ? $ts : '',
                ];

                if (is_int($accountId)) {
                    $intent['accountId'] = $accountId;
                }

                $result[] = $intent;
            }
        }

        return $result;
    }

    /**
     * @param array<int, array{email: string, ssn: string, ts: string, accountId?: int}> $list
     */
    private function saveIntents(array $list): void {
        $this->session->set(self::SECUREMAIL_KEY, json_encode($list));
    }
}
