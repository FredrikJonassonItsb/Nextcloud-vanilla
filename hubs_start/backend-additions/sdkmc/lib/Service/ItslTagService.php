<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · IN-PLACE-ÄNDRAD UPPSTRÖMSFIL · Target: lib/Service/ItslTagService.php
 *
 * Detta är sdkmc 2.2.25:s ItslTagService MED Fas F2-tilläggen (HUBS-START-ADD-
 * markerade: itslTagMeta() + två create-platser som ger case:-/behandlad-taggar
 * läsbara namn + färg i mail-klienten). ÅTERSTÄLLNINGSKÄLLA: F2 försvann vid
 * container-omsynken (wipe-incidenten juni) eftersom in-place-ändringen bara
 * fanns i hubs-code-forken — deploya HELA denna fil vid wipe-recovery.
 * OBS: uppgraderas sdkmc förbi 2.2.25 måste F2-blocken appliceras om på nya filen.
 */

namespace OCA\SdkMc\Service;

use Horde_Imap_Client;
use Horde_Imap_Client_Exception;
use Horde_Imap_Client_Ids;
use OCA\Mail\Account;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Events\MessageFlaggedEvent;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\Sync\SyncService;
use OCA\Mail\Db\Tag;
use OCA\SdkMc\BackgroundJob\DeleteTagsJob;
use OCA\SdkMc\Db\ItslTag;
use OCA\SdkMc\Db\ItslTagMapper;
use OCA\SdkMc\Db\ItslMessageTagMapper;
use DateTime;
use Exception;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
class ItslTagService {
    public function __construct(
        private AccountService $accountService,
        private IMailManager $mailManager,
        private IMAPClientFactory $imapClientFactory,
        private ItslTagMapper $tagMapper,
        private ItslMessageTagMapper $messageTagMapper,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
        private IDBConnection $db,
        private IActivityManager $activityManager,
        private IURLGenerator $url,
        private SyncService $syncService,
        private BackgroundJobService $backgroundJobService,
        // HUBS-START-ADD (Fas F2b — upstream-kandidat): mail-appens EGET tagg-
        // register. Trådvyns "Taggar"-sektion läser oc_mail_tags/-message_tags —
        // utan dubbelskrivning dit är Hubs-taggarna osynliga i mailklienten.
        // TRAILING OPTIONAL — null ⇒ enbart sdkmc-tabellerna (som tidigare).
        private ?\OCA\Mail\Db\TagMapper $mailTagMapper = null,
    ) {
    }

    /**
     * HUBS-START-ADD (Fas F2b — upstream-kandidat): spegla en Hubs-tagg in i
     * MAIL-APPENS taggregister så den SYNS i trådvyns "Taggar"-sektion.
     * Best-effort — mailklient-visningen får aldrig fälla taggningen (sdkmc:s
     * tabeller är den funktionella sanningen för feed/koppling).
     *
     * @param string $userId       Taggande användaren (mail-taggar är per user).
     * @param string $imapLabel    T.ex. 'case:<uuid>' eller 'behandlad'.
     * @param string $rfcMessageId RFC Message-ID (mail_message_tags-nyckeln).
     */
    private function mirrorTagToMailApp(string $userId, string $imapLabel, string $rfcMessageId): void {
        if ($this->mailTagMapper === null || $rfcMessageId === '') {
            return;
        }
        try {
            try {
                $mailTag = $this->mailTagMapper->getTagByImapLabel($imapLabel, $userId);
            } catch (DoesNotExistException) {
                [$displayName, $color] = $this->itslTagMeta($imapLabel);
                $mailTag = new \OCA\Mail\Db\Tag();
                $mailTag->setUserId($userId);
                $mailTag->setImapLabel($imapLabel);
                $mailTag->setDisplayName($displayName);
                $mailTag->setColor($color);
                $mailTag->setIsDefaultTag(false);
                $mailTag = $this->mailTagMapper->insert($mailTag);
            }
            $this->mailTagMapper->tagMessage($mailTag, $rfcMessageId, $userId);
        } catch (\Throwable $e) {
            $this->logger->debug('[hubs-start] mail-tagg-spegling misslyckades (graceful): ' . $e->getMessage());
        }
    }

    /**
     * Get the email address for a mail account.
     *
     * @param string $userId
     * @param int $accountId
     * @return string
     * @throws DoesNotExistException
     */
    public function getEmailForAccount(string $userId, int $accountId): string {
        $account = $this->accountService->find($userId, $accountId);
        return $account->getEmail();
    }

    /**
     * Create a new tag.
     *
     * @param string $userId
     * @param int $accountId
     * @param string $displayName
     * @param string $color
     * @return ItslTag
     * @throws DoesNotExistException
     * @throws DBException
     */
    public function createTag(string $userId, int $accountId, string $displayName, string $color): ItslTag {
        $email = $this->getEmailForAccount($userId, $accountId);

        // Generate IMAP label from display name
        $imapLabel = $this->generateImapLabel($displayName);

        // Prevent user-created tags with reserved assignment tag prefix
        if (str_starts_with($imapLabel, '$assignee_')) {
            throw new Exception('Cannot create tags with reserved prefix');
        }

        // Check if this label already exists for this email
        // If a soft-deleted tag exists with same label, generate unique label
        if ($this->tagMapper->tagExistsByLabel($email, $imapLabel)) {
            $existingTag = $this->tagMapper->getTagByImapLabelIncludingDeleted($imapLabel, $email);
            if ($existingTag->getDeletedAt() === null) {
                // Active tag exists
                throw new Exception('A tag with this name already exists');
            }
            // Soft-deleted tag exists, generate unique label
            $imapLabel = $this->tagMapper->findUniqueImapLabel($email, $imapLabel);
        }

        $tag = new ItslTag();
        $tag->setEmailAddress($email);
        $tag->setImapLabel($imapLabel);
        $tag->setDisplayName($displayName);
        $tag->setColor($color);
        $tag->setIsDefaultTag(false);

        return $this->tagMapper->insert($tag);
    }

