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

namespace NITSAN\NsT3AF\Mcp\Tool\Pages;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;

readonly class PagesCopyTool implements McpNonAiToolInterface
{
    public function __construct(private DataHandlerService $dataHandlerService) {}

    #[McpTool(
        name: 'pages_copy',
        description: 'Copy a page to a new position in the page tree.'
            . ' Use a positive target to copy as a child of that page (target = parent pid).'
            . ' Use a negative target to copy after a specific page (target = -uid of the page to place after).'
            . ' Set includeSubpages to true to copy the entire subtree including all subpages.',
    )]
    public function execute(int $uid, int $target, bool $includeSubpages = false): string
    {
        $newUid = $this->dataHandlerService->copyRecord('pages', $uid, $target, $includeSubpages ? 99 : 0);

        return json_encode(['sourceUid' => $uid, 'newUid' => $newUid], JSON_THROW_ON_ERROR);
    }
}
