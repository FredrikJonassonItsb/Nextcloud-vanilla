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
use OCP\IRequest;
use OCP\IConfig;
use OCP\IL10N;
use OCA\SdkMc\Service\SmsService;
use Psr\Log\LoggerInterface;

class SmsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private SmsService $smsService,
        private IConfig $config,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Send SMS with authentication code
     *
     * Accepts recipient (E.164 phone number) and code (exactly 6 digits) parameters.
     * Builds a WICG origin-bound SMS with domain derived from Nextcloud config.
     *
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @return JSONResponse<200, array{status: string, messageId: string}, array{}>|JSONResponse<400, array{status: string, message: string}, array{}>|JSONResponse<500, array{status: string, message: string}, array{}>
     */
    public function sendAuthCode(): JSONResponse {
        $recipient = $this->request->getParam('recipient');
        $code = $this->request->getParam('code');

        if (!is_string($recipient) || !is_string($code) || $recipient === '' || $code === '') {
            return new JSONResponse(
                ['status' => 'error', 'message' => 'Missing required parameters: recipient and code'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (preg_match('/^\+[1-9][0-9]{6,14}$/', $recipient) !== 1) {
            return new JSONResponse(
                ['status' => 'error', 'message' => 'Invalid recipient phone number format'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return new JSONResponse(
                ['status' => 'error', 'message' => 'Invalid code format'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $message = $this->buildSmsMessage($code);
            $messageId = $this->smsService->sendSms($recipient, $message);

            $this->logger->info('SMS authentication code sent', [
                'messageId' => $messageId,
            ]);

            return new JSONResponse([
                'status' => 'success',
                'messageId' => $messageId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send SMS authentication code', [
                'error' => $e->getMessage(),
            ]);

            return new JSONResponse(
                ['status' => 'error', 'message' => 'Failed to send SMS'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function buildSmsMessage(string $code): string {
        $baseUrl = $this->config->getSystemValueString('overwrite.cli.url', '');
        $host = parse_url($baseUrl, PHP_URL_HOST) ?? '';
        $domain = $host !== '' ? 'securemail.' . $host : '';

        $message = $this->l->t('%s is the code to read your secure message.', [$code]);
        if ($domain !== '') {
            $message .= "\n\n@" . $domain . ' #' . $code;
        }

        return $message;
    }
}