    /**
     * Update a tag.
     *
     * @param string $userId
     * @param int $accountId
     * @param int $tagId
     * @param string $displayName
     * @param string $color
     * @return ItslTag
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws DBException
     */
    public function updateTag(string $userId, int $accountId, int $tagId, string $displayName, string $color): ItslTag {
        $email = $this->getEmailForAccount($userId, $accountId);
        $tag = $this->tagMapper->findById($tagId);

        // Verify the tag belongs to this email
        if ($tag->getEmailAddress() !== $email) {
            throw new DoesNotExistException('Tag not found');
        }

        $tag->setDisplayName($displayName);
        $tag->setColor($color);

        return $this->tagMapper->update($tag);
    }

    /**
     * Soft-delete a tag and trigger background cleanup.
     *
     * The tag is marked as deleted immediately (hidden from UI) and a
     * background job is triggered to remove IMAP labels from messages
     * and perform the hard delete.
     *
     * @param string $userId
     * @param int $accountId
     * @param int $tagId
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws DBException
     */
    public function deleteTag(string $userId, int $accountId, int $tagId): void {
        $email = $this->getEmailForAccount($userId, $accountId);
        $tag = $this->tagMapper->findById($tagId);

        // Verify the tag belongs to this email
        if ($tag->getEmailAddress() !== $email) {
            throw new DoesNotExistException('Tag not found');
        }

        // Prevent deletion of active assignment tags
        if ($tag->getIsAssignmentTag() === true) {
            throw new Exception('Cannot delete assignment tags');
        }

        // Prevent deletion of default system tags (like Important)
        if ($tag->getIsDefaultTag() === true) {
            throw new Exception('Cannot delete default system tags');
        }

        // Soft delete: keep imap_label unchanged (needed for IMAP cleanup)
        $tag->setDeletedAt(new DateTime());
        $this->tagMapper->update($tag);

        // Trigger immediate background job to process all pending deletions
        $this->backgroundJobService->executeNow(DeleteTagsJob::class);
    }

    /**
     * Tag a message.
     *
     * @param string $userId
     * @param int $accountId
     * @param string $imapLabel
     * @param string $messageId The IMAP Message-ID
     * @return ItslTag The tag that was applied
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws DBException
     */
    public function tagMessage(string $userId, int $accountId, string $imapLabel, string $messageId): ItslTag {
        $email = $this->getEmailForAccount($userId, $accountId);
        $account = $this->accountService->find($userId, $accountId);

        // Find or create the tag in our database
        try {
            $tag = $this->tagMapper->getTagByImapLabel($imapLabel, $email);

            // Don't allow tagging with soft-deleted tags
            if ($tag->getDeletedAt() !== null) {
                throw new Exception('Cannot tag with a deleted tag');
            }
        } catch (DoesNotExistException $e) {
            // Create a default tag with this label.
            // HUBS-START-ADD (Fas F2 — upstream-kandidat): ge ärende-/status-taggar en
            // MÄNSKLIGT LÄSBAR display_name + färg så de syns meningsfullt i
            // mail-klienten ('case:<uuid>' → "Ärende <kort>", 'behandlad' → "Behandlad").
            [$displayName, $color] = $this->itslTagMeta($imapLabel);
            $tag = new ItslTag();
            $tag->setEmailAddress($email);
            $tag->setImapLabel($imapLabel);
            $tag->setDisplayName($displayName);
            $tag->setColor($color);
            $tag->setIsDefaultTag(false);
            $tag = $this->tagMapper->insert($tag);
        }

        // Find the message and set the IMAP flag directly
        $messages = $this->mailManager->getByMessageId($account, $messageId);
        if (count($messages) > 0) {
            $message = reset($messages);
            $mailbox = $this->mailManager->getMailbox($userId, $message->getMailboxId());

            // Set IMAP flag directly (replicating mail app's tagMessagesWithClient logic)
            $client = $this->imapClientFactory->getClient($account);
            try {
                if ($this->isPermflagsEnabled($client, $mailbox->getName())) {
                    $client->store(
                        $mailbox->getName(),
                        [
                            'ids' => new Horde_Imap_Client_Ids([$message->getUid()]),
                            'add' => [$imapLabel],
                        ]
                    );
                }
            } finally {
                $client->logout();
            }

            // Dispatch event so mail app updates its database (e.g. flag_important)
            $this->eventDispatcher->dispatchTyped(
                new MessageFlaggedEvent(
                    $account,
                    $mailbox,
                    $message->getUid(),
                    $imapLabel,
                    true
                )
            );

            // Publish Activity notification if this is an assignment tag
            $this->publishAssignmentTagActivity($tag, $message, $userId);
        }

        // Also store in our database for email-based lookup
        $this->messageTagMapper->tagMessage($tag, $messageId, $email);
        // HUBS-START-ADD (Fas F2b): spegla in i mail-appens taggregister så
        // taggen SYNS i trådvyns "Taggar"-sektion (best-effort).
        $this->mirrorTagToMailApp($userId, $imapLabel, $messageId);
        return $tag;
    }

    /**
     * Remove a tag from a message.
     *
     * @param string $userId
     * @param int $accountId
     * @param string $imapLabel
     * @param string $messageId The IMAP Message-ID
     * @return ItslTag The tag that was removed
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws DBException
     */
    public function untagMessage(string $userId, int $accountId, string $imapLabel, string $messageId): ItslTag {
        $email = $this->getEmailForAccount($userId, $accountId);
        $account = $this->accountService->find($userId, $accountId);
        $tag = $this->tagMapper->getTagByImapLabel($imapLabel, $email);

        // Find the message and remove the IMAP flag directly
        $messages = $this->mailManager->getByMessageId($account, $messageId);
        if (count($messages) > 0) {
            $message = reset($messages);
            $mailbox = $this->mailManager->getMailbox($userId, $message->getMailboxId());

            // Remove IMAP flag directly (replicating mail app's tagMessagesWithClient logic)
            $client = $this->imapClientFactory->getClient($account);
            try {
                $permflagsEnabled = $this->isPermflagsEnabled($client, $mailbox->getName());
                if ($permflagsEnabled) {
                    $client->store(
                        $mailbox->getName(),
                        [
                            'ids' => new Horde_Imap_Client_Ids([$message->getUid()]),
                            'remove' => [$imapLabel],
                        ]
                    );
                }
            } finally {
                $client->logout();
            }

            // Dispatch event so mail app updates its database (e.g. flag_important)
            $this->eventDispatcher->dispatchTyped(
                new MessageFlaggedEvent(
                    $account,
                    $mailbox,
                    $message->getUid(),
                    $imapLabel,
                    false
                )
            );
        }

        // Also remove from our database
        $this->messageTagMapper->untagMessage($tag, $messageId);
        return $tag;
    }

