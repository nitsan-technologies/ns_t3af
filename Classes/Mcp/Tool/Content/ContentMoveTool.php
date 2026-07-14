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

namespace NITSAN\NsT3AF\Mcp\Tool\Content;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;

readonly class ContentMoveTool implements McpNonAiToolInterface
{
    public function __construct(private DataHandlerService $dataHandlerService) {}

    #[McpTool(
        name: 'content_move',
        description: 'Move a content element to a new position.'
            . ' Use a positive target to move to the top of a page (target = page pid).'
            . ' Use a negative target to move after another content element (target = -uid of the element to place after).',
    )]
    public function execute(int $uid, int $target): string
    {
        $this->dataHandlerService->moveRecord('tt_content', $uid, $target);

        return json_encode(['uid' => $uid, 'target' => $target, 'moved' => true], JSON_THROW_ON_ERROR);
    }
}
