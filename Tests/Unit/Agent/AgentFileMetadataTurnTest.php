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

use NITSAN\NsT3AF\Agent\Service\AgentRunner;
use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentFileMetadataTurnTest extends TestCase
{
    #[Test]
    public function aRequestedFileNameReplacesAGuessedUid(): void
    {
        $names = [629764 => '', 297 => 'ns_googledocs_246479.png'];
        $arguments = AgentToolTurnProcessor::withRequestedFileName(
            ['fileUid' => 629764],
            'Generate image metadata for ns_googledocs_629764.jpeg',
            static fn(int $uid): string => $names[$uid] ?? '',
        );

        self::assertSame(['fileUrl' => 'ns_googledocs_629764.jpeg'], $arguments);
    }

    #[Test]
    public function aPickedFileWithTheRequestedNameStays(): void
    {
        $arguments = AgentToolTurnProcessor::withRequestedFileName(
            ['fileUid' => 134, 'fileUrl' => 'Image-195.jpg'],
            'Generate alt text for Image-195.jpg',
            static fn(int $uid): string => $uid === 134 ? 'Image-195.jpg' : '',
        );

        self::assertSame(['fileUid' => 134], $arguments);
    }

    #[Test]
    public function theOpenQuestionIsTheNewestClarificationWithoutAReply(): void
    {
        $question = ['type' => 'clarification', 'fileMetaOverwrite' => ['fileUid' => 327]];
        $history = [
            ['role' => 'user', 'content' => 'Generate image metadata for photo.jpg'],
            ['role' => 'assistant', 'content' => 'Already has texts', 'meta' => $question],
        ];

        self::assertSame($question, AgentToolTurnProcessor::openQuestionMeta($history));

        $history[] = ['role' => 'user', 'content' => 'Keep current texts'];
        self::assertSame([], AgentToolTurnProcessor::openQuestionMeta($history));
    }

    #[Test]
    public function aClickedOptionMatchesItsLabel(): void
    {
        self::assertTrue(AgentToolTurnProcessor::sameChoice(' regenerate all. ', 'Regenerate all'));
        self::assertFalse(AgentToolTurnProcessor::sameChoice('Regenerate the title', 'Regenerate all'));
        self::assertFalse(AgentToolTurnProcessor::sameChoice('Regenerate all', ''));
    }

    #[Test]
    public function onlyTextFromTheRequestCountsAsTheEditorsText(): void
    {
        $request = 'Set the alt text of daisy.jpg to "A white  daisy in a meadow"';

        self::assertTrue(AgentToolTurnProcessor::writtenInRequest('A white daisy in a meadow', $request));
        self::assertFalse(AgentToolTurnProcessor::writtenInRequest('White daisy with a yellow center', $request));
    }

    #[Test]
    public function aClickedOptionOfTheImageTextQuestionNamesToolAndFile(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'now regenerate metadata', 'meta' => []],
            ['role' => 'assistant', 'content' => '…', 'meta' => [
                'type' => 'clarification',
                'fileMetaOverwrite' => ['fileUid' => 9, 'languageUid' => 2, 'choices' => ['regenerate' => 'Regenerate all', 'keep' => 'Keep current texts']],
            ]],
        ];
        $expected = ['tool' => 't3aa_update_file_metadata', 'arguments' => ['fileUid' => 9, 'languageUid' => 2]];

        self::assertSame($expected, AgentToolTurnProcessor::imageTextAnswer('Regenerate all', $history));
        self::assertSame($expected, AgentToolTurnProcessor::imageTextAnswer('Keep current texts', $history));
        self::assertNull(AgentToolTurnProcessor::imageTextAnswer('make the title shorter', $history));
        self::assertNull(AgentToolTurnProcessor::imageTextAnswer('Regenerate all', [
            ...$history,
            ['role' => 'user', 'content' => 'something else', 'meta' => []],
        ]));
        self::assertSame(
            ['tool' => 't3aa_get_file_metadata', 'arguments' => ['fileUid' => 134]],
            AgentToolTurnProcessor::imageTextAnswer('1:/a/Image-195.jpg [134]', [
                ['role' => 'assistant', 'content' => '…', 'meta' => ['type' => 'clarification', 'fileChoice' => true, 'fileChoiceTool' => 't3aa_get_file_metadata']],
            ]),
        );
    }

    #[Test]
    public function aPermissionRefusalInTheReplyIsRecognised(): void
    {
        self::assertTrue(AgentRunner::claimsPermissionRefusal(
            'You are not allowed to queue alt text drafts for images in the user_upload folder with your backend account.',
        ));
        self::assertTrue(AgentRunner::claimsPermissionRefusal(
            'Queuing alt text drafts is not available for your backend account.',
        ));
        self::assertFalse(AgentRunner::claimsPermissionRefusal(
            'I queued alt text drafts for 12 images in the user_upload folder. Review them below.',
        ));
    }

    #[Test]
    public function aFollowUpRightAfterImageTextsStillMeansImageTexts(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'generate metadata for deer.jpg', 'meta' => []],
            ['role' => 'assistant', 'content' => '', 'meta' => ['type' => 'suggestions', 'tool' => 't3aa_update_file_metadata']],
            ['role' => 'user', 'content' => '[The editor confirmed …]', 'meta' => ['hidden' => true]],
            ['role' => 'assistant', 'content' => 'Saved.', 'meta' => ['type' => 'nl_reply']],
            ['role' => 'user', 'content' => 'now Hindi', 'meta' => []],
            ['role' => 'assistant', 'content' => 'No site found', 'meta' => ['type' => 'tool_result', 'tool' => 'site_languages_list']],
        ];

        self::assertTrue(AgentRunner::usedRecently('t3aa_update_file_metadata', $history));
        self::assertTrue(AgentRunner::usedRecently('t3aa_update_file_metadata', [
            ['role' => 'assistant', 'content' => '', 'meta' => ['type' => 'clarification', 'tool' => 'ask_clarification', 'askedFor' => 't3aa_update_file_metadata']],
        ]));
        self::assertFalse(AgentRunner::usedRecently('t3aa_update_file_metadata', [
            ...$history,
            ['role' => 'user', 'content' => 'create a news article', 'meta' => []],
            ['role' => 'user', 'content' => 'add a teaser', 'meta' => []],
        ]));
    }
}
