<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use Exception;
use OCA\Mail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\SdkMc\Service\ItslAccountService;

class MailBoxController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ItslAccountService $service,
        private AccountService $accountService,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponse
     * @phpstan-return JSONResponse<200, array<string, string>, array{}>|JSONResponse<500, array{status: string, error: string}, array{}>
     */
    public function existingAddresses(): JSONResponse {
        try {
            $sdkmailboxes = $this->service->getMailBoxes('sdk');
            $returnval = [];
            foreach ($sdkmailboxes as $entry) {
                if (!array_key_exists('sdkaddress', $entry)) {
                    throw new Exception('SDK address entry invalid');
                }
                $returnval[ $entry['sdkaddress'] ] = $entry['email'];
            }
            return new JSONResponse($returnval);
        } catch (Exception $e) {
            return new JSONResponse(
                ['status' => 'Failed to process', 'error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @return JSONResponse
     * @phpstan-return JSONResponse<200, array<string, string>, array{}>|JSONResponse<500, array{status: string, error: string}, array{}>
     */
    public function existingAddressesToken(): JSONResponse {
        return $this->existingAddresses();
    }

    /**
     * @return list<array{name: string, description: string, email: string, canBeRepliedTo: boolean, canMessageBeSentTo: boolean}>
     */
    public function getInternalMailboxes(bool $filtercanMessageBeSentTo): array {
        $gruppboxes = $this->service->getMailBoxes('gruppbox');
        $personligboxes = $this->service->getMailBoxes('personlig');

        $returnval = [];
        foreach (array_merge($gruppboxes, $personligboxes) as $entry) {
            if (!array_key_exists('canMessageBeSentTo', $entry)) {
                $entry['canMessageBeSentTo'] = true;
            }
            if ($filtercanMessageBeSentTo && !$entry['canMessageBeSentTo']) {
                continue;
            }
            $returnval[] = [
                'name' => array_key_exists('name', $entry) ? $entry['name'] : '',
                'description' => array_key_exists('description', $entry) ? $entry['description'] : '',
                'email' => $entry['email'],
                'canBeRepliedTo' => array_key_exists('canBeRepliedTo', $entry) ? $entry['canBeRepliedTo'] : true,
                'canMessageBeSentTo' =>  $entry['canMessageBeSentTo'],
            ];
        }
        return $returnval;
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @return JSONResponse
     * @phpstan-return JSONResponse<200, list<array{name: string, description: string, email: string, canBeRepliedTo: boolean, canMessageBeSentTo: boolean}>, array{}> | JSONResponse<500, array{status: string, error: string}, array{}>
     */
    public function internalMailboxes(): JSONResponse {
        try {
            return new JSONResponse($this->getInternalMailboxes(true));
        } catch (Exception $e) {
            return new JSONResponse(
                ['status' => 'Error', 'error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponsesd
     * @phpstan-return JSONResponse<200, list<array{name: string, description: string, email: string, canBeRepliedTo: boolean, canMessageBeSentTo: boolean}>, array{}> | JSONResponse<500, array{status: string, error: string}, array{}>
     */
    public function internalMailboxesAB(): JSONResponse {
        try {
            return new JSONResponse($this->getInternalMailboxes(false));
        } catch (Exception $e) {
            return new JSONResponse(
                ['status' => 'Error', 'error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponse
     * @phpstan-return JSONResponse<200, array{accounts: mixed}, array{}>|JSONResponse<500, array{status: string, error: string}, array{}>
     */
    public function existingAllMailboxes(): JSONResponse {
        try {
            $allMailboxes = $this->service->getAllAccounts();

            return new JSONResponse([
                'accounts' => $allMailboxes,
            ]);
        } catch (Exception $e) {
            return new JSONResponse(
                ['status' => 'Failed to process', 'error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponse
     * @phpstan-return JSONResponse<200, array{users: mixed}, array{}>|JSONResponse<500, array{status: string, error: string}, array{}>
     */
    public function existingAllUsers(): JSONResponse {
        try {
            $allUsers = $this->service->getAllUsers();

            return new JSONResponse([
                'users' => $allUsers,
            ]);
        } catch (Exception $e) {
            return new JSONResponse(
                ['status' => 'Failed to process', 'error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get personlig and gruppbox mailboxes for the current user
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponse
     * @phpstan-return JSONResponse<200, list<array{accountId: int, name: string, email: string}>, array{}> | JSONResponse<401, array{status: string, error: string}, array{}> | JSONResponse<500, array{status: string, error: string}, array{}>
     */
    public function getUserMailboxes(): JSONResponse {
        try {
            // Get current user from session
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(
                    ['status' => 'Failed to process', 'error' => 'User not authenticated'],
                    Http::STATUS_UNAUTHORIZED
                );
            }

            $userId = $user->getUID();

            // Get user Mail accounts
            $userAccounts = $this->accountService->findByUserId($userId);
            $userEmailAddresses = [];
            $accountIdMap = [];

            foreach ($userAccounts as $account) {
                $email = $account->getEmail();
                $userEmailAddresses[] = $email;
                $accountIdMap[$email] = $account->getId();
            }

            // Get personlig and gruppbox mailboxes from config
            $personnligMailboxes = $this->service->getMailBoxes('personlig');
            $gruppboxMailboxes = $this->service->getMailBoxes('gruppbox');

            $result = [];

            // Filter personlig mailboxes
            foreach ($personnligMailboxes as $mailbox) {
                $email = $mailbox['email'];
                if (in_array($email, $userEmailAddresses, true)) {
                    $result[] = [
                        'accountId' => $accountIdMap[$email] ?? 0,
                        'name' => $mailbox['name'] ?? $email,
                        'email' => $email,
                    ];
                }
            }

            // Filter gruppbox mailboxes
            foreach ($gruppboxMailboxes as $mailbox) {
                $email = $mailbox['email'];
                if (in_array($email, $userEmailAddresses, true)) {
                    $result[] = [
                        'accountId' => $accountIdMap[$email] ?? 0,
                        'name' => $mailbox['name'] ?? $email,
                        'email' => $email,
                    ];
                }
            }

            return new JSONResponse($result);
        } catch (Exception $e) {
            return new JSONResponse(
                ['status' => 'Failed to process', 'error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
