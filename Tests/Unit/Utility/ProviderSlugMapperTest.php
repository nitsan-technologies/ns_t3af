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

namespace NITSAN\NsT3AF\Tests\Unit\Utility;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Utility\ProviderSlugMapper;
use PHPUnit\Framework\TestCase;

final class ProviderSlugMapperTest extends TestCase
{
    public function testSlugFromAdapterTypeMapsKnownAdapters(): void
    {
        self::assertSame('openai', ProviderSlugMapper::slugFromAdapterType('symfony.openai'));
        self::assertSame('claude', ProviderSlugMapper::slugFromAdapterType('symfony.anthropic'));
        self::assertSame('customllm', ProviderSlugMapper::slugFromAdapterType(Provider::ADAPTER_OPENAI_COMPATIBLE));
        self::assertSame('ollama', ProviderSlugMapper::slugFromAdapterType(Provider::ADAPTER_SYMFONY_OLLAMA));
    }

    public function testIsT3CsCompatible(): void
    {
        self::assertTrue(ProviderSlugMapper::isT3CsCompatible('openai'));
        self::assertFalse(ProviderSlugMapper::isT3CsCompatible('deepseek'));
    }
}
