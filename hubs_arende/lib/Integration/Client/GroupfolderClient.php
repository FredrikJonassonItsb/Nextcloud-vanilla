<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Client;

use OCA\HubsArende\Integration\ServiceAccountAuth;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Thin client onto the **groupfolders** app's OCS API (SAGA R4 — the ärenderum,
 * a per-case Groupfolder with a least-permission ACL).
 *
 * groupfolders is a SEPARATE NC app, consumed over its OCS API:
 *   - POST   /apps/groupfolders/folders                       create (mountpoint)
 *   - POST   /apps/groupfolders/folders/{id}/groups           addGroup
 *   - POST   /apps/groupfolders/folders/{id}/acl              enable ACL
 *   - DELETE /apps/groupfolders/folders/{id}                  delete
 *
 * R4 returns the folderId, stored as a pekare (objekt_typ='groupfolder'); the
 * ACL profile comes from ArendeTyp::getAclProfil() (least permission). The
 * compensation removes the folder (+ ACL + Flow rule).
 *
 * GRACEFUL DEGRADATION: groupfolders absent ⇒ NO-OP + null.
 * TODO[auth]: internal call needs a credential (see {@see ocsRequest()}).
 */
class GroupfolderClient {
    private const APP_ID = 'groupfolders';
    private const API_BASE = '/apps/groupfolders/folders';

    public function __construct(
        private IAppManager $appManager,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ServiceAccountAuth $serviceAuth,
    ) {
    }

    public function isAvailable(): bool {
        return $this->appManager->isEnabledForUser(self::APP_ID);
    }

    /**
     * R4 — create the ärenderum Groupfolder, grant the enhet's mottagningskrets-
     * group(s) access, and enable the least-permission ACL.
     *
     * Least-permission: ONLY the enhet group(s) are granted access to the folder
     * (not "everyone"), and advanced ACL is enabled — so the ärenderum is scoped to
     * the unit's reception circle from birth. The per-assignee narrowing is applied
     * later by {@see ArendeService::tilldela()} (re-apply ACL).
     *
     * @param string   $name        Mountpoint name (typically the pseudonym case ref).
     * @param string   $aclProfil   The ACL profile id from ArendeTyp::getAclProfil().
     * @param string[] $enhetGroups NC group ids of the enhet's mottagningskrets to
     *                              grant access. Empty ⇒ folder created with NO group
     *                              grant (fail-closed; ACL still enabled).
     * @return int|null The folderId, or null (NO-OP / not wired).
     */
    public function createArenderum(string $name, string $aclProfil, array $enhetGroups = []): ?int {
        if (!$this->isAvailable()) {
            $this->noop('createArenderum', $name);
            return null;
        }

        $response = $this->ocsRequest('POST', self::API_BASE, ['mountpoint' => $name], $name);
        $folderId = $this->extractFolderId($response);

        if ($folderId !== null) {
            // Grant each enhet-group access BEFORE enabling ACL so the krets can
            // reach the folder; an empty list leaves the folder group-less (closed).
            foreach ($enhetGroups as $gid) {
                $this->addGroup($folderId, (string)$gid);
            }
            $this->applyAcl($folderId, $aclProfil);
        }

        $this->logger->info('hubs_arende: GroupfolderClient.createArenderum', [
            'app' => 'hubs_arende',
            // OSL 26 kap: the mountpoint name may carry PII — log a digest, not the raw value.
            'nameRef' => $this->safeRef($name),
            'folderId' => $folderId,
            'aclProfil' => $aclProfil,
            'enhetGroups' => count($enhetGroups),
        ]);

        return $folderId;
    }

