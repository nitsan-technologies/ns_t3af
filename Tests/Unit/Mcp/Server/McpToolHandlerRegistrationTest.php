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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpToolHandlerRegistrationTest extends TestCase
{
    #[Test]
    public function allMcpToolClassesImplementHandlerContractAndCarryMcpToolAttribute(): void
    {
        $toolDir = dirname(__DIR__, 4) . '/Classes/Mcp/Tool';
        self::assertDirectoryExists($toolDir);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($toolDir));
        $violations = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (!str_contains($content, 'class ') || !str_contains($content, 'function execute')) {
                continue;
            }

            $relativePath = str_replace($toolDir . '/', '', $file->getPathname());
            $implementsHandlerContract = str_contains($content, 'McpToolHandlerInterface')
                || str_contains($content, 'McpNonAiToolInterface')
                || str_contains($content, 'McpFalStorageToolInterface')
                || str_contains($content, 'McpExternalContentToolInterface')
                || str_contains($content, 'McpDualModeContentToolInterface');
            if (!$implementsHandlerContract) {
                $violations[] = $relativePath . ' (missing MCP tool handler contract)';
            }
            if (!str_contains($content, '#[McpTool')) {
                $violations[] = $relativePath . ' (missing #[McpTool] on execute())';
            }
        }

        self::assertSame([], $violations, 'Invalid MCP tool handlers: ' . implode('; ', $violations));
    }
}
