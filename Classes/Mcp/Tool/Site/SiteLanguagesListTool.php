<?php

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

namespace NITSAN\NsT3AF\Mcp\Tool\Site;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\SiteLanguagesListService;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class SiteLanguagesListTool implements McpNonAiToolInterface
{
    public function __construct(
        private SiteLanguagesListService $siteLanguagesListService,
    ) {}

    #[McpTool(
        name: 'site_languages_list',
        description: 'List site languages available for a page. Provide pageId or pageUrl (one required). Use before mass translation queue operations to ask the user which target languages to select.',
    )]
    public function execute(?int $pageId = null, string $pageUrl = ''): string
    {
        try {
            return json_encode(
                $this->siteLanguagesListService->listForPage($pageId, $pageUrl),
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $throwable) {
            $message = trim($throwable->getMessage());

            return json_encode(
                ['error' => $message !== '' ? $message : 'Failed to list site languages.'],
                JSON_THROW_ON_ERROR,
            );
        }
    }
}
