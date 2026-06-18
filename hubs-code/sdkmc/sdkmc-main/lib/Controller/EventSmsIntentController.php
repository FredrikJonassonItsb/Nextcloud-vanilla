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

class EventSmsIntentController extends Controller {
    private const SMS_KEY = 'sdkmc_sms_intents';

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
        $attendeeEmail = $body['attendee_email'] ?? '';
        $phoneNumber = $body['phone_number'] ?? '';

        $email = $this->normalizeEmail(is_string($attendeeEmail) ? $attendeeEmail : '');
        $phone = trim(is_string($phoneNumber) ? $phoneNumber : '');

        $list = $this->getIntents();
        $list = $this->removeExistingEmail($list, $email);
        $list[] = [
            'email' => $email,
            'phone' => $phone,
        ];
        $this->saveIntents($list);

        $this->logger->warning('[SMS] Intent stored', ['email' => $email, 'phone' => $phone]);

        $count = count($list);
        $responseData = [
            'ok' => true,
            'count' => $count
        ];

        return new JSONResponse($responseData);
    }

    /**
     * @NoAdminRequired
     * @return JSONResponse<400, array{ok: false, error: string}, array{}>|JSONResponse<200, array{ok: true, left: int}, array{}>
     */
    public function delete(): JSONResponse {
        $attendeeEmailParam = $this->request->getParam('attendee_email') ?? '';
        $email = $this->normalizeEmail(is_string($attendeeEmailParam) ? $attendeeEmailParam : '');

        if ($email === '') {
            $errorData = [
                'ok' => false,
                'error' => 'missing email'
            ];
            return new JSONResponse($errorData, 400);
        }

        $list = $this->getIntents();
        $remaining = $this->removeExistingEmail($list, $email);
        $this->saveIntents($remaining);

        $this->logger->warning('[SMS] Intent cancelled', ['email' => $email]);

        $left = count($remaining);
        $successData = [
            'ok' => true,
            'left' => $left
        ];

        return new JSONResponse($successData);
    }

    private function normalizeEmail(string $email): string {
        return strtolower(trim(preg_replace('~^mailto:~i', '', $email) ?? ''));
    }

    /**
     * @param array<int, array{email: string, phone: string}> $list
     * @return array<int, array{email: string, phone: string}>
     */
    private function removeExistingEmail(array $list, string $email): array {
        return array_values(array_filter(
            $list,
            fn (array $item): bool => strtolower($item['email']) !== $email
        ));
    }

    /**
     * @return array<int, array{email: string, phone: string}>
     */
    private function getIntents(): array {
        $raw = $this->session->get(self::SMS_KEY);
        $jsonString = is_string($raw) ? $raw : '[]';
        $decoded = json_decode($jsonString, true);

        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['email']) && isset($item['phone'])) {
                $email = $item['email'];
                $phone = $item['phone'];

                $result[] = [
                    'email' => is_string($email) ? $email : '',
                    'phone' => is_string($phone) ? $phone : '',
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array{email: string, phone: string}> $list
     */
    private function saveIntents(array $list): void {
        $this->session->set(self::SMS_KEY, json_encode($list));
    }
}
