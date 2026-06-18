<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use Exception;
use OCA\SdkMc\Db\MessageThread;
use OCA\SdkMc\Db\MessageThreadMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

class MessageThreadController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MessageThreadMapper $mapper,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @param array{ "messageId"?: string, "inReplyTo"?: string, "conversationId"?: string } $email
     * @param array{ "messageId"?: string, "inReplyTo"?: string, "conversationId"?: string } $sdk
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function save(
        array $email,
        array $sdk,
    ): JSONResponse {
        $emailMessageId = $email['messageId'] ?? '';
        $emailConversationId = $email['conversationId'] ?? '';
        $sdkMessageId = $sdk['messageId'] ?? '';
        $sdkConversationId = $sdk['conversationId'] ?? '';
        if ($emailMessageId === '' || $emailConversationId === '' || $sdkMessageId === '' || $sdkConversationId === '') {
            return (new JSONResponse(
                [],
                Http::STATUS_BAD_REQUEST
            ))->setData(['status' => 'Error', 'error' => 'Required fields must not be empty: email.messageId, email.conversationId, sdk.messageId, sdk.conversationId']);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }

        $mt = new MessageThread();
        $mt->setMessageId($emailMessageId);
        $mt->setInReplyTo($email['inReplyTo'] ?? null);
        $mt->setConversationId($emailConversationId);
        $mt->setSdkMessageId($sdkMessageId);
        $mt->setSdkInReplyTo($sdk['inReplyTo'] ?? null);
        $mt->setSdkConversationId($sdkConversationId);

        try {
            $mtPersisted = $this->mapper->insertOrUpdate($mt);
            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Message with Message Id %s got SDK Id %s while passing through the Message Service (MT)', [$emailMessageId, $sdkMessageId]));
            return (new JSONResponse([]))->setData($mtPersisted);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        } catch (Exception $e) {
            $this->logger->error('Failed to store message thread mapping', [
                'email.messageId' => $emailMessageId,
                'sdk.messageId' => $sdkMessageId,
                'exception' => $e,
            ]);
            return (new JSONResponse(
                [],
                Http::STATUS_INTERNAL_SERVER_ERROR
            ))->setData(['status' => 'Error', 'error' => 'Failed to store message thread mapping']);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function getByEmailMessage(string $messageId): JSONResponse {
        try {
            return (new JSONResponse([]))->setData($this->mapper->getByMessage($messageId));  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
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

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @param string $sdkMessageId SDK message ID from URL path
     * @param string $recipientAddress SDK function address of the recipient mailbox (query param).
     *                                 Used to disambiguate local delivery where the same SDK message produces
     *                                 thread rows for multiple recipients.
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function getBySdkMessage(string $sdkMessageId, string $recipientAddress = ''): JSONResponse {
        try {
            return (new JSONResponse([]))->setData($this->mapper->getBySdkMessageForRecipient($sdkMessageId, $recipientAddress));  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        } catch (DoesNotExistException $e) {
            return (new JSONResponse(
                [],
                Http::STATUS_NOT_FOUND
            ))->setData(['status' => 'Error', 'error' => $e->getMessage()]);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function getBySdkConversation(string $conversationId): JSONResponse {
        try {
            return (new JSONResponse([]))->setData($this->mapper->getLatestBySdkConversation($conversationId));  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        } catch (DoesNotExistException $e) {
            return (new JSONResponse(
                [],
                Http::STATUS_NOT_FOUND
            ))->setData(['status' => 'Error', 'error' => $e->getMessage()]);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
        }
    }
}
