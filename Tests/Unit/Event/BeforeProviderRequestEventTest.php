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

namespace NITSAN\NsT3AF\Tests\Unit\Event;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Provider\Capability;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

final class BeforeProviderRequestEventTest extends TestCase
{
    public function testImplementsStoppableEventInterface(): void
    {
        $event = $this->makeEvent();

        self::assertInstanceOf(StoppableEventInterface::class, $event);
        self::assertFalse($event->isPropagationStopped());
    }

    public function testCancelStopsPropagation(): void
    {
        $event = $this->makeEvent();
        $event->cancelWithReason('denied');

        self::assertTrue($event->isCancelled());
        self::assertTrue($event->isPropagationStopped());
        self::assertSame('denied', $event->getCancellationReason());
    }

    private function makeEvent(): BeforeProviderRequestEvent
    {
        $provider = new Provider(
            uid: 1,
            pid: 1,
            identifier: 'openai',
            title: 'OpenAI',
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: [Capability::CHAT],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: true,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: Provider::LAST_STATUS_UNKNOWN,
            lastStatusAt: 0,
            lastStatusMessage: '',
        );

        return new BeforeProviderRequestEvent($provider, 'hello', new AiOptions(), 'complete');
    }
}
