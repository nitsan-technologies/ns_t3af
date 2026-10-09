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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;

/**
 * Severity of a registered tool by name, as declared on the tool class
 * (#[McpToolSeverity]) or by the dynamic-tool operation suffix.
 *
 * Never guesses from the tool name: an undeclared tool has no severity and stays locked.
 *
 * @internal
 */
class DeclaredToolSeverityLookup
{
    /**
     * @var array<string, ToolSeverity|null>|null
     */
    private ?array $byName = null;

    public function __construct(
        private readonly McpToolIntrospectorService $toolIntrospector,
    ) {}

    public function severityFor(string $toolName): ?ToolSeverity
    {
        $this->byName ??= $this->buildIndex();

        return $this->byName[$toolName] ?? null;
    }

    /**
     * @return array<string, ToolSeverity|null>
     */
    private function buildIndex(): array
    {
        $index = [];
        foreach ($this->toolIntrospector->listTools() as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $index[$name] = ToolSeverity::tryFromString((string) ($tool['severity'] ?? ''));
        }

        return $index;
    }
}
