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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Tool\File;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Service\FileService;

readonly class FileUploadTool implements McpFalStorageToolInterface
{
    public function __construct(private FileService $fileService) {}

    #[McpTool(
        name: 'file_upload',
        description: 'Upload a file to a storage directory. Provide either "content" for plain text or "base64Content" for base64-encoded binary data. Exactly one must be specified.',
    )]
    public function execute(
        string $fileName,
        string $base64Content = '',
        string $content = '',
        string $directoryPath = '/',
        int $storageUid = 1,
    ): string {
        $fileContent = $this->resolveContent($base64Content, $content);

        return json_encode(
            $this->fileService->uploadFile($storageUid, $directoryPath, $fileName, $fileContent),
            JSON_THROW_ON_ERROR,
        );
    }

    private function resolveContent(string $base64Content, string $content): string
    {
        if ($base64Content !== '' && $content !== '') {
            throw new ToolCallException('Provide either "content" or "base64Content", not both');
        }

        if ($base64Content === '' && $content === '') {
            throw new ToolCallException('Either "content" or "base64Content" must be provided');
        }

        if ($content !== '') {
            return $content;
        }

        $decoded = base64_decode($base64Content, true);
        if ($decoded === false) {
            throw new ToolCallException('Invalid base64 content');
        }

        return $decoded;
    }
}
