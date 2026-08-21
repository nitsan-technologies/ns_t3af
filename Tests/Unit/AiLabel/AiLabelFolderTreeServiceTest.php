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

use NITSAN\NsT3AF\AiLabel\Service\AiLabelFolderTreeService;
use PHPUnit\Framework\TestCase;

final class AiLabelFolderTreeServiceTest extends TestCase
{
    public function testIsSameFolderIgnoresTrailingSlash(): void
    {
        self::assertTrue(AiLabelFolderTreeService::isSameFolder('/nst3af', '/nst3af/'));
        self::assertFalse(AiLabelFolderTreeService::isSameFolder('/nst3af/', '/nst3af/nst3af_2/'));
    }

    public function testIsAncestorOrSelfForNestedPaths(): void
    {
        self::assertTrue(AiLabelFolderTreeService::isAncestorOrSelf('/nst3af/', '/nst3af/nst3af_2/nst3af_3/'));
        self::assertTrue(AiLabelFolderTreeService::isAncestorOrSelf('/nst3af/nst3af_2/', '/nst3af/nst3af_2/nst3af_3/'));
        self::assertTrue(AiLabelFolderTreeService::isAncestorOrSelf('/', '/nst3af/'));
        self::assertFalse(AiLabelFolderTreeService::isAncestorOrSelf('/camino/', '/nst3af/'));
    }

    public function testMarkActiveAndExpandedOpensAncestors(): void
    {
        $tree = [
            [
                'identifier' => '/nst3af/',
                'children' => [
                    [
                        'identifier' => '/nst3af/nst3af_2/',
                        'children' => [
                            [
                                'identifier' => '/nst3af/nst3af_2/nst3af_3/',
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'identifier' => '/camino/',
                'children' => [],
            ],
        ];

        $marked = AiLabelFolderTreeService::markActiveAndExpanded($tree, '/nst3af/nst3af_2/nst3af_3/');

        self::assertTrue($marked[0]['expanded']);
        self::assertFalse($marked[0]['active']);
        self::assertTrue($marked[0]['children'][0]['expanded']);
        self::assertTrue($marked[0]['children'][0]['children'][0]['active']);
        self::assertFalse($marked[1]['expanded']);
        self::assertFalse($marked[1]['active']);
    }
}
