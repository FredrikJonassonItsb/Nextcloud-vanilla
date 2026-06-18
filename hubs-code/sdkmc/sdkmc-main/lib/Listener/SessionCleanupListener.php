<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Event\GuestLogoutEvent;
use OCA\SdkMc\Event\PublishInitialStateEventForGuests;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCA\Talk\TalkSession;
use OCA\SdkMc\Db\ConversationBankIDAuthMapper;
use DateTime;
use Psr\Log\LoggerInterface;
use OCA\Talk\Events\BeforeTurnServersGetEvent;
use OCP\IURLGenerator;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUser;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Manager;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Exceptions\ParticipantNotFoundException;

/**
 * @template-implements IEventListener<Event>
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class SessionCleanupListener implements IEventListener {
    public function __construct(
        private ISession $session,
        private TalkSession $talkSession,
        private LoggerInterface $logger,
        private IURLGenerator $url,
        private IRequest $request,
        private IUserSession $userSession,
        private ConversationBankIDAuthMapper $convBankAuthMapper,
        private Manager $manager,
        private ParticipantService $participantService,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.ExitExpression")
     */
    public function handle(Event $event): void {
        if ($event instanceof PublishInitialStateEventForGuests) {
            $this->initInitialState($event);
        }
        $user = $this->userSession->getUser();
        if ($event instanceof BeforeTurnServersGetEvent) {
            if (!$user instanceof IUser) {
                $currentUri = $this->request->getRequestUri();
                $path = parse_url($currentUri, PHP_URL_PATH);
                if (!is_string($path)) {
                    return;
                }

                $token = null;

                if (
                    sscanf($path, '/call/%30s', $token) === 1
                    && $path === "/call/$token"
                ) {
                    $query = parse_url($currentUri, PHP_URL_QUERY);
                    $params = [];
                    if (is_string($query)) {
                        parse_str($query, $params);
                    }
                    if (isset($params['vf']) && isset($params['userName'])) {
                        return;
                    }

                    if (isset($params['email'])) {
                        // the invite is sent to someone that has a nextcloud account
                        if (is_string($params['email']) && !is_string($params['access'] ?? null)) {
                            $url = $this->request->getRequestUri();
                            // we have to strip out the email parameter from the url
                            // as we can not log in and then redirect if the redirect_url
                            // contains an @ sign. A possible workaround could be to
                            // url encode the url. However, if the user enters the wrong
                            // password a bug causes the url encoding to be lost on
                            // subsequent login events
                            $urls = explode('?', $url);
                            $urls = explode('/', $urls[0]);
                            $token = end($urls);
                            $newUrl = '/call/' . $token;
                            header('Location: ' . $this->url->getAbsoluteURL('/login?' . http_build_query(['redirect_url' => $newUrl])));
                            die();
                        }

                        // Ensure we have string values, reject if they're arrays or other types
                        if (!is_string($params['email']) || !is_string($params['access'] ?? '')) {
                            http_response_code(400);
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode(['ok' => false, 'error' => 'invalid_data'], JSON_UNESCAPED_SLASHES);
                            die();
                        }

                        $email = $params['email'];
                        $access = $params['access'] ?? '';

                        if (!is_string($token)) {
                            http_response_code(400);
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_SLASHES);
                            die();
                        }

                        if ($email === '' || $access === '' || !$this->validateEmailAccess($token, $email, $access)) {
                            http_response_code(400);
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode(['ok' => false, 'error' => 'invalid_data'], JSON_UNESCAPED_SLASHES);
                            die();
                        }

                        $redirectToBankIDAuth = $this->convBankAuthMapper->requiresBankIdAuth($token, $email);

                        // if user shouldnt sign in with BankID we have to keep him in our flow
                        if (!$redirectToBankIDAuth) {
                            $vf = bin2hex(random_bytes(16));
                            $this->session->set('GuestNameVF', $vf);

                            $url = $this->url->linkToRouteAbsolute('sdkmc.guest.name', [
                                'token'     => $token,
                                'email'     => $email,
                                'access'    => $access,
                                'vf'        => $vf,
                            ]);
                            header('Location: ' . $url, true, 302);
                            die();
                        }
                        $this->convBankAuthMapper->updateActorId($token, $email);
                    }

                    $this->clearSessionIfOld($event);
                    if ($this->session->get('BankIdAuthUserName') === null || $this->session->get('BankIdAuthUserName') === '') {
                        $emailParam = isset($params['email']) && is_string($params['email']) ? $params['email'] : '';
                        header('Location: ' . $this->url->linkToRouteAbsolute('sdkmc.talk.authorize', ['meetingId' => $token, 'email' => $emailParam]));
                        die();
                    }
                }
                return;
            }
        }
        if ($event instanceof GuestLogoutEvent) {
            if (!$user instanceof IUser) {
                $this->forceClearSession($event);
            }
        }
    }

    private function validateEmailAccess(string $meetingToken, string $rawEmail, string $accessFromUrl): bool {
        $email = $this->normalizeEmail($rawEmail);
        if ($email === '' || $accessFromUrl === '') {
            return false;
        }

        try {
            $room = $this->manager->getRoomByToken($meetingToken);

            $actorId = hash('sha256', $email);

            $participant = $this->participantService->getParticipantByActor(
                $room,
                Attendee::ACTOR_EMAILS,
                $actorId
            );

            $expected = (string)$participant->getAttendee()->getAccessToken();
            return ($expected !== '') && hash_equals($expected, $accessFromUrl);
        } catch (ParticipantNotFoundException $e) {
            $this->logger->notice('Email access validation failed: participant not found.');
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Email access validation error: ' . $e->getMessage());
            return false;
        }
    }
    private function initInitialState(PublishInitialStateEventForGuests $event): void {
        $state = $event->getInitialState();

        if ($this->session->exists('BankIdAuthUserName')) {
            $userName = $this->session->get('BankIdAuthUserName');
            if (is_string($userName)) {
                $state->provideInitialState('participantDisplayName', $userName);
            }
            return;
        }
    }

    private function isSessionDataOld(): bool {
        if (!$this->session->exists('BankIdAuthUserNameTimestamp')) {
            return true;
        }
        $guestNameTimestampStr = $this->session->get('BankIdAuthUserNameTimestamp');
        if (!is_string($guestNameTimestampStr)) {
            return true;
        }

        $storedDate = DateTime::createFromFormat(DateTime::ATOM, $guestNameTimestampStr);
        if (!$storedDate instanceof DateTime) {
            return true;
        }

        $now = new DateTime();
        $diffSeconds = $now->getTimestamp() - $storedDate->getTimestamp();
        return $diffSeconds > 2 * 60 * 60;
    }

    private function isOidcFlowActive(): bool {
        return $this->session->exists('oidcState');
    }

    private function clearSessionIfOld(BeforeTurnServersGetEvent $event): void {
        if ($this->isSessionDataOld() && !$this->isOidcFlowActive()) {
            $this->doClearSession($event);
        }
    }

    private function forceClearSession(GuestLogoutEvent $event): void {
        $this->doClearSession($event);
    }

    private function doClearSession(Event $event): void {
        if ($this->session->exists('BankIdAuthUserName')) {
            $this->session->remove('BankIdAuthUserName');
        }
        if ($this->session->exists('BankIdAuthUserNameTimestamp')) {
            $this->session->remove('BankIdAuthUserNameTimestamp');
        }

        $token = null;
        if ($event instanceof GuestLogoutEvent) {
            $token = $event->getToken();
        } elseif ($event instanceof BeforeTurnServersGetEvent) {
            // BeforeTurnServersGetEvent might not have getToken method,
            // so we'll get it from the request parameter instead
            $token = $this->request->getParam('token');
        }

        if (is_string($token) && $token !== '') {
            $this->talkSession->removePasswordForRoom($token);
        }

        $this->session->clear();
        $this->session->regenerateId(true);

        $this->logger->debug('External Auth Provider logout complete');
    }

    private function normalizeEmail(string $email): string {
        return strtolower($email);
    }
}
