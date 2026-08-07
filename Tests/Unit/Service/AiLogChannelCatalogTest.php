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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\AiLogChannelCatalog;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
final class AiLogChannelCatalogTest extends TestCase
{
    private const SYS_LOG_CHANNEL_MAX_LENGTH = 20;

    #[Test]
    public function canonicalChannelsFitSysLogColumn(): void
    {
        $reflection = new ReflectionClass(AiLogChannelCatalog::class);
        foreach ($reflection->getConstants() as $name => $value) {
            if (!is_string($value) || !str_starts_with($name, 'CHANNEL_')) {
                continue;
            }
            // Legacy aliases exist only for normalizing old sys_log rows — never written anew.
            if (str_ends_with($name, '_LEGACY')) {
                continue;
            }

            self::assertLessThanOrEqual(
                self::SYS_LOG_CHANNEL_MAX_LENGTH,
                mb_strlen($value),
                sprintf('Channel constant %s value "%s" exceeds sys_log.channel limit.', $name, $value),
            );
        }
    }

    #[Test]
    public function legacyMcpConfigChannelMapsToCurrentValue(): void
    {
        $catalog = ProviderTestStubs::aiLogChannelCatalog();

        self::assertSame(
            AiLogChannelCatalog::CHANNEL_MCP_CONFIG,
            $catalog->normalizeLogChannel(AiLogChannelCatalog::CHANNEL_MCP_CONFIG_LEGACY),
        );
    }
}
