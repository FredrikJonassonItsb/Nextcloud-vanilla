<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * HMAC wake push to the runner (CONTRACTS §3, verbatim):
 *
 *   POST {runner_base}/wake/{agentCode}
 *   X-AE-Timestamp: <unix seconds>
 *   X-AE-Signature: hex(hmac_sha256(ENGINE_PUSH_SECRET, timestamp + "." + agentCode))
 *
 * No payload — the runner pulls its own work via GET /queue/{agentCode}.
 * Push is LATENCY ONLY: failure is logged and swallowed; the runner's 30-min
 * cron and the 2-min sweep are the correctness floor.
 */
class PushService {
    public function __construct(
        private EngineConfig $config,
        private IClientService $clientService,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /** @return bool true when the runner answered 2xx (used by /push-test). */
    public function wake(string $agentCode): bool {
        $secret = $this->config->pushSecret();
        if ($secret === '') {
            $this->logger->debug('agent_engine: push skipped — push_secret not configured', [
                'app' => 'agent_engine',
                'agentCode' => $agentCode,
            ]);
            return false;
        }

        $timestamp = (string)$this->timeFactory->getTime();
        $signature = hash_hmac('sha256', $timestamp . '.' . $agentCode, $secret);
        $url = $this->config->runnerBase() . '/wake/' . rawurlencode($agentCode);

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($url, [
                'headers' => [
                    'X-AE-Timestamp' => $timestamp,
                    'X-AE-Signature' => $signature,
                ],
                'timeout' => 5,
                'nextcloud' => ['allow_local_address' => true],
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if (!$ok) {
                $this->logger->info('agent_engine: runner wake non-2xx', [
                    'app' => 'agent_engine',
                    'agentCode' => $agentCode,
                    'status' => $status,
                ]);
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->logger->info('agent_engine: runner wake failed (latency only — cron/sweep will catch up)', [
                'app' => 'agent_engine',
                'agentCode' => $agentCode,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
