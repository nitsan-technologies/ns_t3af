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

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use NITSAN\NsT3AF\Mcp\Server\ContextualReferenceHandler;
use NITSAN\NsT3AF\Mcp\Service\McpInvocationContext;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ContextualReferenceHandlerTest extends TestCase
{
    #[Test]
    public function handleAppliesInvocationContextBeforeDelegating(): void
    {
        $workspaceList = $this->createMock(WorkspaceListService::class);
        $workspaceList->method('list')->willReturn([['uid' => 0, 'title' => 'LIVE']]);

        $context = new McpInvocationContext($workspaceList);

        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->expects(self::once())
            ->method('handle')
            ->willReturnCallback(function (ElementReference $reference, array $arguments) use ($context): string {
                self::assertSame('override.provider', $context->getProviderIdentifier());

                return 'ok';
            });

        $handler = new ContextualReferenceHandler($context, $inner);
        $reference = new ElementReference(static fn(): string => 'unused');

        $result = $handler->handle($reference, [
            'aiProvider' => 'override.provider',
            '_session' => new \stdClass(),
        ]);

        self::assertSame('ok', $result);
    }
}
