<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use OCP\AppFramework\Services\IAppConfig;

class TagFileController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IRootFolder $root,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private IAppConfig $appConfig,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Tag a file belonging to the logged-in user.
     *
     * @NoAdminRequired
     * @return JSONResponse<
     *   401, array{message: string}, array{}
     * >|JSONResponse<
     *   404, array{message: string, path: string}, array{}
     * >|JSONResponse<
     *   400, array{message: string}, array{}
     * >|JSONResponse<
     *   200, array{ok: false, reason: 'feature_disabled'|'config_missing'}, array{}
     * >|JSONResponse<
     *   200, array{ok: true, fileId: int, tagId: string, tagName: string}, array{}
     * >
     */
    public function assign(string $fullPath): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        $tagEnabled = $this->appConfig->getAppValueBool('loa3Enabled');
        if (!$tagEnabled) {
            return new JSONResponse(['ok' => false, 'reason' => 'feature_disabled'], Http::STATUS_OK);
        }

        $tagName = $this->appConfig->getAppValueString('loa3Tag');
        if ($tagName === '') {
            return new JSONResponse(['ok' => false, 'reason' => 'config_missing'], Http::STATUS_OK);
        }

        try {
            $tag = $this->tagManager->getTag($tagName, true, true);
        } catch (TagNotFoundException) {
            return new JSONResponse(['message' => 'Tag name not created'], Http::STATUS_BAD_REQUEST);
        }
        $tagId = $tag->getId();

        $fullPath = '/' . ltrim($fullPath, '/');

        try {
            $userFolder = $this->root->getUserFolder($uid);
            $node = $userFolder->get($fullPath);
            $fileId = $node->getId();
        } catch (\Throwable $e) {
            return new JSONResponse([
                'message' => 'File not found',
                'path'    => $fullPath,
            ], Http::STATUS_NOT_FOUND);
        }

        $this->tagMapper->assignTags((string)$fileId, 'files', [(int)$tagId]);

        return new JSONResponse([
            'ok'      => true,
            'fileId'  => $fileId,
            'tagId'   => $tagId,
            'tagName' => $tagName,
        ], Http::STATUS_OK);
    }
}
