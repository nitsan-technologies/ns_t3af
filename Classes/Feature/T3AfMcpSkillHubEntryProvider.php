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

namespace NITSAN\NsT3AF\Feature;

use NITSAN\NsT3AF\Contract\McpSkillHubEntryProviderInterface;

final class T3AfMcpSkillHubEntryProvider implements McpSkillHubEntryProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getEntries(): array
    {
        return [
            'typo3-core' => [
                'name' => 'TYPO3 Core Assistant',
                'triggerKeyword' => '/ai_foundation',
                // Pre-rebrand keyword; installs registered under it must still
                // be detected as installed (extension already shipped).
                'legacyTriggerKeywords' => ['/ai_universe'],
                'description' => 'Navigate pages, inspect schema, list content, and write records via core MCP tools.',
                'sourceUrl' => '',
                'tags' => ['core', 'content', 'schema'],
                'featured' => true,
            ],
            'seo-audit' => [
                'name' => 'SEO Audit Workflow',
                'triggerKeyword' => '/seo_audit',
                'description' => 'Use the audit_page_seo MCP prompt template to find weak meta descriptions and titles.',
                'sourceUrl' => '',
                'tags' => ['seo', 'prompt'],
                'featured' => false,
            ],
        ];
    }
}
