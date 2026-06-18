<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http;
use OCP\ISession;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use DateTime;
use OCA\SdkMc\Utils\NameCleaner;

class GuestController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ISession $session,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @return TemplateResponse<200, array{}>
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function name(string $token): TemplateResponse {
        $vf     = $this->getStringParam('vf');
        $email  = $this->getStringParam('email');
        $access = $this->getStringParam('access');

        return new TemplateResponse(
            $this->appName,
            'GuestName',
            [
                'token'  => $token,
                'vf'     => $vf,
                'email'  => $email,
                'access' => $access,
            ],
            'blank'
        );
    }

    /**
     * @return RedirectResponse<303, array{}>|JSONResponse<400, array<string, mixed>, array{} >
     */
    #[PublicPage]
    #[UseSession]
    public function update(string $name): RedirectResponse|JSONResponse {
        $vf     = $this->getStringParam('vf');
        $token  = $this->getStringParam('token');

        if ($vf === '' || $name === '' || $token === '' || !$this->session->exists('GuestNameVF')) {
            return new JSONResponse(
                ['error' => ''],
                Http::STATUS_BAD_REQUEST
            );
        }

        $sessionVf = $this->getSessionStringValue('GuestNameVF');
        if ($sessionVf === '' || !hash_equals($sessionVf, $vf)) {
            return new JSONResponse(
                ['error' => 'Verification failed'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // remove emojis and clean up the name
        $name = NameCleaner::cleanName($name);

        $today = new DateTime();
        $this->session->set('BankIdAuthUserName', $name);
        $this->session->set('BankIdAuthUserNameTimestamp', $today->format(DateTime::ATOM));
        return new RedirectResponse('/call/' . $token);
    }

    private function getStringParam(string $paramName): string {
        $value = $this->request->getParam($paramName);
        return is_string($value) ? $value : '';
    }

    private function getSessionStringValue(string $key): string {
        $value = $this->session->get($key);
        return is_string($value) ? $value : '';
    }
}
