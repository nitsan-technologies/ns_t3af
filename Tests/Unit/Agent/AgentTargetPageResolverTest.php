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

use NITSAN\NsT3AF\Agent\Service\AgentTargetPageResolver;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentTargetPageResolverTest extends TestCase
{
    #[Test]
    public function extractPageReferenceFromGetThePagePhrase(): void
    {
        $resolver = new AgentTargetPageResolver($this->createMock(McpPlaygroundService::class));

        self::assertSame(
            'Page 3',
            $resolver->extractPageReference('Get the Page 3. Translate it. Then optimise the SEO.'),
        );
    }

    #[Test]
    public function extractPageUidFromExplicitSyntax(): void
    {
        $resolver = new AgentTargetPageResolver($this->createMock(McpPlaygroundService::class));

        self::assertSame('12', $resolver->extractPageReference('Optimise SEO for page uid 12'));
        self::assertSame('5', $resolver->extractPageReference('@pages:5'));
    }
}
