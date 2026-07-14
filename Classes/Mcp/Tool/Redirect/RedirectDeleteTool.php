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

namespace NITSAN\NsT3AF\Mcp\Tool\Redirect;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class RedirectDeleteTool implements McpNonAiToolInterface
{
    private const TABLE = 'sys_redirect';

    public function __construct(private DataHandlerService $dataHandlerService) {}

    #[McpTool(
        name: 'redirect_delete',
        description: 'Delete a redirect record by its uid. Requires cms-redirects extension.',
    )]
    public function execute(int $uid): string
    {
        if (!ExtensionManagementUtility::isLoaded('redirects')) {
            return json_encode(['error' => 'cms-redirects extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $this->dataHandlerService->deleteRecord(self::TABLE, $uid);

        return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
