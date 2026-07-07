<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\AppInfo;

use OCA\HubsArende\Integration\Client\FolkbokforingClient;
use OCA\HubsArende\Integration\Port\EdiariumPort;
use OCA\HubsArende\Integration\Port\FacksystemCommitPort;
use OCA\HubsArende\Integration\Port\FolkbokforingPort;
use OCA\HubsArende\Integration\Port\SigneringPort;
use OCA\HubsArende\Integration\Stub\EdiariumStub;
use OCA\HubsArende\Integration\Stub\FacksystemCommitStub;
use OCA\HubsArende\Integration\Stub\FolkbokforingStub;
use OCA\HubsArende\Integration\Stub\SigneringStub;
use OCA\HubsArende\Notification\Notifier;
use OCA\HubsArende\Teams\ArenderumTeamResourceProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Bootstrap for the standalone hubs_arende ärende-motor.
 *
 * Wires the engine's services and binds each integration Port to a concrete
 * implementation chosen by INTEGRATION_MODE (per port, via IAppConfig). The
 * default mode is 'stub' so the engine runs end-to-end with deterministic
 * synthetic data and verified async callback simulation, with no live
 * facksystem connection.
 *
 * Most engine services (ArendeService, SakerhetsskyddGrind, ArendeTypRegistry,
 * FacksystemCommitService) and the QBMapper subclasses are constructor-autowired
 * by the NC DI container, so they need no explicit registration. The explicit
 * work here is the Port -> implementation binding, which is the seam where the
 * app is later swapped to live integrations (or repackaged as an ExApp).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'hubs_arende';

    /** AppConfig key prefix for per-port integration mode. */
    public const INTEGRATION_MODE_PREFIX = 'integration_mode_';

    /** Default integration mode — deterministic in-process stub. */
    public const MODE_STUB = 'stub';

    /** Live integration mode — skarp klient mot extern integration (Frends). */
    public const MODE_LIVE = 'live';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // --- Integration ports: port -> implementation, mode-selected -----
        //
        // Each port resolves to a concrete implementation based on its
        // INTEGRATION_MODE app-config value (default 'stub'). The stubs live in
        // OCA\HubsArende\Integration\Stub\* and produce deterministic synthetic
        // receipts with simulated verified async callbacks. When a live mode is
        // configured the same factory returns the corresponding live adapter.
        //
        // The factories are the single swap point: nothing else in the engine
        // references a concrete integration class.

        $context->registerService(FacksystemCommitPort::class, function (ContainerInterface $c): FacksystemCommitPort {
            return self::resolvePort(
                $c,
                'facksystem',
                [self::MODE_STUB => FacksystemCommitStub::class],
                FacksystemCommitStub::class,
            );
        });

        $context->registerService(SigneringPort::class, function (ContainerInterface $c): SigneringPort {
            return self::resolvePort(
                $c,
                'signering',
                [self::MODE_STUB => SigneringStub::class],
                SigneringStub::class,
            );
        });

        $context->registerService(EdiariumPort::class, function (ContainerInterface $c): EdiariumPort {
            return self::resolvePort(
                $c,
                'ediarium',
                [self::MODE_STUB => EdiariumStub::class],
                EdiariumStub::class,
            );
        });

        // Folkbokföring (Navet via kommunens interna Frends-API, K-NAV-3.1).
        // Default = FolkbokforingStub (deterministiska testpersoner inkl. skyddade,
        // speglar testbeställning 00000236-FO01-0002). 'live' växlar till den
        // skarpa klienten mot Frends-uppslags-API:t:
        //   occ config:app:set hubs_arende integration_mode_folkbokforing --value live
        //   occ config:app:set hubs_arende folkbokforing_api_url --value https://…
        //   occ config:app:set hubs_arende folkbokforing_api_nyckel --value …
        $context->registerService(FolkbokforingPort::class, function (ContainerInterface $c): FolkbokforingPort {
            return self::resolvePort(
                $c,
                'folkbokforing',
                [
                    self::MODE_STUB => FolkbokforingStub::class,
                    self::MODE_LIVE => FolkbokforingClient::class,
                ],
                FolkbokforingStub::class,
            );
        });

        // --- Team-presentation: akten synlig på ärendets TEAM-sida --------
        // Talk listar redan diskussionsrummet (teamet är deltagare); denna
        // provider lägger till AKTEN (groupfoldern) via motorns pekare, så
        // team-vyn knyter ihop hela ärenderummet. Core gatar per användare
        // (TeamManager kräver team-synlighet innan providern frågas).
        $context->registerTeamResourceProvider(ArenderumTeamResourceProvider::class);

        // --- NC-notiser (klockan): tilldelning/medlemskap/frist-varsel ----
        $context->registerNotifierService(Notifier::class);
    }

    public function boot(IBootContext $context): void {
        // No boot-time work — registration is declarative and the engine is
        // driven entirely through its OCS routes.
    }

    /**
     * Resolve a port to a concrete implementation according to its configured
     * INTEGRATION_MODE. Falls back to the stub default for any unknown mode so
     * the engine never resolves to a missing/live binding by accident.
     *
     * @template T of object
     * @param ContainerInterface $c
     * @param string $portKey short port name, e.g. 'facksystem'
     * @param array<string, class-string<T>> $modeMap mode -> implementation class
     * @param class-string<T> $default fallback implementation (the stub)
     * @return T
     */
    private static function resolvePort(
        ContainerInterface $c,
        string $portKey,
        array $modeMap,
        string $default,
    ): object {
        /** @var IAppConfig $appConfig */
        $appConfig = $c->get(IAppConfig::class);
        $mode = $appConfig->getAppValueString(
            self::INTEGRATION_MODE_PREFIX . $portKey,
            self::MODE_STUB,
        );

        $impl = $modeMap[$mode] ?? $default;

        /** @var T $resolved */
        $resolved = $c->get($impl);
        return $resolved;
    }
}
