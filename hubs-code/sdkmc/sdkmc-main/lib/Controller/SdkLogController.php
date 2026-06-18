<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use Exception;
use OCA\SdkMc\Db\SdkLog;
use OCA\SdkMc\Db\SdkLogMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use DateTime;
use DateTimeZone;

class SdkLogController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private SdkLogMapper $mapper,
    ) {
        parent::__construct($appName, $request);
    }

    private function toDateTime(?string $dateTime): ?DateTime {
        $dateTime = $dateTime === null ? false : DateTime::createFromFormat('Y-m-d G:i:s.u', $dateTime, new DateTimeZone(date_default_timezone_get()));
        return $dateTime === false ? null : $dateTime;
    }

    /**
     * @param Array<mixed> $src
     */
    private function parseString(string $key, array $src): ?string {
        if (!array_key_exists($key, $src)) {
            return null;
        }
        if (!is_string($src[$key])) {
            return null;
        }
        return $src[$key];
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkLogs)
     * @NoCSRFRequired
     * @return JSONResponse
     * @phpstan-return JSONResponse<200|500, array<string, mixed>, array<string, mixed>>
     */
    public function get(int $limit, int $offset, string $search): JSONResponse {
        try {
            $data = $this->mapper->getAll($limit, $offset, $search);
            $count = $this->mapper->getCount($search);

            /** @var array<string, mixed> $responseData */
            $responseData = [
                'data' => $data,
                'count' => $count,
            ];

            /** @var array<string, mixed> $headers */
            $headers = [];

            /** @var 200|500 $status */
            $status = Http::STATUS_OK;

            return new JSONResponse($responseData, $status, $headers);
        } catch (Exception $e) {
            /** @var array<string, mixed> $errorData */
            $errorData = ['status' => 'Error', 'error' => $e->getMessage()];

            /** @var array<string, mixed> $headers */
            $headers = [];

            /** @var 200|500 $status */
            $status = Http::STATUS_INTERNAL_SERVER_ERROR;

            return (new JSONResponse([], $status, $headers))->setData($errorData);
        }
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function save(): JSONResponse {
        try {
            $data = $this->request->getParams();
            foreach ($data as $rawLogEntry) {
                if (
                    !is_array($rawLogEntry)
                    || !array_key_exists('mtsId', $rawLogEntry)
                    || !is_int($rawLogEntry['mtsId'])) {
                    continue;
                }

                $sdkLog = new SdkLog();
                $sdkLog->setId($rawLogEntry['mtsId']);
                $sdkLog->setMessageType($this->parseString('documentType', $rawLogEntry));
                $sdkLog->setCreationDateTime($this->toDateTime($this->parseString('creationDateTime', $rawLogEntry)));
                $sdkLog->setFromClient($this->toDateTime($this->parseString('fromClient', $rawLogEntry)));
                $sdkLog->setToClient($this->toDateTime($this->parseString('toClient', $rawLogEntry)));
                $sdkLog->setFromAp($this->toDateTime($this->parseString('fromAp', $rawLogEntry)));
                $sdkLog->setToAp($this->toDateTime($this->parseString('toAp', $rawLogEntry)));
                $sdkLog->setSender($this->parseString('sender', $rawLogEntry));
                $sdkLog->setSenderAttention($this->parseString('senderAttention', $rawLogEntry));
                $sdkLog->setRecipient($this->parseString('recipient', $rawLogEntry));
                $sdkLog->setRecipientAttention($this->parseString('recipientAttention', $rawLogEntry));
                $sdkLog->setMessageIdAs4($this->parseString('messageIdAS4', $rawLogEntry));
                $sdkLog->setMessageId($this->parseString('messageId', $rawLogEntry));
                $sdkLog->setConversationId($this->parseString('conversationId', $rawLogEntry));
                $sdkLog->setAddressBookCopy('local');
                $sdkLog->setApId($this->parseString('accessPointPartyId', $rawLogEntry));
                $sdkLog->setlogData($rawLogEntry);

                $this->mapper->insertOrUpdate($sdkLog);
            }
            return (new JSONResponse([]))->setData('ok');  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        } catch (Exception $e) {
            return (new JSONResponse(
                [],
                Http::STATUS_INTERNAL_SERVER_ERROR
            ))->setData(['status' => 'Error', 'error' => $e->getMessage()]);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
    }
}
