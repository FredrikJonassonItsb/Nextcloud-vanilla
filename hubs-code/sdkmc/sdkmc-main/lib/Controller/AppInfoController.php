<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\ISession;

class AppInfoController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ISession $session,
        private IConfig $systemConfig,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function getInfo(): JSONResponse {
        return (new JSONResponse([]))->setData([ // @phpstan-ignore argument.type (Error in JSONResponse type definition)
            'version' => $this->appManager->getAppVersion($this->appName),
            'organizationExtension' => $this->appConfig->getAppValueString('organizationExtension'),
            'loa3Tag' => $this->appConfig->getAppValueString('loa3Tag'),
            'loginSecurity' => $this->session->get('LoginStrength'),
            'enforcePersonalSecuremail' => $this->appConfig->getAppValueBool('enforcePersonalSecuremail', false),
            'threadSortNewestFirst' => $this->appConfig->getAppValueBool('threadSortNewestFirst', true),
            'selectNewestInThread' => $this->appConfig->getAppValueBool('selectNewestInThread', false),
        ]);
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     * @InternalAPIAuth
     * @return JSONResponse<Http::STATUS_*, null|string|int|float|bool|array{string: string}|\stdClass|\JsonSerializable, array<string, mixed>>
     */
    public function getSecureMailData(): JSONResponse {
        $useCustomSmtp = $this->appConfig->getAppValueBool('securemailUseCustomSmtp', false);
        $systemSmtpSecure = $this->systemConfig->getSystemValueString('mail_smtpsecure', '');
        if ($systemSmtpSecure === '') {
            $smtpHost = $this->systemConfig->getSystemValueString('mail_smtphost', '');
            $systemSmtpSecure = str_ends_with($smtpHost, '.protection.outlook.com') ? 'tls' : 'none';
        }

        return (new JSONResponse([]))->setData([  // @phpstan-ignore argument.type (Error in JSONResponse type definition)
            'secureMailFromEmail' => $this->appConfig->getAppValueString('secureMailFromEmail'),
            'secureMailMessage' => $this->appConfig->getAppValueString('secureMailMessage'),
            'secureMailSubject' => $this->appConfig->getAppValueString('secureMailSubject'),
            'orgSecureMailMessage' => $this->appConfig->getAppValueString('orgSecureMailMessage'),
            'orgSecureMailSubject' => $this->appConfig->getAppValueString('orgSecureMailSubject'),
            'smtpHost' => $useCustomSmtp
                ? $this->appConfig->getAppValueString('securemailSmtpHost', '')
                : $this->systemConfig->getSystemValueString('mail_smtphost', ''),
            'smtpPort' => $useCustomSmtp
                ? $this->appConfig->getAppValueInt('securemailSmtpPort', 587)
                : $this->systemConfig->getSystemValueInt('mail_smtpport', 587),
            'smtpAuth' => $useCustomSmtp
                ? $this->appConfig->getAppValueString('securemailSmtpUsername', '') !== ''
                : $this->systemConfig->getSystemValueBool('mail_smtpauth', false),
            'smtpUsername' => $useCustomSmtp
                ? $this->appConfig->getAppValueString('securemailSmtpUsername', '')
                : $this->systemConfig->getSystemValueString('mail_smtpname', ''),
            'smtpPassword' => $useCustomSmtp
                ? $this->appConfig->getAppValueString('securemailSmtpPassword', '')
                : $this->systemConfig->getSystemValueString('mail_smtppassword', ''),
            'smtpSecure' => $useCustomSmtp
                ? $this->appConfig->getAppValueString('securemailSmtpSecure', 'tls')
                : $systemSmtpSecure,
        ]);
    }
}
