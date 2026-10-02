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

namespace NITSAN\NsT3AF\Mcp\Service;

/**
 * Request-scoped stack that temporarily overrides the global mcpMode.
 *
 * Used by the AI Agent so DualMode tools can preview in native mode and apply
 * in context mode without mutating Extension Configuration or tool caches.
 *
 * @api
 */
final class McpModeOverride
{
    /** @var list<string> */
    private array $stack = [];

    public function push(string $mode): void
    {
        $normalized = strtolower(trim($mode));
        if (!in_array($normalized, [McpModeResolver::MODE_CONTEXT, McpModeResolver::MODE_NATIVE], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid MCP mode override "%s".', $mode),
                1742200001,
            );
        }
        $this->stack[] = $normalized;
    }

    public function pop(): void
    {
        if ($this->stack === []) {
            throw new \LogicException('MCP mode override stack is empty.', 1742200002);
        }
        array_pop($this->stack);
    }

    public function current(): ?string
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $mode, callable $callback): mixed
    {
        $this->push($mode);
        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }
}
