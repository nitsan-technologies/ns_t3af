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

namespace NITSAN\NsT3AF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Classic TYPO3 installs autoload from ext_emconf.php, not composer.json.
 *
 * @internal
 */
final class ExtEmconfAutoloadTest extends TestCase
{
    public function testExtEmconfDeclaresPsr4MatchingComposerJson(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composerPsr4 = $composer['autoload']['psr-4'] ?? [];
        self::assertNotEmpty($composerPsr4);

        /** @var array<string, array<string, mixed>> $EM_CONF */
        $EM_CONF = [];
        include $packageRoot . '/ext_emconf.php';
        self::assertArrayHasKey('ns_t3af', $EM_CONF);
        /** @var array<string, string> $emconfPsr4 */
        $emconfPsr4 = $EM_CONF['ns_t3af']['autoload']['psr-4'] ?? [];

        foreach ($composerPsr4 as $namespace => $path) {
            self::assertArrayHasKey($namespace, $emconfPsr4, 'Classic ext_emconf.php must declare PSR-4 for ' . $namespace);
            self::assertSame(rtrim((string) $path, '/') . '/', rtrim((string) $emconfPsr4[$namespace], '/') . '/');
        }
    }
}