    /**
     * Ensure a tag exists with the specified properties.
     * Creates the tag if it doesn't exist, does nothing if it already exists.
     *
     * This is useful when the caller needs to guarantee a tag exists with
     * specific displayName and color BEFORE calling tagMessage(), since
     * tagMessage() auto-creates tags with default values.
     *
     * @param string $userId
     * @param int $accountId
     * @param string $imapLabel
     * @param string $displayName
     * @param string $color
     * @return ItslTag
     * @throws DoesNotExistException
     * @throws DBException
     */
    public function ensureTagExists(
        string $userId,
        int $accountId,
        string $imapLabel,
        string $displayName,
        string $color,
    ): ItslTag {
        $email = $this->getEmailForAccount($userId, $accountId);

        try {
            return $this->tagMapper->getTagByImapLabel($imapLabel, $email);
        } catch (DoesNotExistException $e) {
            $tag = new ItslTag();
            $tag->setEmailAddress($email);
            $tag->setImapLabel($imapLabel);
            $tag->setDisplayName($displayName);
            $tag->setColor($color);
            $tag->setIsDefaultTag(false);
            return $this->tagMapper->insert($tag);
        }
    }

    /**
     * Generate an IMAP-safe label from a display name.
     *
     * @param string $displayName
     * @return string
     */
    private function generateImapLabel(string $displayName): string {
        // Convert to lowercase and replace spaces with underscores
        $label = strtolower(trim($displayName));
        $label = preg_replace('/[^a-z0-9_]/', '_', $label) ?? $label;
        $label = preg_replace('/_+/', '_', $label) ?? $label;
        $label = trim($label, '_');

        // Ensure it doesn't start with a dollar sign (reserved for system labels)
        if (str_starts_with($label, '$')) {
            $label = '_' . $label;
        }

        // Limit length
        if (strlen($label) > 60) {
            $label = substr($label, 0, 60);
        }

        // Add a unique suffix if needed
        $label = '$' . $label;

        return $label;
    }

    /**
     * Check if the IMAP server supports custom permanent flags.
     *
     * @param \Horde_Imap_Client_Socket $client
     * @param string $mailbox
     * @return bool
     * @throws Exception
     */
    private function isPermflagsEnabled(\Horde_Imap_Client_Socket $client, string $mailbox): bool {
        try {
            $capabilities = $client->status($mailbox, Horde_Imap_Client::STATUS_PERMFLAGS);
        } catch (Horde_Imap_Client_Exception $e) {
            throw new Exception('Could not get message flag options from IMAP: ' . $e->getMessage(), $e->getCode(), $e);
        }
        $permflags = $capabilities['permflags'] ?? [];
        return is_array($permflags) && in_array("\*", $permflags, true);
    }