    /**
     * Grant an NC group access to the ärenderum folder (the "applicable groups" that
     * may mount it). POST /folders/{id}/groups {group:gid}.
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function addGroup(int $folderId, string $groupId): bool {
        if (!$this->isAvailable()) {
            $this->noop('addGroup', (string)$folderId);
            return false;
        }
        if ($groupId === '') {
            return false;
        }

        $this->ocsRequest('POST', self::API_BASE . '/' . $folderId . '/groups', ['group' => $groupId], (string)$folderId);

        $this->logger->info('hubs_arende: GroupfolderClient.addGroup', [
            'app' => 'hubs_arende',
            'folderId' => $folderId,
            'groupId' => $groupId,
        ]);

        return true;
    }

    /**
     * Apply (or re-apply) the least-permission ACL profile to an ärenderum.
     * Reused by tilldela() for the atomic ACL rewrite.
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function applyAcl(int $folderId, string $aclProfil): bool {
        if (!$this->isAvailable()) {
            $this->noop('applyAcl', (string)$folderId);
            return false;
        }

        // TODO[groupfolders]+TODO[auth]: enable ACL (POST /{id}/acl {acl:1}) and
        //   set the per-group/per-user rules derived from $aclProfil.
        $this->ocsRequest('POST', self::API_BASE . '/' . $folderId . '/acl', ['acl' => 1], (string)$folderId);

        $this->logger->info('hubs_arende: GroupfolderClient.applyAcl', [
            'app' => 'hubs_arende',
            'folderId' => $folderId,
            'aclProfil' => $aclProfil,
        ]);

        return true;
    }

    /**
     * R4 compensation — remove the ärenderum folder (+ ACL + Flow rule).
     *
     * @return bool true if attempted, false on NO-OP.
     */
    public function removeFolder(int $folderId): bool {
        if (!$this->isAvailable()) {
            $this->noop('removeFolder', (string)$folderId);
            return false;
        }

        // TODO[groupfolders]+TODO[auth]: DELETE the folder.
        $this->ocsRequest('DELETE', self::API_BASE . '/' . $folderId, null, (string)$folderId);

        $this->logger->info('hubs_arende: GroupfolderClient.removeFolder', [
            'app' => 'hubs_arende',
            'folderId' => $folderId,
        ]);

        return true;
    }

    // ================================================================== //
    //  Internal helpers
    // ================================================================== //

    /** Pull the folderId out of an OCS envelope or flat data. */
    private function extractFolderId(?array $response): ?int {
        if ($response === null) {
            return null;
        }
        $data = $response['ocs']['data'] ?? $response['data'] ?? $response;
        if (is_array($data) && isset($data['id']) && is_numeric($data['id'])) {
            return (int)$data['id'];
        }
        return null;
    }

    /**
     * Internal OCS request against the groupfolders app.
     *
     * TODO[auth]: in-process call carries no session; the groupfolders OCS API is
     * admin-authenticated. Wire a service/admin credential here. Failures are
     * swallowed + logged so the SAGA continues.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|null
     */
    private function ocsRequest(string $method, string $path, ?array $body, string $ref): ?array {
        try {
            $client = $this->clientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL($path);
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'OCS-APIRequest' => 'true',
                ],
                'timeout' => 10,
                'nextcloud' => ['allow_local_address' => true],
            ];
            $auth = $this->serviceAuth->authorizationHeader();
            if ($auth !== null) {
                $options['headers']['Authorization'] = $auth;
            }
            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $client->request($method, $url, $options);
            $raw = $response->getBody();
            $text = is_string($raw) ? $raw : '';
            $decoded = $text !== '' ? json_decode($text, true) : null;

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: GroupfolderClient OCS-anrop misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'method' => $method,
                'path' => $path,
                'ref' => $ref,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function noop(string $method, string $ref): void {
        $this->logger->debug('hubs_arende: GroupfolderClient.' . $method . ' NO-OP (groupfolders ej aktiverad)', [
            'app' => 'hubs_arende',
            'ref' => $ref,
        ]);
    }

    /**
     * Build a non-reversible, PII-safe digest of a free-text value for logging.
     *
     * OSL 2009:400 26 kap / GDPR art. 5.1.c: a mountpoint name may carry PII and
     * must never reach the log verbatim. Returns the byte length plus a short
     * SHA-256 prefix so log lines stay correlatable without exposing the raw
     * value. Empty input yields a stable sentinel.
     */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }
}
