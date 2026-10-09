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
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentConversationTitleServiceTest extends TestCase
{
    use AgentTranslatorTrait;

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

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

    #[Test]
    public function aPickedSlashCommandIsTitledByTheActionName(): void
    {
        $ai = $this->createMock(AiServiceInterface::class);
        $language = (new \ReflectionClass(AgentLanguageResolver::class))->newInstanceWithoutConstructor();
        $service = new AgentConversationTitleService($ai, $language, new AgentToolEditorLabelService($this->createAgentTranslator()));

        self::assertSame(
            'Check page permissions',
            $service->suggestWithoutLlm([['role' => 'user', 'content' => '/permission_check_page', 'meta' => []]], 2),
        );
        self::assertSame('Check page permissions', $service->displayTitle('/permission_check_page'));
        self::assertSame('Rename About page', $service->displayTitle('Rename About page'));
    }
}
