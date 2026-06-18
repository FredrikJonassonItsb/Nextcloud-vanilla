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
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use Exception;
use Psr\Log\LoggerInterface;
use DateTime;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\SdkMc\Event\GuestLogoutEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\SdkMc\Db\ConversationBankIDAuthMapper;
use OCP\IUserSession;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Service\ParticipantService;
use OCP\IUser;
use OCA\Talk\TalkSession;
use OCA\SdkMc\Utils\SSNHelper;
use OCA\SdkMc\Utils\NameCleaner;
use OCP\IL10N;

/**
 * @SuppressWarnings("CouplingBetweenObjects")
 * @SuppressWarnings("ExcessiveParameterList")
 */
class TalkController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private ISession $session,
        private IURLGenerator $urlGenerator,
        private IClientService $clientService,
        private LoggerInterface $logger,
        private IEventDispatcher $eventDispatcher,
        private IUserSession $userSession,
        private Manager $manager,
        private ConversationBankIDAuthMapper $convBankAuthMapper,
        private ParticipantService $participantService,
        private TalkSession $talkSession,
        private IL10N $l,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @return RedirectResponse<303, array{}>
     */
    #[NoCSRFRequired]
    #[PublicPage]
    #[UseSession]
    public function authorize(string $meetingId, string $email): RedirectResponse {
        $this->session->set('meetingId', $meetingId);
        $this->session->set('USER_EMAIL_FROM_INVITATION', $email);

        $oidcState = bin2hex(random_bytes(16));
        $this->session->set('oidcState', $oidcState);

        $authUrlParams = [
            'response_type' => 'code',
            'client_id'     => $this->appConfig->getValueString('sdkmc', 'clientId'),
            'redirect_uri'  => $this->appConfig->getValueString('sdkmc', 'redirectUrl'),
            'scope'         => $this->appConfig->getValueString('sdkmc', 'scope'),
            'state'         => $oidcState,
        ];
        $separator = str_contains($this->appConfig->getValueString('sdkmc', 'authorizeUrl'), '?') ? '&' : '?';
        $authorizationUrl = $this->appConfig->getValueString('sdkmc', 'authorizeUrl') . $separator . http_build_query($authUrlParams);

        return new RedirectResponse($authorizationUrl);
    }

    /**
     * @return RedirectResponse<303, array{}>
     */
    #[NoCSRFRequired]
    #[PublicPage]
    #[UseSession]
    public function callback(?string $code, ?string $state): RedirectResponse {
        if (is_null($code) || is_null($state)) {
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                    'reason' => 'ssn_mismatch',
                ])
            );
        }
        if ($state !== $this->session->get('oidcState')) {
            $this->logger->warning('OIDC state mismatch, restarting auth flow', [
                'has_session_state' => $this->session->exists('oidcState'),
            ]);
            $meetingId = $this->session->get('meetingId');
            $email = $this->session->get('USER_EMAIL_FROM_INVITATION');
            if (is_string($meetingId) && $meetingId !== '') {
                return new RedirectResponse(
                    $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authorize', [
                        'meetingId' => $meetingId,
                        'email' => is_string($email) ? $email : '',
                    ])
                );
            }
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                    'reason' => 'default',
                ])
            );
        }

        // OIDC flow completed — remove state so isOidcFlowActive() no longer
        // prevents the 2-hour session TTL from being enforced.
        $this->session->remove('oidcState');

        // retrieve and clear invitation email from session
        $email = $this->session->get('USER_EMAIL_FROM_INVITATION');
        $this->logger->info('USER EMAIL FROM INVITATION FROM SESSION', ['email' => $email]);
        if ($this->session->exists('USER_EMAIL_FROM_INVITATION')) {
            $this->session->remove('USER_EMAIL_FROM_INVITATION');
        }
        $email = is_string($email) ? $email : '';

        $tokenUrlParams = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->appConfig->getValueString('sdkmc', 'redirectUrl'),
            'client_id'     => $this->appConfig->getValueString('sdkmc', 'clientId'),
            'client_secret' => $this->appConfig->getValueString('sdkmc', 'clientSecret'),
        ];

        $client = $this->clientService->newClient();
        $response = $client->post(
            $this->appConfig->getValueString('sdkmc', 'tokenUrl'),
            [
                'body' => http_build_query($tokenUrlParams),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]
        );
        $responseString = $response->getBody();
        if (!is_string($responseString)) {
            throw new Exception('Error during oidc request, can not read response from server');
        }
        $responseData = json_decode($responseString, true);
        if (!is_array($responseData)) {
            throw new Exception('Error during oidc request, can not decode response from server');
        }

        if (array_key_exists('error', $responseData)) {
            if (is_string($responseData['error'])) {
                throw new Exception('Error during oidc request; ' . $responseData['error']);
            }
            throw new Exception('Error during oidc request');
        }

        if (!array_key_exists('id_token', $responseData) || !is_string($responseData['id_token'])) {
            throw new Exception('id_token not set in response data');
        }
        $idTokenParts = explode('.', $responseData['id_token']);
        if (count($idTokenParts) !== 3) {
            throw new Exception('Malformed id_token');
        }
        $tokenJson = base64_decode(strtr($idTokenParts[1], '-_', '+/'), true);
        if (!is_string($tokenJson)) {
            throw new Exception('Malformed id_token');
        }
        $payload = json_decode($tokenJson, true);
        if (!is_array($payload)) {
            throw new Exception('Malformed id_token, payload cannot be decoded.');
        }

        $availableClaimKeys = array_keys($payload);
        $ssnClaim = $this->appConfig->getValueString('sdkmc', 'ssnClaim');
        $mappedKey = null;
        if ($ssnClaim !== '') {
            // Explicit claim configured — use it directly
            $mappedKey = $ssnClaim;
            if (!array_key_exists($mappedKey, $payload) || (!is_string($payload[$mappedKey]) && !is_int($payload[$mappedKey]))) {
                $this->logger->error('Configured SSN claim not found in OIDC id_token', [
                    'claim' => $ssnClaim,
                    'available_claims' => $availableClaimKeys,
                ]);
                return new RedirectResponse(
                    $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                        'reason' => 'ssn_claim_not_found',
                    ])
                );
            }
        }
        if ($mappedKey === null) {
            // Default fallback chain — accept string or int (JSON numbers)
            foreach (['personalNumber', 'preferred_username', 'sub'] as $candidate) {
                if (array_key_exists($candidate, $payload) && (is_string($payload[$candidate]) || is_int($payload[$candidate]))) {
                    $mappedKey = $candidate;
                    break;
                }
            }
            if ($mappedKey === null) {
                $this->logger->error('No SSN claim found in OIDC id_token', [
                    'tried' => ['personalNumber', 'preferred_username', 'sub'],
                    'available_claims' => $availableClaimKeys,
                ]);
                return new RedirectResponse(
                    $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                        'reason' => 'ssn_claim_not_found',
                    ])
                );
            }
        }

        $rawClaim = $payload[$mappedKey] ?? null;
        $claimValue = is_int($rawClaim) ? (string)$rawClaim : $rawClaim;
        if (!is_string($claimValue)) {
            $this->logger->error('SSN claim value is not a valid string', [
                'claim' => $mappedKey,
            ]);
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                    'reason' => 'ssn_invalid_format',
                ])
            );
        }
        $ssnCandidates = SSNHelper::formatSsnTryBoth($claimValue);
        if ($ssnCandidates === []) {
            $this->logger->error('Invalid SSN format in OIDC claim', [
                'claim' => $mappedKey,
            ]);
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                    'reason' => 'ssn_invalid_format',
                ])
            );
        }
        $usedSSNForBankID = $ssnCandidates[0]; // primary for display/storage

        $firstNameClaimSetting = $this->appConfig->getValueString('sdkmc', 'firstNameClaim');
        $lastNameClaimSetting = $this->appConfig->getValueString('sdkmc', 'lastNameClaim');
        $givenName = $this->resolveClaim($payload, $firstNameClaimSetting, ['givenName', 'given_name'], 'firstNameClaim', $availableClaimKeys);
        if ($givenName === null) {
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                    'reason' => 'name_claim_not_found',
                ])
            );
        }
        $surname = $this->resolveClaim($payload, $lastNameClaimSetting, ['surname', 'family_name'], 'lastNameClaim', $availableClaimKeys);
        if ($surname === null) {
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                    'reason' => 'name_claim_not_found',
                ])
            );
        }

        $emoji = '☑️';

        $showFirst = true;
        $showLast  = true;
        $showSsn   = true;

        $meetingId = $this->session->get('meetingId');
        if (!is_string($meetingId) || $meetingId === '') {
            throw new Exception('Missing meetingId in session');
        }

        // find visibility & verify SSN if stored
        $convBankAuthItem = $this->convBankAuthMapper->findByConversationAndEmail($meetingId, $email);
        if ($convBankAuthItem !== null) {
            $requiredSsn = $convBankAuthItem->getRequiredSsn();

            if ($requiredSsn !== null && $requiredSsn !== '') {
                $ssnVerified = false;
                foreach ($ssnCandidates as $candidate) {
                    if ($convBankAuthItem->verifySsn($candidate)) {
                        $ssnVerified = true;
                        $usedSSNForBankID = $candidate; // use the matched one
                        break;
                    }
                }
                if ($ssnVerified) {
                    $emoji = '✅';
                }
                if (!$ssnVerified) {
                    $this->logger->warning('BankID SSN verification failed', [
                        'expected_ssn_hash' => hash('sha256', $requiredSsn),
                        'received_ssn_hash' => hash('sha256', $usedSSNForBankID),
                        'meeting_id' => $meetingId,
                        'email' => $email,
                    ]);

                    // clear session and force new ID
                    $this->session->clear();
                    $this->session->regenerateId(true);
                    return new RedirectResponse(
                        $this->urlGenerator->linkToRouteAbsolute('sdkmc.talk.authError', [
                            'reason' => 'ssn_mismatch',
                        ])
                    );
                }
            }

            $this->convBankAuthMapper->updateDataFromProvider($meetingId, $email, $givenName, $surname, $usedSSNForBankID);

            $showFirst = $convBankAuthItem->getShowFirstName();
            $showLast  = $convBankAuthItem->getShowLastName();
            $showSsn   = $convBankAuthItem->getShowSsn();
        }

        // Build display name from visibility settings
        $firstName = $showFirst ? $givenName : '';
        $lastName = $showLast ? $surname : '';

        $name = NameCleaner::cleanName($firstName . ' ' . $lastName);
        if ($name === '') {
            $name = 'Guest';
        }

        $ssnSuffix   = $showSsn ? ' (' . $usedSSNForBankID . ')' : '';
        $displayName = trim($name . $ssnSuffix . ' ' . $emoji);

        $actorId = hash('sha256', strtolower($email));
        $this->talkSession->setAuthedEmailActorIdForRoom($meetingId, $actorId);

        $this->session->set('BankIdAuthUserName', $displayName);
        $today = new DateTime();
        $this->session->set('BankIdAuthUserNameTimestamp', $today->format(DateTime::ATOM));

        return new RedirectResponse(
            $this->urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', [
                'token' => $meetingId,
            ])
        );
    }

    /**
     * Logout functionality for BankID authenticated guests
     * Invalidates session data and redirects to appropriate page
     *
     * @param string $token Optional room token to redirect back to
     * @return TemplateResponse<200, array{}>
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function logout(string $token = ''): TemplateResponse {
        $this->eventDispatcher->dispatchTyped(new GuestLogoutEvent($token));

        return new TemplateResponse($this->appName, 'GuestLogout', [
        ], 'guest');
    }

    /**
     * @return TemplateResponse<200, array{}>
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function authError(string $reason = ''): TemplateResponse {
        $messages = [
            'ssn_mismatch' => $this->l->t('The BankID account used does not match the required identity for this meeting.'),
            'ssn_claim_not_found' => $this->l->t('Authentication failed. The identity provider did not return the expected identity claim.'),
            'ssn_invalid_format' => $this->l->t('Authentication failed. The identity provider returned an unrecognized identity format.'),
            'name_claim_not_found' => $this->l->t('Authentication failed. The identity provider did not return the expected name claims.'),
            'default' => $this->l->t('Authentication failed. Please contact the meeting organizer.'),
        ];
        $errorMessage = $messages[$reason] ?? $messages['default'];

        return new TemplateResponse('core', 'error', [
            'errors' => [['error' => $errorMessage]],
        ], 'guest');
    }

    /**
     * Get full guest identity - only accessible by moderators
     *
     * @param string $token Room token
     * @param string $actorId Guest actorId (SHA256 hash of email)
     * @return JSONResponse
     * @phpstan-ignore-next-line return.type
     */
    #[NoCSRFRequired]
    #[UseSession]
    #[NoAdminRequired]
    public function fullGuestName(string $token, string $actorId): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            // @phpstan-ignore return.type
            return new JSONResponse(['success' => false, 'error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $room = $this->manager->getRoomByToken($token);
            $participant = $this->participantService->getParticipant($room, $user->getUID(), false);

            // check if user is moderator
            if (!$participant->hasModeratorPermissions()) {
                return new JSONResponse(['success' => false, 'error' => 'Moderator permissions required'], Http::STATUS_FORBIDDEN);
            }

            // find the BankID auth record for this participant by actorId
            $authRecord = $this->convBankAuthMapper->findByTokenAndActorId($token, $actorId);

            if ($authRecord === null) {
                return new JSONResponse(['success' => false, 'error' => 'No BankID verification found for this participant'], Http::STATUS_NOT_FOUND);
            }

            // return the verified identity information
            return new JSONResponse([
                'success' => true,
                'firstName' => $authRecord->getFirstName(),
                'lastName' => $authRecord->getLastName(),
                'ssn' => $authRecord->getLastUsedSsn(),
                'email' => $authRecord->getEmail(),
            ]);
        } catch (RoomNotFoundException $e) {
            return new JSONResponse(['success' => false, 'error' => 'Room not found'], Http::STATUS_NOT_FOUND);
        } catch (ParticipantNotFoundException $e) {
            return new JSONResponse(['success' => false, 'error' => 'You are not a participant in this room'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Error retrieving guest identity', [
                'exception' => $e,
            ]);
            return new JSONResponse(['success' => false, 'error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Resolve a required OIDC claim from the ID token payload.
     *
     * If a claim name is explicitly configured, only that key is tried.
     * Otherwise the fallback chain is tried in order.
     * Returns null if no matching claim is found.
     *
     * @param array<mixed, mixed> $payload The decoded OIDC ID token payload
     * @param string $configuredClaim Admin-configured claim name (may be empty)
     * @param list<string> $fallbacks Ordered list of default claim names to try
     * @param string $settingName Name of the admin setting (for log messages)
     * @param list<int|string> $availableKeys Claim keys present in the token (for log messages)
     * @return string|null The resolved claim value, or null if not found
     */
    private function resolveClaim(array $payload, string $configuredClaim, array $fallbacks, string $settingName, array $availableKeys): ?string {
        if ($configuredClaim !== '') {
            $value = $payload[$configuredClaim] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            $this->logger->error('Configured OIDC claim not found in id_token', [
                'setting' => $settingName,
                'claim' => $configuredClaim,
                'available_claims' => $availableKeys,
            ]);
            return null;
        }
        foreach ($fallbacks as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        $this->logger->error('No matching OIDC claim found in id_token', [
            'setting' => $settingName,
            'tried' => $fallbacks,
            'available_claims' => $availableKeys,
        ]);
        return null;
    }
}
