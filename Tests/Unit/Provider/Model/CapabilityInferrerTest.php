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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\Model;

use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Model\CapabilityInferrer;
use PHPUnit\Framework\TestCase;

final class CapabilityInferrerTest extends TestCase
{
    private CapabilityInferrer $inferrer;

    protected function setUp(): void
    {
        $this->inferrer = new CapabilityInferrer();
    }

    public function testEmbeddingModelReturnsOnlyEmbeddings(): void
    {
        self::assertSame([Capability::EMBEDDINGS], $this->inferrer->infer('text-embedding-3-small'));
        self::assertSame([Capability::EMBEDDINGS], $this->inferrer->infer('voyage-large-2'));
    }

    public function testGpt4oGetsVisionAndToolUse(): void
    {
        $caps = $this->inferrer->infer('gpt-4o-mini');
        self::assertContains(Capability::CHAT, $caps);
        self::assertContains(Capability::STREAMING, $caps);
        self::assertContains(Capability::VISION, $caps);
        self::assertContains(Capability::TOOL_USE, $caps);
    }

    public function testClaude3GetsVisionAndToolUse(): void
    {
        $caps = $this->inferrer->infer('claude-3-5-sonnet-20241022');
        self::assertContains(Capability::VISION, $caps);
        self::assertContains(Capability::TOOL_USE, $caps);
    }

    public function testUnknownModelDefaultsToChatStreaming(): void
    {
        $caps = $this->inferrer->infer('some-random-model');
        self::assertSame([Capability::CHAT, Capability::STREAMING], $caps);
    }

    public function testOpenRouterAdapterEnablesToolUse(): void
    {
        $caps = $this->inferrer->infer('meta-llama/llama-3.1-8b-instruct', 'symfony.openrouter');
        self::assertContains(Capability::TOOL_USE, $caps);
    }

    public function testEmptyModelReturnsEmpty(): void
    {
        self::assertSame([], $this->inferrer->infer(''));
    }
}
