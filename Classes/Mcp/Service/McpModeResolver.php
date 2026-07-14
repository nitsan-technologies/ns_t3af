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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Settings\ExtensionSettingsService;

/**
 * Resolves the global MCP mode from Extension Configuration (ns_t3af).
 */
final readonly class McpModeResolver
{
    public const MODE_CONTEXT = 'context';

    public const MODE_NATIVE = 'native';

    public function __construct(
        private ExtensionSettingsService $extensionSettingsService,
    ) {}

    public function getMode(): string
    {
        $mode = strtolower(trim((string) ($this->extensionSettingsService->getAllIgnorePid('ns_t3af')['mcpMode'] ?? self::MODE_CONTEXT)));

        return in_array($mode, [self::MODE_CONTEXT, self::MODE_NATIVE], true)
            ? $mode
            : self::MODE_CONTEXT;
    }

    public function isNative(): bool
    {
        return $this->getMode() === self::MODE_NATIVE;
    }

    public function isContext(): bool
    {
        return !$this->isNative();
    }
}
