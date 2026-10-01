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

namespace NITSAN\NsT3AF\Tests\Unit\Provider;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\ProviderCapabilityGuard;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilityGuardTest extends TestCase
{
    public function testAllowsChatWhenCapabilitiesEmpty(): void
    {
        self::assertTrue(ProviderCapabilityGuard::allowsChat($this->provider([])));
    }

    public function testAllowsChatWhenChatPresent(): void
    {
        self::assertTrue(ProviderCapabilityGuard::allowsChat($this->provider([Capability::CHAT])));
    }

    public function testRejectsChatWhenOnlyToolUse(): void
    {
        self::assertFalse(ProviderCapabilityGuard::allowsChat($this->provider([Capability::TOOL_USE])));
    }

    public function testAssertCallAllowedPassesForEmptyCapabilities(): void
    {
        ProviderCapabilityGuard::assertCallAllowed(
            $this->provider([]),
            ProviderCapabilityGuard::CALL_COMPLETE,
        );
        $this->addToAssertionCount(1);
    }

    public function testAssertCallAllowedThrowsForMissingChat(): void
    {
        $this->expectException(AdapterRuntimeException::class);
        ProviderCapabilityGuard::assertCallAllowed(
            $this->provider([Capability::TOOL_USE]),
            ProviderCapabilityGuard::CALL_COMPLETE,
        );
    }

    public function testAssertCallAllowedRequiresVisionForImageMessages(): void
    {
        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionMessage('vision');
        ProviderCapabilityGuard::assertCallAllowed(
            $this->provider([Capability::CHAT]),
            ProviderCapabilityGuard::CALL_COMPLETE,
            new AiOptions(extra: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/x.png']],
                    ],
                ]],
            ]),
        );
    }

    /**
     * @param list<string> $capabilities
     */
    private function provider(array $capabilities): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'test-provider',
            title: 'Test',
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: $capabilities,
            temperature: 0.7,
            systemPrompt: '',
            isDefault: true,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
        );
    }
}
