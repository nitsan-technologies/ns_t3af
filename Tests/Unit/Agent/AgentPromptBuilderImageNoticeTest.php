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

use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentPromptBuilderImageNoticeTest extends TestCase
{
    private const CAP = '4 of the 6 images you asked for were made: at most 4 are made at a time. I can make the other 2 if you want.';

    #[Test]
    public function aCapIsOwedWhenTheReplyLeavesItOut(): void
    {
        $messages = [$this->imageResult(self::CAP)];

        self::assertSame(self::CAP, AgentPromptBuilder::imageNoticeOwed($messages, 'Your request for 6 pictures of cats is now complete.'));
    }

    #[Test]
    public function nothingIsOwedWhenTheReplyAlreadySaysIt(): void
    {
        $messages = [$this->imageResult(self::CAP)];

        self::assertSame('', AgentPromptBuilder::imageNoticeOwed($messages, self::CAP . ' Anything else?'));
    }

    #[Test]
    public function onlyTheFirstReplyAfterTheImageOwesTheNotice(): void
    {
        $messages = [
            $this->imageResult(self::CAP),
            ['role' => 'assistant', 'content' => 'Done.', 'meta' => ['type' => 'nl_reply']],
        ];

        self::assertSame('', AgentPromptBuilder::imageNoticeOwed($messages, 'Next question answered.'));
    }

    #[Test]
    public function aFailedOrNoticeFreeResultOwesNothing(): void
    {
        self::assertSame('', AgentPromptBuilder::imageNoticeOwed([$this->imageResult('')], 'Done.'));
        $failed = $this->imageResult(self::CAP);
        $failed['meta']['success'] = false;
        self::assertSame('', AgentPromptBuilder::imageNoticeOwed([$failed], 'Done.'));
    }

    #[Test]
    public function otherToolsNeverOweAnImageNotice(): void
    {
        $other = ['role' => 'assistant', 'content' => 'x', 'meta' => ['type' => 'tool_result', 'tool' => 'file_list', 'details' => ['notice' => self::CAP]]];

        self::assertSame('', AgentPromptBuilder::imageNoticeOwed([$other], 'Done.'));
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function imageResult(string $notice): array
    {
        return [
            'role' => 'assistant',
            'content' => '4 images saved.',
            'meta' => [
                'type' => 'tool_result',
                'tool' => 't3ai_generate_image',
                'success' => true,
                'details' => $notice !== '' ? ['fileUid' => 1, 'notice' => $notice] : ['fileUid' => 1],
            ],
        ];
    }
}
