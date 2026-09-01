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

use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Agent\Entitlement\EntitlementResolver;
use NITSAN\NsT3AF\Contract\ExtensionOperationalStatusInterface;
use PHPUnit\Framework\TestCase;

final class EntitlementResolverTest extends TestCase
{
    public function testCoreAndFoundationAlwaysExecutable(): void
    {
        $resolver = new EntitlementResolver([], new ExtensionAvailability());

        self::assertTrue($resolver->isExecutable(null));
        self::assertTrue($resolver->isExecutable(''));
        self::assertTrue($resolver->isExecutable('ns_t3af'));
    }

    public function testOperationalChildIsExecutableWhenLoadedAndReported(): void
    {
        $provider = new class implements ExtensionOperationalStatusInterface {
            public function extensionKey(): string
            {
                return 'ns_t3af';
            }

            public function isOperational(): bool
            {
                return true;
            }

            public function toolCount(): int
            {
                return 54;
            }
        };

        $resolver = new EntitlementResolver([$provider], new ExtensionAvailability());

        self::assertTrue($resolver->isExecutable('ns_t3af'));
        self::assertSame(54, $resolver->getToolCount('ns_t3af'));
    }

    public function testUnknownOwnerWithoutProviderDefaultsToLoadedCheck(): void
    {
        $resolver = new EntitlementResolver([], new ExtensionAvailability());

        // Extension not loaded in unit context — should be false for fake keys
        self::assertFalse($resolver->isExecutable('definitely_not_an_extension_key_xyz'));
    }
}
