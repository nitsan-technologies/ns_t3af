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

namespace NITSAN\NsT3AF\Tests\Unit\Registry;

use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\TestCase;

final class FeatureAccessBindingRegistryTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    private FeatureAccessBindingRegistry $registry;

    protected function setUp(): void
    {
        $this->mockAllCatalogExtensionsLoaded();
        $this->registry = $this->createFeatureAccessBindingRegistry();
    }

    public function testResolvesT3AiTabFeature(): void
    {
        self::assertSame('Content', $this->registry->resolveTabFeature('t3ai', 'content'));
        self::assertNull($this->registry->resolveTabFeature('t3ai', 'dashboard'));
    }

    public function testResolvesT3AaCardFeatureFromRules(): void
    {
        self::assertSame(
            'T3AA.FileMeta',
            $this->registry->resolveCardFeature('t3aa', 'dashboard', 'aiFileMetaAltText'),
        );
        self::assertSame(
            'T3AA.Media',
            $this->registry->resolveCardFeature('t3aa', 'dashboard', 'mediaAiAudio'),
        );
    }

    public function testResolvesT3CsSuiteTabFeature(): void
    {
        self::assertSame('T3CS.Index', $this->registry->resolveTabFeature('t3cs', 'DataSource'));
        self::assertSame('T3CS.Chat', $this->registry->resolveTabFeature('t3cs', 'Chatbot'));
    }

    public function testManageableBaseFeaturesIncludeContent(): void
    {
        self::assertContains('Content', $this->registry->manageableBaseFeatures());
    }
}
