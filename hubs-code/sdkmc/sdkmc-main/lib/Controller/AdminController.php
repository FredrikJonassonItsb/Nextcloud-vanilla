<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCA\SdkMc\BackgroundJob\UpdateAddressBookBackgroundJob;
use OCA\SdkMc\Service\BackgroundJobService;
use OCA\SdkMc\Service\ItslAccountService;
use OCA\SdkMc\Service\MailboxRetentionService;
use OCP\Activity\ActivitySettings;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Controller;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IAppConfig as IGlobalAppConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;
use OCP\IUser;
use OCP\AppFramework\Http;
use OCA\SdkMc\Settings\ServerSettings;
use OCA\SdkMc\Settings\ExternalSettings;
use OCP\AppFramework\Http\JSONResponse;
use OCA\SdkMc\BackgroundJob\DeleteInactiveTalkRoomBackgroundJob;
use OCA\SdkMc\BackgroundJob\ExpungeJob;
use OCA\SdkMc\BackgroundJob\ProvisionPersonligAccountsBackgroundJob;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use DateTime;
use Exception;
use OCP\IURLGenerator;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
class AdminController extends Controller {
    private const ACTIVITY_ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IGlobalAppConfig $globalAppConfig,
        private ItslAccountService $service,
        private IJobList $jobList,
        private IEventDispatcher $eventDispatcher,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private MailboxRetentionService $retentionService,
        private BackgroundJobService $backgroundJobService,
        private IDBConnection $db,
        private IActivityManager $activityManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueServerSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array<string, array<mixed>|bool|float|int|string>, array{}>
     */
    public function getServerSettings(): JSONResponse {
        /** @var array<string, string|int|float|bool|array<mixed>> */
        $settings = [];

        foreach (ServerSettings::$availableSettings as $key => $meta) {
            $type = $meta['type'];
            $defaultBoolean = $key === 'secureMeetingsEnabled' || $key === 'threadSortNewestFirst' || $key === 'hideDefaultLoginLink'; // these settings default to true

            $value = match($type) {
                'string' => $this->appConfig->getAppValueString($key, ''),
                'int' => $this->appConfig->getAppValueInt($key, 0),
                'float' => $this->appConfig->getAppValueFloat($key, 0.0),
                'bool' => $this->appConfig->getAppValueBool($key, $defaultBoolean),
                'array' => $this->appConfig->getAppValueArray($key, []),
            };

            $settings[$key] = $value;
        }

        foreach (ExternalSettings::$availableSettings as $key => $meta) {
            $type = $meta['type'];

            $value = match($type) {
                'string' => $this->globalAppConfig->getValueString($meta['app'], $key, ''),
                'int' => $this->globalAppConfig->getValueInt($meta['app'], $key, 0),
                'float' => $this->globalAppConfig->getValueFloat($meta['app'], $key, 0.0),
                'bool' => $this->globalAppConfig->getValueBool($meta['app'], $key, false),
                'array' => $this->globalAppConfig->getValueArray($meta['app'], $key, []),
            };

            $settings[$key] = $value;
        }

        if (!isset($settings['tenantUrl']) || $settings['tenantUrl'] === '') {
            $settings['tenantUrl'] = $this->urlGenerator->getAbsoluteURL('/apps/scimserviceprovider');
        }

        return new JSONResponse($settings);
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueServerSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function serverSettings(): JSONResponse {
        $oldDays = $this->appConfig->getAppValueInt('deleteTalkAfterDays', 0);
        foreach (ServerSettings::$availableSettings as $setting => $settingProperties) {
            match($settingProperties['type']) {
                'string' => $this->appConfig->setAppValueString($setting, strval($this->request->getParam($setting, '')), $settingProperties['lazy'], $settingProperties['secret']), // @phpstan-ignore argument.type
                'int' => $this->appConfig->setAppValueInt($setting, intval($this->request->getParam($setting, 0)), $settingProperties['lazy'], $settingProperties['secret']), // @phpstan-ignore argument.type
                'float' => $this->appConfig->setAppValueFloat($setting, floatval($this->request->getParam($setting, 0.0)), $settingProperties['lazy'], $settingProperties['secret']), // @phpstan-ignore argument.type
                'bool' => $this->appConfig->setAppValueBool($setting, filter_var($this->request->getParam($setting, false), FILTER_VALIDATE_BOOLEAN), $settingProperties['lazy']),
                'array' => $this->appConfig->setAppValueArray($setting, (array)$this->request->getParam($setting, []), $settingProperties['lazy'], $settingProperties['secret']), //todo: this one will probably need some more work
            };
        }

        foreach (ExternalSettings::$availableSettings as $setting => $settingProperties) {
            match($settingProperties['type']) {
                'string' => $this->globalAppConfig->setValueString(strval($settingProperties['app']), $setting, strval($this->request->getParam($setting, '')), $settingProperties['lazy'], $settingProperties['secret']), // @phpstan-ignore argument.type
                'int' => $this->globalAppConfig->setValueInt(strval($settingProperties['app']), $setting, intval($this->request->getParam($setting, 0)), $settingProperties['lazy'], $settingProperties['secret']), // @phpstan-ignore argument.type
                'float' => $this->globalAppConfig->setValueFloat(strval($settingProperties['app']), $setting, floatval($this->request->getParam($setting, 0.0)), $settingProperties['lazy'], $settingProperties['secret']), // @phpstan-ignore argument.type
                'bool' => $this->globalAppConfig->setValueBool(strval($settingProperties['app']), $setting, filter_var($this->request->getParam($setting, false), FILTER_VALIDATE_BOOLEAN), $settingProperties['lazy']),
                'array' => $this->globalAppConfig->setValueArray(strval($settingProperties['app']), $setting, (array)$this->request->getParam($setting, []), $settingProperties['lazy'], $settingProperties['secret']), //todo: this one will probably need some more work
            };
        }

        $newDays = $this->appConfig->getAppValueInt('deleteTalkAfterDays', 0);
        if ($oldDays <= 0 && $newDays > 0) {
            $this->jobList->scheduleAfter(DeleteInactiveTalkRoomBackgroundJob::class, time() - 1);
        } elseif ($oldDays > 0 && $newDays <= 0) {
            $this->jobList->remove(DeleteInactiveTalkRoomBackgroundJob::class);
        }

        /**
         * Apply system config based on autoLogoutSeconds rules:
         * - If user enters a number > 120:
         *     session_lifetime = seconds
         *     session_keepalive = false
         *     auto_logout = true
         * - If user enters 0:
         *     session_lifetime = 2147483646
         *     session_keepalive = true
         *     auto_logout = false
         */
        $raw = $this->request->getParam('autoLogoutSeconds', null);
        if ($raw !== null) {
            $seconds = filter_var($raw, FILTER_VALIDATE_INT);
            $seconds = $seconds !== false ? $seconds : 0;

            if ($seconds === 0) {
                $this->disableAutoLogout();
            } elseif ($seconds > 120) {
                $this->enableAutoLogout($seconds);
            }
        }

        return new JSONResponse();
    }
    private function disableAutoLogout(): void {
        ob_start();
        system('php occ config:system:set session_lifetime --value="2147483646"');
        system('php occ config:system:set session_keepalive --value="true"');
        system('php occ config:system:set auto_logout --value="false"');
        ob_end_clean();
    }

    private function enableAutoLogout(int $seconds): void {
        $safe = $seconds;
        if ($safe < 121) {
            $safe = 121;
        }
        // Prevent overflow or silly values; 2^31-2 is enough (keep below PHP int edge / OC limits)
        if ($safe > 2147483646) {
            $safe = 2147483646;
        }
        $safeStr = (string)$safe;

        ob_start();
        system('php occ config:system:set session_lifetime --value="' . $safeStr . '"');
        system('php occ config:system:set session_keepalive --value="false"');
        system('php occ config:system:set auto_logout --value="true"');
        ob_end_clean();
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function addUserToMailBox(
        string $userId,
        string $messageType,
        string $email,
    ): JSONResponse {
        $this->service->addUserToMailBox($messageType, $email, $userId);
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Added user [%s] to mailbox [%s], type [%s]', [$userId, $email, $messageType]));
        return new JSONResponse();
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function removeUserFromMailBox(
        string $userId,
        string $email,
    ): JSONResponse {
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Removed user [%s] from mailbox [%s]', [$userId, $email]));
        $this->service->removeUserFromMailBox($email, $userId);
        return new JSONResponse();
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{groups: array<string>}, array{}>
     */
    public function getGroupsForMailBox(
        string $messageType,
        string $email,
    ): JSONResponse {
        $groups = $this->service->getGroupsForMailBox($messageType, $email);
        return new JSONResponse(['groups' => $groups]);
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function addGroupToMailBox(
        string $messageType,
        string $email,
        string $groupId,
    ): JSONResponse {
        $this->service->addGroupToMailBox($messageType, $email, $groupId);
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Added group [%s] to mailbox [%s], type [%s]', [$groupId, $email, $messageType]));
        return new JSONResponse();
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function removeGroupFromMailBox(
        string $messageType,
        string $email,
        string $groupId,
    ): JSONResponse {
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Removed group [%s] from mailbox [%s], type [%s]', [$groupId, $email, $messageType]));
        $this->service->removeGroupFromMailBox($messageType, $email, $groupId);
        return new JSONResponse();
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @param array<string, int|null>|null $retentionOverrides
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    public function addAccount(
        string $messageType,
        string $sdkaddress,
        string $alias,
        string $name,
        string $description,
        string $number,
        bool $canBeRepliedTo,
        bool $canMessageBeSentTo,
        ?array $retentionOverrides = null,
        ?string $notificationEmail = null,
    ): JSONResponse {
        try {
            $email = strtolower($alias . '@' . $messageType);
            $this->service->addAccount(
                $messageType,
                $email,
                $alias,
                ['sdkaddress' => $sdkaddress, 'name' => $name, 'description' => $description, 'number' => $number, 'canBeRepliedTo' => $canBeRepliedTo, 'canMessageBeSentTo' => $canMessageBeSentTo, 'notificationEmail' => $notificationEmail ]
            );

            // Save retention overrides if provided
            if ($retentionOverrides !== null) {
                /** @var array<string, int|null> $retentionOverrides */
                $this->retentionService->saveOverrides($email, $retentionOverrides);
            }

            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Added account with name [%s], alias %s [%s]', [$name, $alias, $description]));
            return new JSONResponse();
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @param array<string, int|null>|null $retentionOverrides
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    public function updateAccount(
        string $messageType,
        string $alias,
        string $name,
        string $description,
        bool $canBeRepliedTo,
        bool $canMessageBeSentTo,
        ?array $retentionOverrides = null,
        ?string $notificationEmail = null,
    ): JSONResponse {
        try {
            $email = strtolower($alias . '@' . $messageType);

            // Build settings array based on what each mailbox type allows
            $settings = match ($messageType) {
                'sdk' => ['notificationEmail' => $notificationEmail],
                'sms' => ['name' => $name, 'description' => $description],
                'fax' => ['name' => $name, 'description' => $description, 'notificationEmail' => $notificationEmail],
                default => ['name' => $name, 'description' => $description, 'canBeRepliedTo' => $canBeRepliedTo, 'canMessageBeSentTo' => $canMessageBeSentTo, 'notificationEmail' => $notificationEmail],
            };

            $this->service->updateAccount($messageType, $alias, $settings);

            // Save retention overrides if provided
            if ($retentionOverrides !== null) {
                /** @var array<string, int|null> $retentionOverrides */
                $this->retentionService->saveOverrides($email, $retentionOverrides);
            }

            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Updated account with name [%s], alias %s [%s]', [$name, $alias, $description]));
            return new JSONResponse();
        } catch (Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function provisionPersonligAccounts(?string $groupId = null): JSONResponse {
        $this->backgroundJobService->executeNow(
            ProvisionPersonligAccountsBackgroundJob::class,
            ['groupId' => $groupId],
            false  // Don't skip - allow multiple provisions with different groupIds
        );
        return new JSONResponse();
    }

    /**
     * @NoCSRFRequired
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueServerSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function updateAddressBook(): JSONResponse {
        $this->backgroundJobService->executeNow(UpdateAddressBookBackgroundJob::class);
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Updated address book [at: %s]', [new DateTime()]));
        return new JSONResponse();
    }

    /**
     * @NoCSRFRequired
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueServerSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function runExpungeNow(): JSONResponse {
        $this->backgroundJobService->executeNow(ExpungeJob::class);
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Retention expunge triggered manually [at: %s]', [new DateTime()]));
        return new JSONResponse();
    }

    /**
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{}, array{}>
     */
    public function removeAccount(string $email, string $messageType): JSONResponse {
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Removed account [email: %s]', [$email]));
        $this->retentionService->deleteOverrides($email);
        $this->service->removeAccount($messageType, $email);
        return new JSONResponse();
    }

    /**
     * Get current admin notification defaults and per-key user drift counts,
     * grouped by activity group (matching NC Activity admin page).
     *
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueServerSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{groups: list<array{id: string, name: string, settings: list<array{id: string, name: string, priority: int, email: array{key: string, adminDefault: bool, differCount: int, canChange: bool}, push: array{key: string, adminDefault: bool, differCount: int, canChange: bool}}>}>, totalUsers: int, hasDefaults: bool}, array{}>
     */
    public function getActivityNotificationStatus(): JSONResponse {
        // 1. Fetch all registered activity settings, sorted by priority then identifier
        $activitySettings = $this->activityManager->getSettings();
        usort($activitySettings, static function (ActivitySettings $a, ActivitySettings $b): int {
            $cmp = $a->getPriority() <=> $b->getPriority();
            return $cmp !== 0 ? $cmp : strcmp($a->getIdentifier(), $b->getIdentifier());
        });

        // 2. Filter out settings where admin cannot change either method
        //    and validate identifiers against known-safe pattern (M-2)
        $activitySettings = array_filter(
            $activitySettings,
            static fn ($s): bool => ($s->canChangeMail() || $s->canChangeNotification())
                && preg_match(self::ACTIVITY_ID_PATTERN, $s->getIdentifier()) === 1,
        );

        // 3. Build list of all keys and read admin defaults
        $allKeys = [];
        $adminDefaults = [];
        foreach ($activitySettings as $setting) {
            $id = $setting->getIdentifier();
            foreach (['email', 'notification'] as $method) {
                $key = 'notify_' . $method . '_' . $id;
                $allKeys[] = $key;
                $adminDefaults[$key] = $this->globalAppConfig->getValueString('activity', $key, '');
            }
        }

        $hasDefaults = count(array_filter($adminDefaults, static fn (string $v): bool => $v !== '')) > 0;

        // 4. Count users per key/value in oc_preferences (single efficient query)
        $prefCounts = [];
        if ($allKeys !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('configkey', 'configvalue')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('preferences')
                ->where($qb->expr()->eq('appid', $qb->createNamedParameter('activity')))
                ->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter($allKeys, IQueryBuilder::PARAM_STR_ARRAY)))
                ->groupBy('configkey', 'configvalue');

            $result = $qb->executeQuery();
            /** @var list<array{configkey: string, configvalue: string, cnt: string}> $rows */
            $rows = $result->fetchAll();
            $result->closeCursor();

            foreach ($rows as $row) {
                $prefCounts[$row['configkey']][$row['configvalue']] = (int)$row['cnt'];
            }
        }

        // 5. Count total users
        $totalUsers = 0;
        $counts = $this->userManager->countUsers();
        foreach ($counts as $count) {
            $totalUsers += $count;
        }

        // 6. Group settings by group identifier
        /** @var array<string, array{id: string, name: string, settings: list<array{id: string, name: string, priority: int, email: array{key: string, adminDefault: bool, differCount: int, canChange: bool}, push: array{key: string, adminDefault: bool, differCount: int, canChange: bool}}>}> $grouped */
        $grouped = [];
        foreach ($activitySettings as $setting) {
            $groupId = $setting->getGroupIdentifier();
            $groupName = $setting->getGroupName();
            $id = $setting->getIdentifier();

            if (!isset($grouped[$groupId])) {
                $grouped[$groupId] = [
                    'id' => $groupId,
                    'name' => strip_tags($groupName),
                    'settings' => [],
                ];
            }

            $emailKey = 'notify_email_' . $id;
            $pushKey = 'notify_notification_' . $id;

            $grouped[$groupId]['settings'][] = [
                'id' => $id,
                'name' => strip_tags($setting->getName()),
                'priority' => $setting->getPriority(),
                'email' => $this->buildMethodStatus($emailKey, $adminDefaults[$emailKey] ?? '', $prefCounts, $setting->canChangeMail()),
                'push' => $this->buildMethodStatus($pushKey, $adminDefaults[$pushKey] ?? '', $prefCounts, $setting->canChangeNotification()),
            ];
        }

        // 7. Move the 'other' group to the end (matching NC Activity admin page)
        $groups = array_values($grouped);
        $otherIdx = array_search('other', array_column($groups, 'id'), true);
        if ($otherIdx !== false) {
            $other = array_splice($groups, $otherIdx, 1);
            $groups = array_merge($groups, $other);
        }

        return new JSONResponse([
            'groups' => $groups,
            'totalUsers' => $totalUsers,
            'hasDefaults' => $hasDefaults,
        ]);
    }

    /**
     * Build status for a single notification method (email or push).
     *
     * @param string $key The oc_appconfig key (e.g. 'notify_email_messages_sdk')
     * @param string $adminValue Raw admin default value ('' if unset, '0' or '1')
     * @param array<string, array<string, int>> $prefCounts Lookup of key => [value => userCount]
     * @param bool $canChange Whether the admin can change this method
     * @return array{key: string, adminDefault: bool, differCount: int, canChange: bool}
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function buildMethodStatus(string $key, string $adminValue, array $prefCounts, bool $canChange = true): array {
        // If admin never saved this key, treat the code default (true) as the effective default
        $effectiveValue = $adminValue !== '' ? $adminValue : '1';
        $adminBool = $effectiveValue === '1';

        // Count users who explicitly have a DIFFERENT value
        $oppositeValue = $adminBool ? '0' : '1';
        $differCount = $prefCounts[$key][$oppositeValue] ?? 0; // @phpstan-ignore nullCoalesce.offset

        return [
            'key' => $key,
            'adminDefault' => $adminBool,
            'differCount' => $differCount,
            'canChange' => $canChange,
        ];
    }

    /**
     * Propagate selected admin notification defaults to all existing users
     * using batch SQL (UPDATE existing + INSERT missing) in a single transaction.
     *
     * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueServerSettingsAdmin)
     * @return JSONResponse<Http::STATUS_OK, array{updated: int, keys: list<string>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     */
    public function propagateActivityDefaults(): JSONResponse {
        // Build the allowed key set dynamically from registered activity settings
        $allowedKeys = [];
        foreach ($this->activityManager->getSettings() as $setting) {
            if (!$setting->canChangeMail() && !$setting->canChangeNotification()) {
                continue;
            }
            $id = $setting->getIdentifier();
            if (preg_match(self::ACTIVITY_ID_PATTERN, $id) !== 1) {
                continue;
            }
            $allowedKeys[] = 'notify_email_' . $id;
            $allowedKeys[] = 'notify_notification_' . $id;
        }

        // Read selected keys from request (supports both keys[]=... and full FormData)
        $keys = $this->request->getParam('keys');
        if (!is_array($keys) || $keys === []) {
            $keys = $allowedKeys;
        }

        // I-1: pre-filter non-string elements before array_diff
        $keys = array_values(array_filter($keys, 'is_string'));
        if ($keys === []) {
            $keys = $allowedKeys;
        }

        // Validate: every key must be in the allowed set
        $invalid = array_diff($keys, $allowedKeys);
        if ($invalid !== []) {
            return new JSONResponse(
                ['error' => 'Invalid keys: ' . implode(', ', $invalid)],
                Http::STATUS_BAD_REQUEST
            ); // @phpstan-ignore return.type
        }

        // Phase 1: Collect all UIDs (DB, LDAP, SAML — all backends)
        $uids = [];
        $this->userManager->callForAllUsers(function (IUser $user) use (&$uids): void {
            $uids[] = $user->getUID();
        });

        // Phase 2: Read admin defaults (fallback to '1' if unset)
        $defaults = [];
        foreach ($keys as $key) {
            $value = $this->globalAppConfig->getValueString('activity', $key, '');
            $defaults[$key] = $value !== '' ? $value : '1';
        }

        // Phase 3: Bulk UPDATE existing + INSERT missing in a single transaction
        $this->db->beginTransaction();
        try {
            foreach ($defaults as $key => $value) {
                // One UPDATE covers ALL existing rows for this key
                $qb = $this->db->getQueryBuilder();
                $qb->update('preferences')
                    ->set('configvalue', $qb->createNamedParameter($value))
                    ->set('type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->set('flags', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('appid', $qb->createNamedParameter('activity')))
                    ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
                $qb->executeStatement();

                // Find which users already have this key
                $qb = $this->db->getQueryBuilder();
                $qb->select('userid')
                    ->from('preferences')
                    ->where($qb->expr()->eq('appid', $qb->createNamedParameter('activity')))
                    ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
                $result = $qb->executeQuery();
                $existingUids = [];
                while ($row = $result->fetch()) {
                    $existingUids[$row['userid']] = true; // @phpstan-ignore offsetAccess.nonOffsetAccessible
                }
                $result->closeCursor();

                // INSERT only for users missing this preference
                $missingUids = array_filter($uids, static fn (string $uid): bool => !isset($existingUids[$uid]));
                foreach ($missingUids as $uid) {
                    $this->db->setValues(
                        'preferences',
                        ['userid' => $uid, 'appid' => 'activity', 'configkey' => $key],
                        ['configvalue' => $value, 'lazy' => 0, 'type' => 0, 'flags' => 0, 'indexed' => ''],
                    );
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Phase 4: Audit log + response
        $keyList = array_keys($defaults);
        $this->eventDispatcher->dispatchTyped(
            new CriticalActionPerformedEvent(
                'Propagated activity notification defaults (%s) to %d users',
                [implode(', ', $keyList), count($uids)]
            )
        );

        return new JSONResponse(['updated' => count($uids), 'keys' => $keyList]); // @phpstan-ignore return.type
    }
}
