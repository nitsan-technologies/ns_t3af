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

namespace NITSAN\NsT3AF\Mcp\Tool\Cache;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\CacheService;

readonly class CacheClearTool implements McpNonAiToolInterface
{
    public function __construct(private CacheService $cacheService) {}

    #[McpTool(
        name: 'cache_clear',
        description: 'Clear TYPO3 caches. Scope: "pages" (default) clears page and content caches,'
            . ' "all" clears all caches including system caches, "page" clears cache for a single page (requires pageId).',
    )]
    public function execute(string $scope = 'pages', int $pageId = 0): string
    {
        if (!in_array($scope, ['pages', 'all', 'page'], true)) {
            return json_encode(
                ['error' => 'Invalid scope: ' . $scope, 'validScopes' => ['pages', 'all', 'page']],
                JSON_THROW_ON_ERROR,
            );
        }

        if ($scope === 'page' && $pageId === 0) {
            return json_encode(['error' => 'pageId is required when scope is "page"'], JSON_THROW_ON_ERROR);
        }

        match ($scope) {
            'pages' => $this->cacheService->flushPageCaches(),
            'all' => $this->cacheService->flushAllCaches(),
            'page' => $this->cacheService->flushPageCache($pageId),
        };

        return json_encode(['cleared' => true, 'scope' => $scope, 'pageId' => $pageId > 0 ? $pageId : null], JSON_THROW_ON_ERROR);
    }
}
