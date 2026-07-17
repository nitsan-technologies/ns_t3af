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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Search;

use NITSAN\NsT3AF\Mcp\Tool\Search\SearchConditionParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SearchConditionParserTest extends TestCase
{
    #[Test]
    public function fromArrayFiltersUnknownFieldsAndParsesOperators(): void
    {
        $result = SearchConditionParser::fromArray(
            [
                'title' => 'Home',
                'uid' => ['op' => 'gt', 'value' => '10'],
                'secret' => 'ignored',
            ],
            ['uid', 'title'],
        );

        self::assertSame(
            [
                'title' => ['operator' => 'like', 'value' => 'Home'],
                'uid' => ['operator' => 'gt', 'value' => '10'],
            ],
            $result,
        );
    }
}
