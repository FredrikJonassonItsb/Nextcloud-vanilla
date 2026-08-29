<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use Exception;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Exception\ClientException;
use OCA\SdkMc\Db\ItslTag;
use OCA\SdkMc\Service\ItslTagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ItslTagController extends Controller {
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        private ItslTagService $tagService,
        private IMailManager $mailManager,
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
    }

    /**
     * Create a new tag.
     *
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueTagSettingsAdmin)
     * @NoCSRFRequired
     * @return JSONResponse<201, ItslTag, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function createTag(int $accountId, string $displayName, string $color): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $tag = $this->tagService->createTag($this->userId, $accountId, $displayName, $color);
            return new JSONResponse($tag, Http::STATUS_CREATED);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Update a tag.
     *
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueTagSettingsAdmin)
     * @NoCSRFRequired
     * @return JSONResponse<200, ItslTag, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function updateTag(int $accountId, int $id, string $displayName, string $color): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $tag = $this->tagService->updateTag($this->userId, $accountId, $id, $displayName, $color);
            return new JSONResponse($tag);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Delete a tag.
     *
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueTagSettingsAdmin)
     * @NoCSRFRequired
     * @return JSONResponse<200, array{status: string}, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function deleteTag(int $accountId, int $id): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->tagService->deleteTag($this->userId, $accountId, $id);
            return new JSONResponse(['status' => 'ok']);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Tag a message.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse<200, ItslTag, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function setMessageTag(int $id, string $imapLabel): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            // Look up message, mailbox, and account from the database ID (like mail app does)
            $message = $this->mailManager->getMessage($this->userId, $id);
            $mailbox = $this->mailManager->getMailbox($this->userId, $message->getMailboxId());
            $accountId = $mailbox->getAccountId();
            $messageId = $message->getMessageId();
            if ($messageId === null) {
                return new JSONResponse(['error' => 'Message has no Message-ID'], Http::STATUS_BAD_REQUEST);
            }

            $tag = $this->tagService->tagMessage($this->userId, $accountId, $imapLabel, $messageId);
            return new JSONResponse($tag);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Remove a tag from a message.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse<200, ItslTag, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function removeMessageTag(int $id, string $imapLabel): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            // Look up message, mailbox, and account from the database ID (like mail app does)
            $message = $this->mailManager->getMessage($this->userId, $id);
            $mailbox = $this->mailManager->getMailbox($this->userId, $message->getMailboxId());
            $accountId = $mailbox->getAccountId();
            $messageId = $message->getMessageId();
            if ($messageId === null) {
                return new JSONResponse(['error' => 'Message has no Message-ID'], Http::STATUS_BAD_REQUEST);
            }

            $tag = $this->tagService->untagMessage($this->userId, $accountId, $imapLabel, $messageId);
            return new JSONResponse($tag);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Tag multiple messages in a thread (bulk operation).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int[] $ids Array of message database IDs
     * @return JSONResponse<200, ItslTag, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function setThreadTag(string $imapLabel, array $ids): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($ids === []) {
            return new JSONResponse(['error' => 'ids parameter is required and must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $tag = $this->tagService->tagMessages($this->userId, $imapLabel, $ids);
            return new JSONResponse($tag);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Remove a tag from multiple messages in a thread (bulk operation).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int[] $ids Array of message database IDs
     * @return JSONResponse<200, ItslTag, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function removeThreadTag(string $imapLabel, array $ids): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($ids === []) {
            return new JSONResponse(['error' => 'ids parameter is required and must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $tag = $this->tagService->untagMessages($this->userId, $imapLabel, $ids);
            return new JSONResponse($tag);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * [HUBS-ARENDE-KRAV 2026-07-12] Delete a case-tag by its IMAP label, across
     * every mailbox, without an accountId.
     *
     * Consumed by hubs_arende's NEVER-SoR gallring (GallringService →
     * SdkmcClient::deleteCaseTagByLabel) so a purged case's `case:<uuid>`
     * coordination tag is removed deterministically even when it lives on a shared
     * funktionsadress the calling service account does not own.
     *
     * FAIL-CLOSED: the service layer refuses any label outside the reserved
     * `case:` namespace, so this route can only ever remove case-coordination tags
     * — never user/assignment/default tags.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string $imapLabel The `case:<uuid>` label to purge everywhere.
     * @return JSONResponse<200, array{status: string, deleted: int}, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>
     */
    public function deleteCaseTagByLabel(string $imapLabel): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $deleted = $this->tagService->deleteCaseTagsByLabel($imapLabel);
            return new JSONResponse(['status' => 'ok', 'deleted' => $deleted]);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Set flags on multiple messages in a thread (bulk operation).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param int[] $ids Array of message database IDs
     * @param array<string, bool> $flags Map of flag name => value
     * @return JSONResponse<200, array{status: string}, array{}>|JSONResponse<400, array{error: string}, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>
     */
    public function setThreadFlags(array $ids, array $flags): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($ids === []) {
            return new JSONResponse(['error' => 'ids parameter is required and must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        if ($flags === []) {
            return new JSONResponse(['error' => 'flags parameter is required and must be a non-empty object'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->tagService->flagMessages($this->userId, $ids, $flags);
            return new JSONResponse(['status' => 'ok']);
        } catch (DoesNotExistException|ClientException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
