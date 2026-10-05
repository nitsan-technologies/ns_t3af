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

use NITSAN\NsT3AF\Agent\Service\AgentConversationTitleService;
use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentConversationTitleServiceTest extends TestCase
{
    #[Test]
    public function suggestWithoutLlmDoesNotCallAiService(): void
    {
        $ai = $this->createMock(AiServiceInterface::class);
        $ai->expects(self::never())->method('complete');

        $language = (new \ReflectionClass(AgentLanguageResolver::class))->newInstanceWithoutConstructor();
        $service = new AgentConversationTitleService($ai, $language);

        $title = $service->suggestWithoutLlm([
            ['role' => 'user', 'content' => 'Update this page title with suffix by AI Agent updated', 'meta' => []],
            ['role' => 'assistant', 'content' => 'Done', 'meta' => ['type' => 'nl_reply']],
        ], 160);

        self::assertNotNull($title);
        self::assertNotSame('', $title);
    }
}
