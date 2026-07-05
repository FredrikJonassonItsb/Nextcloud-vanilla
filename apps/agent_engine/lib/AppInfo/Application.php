<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\AppInfo;

use OCA\AgentEngine\Dashboard\MyAgentWidget;
use OCA\AgentEngine\Listener\CommentsEventHandler;
use OCA\AgentEngine\Listener\DeckAclListener;
use OCA\AgentEngine\Listener\DeckCardListener;
use OCA\AgentEngine\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Comments\ICommentsManager;
use Psr\Container\ContainerInterface;

/**
 * Bootstrap for agent_engine (CONTRACTS §3).
 *
 * All services/mappers are constructor-autowired by the NC DI container
 * (hubs_arende convention); the explicit work here is
 *
 *  (a) DEFENSIVE Deck event binding — the latency path. CONTRACTS §3 mandates
 *      in-process listeners (NOT webhook_listeners) with the 2-min sweep as
 *      the correctness floor. Deck has no dedicated public assign-event, so
 *      we subscribe broadly to every ACardEvent subclass that exists on the
 *      deployed Deck version (class_exists-guarded — a missing class simply
 *      means that pathway degrades to the sweep):
 *        - OCA\Deck\Event\CardCreatedEvent
 *        - OCA\Deck\Event\CardUpdatedEvent
 *      Assignment changes are detected by diffing the event card's
 *      assignedUsers against our card_links state (an invariant check, no
 *      before/after needed) — see DeckCardListener's class docblock.
 *
 *  (b) the comments handler for origin→engine mirroring (Deck comments are NC
 *      comments with objectType 'deckCard'; ICommentsManager handlers are the
 *      in-process add-hook).
 *
 *  (c) the bell Notifier.
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'agent_engine';

    /** Candidate Deck card event FQCNs — bound only when loadable. */
    private const DECK_CARD_EVENTS = [
        'OCA\\Deck\\Event\\CardCreatedEvent',
        'OCA\\Deck\\Event\\CardUpdatedEvent',
    ];

    /**
     * Deck board-ACL event FQCNs — the self-service enrollment trigger
     * (§2.10): sharing a board with bot-engine activates its agents, un-sharing
     * deactivates. Bound by string FQCN, same discipline as the card events.
     */
    private const DECK_ACL_EVENTS = [
        'OCA\\Deck\\Event\\AclCreatedEvent',
        'OCA\\Deck\\Event\\AclDeletedEvent',
    ];

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register listeners UNCONDITIONALLY by string FQCN. NC resolves the
        // listener lazily only when the event actually fires — by which point
        // Deck's autoloader is fully registered. We must NOT call class_exists()
        // on a foreign app's class here: during the bootstrap registration phase
        // Deck's PSR-4 namespace is not yet registered, so force-loading its
        // event class poisons Deck's own later autoload of the same class and
        // makes Deck's card creation fatal ("Class ... not found"). If Deck is
        // absent the listener simply never fires (the 2-min sweep covers intake).
        foreach (self::DECK_CARD_EVENTS as $eventClass) {
            $context->registerEventListener($eventClass, DeckCardListener::class);
        }

        // Board-ACL events drive self-service enrollment (§2.10): a user shares
        // their board with the "Agent Engine" account to activate agents on it.
        // Same UNCONDITIONAL string-FQCN binding as the card events above — NC
        // resolves the listener lazily, after Deck's autoloader is registered.
        foreach (self::DECK_ACL_EVENTS as $eventClass) {
            $context->registerEventListener($eventClass, DeckAclListener::class);
        }

        $context->registerNotifierService(Notifier::class);

        // The per-person "Min agent" overview widget (INTERAKTIONSDESIGN §2.9).
        // IAPIWidgetV2 returns WidgetItems as data — NC renders them itself, so
        // no frontend pipeline is needed for this app.
        $context->registerDashboardWidget(MyAgentWidget::class);
    }

    public function boot(IBootContext $context): void {
        // Comments handlers cannot be registered declaratively — the manager
        // takes a factory closure. Resolution is lazy so the handler's own
        // dependency graph is only built when a comment event actually fires.
        try {
            $container = $context->getAppContainer();
            /** @var ICommentsManager $commentsManager */
            $commentsManager = $context->getServerContainer()->get(ICommentsManager::class);
            $commentsManager->registerEventHandler(
                static function () use ($container): CommentsEventHandler {
                    /** @var ContainerInterface $container */
                    return $container->get(CommentsEventHandler::class);
                },
            );
        } catch (\Throwable) {
            // Comments manager unavailable (CLI edge cases) — mirroring
            // degrades to the 2-min sweep, by design.
        }
    }
}
