<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use Exception;
use OCA\SdkMc\Db\MessageReceipt;
use OCA\SdkMc\Db\MessageReceiptMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

class MessageReceiptController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MessageReceiptMapper $mapper,
        private IEventDispatcher $eventDispatcher,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @param array{ "messageId"?: string, "documentReference"?: string, "status"?: string, "statusCode"?: string, "statusReason"?: string, "receiptData"?: array<string, mixed> } $data
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function save(
        array $data,
    ): JSONResponse {
        $mt = new MessageReceipt();
        if (array_key_exists('messageId', $data)) {
            $mt->setMessageId($data['messageId']);
        }
        if (array_key_exists('documentReference', $data)) {
            $mt->setDocumentReference($data['documentReference']);
        }
        if (array_key_exists('status', $data)) {
            $mt->setStatus($data['status']);
        }
        $code = '';
        if (array_key_exists('statusCode', $data)) {
            $mt->setStatusCode($data['statusCode']);
            $code = ' (code: ' . $data['statusCode'] . ')';
        }
        if (array_key_exists('statusReason', $data)) {
            $mt->setStatusReason($data['statusReason']);
        }
        if (array_key_exists('receiptData', $data)) {
            $mt->setReceiptData($data['receiptData']);
        }
        try {
            $mtPersisted = $this->mapper->insertOrUpdate($mt);
            $this->eventDispatcher->dispatchTyped(
                new CriticalActionPerformedEvent(
                    'SDK message Id %s received a receipt (SDK Id %s) with status %s %s',
                    [
                        $mt->getDocumentReference(),
                        $mt->getMessageId(),
                        $mt->getStatus(),
                        $code
                    ]
                )
            );
            return (new JSONResponse([]))->setData($mtPersisted); // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        } catch (Exception $e) {
            return (new JSONResponse(
                [],
                Http::STATUS_INTERNAL_SERVER_ERROR
            ))->setData(['status' => 'Failed to process', 'error' => $e->getMessage()]);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function get(string $messageId): JSONResponse {
        try {
            return (new JSONResponse([]))->setData($this->mapper->getByDocumentReference($messageId)); // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        } catch (DoesNotExistException $e) {
            return (new JSONResponse(
                [],
                Http::STATUS_NOT_FOUND
            ))->setData(['status' => 'Error', 'error' => $e->getMessage()]);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        } catch (MultipleObjectsReturnedException $e) {
            return (new JSONResponse(
                [],
                Http::STATUS_INTERNAL_SERVER_ERROR
            ))->setData(['status' => 'Error', 'error' => $e->getMessage()]);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
    }
}
