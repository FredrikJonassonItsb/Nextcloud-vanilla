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
use OCP\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Exception;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;

/**
 * Token proxy controller for OAuth token exchange.
 *
 * This controller handles the token exchange for BankID/GrandID OAuth flows.
 * It rewrites the redirect_uri to match GrandID's registered callback URL
 * (login-XXXXX.hubs.se) when the actual request comes from a different hostname.
 *
 * IMPORTANT: This controller must NOT have any dependencies on Talk (spreed)
 * or FederatedFileSharing apps, as it needs to work regardless of which
 * Nextcloud apps are enabled. See issue #231 for details.
 */
class TokenProxyController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IClientService $clientService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get OAuth provider configuration from sociallogin app.
     *
     * @return array{name: string, title: string, authorizeUrl: string, tokenUrl: string, displayNameClaim: string, userInfoUrl: string, logoutUrl: string, clientId: string, clientSecret: string, scope: string, groupsClaim: string, style: string, defaultGroup: string}
     */
    private function getProvider(string $loginProvider = 'sociallogin', string $loginType = 'custom_oidc', ?string $loginMethod = null) {
        if ($loginProvider !== 'sociallogin') {
            throw new Exception('Currently only supports sociallogin');
        }
        if ($loginType !== 'custom_oidc') {
            throw new Exception('Currently only supports sociallogin');
        }

        $providers = $this->appConfig->getValueArray('sociallogin', 'custom_providers');
        if (!array_key_exists('custom_oidc', $providers) || !is_array($providers['custom_oidc'])) {
            throw new Exception('No custom oidc provider set up in sociallogin');
        }

        foreach ($providers['custom_oidc'] as $provider) {
            if (is_null($loginMethod)) {
                if (is_array($provider) && array_key_exists('scope', $provider) && is_string($provider['scope']) && str_contains($provider['scope'], 'bankid')) {
                    return $provider; // @phpstan-ignore return.type
                }
            }
            if (is_array($provider) && array_key_exists('name', $provider) && is_string($provider['name']) && $provider['name'] === $loginMethod) {
                return $provider; // @phpstan-ignore return.type
            }
        }
        throw new Exception('BankID provider not found in sociallogin config');
    }

    /**
     * Legacy token endpoint (without provider parameters).
     *
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function tokenLegacy(string $code): JSONResponse {
        return $this->token($code, 'sociallogin', 'custom_oidc', null);
    }

    /**
     * Token exchange proxy for OAuth flows.
     *
     * Proxies the OAuth token exchange to GrandID, rewriting the redirect_uri
     * to match the URL registered with GrandID (extracted from authorizeUrl).
     *
     * Why this proxy exists:
     * - GrandID has registered https://login-XXXXX.hubs.se/auth-interceptor as callback
     * - But actual OAuth flow happens on https://customer.hubs.se
     * - This proxy extracts base URL from authorizeUrl and uses that for token exchange
     * - Without this, GrandID rejects with "redirect_uri mismatch"
     *
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function token(string $code, string $loginProvider = 'sociallogin', string $loginType = 'custom_oidc', ?string $loginMethod = null): JSONResponse {
        $reversedParts = explode('/', strrev($this->getProvider($loginProvider, $loginType, $loginMethod)['authorizeUrl']), 2);
        if (!array_key_exists(1, $reversedParts)) {
            throw new Exception('Can not understand the authorizeUrl');
        }
        $redirectUri = strrev($reversedParts[1]);

        $tokenUrlParams = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $this->getProvider($loginProvider, $loginType, $loginMethod)['clientId'],
            'client_secret' => $this->getProvider($loginProvider, $loginType, $loginMethod)['clientSecret'],
        ];
        $client = $this->clientService->newClient();

        $reversedParts = explode('/', strrev($this->getProvider($loginProvider, $loginType, $loginMethod)['userInfoUrl']), 2);
        if (!array_key_exists(1, $reversedParts)) {
            throw new Exception('Can not understand the userInfoUrl');
        }
        $tokenUri = strrev($reversedParts[1]) . '/access_token';

        $response = $client->post(
            $tokenUri,
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

        return (new JSONResponse([]))->setData($responseData);  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
    }
}
