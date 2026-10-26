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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Search;

use NITSAN\NsT3AF\Mcp\Tool\Search\SearchSortResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SearchSortResolverTest extends TestCase
{
    #[Test]
    public function invalidOrderByUsesDedicatedCode(): void
    {
        try {
            SearchSortResolver::resolve('not_a_field', 'ASC', ['uid', 'title']);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(1790600007, $exception->getCode());
        }
    }

    #[Test]
    public function invalidOrderDirectionUsesDedicatedCode(): void
    {
        try {
            SearchSortResolver::resolve('', 'UP', ['uid', 'title']);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(1790600008, $exception->getCode());
        }
    }
}
