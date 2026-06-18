<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use Exception;
use OCP\AppFramework\Services\IAppConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Service to handle email expunge operations via mailstack API.
 * Deletes emails older than configured retention periods.
 */
class ExpungeService {
    private const EXPUNGE_PORT = 10124;
    private const EXPUNGE_ENDPOINT = 'dovecot_messageclient/expunge_policy';

    /** @var array<string, string> Mapping from settings to IMAP folder names */
    private const FOLDER_MAP = [
        'mailRetentionInbox' => 'INBOX',
        'mailRetentionSent' => 'Sent',
        'mailRetentionArchive' => 'Archive',
        'mailRetentionTrash' => 'Trash',
        'mailRetentionDraft' => 'Drafts',
    ];

    public function __construct(
        private IAppConfig $appConfig,
        private IClientService $clientService,
        private ItslAccountService $accountService,
        private MailboxRetentionService $retentionService,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Execute expunge for all mailboxes using current retention settings.
     *
     * @param bool $dryRun If true, only simulate the expunge without deleting
     * @return array{success: bool, stats: array<string, int>, total_operations: int, warnings: array<int, string>, errors: array<int, string>}
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function executeExpunge(bool $dryRun = false): array {
        $emptyResult = [
            'success' => true,
            'stats' => ['total_users' => 0, 'processed' => 0, 'failed' => 0],
            'total_operations' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        // Check preconditions
        $imapHost = $this->appConfig->getAppValueString('imapHost', '');
        if ($imapHost === '') {
            $this->logger->info('ExpungeService: skipping - imapHost not configured');
            return $emptyResult;
        }

        $policy = $this->buildExpungePolicy();
        if ($policy === []) {
            $this->logger->info('ExpungeService: skipping - no retention policy configured or no mailboxes');
            return $emptyResult;
        }

        $this->logger->debug('ExpungeService: executing expunge for ' . count($policy) . ' mailboxes');

        try {
            $policyJson = json_encode($policy);
            if ($policyJson === false) {
                throw new Exception('Failed to encode policy JSON');
            }

            $args = $dryRun
                ? ['--dry-run', '--json', $policyJson]
                : ['--json', $policyJson];

            $reportJson = $this->makeExpungeRequest($args);
            $result = json_decode($reportJson, true);

            if (!is_array($result)) {
                throw new Exception('Invalid response from expunge API');
            }

            $success = isset($result['success']) && is_bool($result['success']) ? $result['success'] : false;
            $totalOperations = isset($result['total_operations']) && is_int($result['total_operations']) ? $result['total_operations'] : 0;

            /** @var array<string, int> $stats */
            $stats = isset($result['stats']) && is_array($result['stats']) ? $result['stats'] : ['total_users' => 0, 'processed' => 0, 'failed' => 0];
            $processed = $stats['processed'] ?? 0;

            /** @var array<int, string> $warnings */
            $warnings = isset($result['warnings']) && is_array($result['warnings']) ? array_values($result['warnings']) : [];

            /** @var array<int, string> $errors */
            $errors = isset($result['errors']) && is_array($result['errors']) ? array_values($result['errors']) : [];

            // Dispatch audit event for successful expunge
            if (!$dryRun && $totalOperations > 0) {
                $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
                    'Expunged emails: [%d] mailboxes processed, [%d] operations performed',
                    [$processed, $totalOperations]
                ));
            }

            return [
                'success' => $success,
                'stats' => $stats,
                'total_operations' => $totalOperations,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        } catch (Exception $e) {
            $this->logger->error('ExpungeService: failed - ' . $e->getMessage(), ['exception' => $e]);
            return [
                'success' => false,
                'stats' => ['total_users' => 0, 'processed' => 0, 'failed' => 0],
                'total_operations' => 0,
                'warnings' => [],
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Build expunge policy from retention settings for all mailboxes.
     * Merges global settings with per-mailbox overrides.
     *
     * @return array<string, array<string, string|null>> Policy indexed by email address
     */
    public function buildExpungePolicy(): array {
        $mailboxes = $this->accountService->getAllMailboxes();
        $policy = [];

        // Read global retention settings
        $globalDefault = $this->appConfig->getAppValueInt('mailRetentionDefault', 0);
        $globalFolders = [];
        foreach (self::FOLDER_MAP as $settingKey => $imapFolder) {
            $globalFolders[$imapFolder] = $this->appConfig->getAppValueInt($settingKey, 0);
        }

        // Apply to all mailboxes
        foreach ($mailboxes as $typeMailboxes) {
            foreach ($typeMailboxes as $mailbox) {
                if (!is_array($mailbox)) {
                    continue;
                }
                $email = $mailbox['email'] ?? null;
                if (!is_string($email)) {
                    continue;
                }

                // Get per-mailbox overrides
                $overrides = $this->retentionService->getOverrides($email);

                $folderPolicy = $this->buildMailboxFolderPolicy(
                    $overrides,
                    $globalDefault,
                    $globalFolders
                );

                if ($folderPolicy !== []) {
                    $policy[$email] = $folderPolicy;
                }
            }
        }

        return $policy;
    }

    /**
     * Build folder policy for a specific mailbox, merging per-mailbox overrides with global settings.
     *
     * @param array<string, int|null> $overrides Per-mailbox folder overrides
     * @param int $globalDefault Global default retention in days
     * @param array<string, int> $globalFolders Global per-folder retention values
     * @return array<string, string|null> Folder policy
     */
    private function buildMailboxFolderPolicy(
        array $overrides,
        int $globalDefault,
        array $globalFolders,
    ): array {
        // Effective default: mailbox override -> global
        $effectiveDefault = array_key_exists('*', $overrides)
            ? $overrides['*']
            : $globalDefault;

        $folderPolicy = [];
        $hasWildcard = $effectiveDefault > 0;

        // Set wildcard (default) if applicable
        if ($hasWildcard) {
            $folderPolicy['*'] = $this->daysToAgeFormat($effectiveDefault);
        }

        // Process each folder
        foreach (self::FOLDER_MAP as $imapFolder) {
            // Priority: mailbox override -> global
            if (array_key_exists($imapFolder, $overrides)) {
                $days = $overrides[$imapFolder];
                if ($days > 0) {
                    $folderPolicy[$imapFolder] = $this->daysToAgeFormat($days);
                } elseif ($days === 0 && $hasWildcard) {
                    // Keep forever - override wildcard with null
                    $folderPolicy[$imapFolder] = null;
                }
                continue;
            }
            // No mailbox override - use global
            $globalValue = $globalFolders[$imapFolder] ?? 0;
            if ($globalValue > 0) {
                $folderPolicy[$imapFolder] = $this->daysToAgeFormat($globalValue);
            } elseif ($hasWildcard && $globalValue === 0) {
                // Global says keep forever but we have wildcard - override
                $folderPolicy[$imapFolder] = null;
            }
        }

        // Snoozed is an extension of INBOX — apply the same policy
        if (array_key_exists('INBOX', $folderPolicy)) {
            $folderPolicy['Snoozed'] = $folderPolicy['INBOX'];
        }

        return $folderPolicy;
    }

    /**
     * Convert days to API age format.
     *
     * @param int $days Number of days (must be > 0)
     * @return string Age format like "30d"
     */
    private function daysToAgeFormat(int $days): string {
        return $days . 'd';
    }

    /**
     * Make HTTP request to the expunge API.
     * Duplicated from ItslAccountService::makeHttpRequest() since it's private.
     *
     * @param list<string> $arguments CLI arguments for the expunge script
     * @return string The report JSON from the API
     * @throws Exception If the request fails
     */
    private function makeExpungeRequest(array $arguments): string {
        $client = $this->clientService->newClient();
        $baseUrl = 'http://' . $this->appConfig->getAppValueString('imapHost') . ':' . self::EXPUNGE_PORT . '/api/' . self::EXPUNGE_ENDPOINT;

        // Start the async job
        $response = $client->post($baseUrl, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Api-Token' => $this->appConfig->getAppValueString('sdkmcmwSecretPassword'),
            ],
            'body' => json_encode([
                'args' => $arguments,
                'force_unique_key' => true
            ])
        ]);

        $bodyString = $response->getBody();
        if (!is_string($bodyString)) {
            throw new Exception('Unable to initialize expunge job');
        }

        $body = json_decode($bodyString, true);
        if (!is_array($body) || !array_key_exists('status', $body) || $body['status'] !== 'running') {
            throw new Exception('Unable to initialize expunge job');
        }

        if (!array_key_exists('key', $body) || !is_string($body['key'])) {
            throw new Exception('Unable to initialize expunge job');
        }

        // Wait for completion (blocking)
        $waitUrl = $baseUrl . '?key=' . $body['key'] . '&wait=true';
        $response = $client->get($waitUrl, [
            'headers' => [
                'X-Api-Token' => $this->appConfig->getAppValueString('sdkmcmwSecretPassword'),
            ],
        ]);

        $bodyString = $response->getBody();
        if (!is_string($bodyString)) {
            throw new Exception('Unable to get expunge result');
        }

        $body = json_decode($bodyString, true);
        if (!is_array($body)) {
            throw new Exception('Expunge job failed: Invalid JSON response');
        }
        if (!array_key_exists('returncode', $body) || $body['returncode'] !== 0) {
            $error = isset($body['error']) && is_string($body['error'])
                ? $body['error']
                : 'Unknown error';
            throw new Exception('Expunge job failed: ' . $error);
        }

        if (!array_key_exists('report', $body) || !is_string($body['report'])) {
            $error = isset($body['error']) && is_string($body['error'])
                ? $body['error']
                : 'No report in response';
            throw new Exception('Expunge job failed: ' . $error);
        }

        return $body['report'];
    }
}
