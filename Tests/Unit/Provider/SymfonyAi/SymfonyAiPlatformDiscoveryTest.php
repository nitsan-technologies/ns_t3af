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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\SymfonyAi;

use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiPlatformDiscovery;
use PHPUnit\Framework\TestCase;

final class SymfonyAiPlatformDiscoveryTest extends TestCase
{
    public function testDiscoversOnlyMatchingPackages(): void
    {
        $discovery = new SymfonyAiPlatformDiscovery(static fn(): array => [
            'symfony/ai-openai-platform',
            'symfony/ai-anthropic-platform',
            'symfony/console',
            'typo3/cms-core',
            'lochmueller/seal-ai-mistral',
            'random/package',
        ]);

        $descriptors = $discovery->discover();
        $types = array_map(static fn($d) => $d->type, $descriptors);

        self::assertContains('symfony.openai', $types);
        self::assertContains('symfony.anthropic', $types);
        self::assertContains('symfony.mistral', $types);
        self::assertNotContains('symfony.console', $types);
        self::assertNotContains('symfony.cms-core', $types);
        self::assertCount(3, $descriptors);
    }

    public function testEndpointDefaultsForKnownVendors(): void
    {
        $discovery = new SymfonyAiPlatformDiscovery(static fn(): array => [
            'symfony/ai-openai-platform',
            'symfony/ai-ollama-platform',
        ]);
        $byType = [];
        foreach ($discovery->discover() as $d) {
            $byType[$d->type] = $d;
        }

        self::assertSame('https://api.openai.com/v1', $byType['symfony.openai']->defaultEndpoint);
        self::assertSame('http://localhost:11434', $byType['symfony.ollama']->defaultEndpoint);
    }

    public function testCapabilitiesIncludeStreamingForChatVendors(): void
    {
        $discovery = new SymfonyAiPlatformDiscovery(static fn(): array => ['symfony/ai-openai-platform']);
        $d = $discovery->discover()[0];

        self::assertContains(Capability::CHAT, $d->defaultCapabilities);
        self::assertContains(Capability::STREAMING, $d->defaultCapabilities);
    }

    public function testOllamaCapabilitiesIncludeEmbeddings(): void
    {
        $discovery = new SymfonyAiPlatformDiscovery(static fn(): array => ['symfony/ai-ollama-platform']);
        $d = $discovery->discover()[0];

        self::assertSame('symfony.ollama', $d->type);
        self::assertContains(Capability::EMBEDDINGS, $d->defaultCapabilities);
    }

    public function testEmptyPackageListReturnsEmpty(): void
    {
        $discovery = new SymfonyAiPlatformDiscovery(static fn(): array => []);
        self::assertSame([], $discovery->discover());
    }

    public function testHyphenatedOpenAiPackageNormalizesToSymfonyOpenaiType(): void
    {
        $discovery = new SymfonyAiPlatformDiscovery(static fn(): array => [
            'symfony/ai-open-ai-platform',
        ]);
        $d = $discovery->discover()[0];

        self::assertSame('symfony.openai', $d->type);
        self::assertSame('open-ai', $d->vendorKey);
        self::assertSame('https://api.openai.com/v1', $d->defaultEndpoint);
    }

    public function testExcludesGenericPlatformTransitiveDependency(): void
    {
        $discovery = new SymfonyAiPlatformDiscovery(static fn(): array => [
            'symfony/ai-generic-platform',
            'symfony/ai-mistral-platform',
        ]);

        $types = array_map(static fn($d) => $d->type, $discovery->discover());

        self::assertContains('symfony.mistral', $types);
        self::assertNotContains('symfony.generic', $types);
        self::assertCount(1, $types);
    }
}
