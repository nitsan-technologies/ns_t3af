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

namespace NITSAN\NsT3AF\Access;

/**
 * Wizard {@see RecordPermissionCatalog} row IDs for ns_t3af admin surfaces.
 */
final class AiUniverseRecordMap
{
    public const BRAND_PROFILES = 'brandProfiles';
    public const AI_PROMPT_STORAGE = 'aiPromptStorage';
    /** @deprecated Use AI_PROMPT_STORAGE */
    public const GLOBAL_PROMPTS = self::AI_PROMPT_STORAGE;
    /** @deprecated Use AI_PROMPT_STORAGE */
    public const T3AA_GLOBAL_PROMPTS = self::AI_PROMPT_STORAGE;
    /** @deprecated Use AI_PROMPT_STORAGE */
    public const SIDEBAR_PROMPTS = self::AI_PROMPT_STORAGE;
    public const USAGE_REQUEST_LOG = 'usageRequestLog';
    public const EXTENSION_SETTINGS = 'extensionSettings';
    public const OAUTH_CLIENTS = 'oauthClients';
    public const MCP_DISCOVERED_TABLES = 'mcpDiscoveredTables';
    public const MCP_CUSTOM_TOOLS = 'mcpCustomTools';
    public const MCP_PROMPT_TEMPLATES = 'mcpPromptTemplates';
    public const GROUP_SETTINGS = 'groupSettings';
    public const RUNTIME_SETTINGS = 'runtimeSettings';
}
