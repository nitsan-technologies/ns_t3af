<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, either version 2 of the
 * License, or (at your option) any later version.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Tool\File;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Service\FileUploadService;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use NITSAN\NsT3AF\Mcp\Service\McpPublicUrlService;
use Psr\Http\Message\ServerRequestInterface;

readonly class FileUploadPrepareTool implements McpFalStorageToolInterface
{
    public function __construct(
        private FileUploadService $fileUploadService,
        private McpPathProvider $pathProvider,
        private McpPublicUrlService $publicUrlService,
    ) {}

    #[McpTool(
        name: 'file_upload_prepare',
        description: 'Prepare an out-of-band binary upload: returns a single-use upload URL and bearer token. '
            . 'The MCP client (or user) then HTTP-PUTs the raw file bytes to uploadUrl. Prefer this over '
            . 'file_upload base64 for local/binary files so bytes never travel through the model context.',
    )]
    public function execute(
        string $fileName = '',
        string $directoryPath = '/',
        int $storageUid = 1,
    ): string {
        if ($fileName !== '') {
            $this->fileUploadService->assertFileNameIsAllowed($fileName);
        }

        $folder = $this->fileUploadService->resolveFolder($storageUid, $directoryPath);
        $tokenData = $this->fileUploadService->createUploadToken($folder, $fileName);

        $uploadUrl = $this->buildAbsoluteUploadUrl();
        if ($uploadUrl === null) {
            throw new ToolCallException(
                'Cannot build an absolute upload URL (no HTTP request context and no site with a fully qualified base URL). '
                . 'Configure a site base URL including scheme and domain, or upload via file_upload content / file_upload_from_url.',
            );
        }

        $curlFileName = $fileName !== '' ? $fileName : 'photo.jpg';
        $curlUrl = $uploadUrl . ($fileName === '' ? '?fileName=' . rawurlencode($curlFileName) : '');

        return json_encode([
            'uploadUrl' => $uploadUrl,
            'uploadToken' => $tokenData['token'],
            'targetFolder' => $folder->getCombinedIdentifier(),
            'fileName' => $fileName !== '' ? $fileName : null,
            'validUntil' => date('c', $tokenData['validUntil']),
            'instructions' => 'Send the raw file bytes via HTTP PUT (or POST) to the uploadUrl, '
                . 'passing the uploadToken as bearer token, e.g.: '
                . "curl -sS -T '" . $curlFileName . "' -H 'Authorization: Bearer " . $tokenData['token'] . "' '" . $curlUrl . "'"
                . ($fileName === '' ? ' — replace the fileName query parameter with the actual file name including extension.' : '')
                . ' The token is single-use and expires at validUntil. '
                . 'The endpoint responds with JSON containing the created file (uid, name, publicUrl, …); '
                . 'use that uid with file_reference_add to attach the file to a record.',
        ], JSON_THROW_ON_ERROR);
    }

    private function buildAbsoluteUploadUrl(): ?string
    {
        $uploadPath = $this->pathProvider->getUploadPath();

        // MCP upload is registered at the host root (e.g. /mcp_upload), not under the
        // TYPO3 site base path (/home, /ai, …). Always join origin + uploadPath only.
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $request = $request instanceof ServerRequestInterface ? $request : null;
        $origin = $this->publicUrlService->resolveOrigin($request);
        if ($origin === 'https://your-site.com') {
            return null;
        }

        return rtrim($origin, '/') . $uploadPath;
    }
}
