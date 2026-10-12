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
final class AgentPendingAttachFilesTest extends TestCase
{
    private const REQUEST = 'Create a text & media content element with the heading Our Treats and attach the existing images sys_file uid 3 (cake.png) and uid 11 (qa-rt-tsconfig.png)';

    #[Test]
    public function existingFilesNamedInTheRequestStillNeedAttachingAfterTheElementIsCreated(): void
    {
        $history = [self::userRequest(self::REQUEST), self::createdTextMedia(713)];

        self::assertSame([], AgentPromptBuilder::unattachedFileUids($history));
        self::assertSame([3, 11], AgentPromptBuilder::pendingAttachFileUids($history, self::REQUEST));
    }

    #[Test]
    public function namedFilesAttachedForThisRequestAreNoLongerPending(): void
    {
        $partly = [self::userRequest(self::REQUEST), self::createdTextMedia(713), self::attached(713, '3')];
        $all = [self::userRequest(self::REQUEST), self::createdTextMedia(713), self::attached(713, '3,11')];

        self::assertSame([11], AgentPromptBuilder::pendingAttachFileUids($partly, self::REQUEST));
        self::assertSame([], AgentPromptBuilder::pendingAttachFileUids($all, self::REQUEST));
    }

    #[Test]
    public function aNamedFileThatDoesNotExistIsNotPending(): void
    {
        $history = [
            self::userRequest(self::REQUEST),
            self::createdTextMedia(713),
            [
                'role' => 'assistant',
                'content' => 'There is no file with sys_file uid 11.',
                'meta' => ['type' => 'tool_result', 'tool' => 'file_reference_add', 'success' => false],
            ],
        ];

        self::assertSame([3], AgentPromptBuilder::pendingAttachFileUids($history, self::REQUEST));
    }

    #[Test]
    public function anAttachForAnEarlierRequestDoesNotCountForANewOne(): void
    {
        $earlier = 'Attach the existing image sys_file uid 3 to content element 500';
        $history = [
            self::userRequest($earlier),
            self::attached(500, '3'),
            self::userRequest(self::REQUEST),
            self::createdTextMedia(713),
        ];

        self::assertSame([3, 11], AgentPromptBuilder::pendingAttachFileUids($history, self::REQUEST));
    }

    #[Test]
    public function aRequestWithoutFileNamesOnlyKeepsSavedFilesPending(): void
    {
        $request = 'Create a text & media content element with an AI-generated image of a lighthouse';
        $history = [
            self::userRequest($request),
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => ['type' => 'tool_result', 'tool' => 't3ai_generate_image', 'success' => true, 'details' => ['fileUid' => 169]],
            ],
        ];

        self::assertSame([169], AgentPromptBuilder::pendingAttachFileUids($history, $request));
        self::assertSame([], AgentPromptBuilder::pendingAttachFileUids([self::userRequest('Rename the header of element 289')], 'Rename the header of element 289'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function userRequest(string $text): array
    {
        return ['role' => 'user', 'content' => $text, 'meta' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function createdTextMedia(int $uid): array
    {
        return [
            'role' => 'assistant',
            'content' => 'Done: Page Content "Our Treats" was created.',
            'meta' => [
                'type' => 'readback_result',
                'readback' => [['table' => 'tt_content', 'uid' => $uid, 'values' => ['CType' => 'textmedia', 'header' => 'Our Treats']]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attached(int $contentUid, string $fileUids): array
    {
        return [
            'role' => 'assistant',
            'content' => 'Attached.',
            'meta' => [
                'type' => 'tool_result',
                'tool' => 'file_reference_add',
                'success' => true,
                'details' => ['table' => 'tt_content', 'uid' => $contentUid, 'fileUids' => $fileUids],
            ],
        ];
    }
}