    /**
     * Tag a message with specific metadata (displayName, color).
     * Combines ensureTagExists + tagMessage in one call.
     * Wraps in try-catch to handle errors gracefully.
     * MUST return void to allow `return $this->method()` in void functions.
     *
     * @param string $userId
     * @param int $accountId
     * @param string $imapLabel e.g. '$follow_up'
     * @param string $messageId IMAP Message-ID string
     * @param string $displayName e.g. 'Follow up'
     * @param string $color e.g. '#d77000'
     */
    public function tagMessageWithMetadata(
        string $userId,
        int $accountId,
        string $imapLabel,
        string $messageId,
        string $displayName,
        string $color,
    ): void {
        try {
            $this->ensureTagExists($userId, $accountId, $imapLabel, $displayName, $color);
            $this->tagMessage($userId, $accountId, $imapLabel, $messageId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to tag message with metadata: ' . $e->getMessage(), [
                'exception' => $e,
                'userId' => $userId,
                'accountId' => $accountId,
                'imapLabel' => $imapLabel,
            ]);
        }
    }

    /**
     * Get message IDs that have a specific tag, given Message objects.
     * Handles the array_map internally to reduce mail app code.
     *
     * @param \OCA\Mail\Db\Message[] $messages Array of Message objects
     * @param string $emailAddress
     * @param string $imapLabel e.g. Tag::LABEL_IMPORTANT
     * @return string[] Message IDs that have the tag
     */
    public function getTaggedMessageIdsFromMessages(
        array $messages,
        string $emailAddress,
        string $imapLabel,
    ): array {
        $messageIds = array_filter(array_map(static function ($message) {
            return $message->getMessageId();
        }, $messages), static fn ($id) => $id !== null);
        return $this->messageTagMapper->getTaggedMessageIdsForMessages(
            array_values($messageIds),
            $emailAddress,
            $imapLabel
        );
    }

    /**
     * Get all tags for multiple accounts, keyed by accountId.
     * Used for frontend initialization to populate itslStore.tagsByAccount directly.
     *
     * @param array<int, string> $accountIdToEmail Map of accountId => email
     * @return array<int, list<array<string, mixed>>> {accountId: [tag, ...]}
     */
    public function getAllTagsKeyedByAccount(array $accountIdToEmail): array {
        $result = [];
        foreach ($accountIdToEmail as $accountId => $email) {
            $itslTags = $this->tagMapper->getAllTagsForMailbox($email);
            $result[$accountId] = array_values(array_map(
                fn ($itslTag) => $this->itslTagToFrontendFormat($itslTag),
                $itslTags
            ));
        }
        return $result;
    }

    /**
     * Convert ItslTag to frontend-compatible format.
     * Frontend expects 'emailAddress' field, NOT 'userId'.
     *
     * @return array<string, mixed>
     */
    private function itslTagToFrontendFormat(ItslTag $itslTag): array {
        return [
            'id' => $itslTag->getId(),
            'emailAddress' => $itslTag->getEmailAddress(),
            'imapLabel' => $itslTag->getImapLabel(),
            'displayName' => $itslTag->getDisplayName(),
            'color' => $itslTag->getColor(),
            'isDefaultTag' => $itslTag->getIsDefaultTag() === true,
            'isAssignmentTag' => $itslTag->getIsAssignmentTag() === true,
            'username' => $itslTag->getUsername(),
        ];
    }

    /**
     * Convert ItslTag to mail app's Tag object.
     * Used internally where mail app expects Tag type.
     */
    private function itslTagToMailTag(ItslTag $itslTag): Tag {
        $tag = new Tag();
        $tag->setId($itslTag->getId());
        $tag->setImapLabel($itslTag->getImapLabel());
        $tag->setDisplayName($itslTag->getDisplayName());
        $tag->setColor($itslTag->getColor());
        $tag->setIsDefaultTag($itslTag->getIsDefaultTag() ?? false);
        return $tag;
    }

    /**
     * Get the important tag for an email address as a mail Tag object.
     * Returns null if not found. Logs error for debugging.
     *
     * @param string $emailAddress
     * @param string|null $userId For logging context (optional)
     * @return Tag|null
     */
    public function getImportantTagAsMailTag(string $emailAddress, ?string $userId = null): ?Tag {
        try {
            $itslTag = $this->tagMapper->getTagByImapLabel(
                Tag::LABEL_IMPORTANT,
                $emailAddress
            );
            return $this->itslTagToMailTag($itslTag);
        } catch (DoesNotExistException $e) {
            // Auto-create the important tag if missing (handles pre-migration mailboxes)
            $tag = new ItslTag();
            $tag->setEmailAddress($emailAddress);
            $tag->setImapLabel(Tag::LABEL_IMPORTANT);
            $tag->setDisplayName('Important');
            $tag->setColor('#FF7A66');
            $tag->setIsDefaultTag(true);
            $this->tagMapper->insert($tag);

            $this->logger->info('Auto-created missing important tag for ' . ($userId ?? $emailAddress));
            return $this->itslTagToMailTag($tag);
        }
    }

    /**
     * Sync a tag to a message during IMAP sync.
     * Creates tag if it doesn't exist, stores association in DB.
     * Does NOT set IMAP flags (already read from IMAP).
     *
     * @param string $emailAddress
     * @param string $messageId IMAP Message-ID
     * @param Tag $tag Mail app Tag object with label, displayName, color
     */
    public function syncTag(string $emailAddress, string $messageId, Tag $tag): void {
        $itslTag = $this->tagMapper->getOrCreateTag(
            $emailAddress,
            $tag->getImapLabel(),
            $tag->getDisplayName(),
            $tag->getColor(),
            $tag->getIsDefaultTag() ?? false
        );
        $this->messageTagMapper->tagMessage($itslTag, $messageId, $emailAddress);
    }

    /**
     * Remove a tag from a message during IMAP sync.
     *
     * @param string $emailAddress
     * @param string $messageId IMAP Message-ID
     * @param Tag $tag Mail app Tag object
     */
    public function unsyncTag(string $emailAddress, string $messageId, Tag $tag): void {
        try {
            $itslTag = $this->tagMapper->getTagByImapLabel(
                $tag->getImapLabel(),
                $emailAddress
            );
            $this->messageTagMapper->untagMessage($itslTag, $messageId);
        } catch (DoesNotExistException $e) {
            // Tag doesn't exist, nothing to remove
        }
    }

    /**
     * Get all tags for messages, returning mail-compatible Tag objects.
     *
     * @param string[] $messageIds Array of IMAP Message-ID strings
     * @param string $emailAddress
     * @return array<string, Tag[]> Map of messageId => Tag[]
     */
    public function getTagsForMessages(array $messageIds, string $emailAddress): array {
        $itslTags = $this->tagMapper->getAllTagsForMessages($messageIds, $emailAddress);

        $result = [];
        foreach ($itslTags as $messageId => $itslTagsForMessage) {
            $result[$messageId] = array_map(
                fn ($itslTag) => $this->itslTagToMailTag($itslTag),
                $itslTagsForMessage
            );
        }
        return $result;
    }

    /**
     * Get all tags for messages by mailbox ID, merged by thread.
     * Returns tags from all messages in the same thread for each message.
     * Queries across all mailboxes for the same account to handle unified inbox.
     *
     * @param string[] $messageIds Array of IMAP Message-ID strings
     * @param int $mailboxId
     * @return array<string, Tag[]> Map of messageId => Tag[]
     */
    public function getTagsForMessagesByMailboxId(array $messageIds, int $mailboxId): array {
        $mailboxInfo = $this->getMailboxInfo($mailboxId);
        if ($mailboxInfo === null) {
            return [];
        }
        $emailAddress = $mailboxInfo['email'];
        $accountId = $mailboxInfo['account_id'];

        if ($messageIds === []) {
            return [];
        }

        // Get thread_root_id for each message (queries all mailboxes for account)
        $threadMapping = $this->getThreadRootIdsForMessages($messageIds, $accountId);

        // Get unique thread root IDs (filter out empty values)
        $threadRootIds = array_unique(array_filter(
            array_values($threadMapping),
            static fn (string $v): bool => $v !== ''
        ));

        if ($threadRootIds === []) {
            // No threads found, fall back to per-message tags
            return $this->getTagsForMessages($messageIds, $emailAddress);
        }

        // Get all message IDs in these threads (queries all mailboxes for account)
        $allThreadMessageIds = $this->getAllMessageIdsInThreads($threadRootIds, $accountId);

        // Get tags for all thread messages
        $allTags = $this->getTagsForMessages($allThreadMessageIds, $emailAddress);

        // Build merged tags per thread
        $mergedTagsByThread = [];
        $messageToThread = $this->getMessageIdToThreadRootMapping($threadRootIds, $accountId);
        foreach ($allTags as $messageId => $tagsForMessage) {
            $threadRootId = $messageToThread[$messageId] ?? null;
            if ($threadRootId !== null) {
                if (!isset($mergedTagsByThread[$threadRootId])) {
                    $mergedTagsByThread[$threadRootId] = [];
                }
                foreach ($tagsForMessage as $tag) {
                    $mergedTagsByThread[$threadRootId][$tag->getImapLabel()] = $tag;
                }
            }
        }

        // Return merged tags keyed by original message IDs
        $result = [];
        foreach ($messageIds as $messageId) {
            $threadRootId = $threadMapping[$messageId] ?? null;
            $result[$messageId] = array_values($mergedTagsByThread[$threadRootId] ?? []);
        }
        return $result;
    }

    /**
     * Get thread_root_id for each message from mail_messages table.
     *
     * @param string[] $messageIds Array of IMAP Message-ID strings
     * @param int $accountId Account ID to scope the query
     * @return array<string, string> Map of messageId => threadRootId
     */
    private function getThreadRootIdsForMessages(array $messageIds, int $accountId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.message_id', 'm.thread_root_id')
            ->from('mail_messages', 'm')
            ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
            ->where($qb->expr()->in('m.message_id', $qb->createParameter('ids')))
            ->andWhere($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));

        /** @var array<string, string> $mapping */
        $mapping = [];
        foreach (array_chunk($messageIds, 1000) as $chunk) {
            $qb->setParameter('ids', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                /** @var array{message_id: string, thread_root_id: string} $row */
                $mapping[$row['message_id']] = $row['thread_root_id'];
            }
            $result->closeCursor();
        }
        return $mapping;
    }

    /**
     * Get all message IDs in threads with the given thread root IDs.
     *
     * @param string[] $threadRootIds
     * @param int $accountId Account ID to scope the query
     * @return string[] Array of message IDs
     */
    private function getAllMessageIdsInThreads(array $threadRootIds, int $accountId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.message_id')
            ->from('mail_messages', 'm')
            ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
            ->where($qb->expr()->in('m.thread_root_id', $qb->createParameter('rootIds')))
            ->andWhere($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));

        /** @var string[] $results */
        $results = [];
        foreach (array_chunk($threadRootIds, 1000) as $chunk) {
            $qb->setParameter('rootIds', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                /** @var array{message_id: string} $row */
                $results[] = $row['message_id'];
            }
            $result->closeCursor();
        }
        return $results;
    }

    /**
     * Get mapping of message ID to thread root ID for all messages in threads.
     *
     * @param string[] $threadRootIds
     * @param int $accountId Account ID to scope the query
     * @return array<string, string> Map of messageId => threadRootId
     */
    private function getMessageIdToThreadRootMapping(array $threadRootIds, int $accountId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.message_id', 'm.thread_root_id')
            ->from('mail_messages', 'm')
            ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
            ->where($qb->expr()->in('m.thread_root_id', $qb->createParameter('rootIds')))
            ->andWhere($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));

        /** @var array<string, string> $mapping */
        $mapping = [];
        foreach (array_chunk($threadRootIds, 1000) as $chunk) {
            $qb->setParameter('rootIds', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                /** @var array{message_id: string, thread_root_id: string} $row */
                $mapping[$row['message_id']] = $row['thread_root_id'];
            }
            $result->closeCursor();
        }
        return $mapping;
    }

    /**
     * Get email address for a mailbox ID.
     * Queries mail app tables (mail_mailboxes, mail_accounts).
     *
     * @param int $mailboxId
     * @return string|null
     */
    public function getEmailForMailbox(int $mailboxId): ?string {
        $info = $this->getMailboxInfo($mailboxId);
        return $info['email'] ?? null;
    }

    /**
     * Get email address and account ID for a mailbox ID.
     * Queries mail app tables (mail_mailboxes, mail_accounts).
     *
     * @param int $mailboxId
     * @return array{email: string, account_id: int}|null
     */
    private function getMailboxInfo(int $mailboxId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.email', 'a.id as account_id')
            ->from('mail_mailboxes', 'mb')
            ->join('mb', 'mail_accounts', 'a', $qb->expr()->eq('mb.account_id', 'a.id'))
            ->where($qb->expr()->eq('mb.id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!is_array($row) || !isset($row['email']) || !isset($row['account_id'])) {
            return null;
        }
        /** @var string $email */
        $email = $row['email'];
        /** @var int $accountId */
        $accountId = $row['account_id'];
        return [
            'email' => $email,
            'account_id' => $accountId,
        ];
    }

    /**
     * Get the tagee's mailbox ID for the same folder as the tagger's mailbox.
     *
     * @param string $userId Tagee's user ID
     * @param string $emailAddress Shared mailbox email
     * @param int $taggerMailboxId Tagger's mailbox ID (to get folder name)
     * @return int|null Tagee's mailbox ID for the same folder
     */
    private function getTageeMailboxId(string $userId, string $emailAddress, int $taggerMailboxId): ?int {
        $qb = $this->db->getQueryBuilder();

        // Get the folder name from the tagger's mailbox
        $qb->select('mb.name')
            ->from('mail_mailboxes', 'mb')
            ->where($qb->expr()->eq('mb.id', $qb->createNamedParameter($taggerMailboxId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!is_array($row) || !isset($row['name'])) {
            return null;
        }
        /** @var array{name: string} $row */
        $folderName = $row['name'];

        // Find the tagee's mailbox with the same folder name
        $qb = $this->db->getQueryBuilder();
        $qb->select('mb.id')
            ->from('mail_accounts', 'a')
            ->join('a', 'mail_mailboxes', 'mb', $qb->expr()->eq('a.id', 'mb.account_id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('a.email', $qb->createNamedParameter($emailAddress)))
            ->andWhere($qb->expr()->eq('mb.name', $qb->createNamedParameter($folderName)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!is_array($row) || !isset($row['id'])) {
            return null;
        }
        /** @var array{id: int|string} $row */
        return (int)$row['id'];
    }

    /**
     * Get the tagee's message info for the same IMAP message.
     *
     * @param int $tageeMailboxId Tagee's mailbox ID
     * @param string $imapMessageId IMAP Message-ID header value
     * @return array{id: int, thread_root_id: string, flag_seen: bool}|null
     */
    private function getTageeMessageInfo(int $tageeMailboxId, string $imapMessageId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'thread_root_id', 'flag_seen')
            ->from('mail_messages')
            ->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($tageeMailboxId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($imapMessageId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!is_array($row)) {
            return null;
        }

        /** @var array{id: int|string, thread_root_id: string, flag_seen: bool|int} $row */
        return [
            'id' => (int)$row['id'],
            'thread_root_id' => $row['thread_root_id'],
            'flag_seen' => (bool)$row['flag_seen'],
        ];
    }

    /**
     * Sync the tagee's mailbox to ensure the message exists in DB.
     *
     * @param string $userId Tagee's user ID
     * @param int $mailboxId Tagee's mailbox ID
     */
    private function syncTageeMailbox(string $userId, int $mailboxId): void {
        try {
            $mailbox = $this->mailManager->getMailbox($userId, $mailboxId);
            $account = $this->accountService->find($userId, $mailbox->getAccountId());

            $this->syncService->syncMailbox(
                $account,
                $mailbox,
                Horde_Imap_Client::SYNC_NEWMSGSUIDS,
                false,
                null,
                null
            );
        } catch (\Exception $e) {
            $this->logger->warning('Failed to sync tagee mailbox', [
                'mailboxId' => $mailboxId,
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish Activity notification when an assignment tag is applied.
     * Skips notification if the user is tagging themselves.
     *
     * @param ItslTag $tag The tag that was applied
     * @param \OCA\Mail\Db\Message $message The message that was tagged
     * @param string $userId The user who applied the tag
     */
    private function publishAssignmentTagActivity(ItslTag $tag, \OCA\Mail\Db\Message $message, string $userId): void {
        // Only notify for assignment tags with a username
        $tageeUsername = $tag->getUsername();
        if ($tag->getIsAssignmentTag() !== true || $tageeUsername === null) {
            return;
        }

        // Skip notification if user is tagging themselves
        if ($userId === $tageeUsername) {
            return;
        }

        try {
            // Look up the tagee's mailbox ID for the same folder (not the tagger's mailbox)
            $tageeMailboxId = $this->getTageeMailboxId(
                $tageeUsername,
                $tag->getEmailAddress(),
                $message->getMailboxId()
            );

            if ($tageeMailboxId === null) {
                $this->logger->warning('Could not find mailbox for tagee', [
                    'tagee' => $tag->getUsername(),
                    'email' => $tag->getEmailAddress(),
                    'taggerMailboxId' => $message->getMailboxId(),
                ]);
                return;
            }

            // Look up the tagee's message info (not the tagger's)
            $imapMessageId = $message->getMessageId();
            if ($imapMessageId === null) {
                $this->logger->warning('Message has no IMAP Message-ID, cannot publish activity');
                return;
            }
            $messageInfo = $this->getTageeMessageInfo($tageeMailboxId, $imapMessageId);

            // If message not found, sync the mailbox and try again
            if ($messageInfo === null) {
                $this->syncTageeMailbox($tageeUsername, $tageeMailboxId);
                $messageInfo = $this->getTageeMessageInfo($tageeMailboxId, $imapMessageId);
            }

            if ($messageInfo === null) {
                $this->logger->warning('Could not find message for tagee after sync', [
                    'tagee' => $tageeUsername,
                    'imapMessageId' => $imapMessageId,
                    'tageeMailboxId' => $tageeMailboxId,
                ]);
                return;
            }

            $realUri = $this->url->linkToRouteAbsolute('mail.page.thread', [
                'mailboxId' => $tageeMailboxId,
                'id' => $messageInfo['id'],
            ]);

            $activity = $this->activityManager->generateEvent();
            $activity->setApp('mail')
                ->setType('tag_assignment')
                ->setAuthor($userId)
                ->setAffectedUser($tageeUsername)
                ->setLink($realUri)
                ->setSubject('{announcement}', ['announcement' => [
                    'type' => 'announcement',
                    'id' => '0',
                    'name' => 'tag_assignment',
                    'link' => $realUri,
                    'mailbox_id' => $tageeMailboxId,
                    'message_id' => $messageInfo['id'],
                    'thread_root_id' => $messageInfo['thread_root_id'],
                    'flag_seen' => $messageInfo['flag_seen'],
                ]]);
            $this->activityManager->publish($activity);
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish assignment tag activity: ' . $e->getMessage(), [
                'exception' => $e,
                'tagId' => $tag->getId(),
                'messageId' => $message->getId(),
            ]);
        }
    }

    /**
     * Process all pending tag deletions.
     * Called by DeleteTagsJob (immediately via executeNow, or daily scheduled).
     */
    public function processAllPendingDeletions(): void {
        $pendingTags = $this->tagMapper->getTagsPendingDeletion(100);

        foreach ($pendingTags as $tag) {
            try {
                $this->executeTagDeletion($tag);
            } catch (\Exception $e) {
                $this->logger->error('Failed to process tag deletion', [
                    'tagId' => $tag->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Execute the actual deletion of a soft-deleted tag.
     * Removes IMAP labels from messages, deletes DB associations, then hard deletes the tag.
     */
    private function executeTagDeletion(ItslTag $tag): void {
        $email = $tag->getEmailAddress();
        $imapLabel = $tag->getImapLabel();

        // Get all message IDs with this tag
        $messageIds = $this->messageTagMapper->getMessagesByTag($tag->getId(), $email);

        if (count($messageIds) > 0) {
            // Find an account for this email address
            $account = $this->findAccountForEmail($email);
            if ($account !== null) {
                // Remove IMAP labels in batches
                $this->removeImapLabelsInBatches($account, $imapLabel, $messageIds);
            }

            // Delete DB associations
            $this->messageTagMapper->deleteByTagId($tag->getId());
        }

        // Hard delete the tag record
        $this->tagMapper->delete($tag);

        $this->logger->info('Tag deletion completed', [
            'tagId' => $tag->getId(),
            'imapLabel' => $imapLabel,
            'messagesProcessed' => count($messageIds),
        ]);
    }

    /**
     * Remove IMAP labels from messages in batches.
     *
     * @param Account $account
     * @param string $imapLabel
     * @param string[] $messageIds
     */
    private function removeImapLabelsInBatches(Account $account, string $imapLabel, array $messageIds): void {
        // Process in chunks of 500 to avoid timeouts
        foreach (array_chunk($messageIds, 500) as $chunk) {
            $client = $this->imapClientFactory->getClient($account);
            try {
                $grouped = $this->groupMessagesByMailbox($account, $chunk);
                foreach ($grouped as $mailboxId => $messages) {
                    try {
                        $mailbox = $this->mailManager->getMailbox($account->getUserId(), $mailboxId);
                        $uids = array_map(static fn ($m) => $m->getUid(), $messages);

                        if ($this->isPermflagsEnabled($client, $mailbox->getName())) {
                            $client->store(
                                $mailbox->getName(),
                                [
                                    'ids' => new Horde_Imap_Client_Ids($uids),
                                    'remove' => [$imapLabel],
                                ]
                            );
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to remove IMAP label from mailbox', [
                            'mailboxId' => $mailboxId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } finally {
                $client->logout();
            }
        }
    }

    /**
     * Group messages by mailbox for efficient IMAP operations.
     *
     * @param Account $account
     * @param string[] $messageIds Array of IMAP Message-ID strings
     * @return array<int, \OCA\Mail\Db\Message[]> Map of mailboxId => Message[]
     */
    private function groupMessagesByMailbox(Account $account, array $messageIds): array {
        $grouped = [];
        foreach ($messageIds as $messageId) {
            $messages = $this->mailManager->getByMessageId($account, $messageId);
            foreach ($messages as $message) {
                $mailboxId = $message->getMailboxId();
                if (!isset($grouped[$mailboxId])) {
                    $grouped[$mailboxId] = [];
                }
                $grouped[$mailboxId][] = $message;
            }
        }
        return $grouped;
    }

    /**
     * Find any mail account for the given email address.
     *
     * @param string $email
     * @return Account|null
     */
    private function findAccountForEmail(string $email): ?Account {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id')
            ->from('mail_accounts')
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!is_array($row) || !isset($row['id'], $row['user_id'])) {
            return null;
        }

        /** @var array{id: int, user_id: string} $row */
        try {
            return $this->accountService->find($row['user_id'], $row['id']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Group messages by mailbox for efficient IMAP operations.
     * Uses message database IDs instead of IMAP Message-ID strings.
     *
     * @param string $userId
     * @param int[] $messageDbIds Array of message database IDs
     * @return array<int, \OCA\Mail\Db\Message[]> Map of mailboxId => Message[]
     */
    private function groupMessagesByDbIds(string $userId, array $messageDbIds): array {
        $grouped = [];
        foreach ($messageDbIds as $dbId) {
            try {
                $message = $this->mailManager->getMessage($userId, $dbId);
                $mailboxId = $message->getMailboxId();
                if (!isset($grouped[$mailboxId])) {
                    $grouped[$mailboxId] = [];
                }
                $grouped[$mailboxId][] = $message;
            } catch (DoesNotExistException $e) {
                // Skip messages that don't exist
                $this->logger->warning('Message not found during bulk operation', [
                    'messageDbId' => $dbId,
                    'userId' => $userId,
                ]);
            }
        }
        return $grouped;
    }

    /**
     * Tag multiple messages with a single IMAP label (bulk operation).
     *
     * @param string $userId
     * @param string $imapLabel
     * @param int[] $messageDbIds Array of message database IDs
     * @return ItslTag The tag that was applied
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws DBException
     */
    /**
     * HUBS-START-ADD (Fas F2 — upstream-kandidat): mänskligt läsbar display_name +
     * färg för en ny ItslTag utifrån dess imapLabel, så Hubs-taggar syns meningsfullt
     * i mail-klienten:
     *   - 'case:<uuid>'  → ["Ärende <kort-uuid>", grön]  (kopplad till ett ärende)
     *   - 'behandlad'    → ["Behandlad", blå]            (status: triagat/hanterat)
     *   - övrigt         → [imapLabel, grå]              (oförändrat default)
     *
     * @return array{0:string,1:string} [displayName, color]
     */
    private function itslTagMeta(string $imapLabel): array {
        if (str_starts_with($imapLabel, 'case:')) {
            $kort = substr($imapLabel, 5, 6); // 'case:' + första 6 av hubsCaseId (pseudonym, kort-ref)
            return ['Ärende ' . $kort, '#2d7d46'];
        }
        if (strtolower($imapLabel) === 'behandlad') {
            return ['Behandlad', '#0969da'];
        }
        return [$imapLabel, '#808080'];
    }

    public function tagMessages(string $userId, string $imapLabel, array $messageDbIds): ItslTag {
        if ($messageDbIds === []) {
            throw new Exception('No message IDs provided');
        }

        // Get the first message to determine the account
        $firstId = reset($messageDbIds);
        $firstMessage = $this->mailManager->getMessage($userId, $firstId);
        $mailbox = $this->mailManager->getMailbox($userId, $firstMessage->getMailboxId());
        $accountId = $mailbox->getAccountId();
        $email = $this->getEmailForAccount($userId, $accountId);
        $account = $this->accountService->find($userId, $accountId);

        // Find or create the tag in our database
        try {
            $tag = $this->tagMapper->getTagByImapLabel($imapLabel, $email);

            // Don't allow tagging with soft-deleted tags
            if ($tag->getDeletedAt() !== null) {
                throw new Exception('Cannot tag with a deleted tag');
            }
        } catch (DoesNotExistException $e) {
            // Create a default tag with this label.
            // HUBS-START-ADD (Fas F2 — upstream-kandidat): ge ärende-/status-taggar en
            // MÄNSKLIGT LÄSBAR display_name + färg så de syns meningsfullt i
            // mail-klienten ('case:<uuid>' → "Ärende <kort>", 'behandlad' → "Behandlad").
            [$displayName, $color] = $this->itslTagMeta($imapLabel);
            $tag = new ItslTag();
            $tag->setEmailAddress($email);
            $tag->setImapLabel($imapLabel);
            $tag->setDisplayName($displayName);
            $tag->setColor($color);
            $tag->setIsDefaultTag(false);
            $tag = $this->tagMapper->insert($tag);
        }

        // Group messages by mailbox for efficient IMAP operations
        $messagesByMailbox = $this->groupMessagesByDbIds($userId, $messageDbIds);

        // Batch IMAP operations (one store() call per mailbox)
        $client = $this->imapClientFactory->getClient($account);
        $firstMessageForActivity = null;
        try {
            foreach ($messagesByMailbox as $mailboxId => $messages) {
                $currentMailbox = $this->mailManager->getMailbox($userId, $mailboxId);
                $uids = array_map(static fn ($m) => $m->getUid(), $messages);

                if ($this->isPermflagsEnabled($client, $currentMailbox->getName())) {
                    $client->store(
                        $currentMailbox->getName(),
                        [
                            'ids' => new Horde_Imap_Client_Ids($uids),
                            'add' => [$imapLabel],
                        ]
                    );
                }

                // Dispatch events for each message (for mail app DB updates)
                foreach ($messages as $message) {
                    $this->eventDispatcher->dispatchTyped(
                        new MessageFlaggedEvent(
                            $account,
                            $currentMailbox,
                            $message->getUid(),
                            $imapLabel,
                            true
                        )
                    );

                    // Store in our database for email-based lookup
                    $messageId = $message->getMessageId();
                    if ($messageId !== null) {
                        $this->messageTagMapper->tagMessage($tag, $messageId, $email);
                        // HUBS-START-ADD (Fas F2b): spegla in i mail-appens tagg-
                        // register så taggen SYNS i trådvyn (best-effort).
                        $this->mirrorTagToMailApp($userId, $imapLabel, $messageId);
                    }

                    // Track first message for activity notification
                    if ($firstMessageForActivity === null) {
                        $firstMessageForActivity = $message;
                    }
                }
            }
        } finally {
            $client->logout();
        }

        // Activity notification (only for assignment tags, first message only)
        if ($firstMessageForActivity !== null) {
            $this->publishAssignmentTagActivity($tag, $firstMessageForActivity, $userId);
        }

        return $tag;
    }

    /**
     * Remove a tag from multiple messages (bulk operation).
     *
     * @param string $userId
     * @param string $imapLabel
     * @param int[] $messageDbIds Array of message database IDs
     * @return ItslTag The tag that was removed
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws DBException
     */
    public function untagMessages(string $userId, string $imapLabel, array $messageDbIds): ItslTag {
        if ($messageDbIds === []) {
            throw new Exception('No message IDs provided');
        }

        // Get the first message to determine the account
        $firstId = reset($messageDbIds);
        $firstMessage = $this->mailManager->getMessage($userId, $firstId);
        $mailbox = $this->mailManager->getMailbox($userId, $firstMessage->getMailboxId());
        $accountId = $mailbox->getAccountId();
        $email = $this->getEmailForAccount($userId, $accountId);
        $account = $this->accountService->find($userId, $accountId);
        $tag = $this->tagMapper->getTagByImapLabel($imapLabel, $email);

        // Group messages by mailbox for efficient IMAP operations
        $messagesByMailbox = $this->groupMessagesByDbIds($userId, $messageDbIds);

        // Batch IMAP operations (one store() call per mailbox)
        $client = $this->imapClientFactory->getClient($account);
        try {
            foreach ($messagesByMailbox as $mailboxId => $messages) {
                $currentMailbox = $this->mailManager->getMailbox($userId, $mailboxId);
                $uids = array_map(static fn ($m) => $m->getUid(), $messages);

                if ($this->isPermflagsEnabled($client, $currentMailbox->getName())) {
                    $client->store(
                        $currentMailbox->getName(),
                        [
                            'ids' => new Horde_Imap_Client_Ids($uids),
                            'remove' => [$imapLabel],
                        ]
                    );
                }

                // Dispatch events for each message (for mail app DB updates)
                foreach ($messages as $message) {
                    $this->eventDispatcher->dispatchTyped(
                        new MessageFlaggedEvent(
                            $account,
                            $currentMailbox,
                            $message->getUid(),
                            $imapLabel,
                            false
                        )
                    );

                    // Remove from our database
                    $messageId = $message->getMessageId();
                    if ($messageId !== null) {
                        $this->messageTagMapper->untagMessage($tag, $messageId);
                    }
                }
            }
        } finally {
            $client->logout();
        }

        return $tag;
    }

    /**
     * Set flags on multiple messages (bulk operation).
     *
     * @param string $userId
     * @param int[] $messageDbIds Array of message database IDs
     * @param array<string, bool> $flags Map of flag name => value (e.g., ['flagged' => true])
     * @throws DoesNotExistException
     */
    public function flagMessages(string $userId, array $messageDbIds, array $flags): void {
        if ($messageDbIds === []) {
            throw new Exception('No message IDs provided');
        }

        if ($flags === []) {
            throw new Exception('No flags provided');
        }

        // Get the first message to determine the account
        $firstId = reset($messageDbIds);
        $firstMessage = $this->mailManager->getMessage($userId, $firstId);
        $mailbox = $this->mailManager->getMailbox($userId, $firstMessage->getMailboxId());
        $accountId = $mailbox->getAccountId();
        $account = $this->accountService->find($userId, $accountId);

        // Group messages by mailbox for efficient IMAP operations
        $messagesByMailbox = $this->groupMessagesByDbIds($userId, $messageDbIds);

        // Map flag names to IMAP flags
        $flagMapping = [
            'flagged' => '\\Flagged',
            'seen' => '\\Seen',
            'answered' => '\\Answered',
            'deleted' => '\\Deleted',
            'draft' => '\\Draft',
            'mdnsent' => '$MDNSent',
            'junk' => '$Junk',
            'notjunk' => '$NotJunk',
        ];

        // Batch IMAP operations (one store() call per mailbox per flag)
        $client = $this->imapClientFactory->getClient($account);
        try {
            foreach ($messagesByMailbox as $mailboxId => $messages) {
                $currentMailbox = $this->mailManager->getMailbox($userId, $mailboxId);
                $uids = array_map(static fn ($m) => $m->getUid(), $messages);

                foreach ($flags as $flagName => $value) {
                    $imapFlag = $flagMapping[$flagName] ?? null;
                    if ($imapFlag === null) {
                        continue;
                    }

                    $operation = $value ? 'add' : 'remove';
                    $client->store(
                        $currentMailbox->getName(),
                        [
                            'ids' => new Horde_Imap_Client_Ids($uids),
                            $operation => [$imapFlag],
                        ]
                    );

                    // Dispatch events for each message (for mail app DB updates)
                    foreach ($messages as $message) {
                        $this->eventDispatcher->dispatchTyped(
                            new MessageFlaggedEvent(
                                $account,
                                $currentMailbox,
                                $message->getUid(),
                                $flagName,
                                $value
                            )
                        );
                    }
                }
            }
        } finally {
            $client->logout();
        }
    }
}
