<?php

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service\Sms;

use OCP\AppFramework\Services\IAppConfig;
use OCA\SdkMc\Interface\ISmsService;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Exception;

class ElkService implements ISmsService {
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private IClientService $clientService,
    ) {
    }

    public function sendSms(string $recipient, string $message): string {
        $username = $this->appConfig->getAppValueString('smsGatewayUsername');
        $password = $this->appConfig->getAppValueString('smsGatewayPassword');
        $sender = $this->appConfig->getAppValueString('smsGatewaySender');
        if ($username === '' || $password === '' || $sender === '') {
            $this->logger->error('46elks SMS credentials not configured — set smsGatewayUsername, smsGatewayPassword, and smsGatewaySender in admin settings');
            throw new Exception('SMS gateway credentials not configured');
        }

        $client = $this->clientService->newClient();

        $response = $client->post('https://api.46elks.com/a1/sms', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'from' => $sender,
                'to' => $recipient,
                'message' => $message
            ]
        ]);
        $bodyString = $response->getBody();
        if (!is_string($bodyString)) {
            throw new Exception('Unable to send SMS');
        }
        $body = json_decode($bodyString, true);
        if (!is_array($body) || !array_key_exists('id', $body) || !is_string($body['id'])) {
            throw new Exception('Unable to send SMS');
        }

        $this->logger->debug('SMS sent via 46elks', ['messageId' => $body['id']]);
        return $body['id'];
    }

    public function getStatus(string $id): string {
        $client = $this->clientService->newClient();
        $response = $client->get('https://api.46elks.com/a1/sms/' . urlencode($id), [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->appConfig->getAppValueString('smsGatewayUsername') . ':' . $this->appConfig->getAppValueString('smsGatewayPassword')),
            ],
        ]);
        $bodyString = $response->getBody();
        if (!is_string($bodyString)) {
            throw new Exception('Unable to get SMS status');
        }
        $body = json_decode($bodyString, true);
        if (!is_array($body) || !array_key_exists('status', $body) || !is_string($body['status']) || !in_array($body['status'], ['created', 'sent', 'delivered', 'failed'], true)) {
            throw new Exception('Unable to get SMS status');
        }

        return $body['status'];
    }
}
