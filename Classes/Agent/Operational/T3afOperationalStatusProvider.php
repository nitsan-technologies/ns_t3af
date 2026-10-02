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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Agent\Operational;

use NITSAN\NsT3AF\Contract\ExtensionOperationalStatusInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpToolHandlerInterface;

/**
 * Self-report for the foundation extension itself (always operational).
 */
final readonly class T3afOperationalStatusProvider implements ExtensionOperationalStatusInterface
{
    private const EXTENSION_KEY = 'ns_t3af';

    /**
     * @param iterable<McpToolHandlerInterface> $coreTools
     */
    public function __construct(
        private iterable $coreTools,
    ) {}

    public function extensionKey(): string
    {
        return self::EXTENSION_KEY;
    }

    public function isOperational(): bool
    {
        return true;
    }

    public function toolCount(): int
    {
        $count = 0;
        foreach ($this->coreTools as $tool) {
            if (!str_starts_with($tool::class, 'NITSAN\\NsT3AF\\Mcp\\Tool\\')) {
                continue;
            }
            ++$count;
        }

        return $count;
    }
}
