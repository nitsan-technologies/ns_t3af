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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\SymfonyAi;

use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiMessageBagFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * @internal
 */
final class SymfonyAiMessageBagFactoryTest extends TestCase
{
    #[Test]
    public function aToolCallSignatureIsSentBackWithTheAssistantMessage(): void
    {
        $bag = (new SymfonyAiMessageBagFactory())->createFromChatMessages([
            ['role' => 'user', 'content' => 'Show page 49'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'pages_get', 'arguments' => ['uid' => 49], 'signature' => 'sig-abc'],
                    ['id' => 'call_2', 'name' => 'content_list', 'arguments' => ['pid' => 49]],
                ],
            ],
        ]);

        self::assertInstanceOf(MessageBag::class, $bag);
        $assistant = $bag->getMessages()[1];
        self::assertInstanceOf(AssistantMessage::class, $assistant);
        $calls = array_values($assistant->getToolCalls());
        self::assertSame('sig-abc', $calls[0]->getSignature());
        self::assertNull($calls[1]->getSignature());
    }
}
