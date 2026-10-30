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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Redirect;

use NITSAN\NsT3AF\Mcp\Tool\Redirect\RedirectCreateTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RedirectCreateToolTest extends TestCase
{
    #[Test]
    public function aJavascriptTargetIsRefused(): void
    {
        self::assertSame(
            'The redirect target must be a t3 link, a site path, or an http or https URL.',
            RedirectCreateTool::refusedReason('javascript:alert(1)', 301),
        );
        self::assertFalse(RedirectCreateTool::isAllowedTarget('javascript:alert(1)'));
        self::assertFalse(RedirectCreateTool::isAllowedTarget(''));
    }

    #[Test]
    public function status999IsRefused(): void
    {
        self::assertSame(
            'The redirect status must be 301, 302, 303, 307, or 308.',
            RedirectCreateTool::refusedReason('/', 999),
        );
        self::assertNull(RedirectCreateTool::refusedReason('/', 301));
        self::assertNull(RedirectCreateTool::refusedReason('https://example.com/', 302));
        self::assertNull(RedirectCreateTool::refusedReason('t3://page?uid=1', 307));
    }

    #[Test]
    public function aDuplicateHostAndPathIsRefused(): void
    {
        self::assertSame(
            'A redirect with this host and path already exists.',
            RedirectCreateTool::refusedReason('/', 301, 35),
        );
    }
}
