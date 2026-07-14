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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

/**
 * Holds per-request MCP auth context set by {@see \NITSAN\NsT3AF\Mcp\Middleware\McpServerMiddleware}.
 *
 * @internal
 */
final class McpRuntimeContext
{
    private int $tokenUid = 0;

    private string $clientLabel = '';

    private int $beUser = 0;

    public function set(int $tokenUid, string $clientLabel, int $beUser): void
    {
        $this->tokenUid = $tokenUid;
        $this->clientLabel = $clientLabel;
        $this->beUser = $beUser;
    }

    public function clear(): void
    {
        $this->tokenUid = 0;
        $this->clientLabel = '';
        $this->beUser = 0;
    }

    public function getTokenUid(): int
    {
        return $this->tokenUid;
    }

    public function getClientLabel(): string
    {
        return $this->clientLabel;
    }

    public function getBeUser(): int
    {
        return $this->beUser;
    }

    public function isSet(): bool
    {
        return $this->tokenUid > 0 || $this->beUser > 0;
    }
}
