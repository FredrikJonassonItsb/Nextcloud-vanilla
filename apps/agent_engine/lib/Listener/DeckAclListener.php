<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Listener;

use OCA\AgentEngine\Protocol;
use OCA\AgentEngine\Service\EnrollmentService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The self-service enrollment trigger (INTERAKTIONSDESIGN §2.10). A human
 * activates agents on their own Deck board by SHARING it with the "Agent
 * Engine" account (bot-engine); un-sharing deactivates.
 *
 * Bound (by string FQCN, unconditionally — see Application::register, same
 * discipline as DeckCardListener) to Deck's board-ACL events:
 *   - OCA\Deck\Event\AclCreatedEvent  → a share was added
 *   - OCA\Deck\Event\AclDeletedEvent  → a share was removed
 *
 * Both extend Deck's AAclEvent and expose getAcl(): OCA\Deck\Db\Acl, which
 * carries getParticipant() (the uid), getType() (Acl::PERMISSION_TYPE_USER = 0)
 * and getBoardId(). We act ONLY when the participant is bot-engine and the
 * ACL type is a user (not group/circle):
 *   added   → EnrollmentService::autoEnroll(boardId, actorUid)
 *   removed → EnrollmentService::autoUnenroll(boardId)
 *
 * The Deck class shapes are probed defensively (method_exists / accessors) so a
 * Deck version whose Acl entity differs degrades to a no-op rather than fataling
 * the user's share action. Fully swallowing — a throw here would break Deck's
 * ACL write.
 */
class DeckAclListener implements IEventListener {
    /** Deck's Acl::PERMISSION_TYPE_USER — a user participant (vs group/circle). */
    private const PERMISSION_TYPE_USER = 0;

    private const EVENT_ACL_CREATED = 'OCA\\Deck\\Event\\AclCreatedEvent';
    private const EVENT_ACL_DELETED = 'OCA\\Deck\\Event\\AclDeletedEvent';

    public function __construct(
        private EnrollmentService $enrollment,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        try {
            // NB: Deck's Acl is an NC Entity — its accessors (getType/
            // getParticipant/getBoardId) are MAGIC (__call), so method_exists()
            // returns FALSE for them and must NOT be used as a guard. Call them
            // directly inside this try/catch (any shape mismatch is caught).
            if (!is_callable([$event, 'getAcl'])) {
                return;
            }
            $acl = $event->getAcl();
            if (!is_object($acl)) {
                return;
            }

            // Only user-type ACLs matter; a group/circle share is not our trigger.
            if ((int)$acl->getType() !== self::PERMISSION_TYPE_USER) {
                return;
            }

            $participant = $this->participantUid($acl);
            if ($participant !== Protocol::ENGINE_BOT) {
                return; // only sharing with the "Agent Engine" account activates
            }

            $boardId = (int)$acl->getBoardId();
            if ($boardId <= 0) {
                return;
            }

            $eventClass = $event::class;
            if (is_a($event, self::EVENT_ACL_CREATED)) {
                $actor = $this->userSession->getUser()?->getUID() ?? '';
                $this->enrollment->autoEnroll($boardId, $actor);
            } elseif (is_a($event, self::EVENT_ACL_DELETED)) {
                $this->enrollment->autoUnenroll($boardId);
            } else {
                $this->logger->debug('agent_engine: unhandled Deck ACL event', [
                    'app' => Protocol::ENGINE_BOT,
                    'event' => $eventClass,
                ]);
            }
        } catch (\Throwable $e) {
            // Must never break Deck's ACL write; enrollment is also reachable via
            // the admin endpoint, so a miss here is recoverable.
            $this->logger->warning('agent_engine: ACL listener failed', [
                'app' => Protocol::ENGINE_BOT,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The participant uid. Deck's Acl::getParticipant() is a magic Entity getter
     * (returns the uid string for a user ACL); call it directly, tolerating an
     * object shape defensively.
     */
    private function participantUid(object $acl): string {
        $participant = $acl->getParticipant();
        if (is_string($participant)) {
            return $participant;
        }
        if (is_object($participant)) {
            foreach (['getUID', 'getPrimaryKey', 'getId'] as $m) {
                if (is_callable([$participant, $m])) {
                    return (string)$participant->$m();
                }
            }
        }
        return '';
    }
}
