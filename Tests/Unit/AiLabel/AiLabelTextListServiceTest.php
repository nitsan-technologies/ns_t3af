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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use NITSAN\NsT3AF\AiLabel\Service\AiLabelTextListService;
use PHPUnit\Framework\TestCase;

final class AiLabelTextListServiceTest extends TestCase
{
    public function testResolveTitleFallsBackForBlankHeader(): void
    {
        $title = $this->resolveTitle('tt_content', [
            'uid' => 42,
            'pid' => 7,
            'header' => '',
            'CType' => 'text',
        ]);

        self::assertSame('Page 7 · Content 42', $title);
    }

    public function testResolveTitleUsesHeaderWhenPresent(): void
    {
        $title = $this->resolveTitle('tt_content', [
            'uid' => 42,
            'pid' => 7,
            'header' => 'Hello',
        ]);

        self::assertSame('Hello', $title);
    }

    public function testResolveTitleFallsBackForBlankPageTitle(): void
    {
        $title = $this->resolveTitle('pages', [
            'uid' => 3,
            'title' => '   ',
        ]);

        self::assertSame('Page #3', $title);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveTitle(string $table, array $record): string
    {
        $service = (new \ReflectionClass(AiLabelTextListService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AiLabelTextListService::class, 'resolveTitle');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $table, $record);
    }
}
