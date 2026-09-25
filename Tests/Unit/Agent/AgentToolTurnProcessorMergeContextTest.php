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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolTurnProcessorMergeContextTest extends TestCase
{
    #[Test]
    public function mergeContextDoesNotCopyPageIdIntoUid(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 7, 'module' => 'web_layout'],
        );

        self::assertSame(7, $merged['pageId']);
        self::assertSame(7, $merged['pid']);
        self::assertArrayNotHasKey('uid', $merged);
    }

    #[Test]
    public function mergeContextUsesFocusedRecordUid(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            [
                'pageId' => 7,
                'record' => ['table' => 'tt_content', 'uid' => 20],
            ],
        );

        self::assertSame(20, $merged['uid']);
        self::assertSame('tt_content', $merged['table']);
        self::assertSame(7, $merged['pageId']);
    }

    #[Test]
    public function mergeContextKeepsExplicitUidOverRecord(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['uid' => 99],
            [
                'pageId' => 7,
                'record' => ['table' => 'tt_content', 'uid' => 20],
            ],
        );

        self::assertSame(99, $merged['uid']);
    }

    #[Test]
    public function mergeContextDoesNotForcePidOntoSearchTools(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['search' => 'Camino'],
            ['pageId' => 7],
            'content_search',
        );

        self::assertSame(7, $merged['pageId']);
        self::assertSame('Camino', $merged['search']);
        self::assertArrayNotHasKey('pid', $merged);
    }

    #[Test]
    public function mergeContextStillSetsPidForNonSearchTools(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 7],
            'content_list',
        );

        self::assertSame(7, $merged['pid']);
    }
}
