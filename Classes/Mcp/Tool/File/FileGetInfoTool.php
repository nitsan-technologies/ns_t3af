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
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\FileService;

#[McpToolSeverity(ToolSeverity::Read)]
readonly class FileGetInfoTool implements McpFalStorageToolInterface
{
    public function __construct(private FileService $fileService) {}

    /**
     * @param int    $storageUid     File storage uid (1 = fileadmin).
     * @param string $fileIdentifier Path in the storage ("/user_upload/photo.jpg"), or just the file name; a name is found in any folder.
     */
    #[McpTool(
        name: 'file_get_info',
        description: 'Get details (uid, folder, size, type, public URL) of a file by storage UID and file identifier or just its file name.',
    )]
    public function execute(int $storageUid, string $fileIdentifier): string
    {
        return json_encode(
            $this->fileService->getFileInfo($storageUid, $fileIdentifier),
            JSON_THROW_ON_ERROR,
        );
    }
}
