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

namespace NITSAN\NsT3AF\Tests\Unit\Registry;

use NITSAN\NsT3AF\Contract\ExtensionSettingsFieldRedirectProviderInterface;
use NITSAN\NsT3AF\Registry\ExtensionSettingsFieldRedirectRegistry;
use PHPUnit\Framework\TestCase;

final class ExtensionSettingsFieldRedirectRegistryTest extends TestCase
{
    public function testFindRedirectHandlerReturnsMatchingProvider(): void
    {
        $provider = new class implements ExtensionSettingsFieldRedirectProviderInterface {
            public function getExtensionKey(): string
            {
                return 'ns_demo';
            }

            public function getRedirectedFields(): array
            {
                return [
                    ['scope' => 'drawer', 'field' => 'flagOne'],
                ];
            }

            public function resolveFieldValue(string $scope, string $field, int $storagePid): string
            {
                return '1';
            }

            public function persistFieldValue(string $scope, string $field, string $value, int $storagePid): bool
            {
                return true;
            }
        };

        $registry = new ExtensionSettingsFieldRedirectRegistry([$provider]);

        self::assertSame($provider, $registry->findRedirectHandler('ns_demo', 'drawer', 'flagOne'));
        self::assertNull($registry->findRedirectHandler('ns_demo', 'other', 'flagOne'));
        self::assertNull($registry->findRedirectHandler('ns_other', 'drawer', 'flagOne'));
    }
}
