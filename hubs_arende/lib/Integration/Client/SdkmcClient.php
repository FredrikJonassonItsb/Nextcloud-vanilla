<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Client;

use OCA\HubsArende\Integration\ServiceAccountAuth;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Thin client onto the **sdkmc** app's ITSL Tag API — the carrier of the
 * case:{hubsCaseId} token (SAGA R3) and the per-message case tagging (SAGA R9).
 *
 * sdkmc is a SEPARATE NC app. We never reach into its services; we call its OCS
 * routes (sdkmc/appinfo/routes.php → itsl_tag#*):
 *   - POST   /api/tags/{accountId}                       createTag(displayName,color)
 *   - DELETE /api/tags/{accountId}/delete/{id}           deleteTag
 *   - DELETE /api/tags/by-label/{imapLabel}              deleteCaseTagByLabel  [HUBS-ARENDE-KRAV 2026-07-12]
 *   - PUT    /api/messages/{id}/tags/{imapLabel}         setMessageTag
 *   - DELETE /api/messages/{id}/tags/{imapLabel}         removeMessageTag
 *   - PUT    /api/thread/tags/{imapLabel}                setThreadTag (bulk {ids:[]})
 *   - DELETE /api/thread/tags/{imapLabel}                removeThreadTag
 *
 * GRACEFUL DEGRADATION: if sdkmc is not enabled, every method is a NO-OP that
 * returns null (R3/R9 then simply record nothing — the SAGA continues; this is
 * the AppDetectionService pattern: missing app ⇒ skip, never crash).
 *
 * ExApp-future: the {@see isAvailable()} + small call surface is the seam. When
 * sdkmc (or this app) becomes an ExApp the body of {@see ocsRequest()} becomes a
 * remote HTTP call; nothing in the SAGA changes.
 *
 * AUTH NOTE (Seam A — KLAR): the itsl_tag routes are session authenticated. The
 * server-to-server credential is supplied by {@see ServiceAccountAuth}; when a
 * service account is provisioned the calls carry a Basic header and reach sdkmc.
 *
 * ACCOUNTID SEAM (Seam B — message tagging KLAR, case-tag PENDING sdkmc support):
 *   The bulk message-tagging routes (/api/thread/tags/{imapLabel}) take NO
 *   accountId — sdkmc derives the account from the first message's mailbox. So
 *   {@see tagMessage()}/{@see untagMessage()} are fully wired and correct.
 *
 *   The case-tag routes (/api/tags/{accountId}) DO take an accountId, and sdkmc
 *   resolves it via AccountService::find($serviceAccountUser, $accountId) — i.e.
 *   it is a **Mail-app numeric account id owned by the calling (service) user**,
 *   NOT an sdkmc id and NOT derivable from the funktionsadress in a server-to-
 *   server context. The underlying ItslTag is in fact email-scoped (see
 *   ItslTagMapper); accountId is used by sdkmc ONLY to look up that email. There
 *   is no honest email→accountId path available from here.
 *
 *   Determination: this is a SEAM that needs sdkmc-side support (an email-scoped
 *   case-tag route, or an explicit accountId mapping). Until then case-tag
 *   creation resolves an OPTIONAL operator-provided account id from app-config
 *   ({@see CONFIG_KEY_TAG_ACCOUNT}). If that key is unset we DO NOT POST to
 *   account 0 (which always 404s "Account not found" and spams the log) — we
 *   honestly NO-OP + return null, exactly like the sdkmc-absent path. The pointer
 *   row simply records nothing for R3; the SAGA continues unchanged.
 */
class SdkmcClient {
    private const APP_ID = 'sdkmc';

    /**
     * App-config key (hubs_arende.sdkmc_tag_account) holding the Mail-app numeric
     * account id the service account uses to scope case:-tags. OPTIONAL: when
     * empty, case-tag creation NO-OPs gracefully (see class docblock — accountId
     * cannot be resolved from the funktionsadress in a server-to-server context).
     */
    public const CONFIG_KEY_TAG_ACCOUNT = 'sdkmc_tag_account';

    public function __construct(
        private IAppManager $appManager,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ServiceAccountAuth $serviceAuth,
        private IAppConfig $appConfig,
    ) {
    }

    /**
     * Is the sdkmc app installed and enabled? When false, every method below is a
     * NO-OP returning null and the SAGA step skips gracefully.
     */
    public function isAvailable(): bool {
        return $this->appManager->isEnabledForUser(self::APP_ID);
    }

    /**
     * R3 — create the case:{hubsCaseId} systemtag/imap_label on the inflow
     * account. Returns the created tag's imapLabel (the token used to tag
     * messages in R9), or null when sdkmc is absent or the call could not be
     * completed.
     *
     * @param string $hubsCaseId Canonical case UUID — becomes the case: token.
     * @param string $emailAddress The inflow funktionsadress (resolves accountId).
     * @return string|null The case: imapLabel, or null (NO-OP / not wired).
     */
    public function createCaseTag(string $hubsCaseId, string $emailAddress): ?string {
        if (!$this->isAvailable()) {
            return $this->noop('createCaseTag', $hubsCaseId);
        }

        $imapLabel = $this->caseLabel($hubsCaseId);

        // sdkmc's /api/tags/{accountId} resolves accountId via AccountService::find
        // against the SERVICE account user — it is a Mail-app numeric account id,
        // not derivable from $emailAddress here (see class docblock: ACCOUNTID SEAM).
        // Honest graceful: if no operator-provided account id is configured we do
        // NOT POST to account 0 (always 404 + log spam). We NO-OP + return null.
        $accountId = $this->resolveTagAccountId();
        if ($accountId === null) {
            $this->logger->info(
                'hubs_arende: SdkmcClient.createCaseTag SEAM (case-tag konto ej konfigurerat, no-op)',
                [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $hubsCaseId,
                    'imapLabel' => $imapLabel,
                    // OSL 26 kap / GDPR: a funktionsadress is PII — log a digest only.
                    'emailRef' => $this->safeRef($emailAddress),
                    'configKey' => 'hubs_arende.' . self::CONFIG_KEY_TAG_ACCOUNT,
                ]
            );
            return null;
        }

        // POST sdkmc.itsl_tag.createTag — sdkmc generates the IMAP label from
        // displayName and stores the tag email-scoped against $accountId's mailbox.
        $this->ocsRequest('POST', "/api/tags/{$accountId}", [
            'displayName' => $imapLabel,
            'color' => '#888888',
        ], $hubsCaseId);

        $this->logger->info('hubs_arende: SdkmcClient.createCaseTag', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'imapLabel' => $imapLabel,
            'accountId' => $accountId,
            // OSL 26 kap / GDPR: a funktionsadress is PII — log a digest only.
            'emailRef' => $this->safeRef($emailAddress),
        ]);

        return $imapLabel;
    }

    /**
     * R3 compensation — delete the case:{hubsCaseId} tag.
     *
     * @param string $hubsCaseId Canonical case UUID.
     * @param string $emailAddress The inflow funktionsadress (resolves accountId).
     * @param string $tagId The sdkmc tag id to delete (from the R3 pekare).
     * @return bool true if a delete was attempted, false on NO-OP.
     */
    public function deleteCaseTag(string $hubsCaseId, string $emailAddress, string $tagId): bool {
        if (!$this->isAvailable()) {
            $this->noop('deleteCaseTag', $hubsCaseId);
            return false;
        }

        // Mirror createCaseTag: accountId cannot be derived from $emailAddress in
        // the server-to-server context (see class docblock: ACCOUNTID SEAM). If no
        // operator-provided account id is configured, no R3 case-tag was ever
        // created (createCaseTag NO-OPed), so there is nothing to compensate.
        $accountId = $this->resolveTagAccountId();
        if ($accountId === null) {
            $this->logger->info(
                'hubs_arende: SdkmcClient.deleteCaseTag SEAM (case-tag konto ej konfigurerat, no-op)',
                [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $hubsCaseId,
                    'tagId' => $tagId,
                ]
            );
            return false;
        }

        // DELETE sdkmc.itsl_tag.deleteTag (/api/tags/{accountId}/delete/{id}).
        $this->ocsRequest('DELETE', "/api/tags/{$accountId}/delete/{$tagId}", null, $hubsCaseId);

        $this->logger->info('hubs_arende: SdkmcClient.deleteCaseTag', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'accountId' => $accountId,
            'tagId' => $tagId,
        ]);

        return true;
    }

    /**
     * DESTRUKTIONSSPEGELNS LABEL-VÄG (2026-07-12) — riv case:{hubsCaseId}-taggen
     * DETERMINISTISKT via dess imap_label, utan accountId och utan R3-pekare.
     *
     * Löser den öppna begränsningen i {@see deleteCaseTag()}: på miljöer där
     * case-taggen skapades via `koppla`/`tagMessage` (label-vägen) finns INGEN
     * case_tag-pekare, så den pekar-baserade kompensationen städar ingenting och
     * taggen blir en DINGLANDE referens mot ett gallrat ärende (döljer anmälan i
     * inflödet). Denna metod anropar sdkmc:s nya, kravmärkta rutt
     * (DELETE /api/tags/by-label/{imapLabel}, se KRAV-SDKMC-2026-07-12.md) som är
     * fail-closed till `case:`-namnrymden.
     *
     * @param string $hubsCaseId Canonical case UUID — dess case:-label rivs överallt.
     * @return bool true om ett borttag försöktes (sdkmc nåbar), false på NO-OP.
     */
    public function deleteCaseTagByLabel(string $hubsCaseId): bool {
        if (!$this->isAvailable()) {
            $this->noop('deleteCaseTagByLabel', $hubsCaseId);
            return false;
        }

        $imapLabel = $this->caseLabel($hubsCaseId);
        // Ingen accountId behövs: labeln case:<uuid> är globalt unik och sdkmc-rutten
        // hittar taggen över alla brevlådor (fail-closed till case:-namnrymden).
        $this->ocsRequest('DELETE', '/api/tags/by-label/' . rawurlencode($imapLabel), null, $hubsCaseId);

        $this->logger->info('hubs_arende: SdkmcClient.deleteCaseTagByLabel', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'imapLabel' => $imapLabel,
        ]);

        return true;
    }

    /**
     * R9 — tag the triggering message(s) with case:{hubsCaseId}.
     *
     * @param string   $hubsCaseId Canonical case UUID.
     * @param int[]    $messageIds sdkmc/mail message DB ids to tag.
     * @return bool true if the tag actually LANDED (OCS success), false on
     *         NO-OP / failure. NOTE: "landed", not merely "attempted" — callers
     *         (e.g. the user-driven koppla) rely on this to avoid recording a
     *         coupling pointer for a tag that silently 401:ed / failed.
     */
    public function tagMessage(string $hubsCaseId, array $messageIds): bool {
        if (!$this->isAvailable()) {
            $this->noop('tagMessage', $hubsCaseId);
            return false;
        }
        if ($messageIds === []) {
            return false;
        }

        $imapLabel = $this->caseLabel($hubsCaseId);
        // Bulk path: PUT /api/thread/tags/{imapLabel} with {ids:[...]}.
        // TODO[auth]: wire the internal-call credential (see ocsRequest()).
        $response = $this->ocsRequest('PUT', '/api/thread/tags/' . rawurlencode($imapLabel), [
            'ids' => array_values($messageIds),
        ], $hubsCaseId);
        $landed = $this->ocsSuccessful($response);

        $this->logger->info('hubs_arende: SdkmcClient.tagMessage', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'imapLabel' => $imapLabel,
            'count' => count($messageIds),
            'landed' => $landed,
        ]);

        return $landed;
    }

    /**
     * R9 compensation — remove the case:{hubsCaseId} tag from the message(s).
     *
     * @param string $hubsCaseId Canonical case UUID.
     * @param int[]  $messageIds sdkmc/mail message DB ids to untag.
     * @return bool true if an untag was attempted, false on NO-OP.
     */
    public function untagMessage(string $hubsCaseId, array $messageIds): bool {
        if (!$this->isAvailable()) {
            $this->noop('untagMessage', $hubsCaseId);
            return false;
        }
        if ($messageIds === []) {
            return false;
        }

        $imapLabel = $this->caseLabel($hubsCaseId);
        // TODO[auth]: DELETE /api/thread/tags/{imapLabel} with {ids:[...]}.
        $this->ocsRequest('DELETE', '/api/thread/tags/' . rawurlencode($imapLabel), [
            'ids' => array_values($messageIds),
        ], $hubsCaseId);

        $this->logger->info('hubs_arende: SdkmcClient.untagMessage', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'imapLabel' => $imapLabel,
            'count' => count($messageIds),
        ]);

        return true;
    }

    // ================================================================== //
    //  Internal helpers
    // ================================================================== //

    /** The case: token carried as the imap_label / systemtag displayName. */
    private function caseLabel(string $hubsCaseId): string {
        return 'case:' . $hubsCaseId;
    }

    /**
     * Whether an OCS response indicates the call actually SUCCEEDED (2xx). A null
     * response (swallowed error / 401) or a non-2xx OCS meta statuscode ⇒ false, so
     * a coupling is only recorded for a tag that genuinely landed.
     *
     * @param array<string,mixed>|null $response
     */
    private function ocsSuccessful(?array $response): bool {
        if ($response === null) {
            return false;
        }
        $code = $response['ocs']['meta']['statuscode'] ?? null;
        if (is_numeric($code)) {
            $code = (int)$code;
            return $code >= 200 && $code < 300;
        }
        return false;
    }

    /**
     * Resolve the Mail-app account id to scope case:-tags against, or null.
     *
     * sdkmc's /api/tags/{accountId} resolves the id via
     * AccountService::find($serviceAccountUser, $accountId) — it must be a numeric
     * Mail account OWNED BY THE SERVICE ACCOUNT, which cannot be derived from the
     * funktionsadress in a server-to-server context (see class docblock). We
     * therefore read an OPTIONAL operator-provided id from app-config:
     *   hubs_arende.sdkmc_tag_account = <mail account id, e.g. '12'>
     *
     * Returns null when the key is absent or not a positive integer. A null result
     * means "case-tag account not configured" and callers NO-OP gracefully rather
     * than POSTing to account 0 (which 404s + spams the log).
     *
     * SEAM[sdkmc]: the durable fix is sdkmc exposing an email-scoped case-tag route
     * (the underlying ItslTag is already email-scoped — accountId is only used by
     * sdkmc to look up that email), removing the need for this config indirection.
     */
    private function resolveTagAccountId(): ?int {
        $raw = trim($this->appConfig->getAppValueString(self::CONFIG_KEY_TAG_ACCOUNT, ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $accountId = (int)$raw;
        return $accountId > 0 ? $accountId : null;
    }

    /**
     * Build a non-reversible, PII-safe digest of a free-text value for logging.
     *
     * OSL 2009:400 26 kap / GDPR art. 5.1.c: a funktionsadress may identify a
     * person and must never reach the log verbatim. Returns the byte length plus a
     * short SHA-256 prefix so log lines stay correlatable without exposing the raw
     * value. Empty input yields a stable sentinel.
     */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }

    /**
     * Perform an internal OCS request against the sdkmc app.
     *
     * Builds an absolute URL from the route prefix (sdkmc OCS routes are mounted
     * under the app) via IURLGenerator, then issues it with IClientService.
     *
     * TODO[auth]: an in-process server-to-server call carries no user session, so
     * the itsl_tag routes (user-authenticated) will 401. Wire one of:
     *   - a dedicated service-account app-password (Authorization: Basic …), or
     *   - a signed internal-request header trusted by sdkmc, or
     *   - promote the needed routes to ExApp/OCS-with-app-auth.
     * Until then the request is attempted but failures are swallowed + logged so
     * the SAGA behaves identically whether or not the credential is present.
     *
     * @param string                    $method  HTTP verb.
     * @param string                    $path    Route path under the sdkmc app, e.g. '/api/tags/0'.
     * @param array<string,mixed>|null  $body    JSON body, or null.
     * @param string                    $hubsCaseId For correlation in logs.
     * @return array<string,mixed>|null Decoded JSON response, or null on NO-OP/failure.
     */
    private function ocsRequest(string $method, string $path, ?array $body, string $hubsCaseId): ?array {
        $url = $this->absoluteSdkmcUrl($path);

        try {
            $client = $this->clientService->newClient();
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
            // Swallow — the SAGA must not break because sdkmc is unreachable or
            // the internal-call credential is not yet wired (TODO[auth]).
            $this->logger->warning('hubs_arende: SdkmcClient OCS-anrop misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'method' => $method,
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build the absolute URL for a path under the sdkmc app, stripping the
     * /index.php/ prefix the same way sdkmc's own internal callers do.
     */
    private function absoluteSdkmcUrl(string $path): string {
        $appWeb = $this->urlGenerator->linkToRoute(self::APP_ID . '.app_info.getInfo');
        // Reduce to the app's web root, then append the OCS-style path.
        $base = preg_replace('#/api/v2/frontend/getSettings$#', '', $appWeb) ?? $appWeb;
        if (str_starts_with($base, '/index.php/')) {
            $base = substr($base, 10);
        }
        return $this->urlGenerator->getAbsoluteURL(rtrim($base, '/') . $path);
    }

    /** Log + return null for a NO-OP (sdkmc absent). */
    private function noop(string $method, string $hubsCaseId): ?string {
        $this->logger->debug('hubs_arende: SdkmcClient.' . $method . ' NO-OP (sdkmc ej aktiverad)', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
        ]);
        return null;
    }
}
