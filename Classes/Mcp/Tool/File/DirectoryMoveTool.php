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
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Service\FileService;

readonly class DirectoryMoveTool implements McpFalStorageToolInterface
{
    public function __construct(private FileService $fileService) {}

    #[McpTool(
        name: 'directory_move',
        description: 'Move a directory to a different parent directory within the same storage.',
    )]
    public function execute(string $directoryIdentifier, string $targetDirectory, int $storageUid = 1): string
    {
        $this->fileService->moveDirectory($storageUid, $directoryIdentifier, $targetDirectory);

        return json_encode(
            ['directoryIdentifier' => $directoryIdentifier, 'targetDirectory' => $targetDirectory, 'moved' => true],
            JSON_THROW_ON_ERROR,
        );
    }
}
