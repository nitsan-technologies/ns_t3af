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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A confirmed write that answers {"error": ...} is a failure, not an applied change (ticket 14zervyucgn).
 *
 * @internal
 */
final class McpPlaygroundServiceErrorPayloadTest extends TestCase
{
    #[Test]
    public function errorPayloadIsReportedAsMessage(): void
    {
        self::assertSame(
            'Folder "/x/" does not exist.',
            McpPlaygroundService::errorMessageOf('{"error":"Folder \"/x/\" does not exist."}'),
        );
    }

    #[Test]
    public function normalResultsAreNotErrors(): void
    {
        self::assertNull(McpPlaygroundService::errorMessageOf('{"cleared":true}'));
        self::assertNull(McpPlaygroundService::errorMessageOf('not json'));
        self::assertNull(McpPlaygroundService::errorMessageOf(null));
        self::assertNull(McpPlaygroundService::errorMessageOf(['error' => '']));
        self::assertNull(McpPlaygroundService::errorMessageOf(['error' => 'x', 'success' => true]));
    }
}
