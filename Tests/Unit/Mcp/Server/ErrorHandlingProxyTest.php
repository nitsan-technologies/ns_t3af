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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Server;

use NITSAN\NsT3AF\Mcp\Logging\AuditLogger;
use NITSAN\NsT3AF\Mcp\Server\ErrorHandlingProxy;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
final class ErrorHandlingProxyTest extends TestCase
{
    #[Test]
    public function aToolAnswerWithAnErrorIsLoggedAsFailure(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logFailure');
        $audit->expects(self::never())->method('logSuccess');
        $toolLog = $this->createMock(McpToolLogService::class);
        $toolLog->expects(self::once())->method('logFailure')
            ->with(self::anything(), 'tool', self::anything(), self::anything(), 'Only administrators may clear all caches.');
        $toolLog->expects(self::never())->method('logSuccess');

        $inner = new class {
            public function execute(): string
            {
                return '{"error":"Only administrators may clear all caches."}';
            }
        };

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $audit, $toolLog, 'tool');

        self::assertSame('{"error":"Only administrators may clear all caches."}', $proxy->__call('execute', []));
    }

    #[Test]
    public function aNormalAnswerIsLoggedAsSuccess(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logSuccess');
        $audit->expects(self::never())->method('logFailure');
        $toolLog = $this->createMock(McpToolLogService::class);
        $toolLog->expects(self::once())->method('logSuccess');
        $toolLog->expects(self::never())->method('logFailure');

        $inner = new class {
            public function execute(): string
            {
                return '{"success":true,"cleared":"pages"}';
            }
        };

        (new ErrorHandlingProxy($inner, new NullLogger(), $audit, $toolLog, 'tool'))->__call('execute', []);
    }
}
