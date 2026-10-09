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

use NITSAN\NsT3AF\Agent\Context\AgentContextPresenter;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Domain\Repository\AgentConversationRepository;
use NITSAN\NsT3AF\Mcp\Tool\Agent\AskClarificationTool;
use NITSAN\NsT3AF\Updates\AgentConversationSessionsUpdate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Context block for the model, conversation titles and history captions.
 *
 * @internal
 */
final class AgentSessionContextTest extends TestCase
{
    #[Test]
    public function promptBlockDescribesTheEditorsScreen(): void
    {
        $block = AgentContextPresenter::promptBlock(['details' => [
            'module' => ['route' => 'web_layout', 'label' => 'Page'],
            'page' => ['uid' => 49, 'title' => 'AI ChEddi', 'slug' => '/ai-cheddi', 'parent' => ['uid' => 45, 'title' => 'Home']],
            'language' => ['id' => 1, 'title' => 'German'],
            'siteLanguages' => [['id' => 0, 'title' => 'English'], ['id' => 1, 'title' => 'German']],
            'record' => ['table' => 'tt_content', 'uid' => 123, 'label' => 'Welcome', 'tableLabel' => 'Content element'],
            'workspace' => ['id' => 1, 'title' => 'AI Suite MCP', 'live' => false],
            'folder' => null,
        ]]);

        self::assertSame(implode("\n", [
            'Current context (use it when the editor says "this page", "here", "this element"):',
            '- Module: Page (web_layout)',
            '- Page: "AI ChEddi" [uid 49], slug /ai-cheddi, parent "Home" [45]',
            '- Language: German [1] (site languages: English [0], German [1])',
            '- Open record: tt_content [123] "Welcome"',
            '- Workspace: "AI Suite MCP" [1] — confirmed changes go to this draft workspace',
        ]), $block);
    }

    #[Test]
    public function promptBlockWithoutPageSaysSo(): void
    {
        $block = AgentContextPresenter::promptBlock(['details' => [
            'module' => ['route' => 'media_management', 'label' => 'Filelist'],
            'page' => null,
            'siteLanguages' => [],
            'folder' => ['storageUid' => 1, 'identifier' => '/user_upload/'],
            'workspace' => ['id' => 0, 'title' => 'Live', 'live' => true],
        ]]);

        self::assertStringContainsString('- Page: none selected', $block);
        self::assertStringContainsString('- Folder: 1:/user_upload/', $block);
        self::assertStringContainsString('- Workspace: Live — confirmed changes are visible on the website', $block);
        self::assertSame('', AgentContextPresenter::promptBlock(['pageId' => 3]));
    }

    #[Test]
    public function titleIsTheFirstUserMessageShortened(): void
    {
        $long = str_repeat('Erstelle Überschriften ', 5);

        self::assertSame(
            'Generate SEO for this page',
            AgentConversationSessionsUpdate::titleFromMessages([
                ['role' => 'assistant', 'content' => 'Hi'],
                ['role' => 'user', 'content' => "  Generate   SEO\nfor this page "],
            ], 49),
        );
        $title = AgentConversationSessionsUpdate::titleFromMessages([['role' => 'user', 'content' => $long]], 49);
        self::assertSame(60, mb_strlen($title));
        self::assertStringEndsWith('…', $title);
        self::assertSame('Conversation on page 12', AgentConversationSessionsUpdate::titleFromMessages([], 12));
    }

    #[Test]
    public function olderMessagesFromAnotherPageAreMarked(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $history = $builder->buildHistory([
            ['role' => 'user', 'content' => 'Translate this page', 'meta' => ['context' => ['pageId' => 12, 'pageTitle' => 'Landing']]],
            ['role' => 'assistant', 'content' => 'Done', 'meta' => ['type' => 'nl_reply']],
            ['role' => 'user', 'content' => 'And this one?', 'meta' => ['context' => ['pageId' => 49, 'pageTitle' => 'AI ChEddi']]],
            ['role' => 'assistant', 'content' => 'thinking…', 'meta' => ['type' => 'provider_thinking']],
        ], 49);

        self::assertSame([
            ['role' => 'user', 'content' => '[written on page "Landing" [12]] Translate this page'],
            ['role' => 'assistant', 'content' => 'Done'],
            ['role' => 'user', 'content' => 'And this one?'],
        ], $history);
    }

    #[Test]
    public function sessionIdsAreVersion4Uuids(): void
    {
        $uuid = AgentConversationRepository::newUuid();

        self::assertTrue(AgentConversationRepository::isValidUuid($uuid));
        self::assertSame('4', $uuid[14]);
        self::assertNotSame($uuid, AgentConversationRepository::newUuid());
        self::assertFalse(AgentConversationRepository::isValidUuid("x' OR 1=1"));
        self::assertFalse(AgentConversationRepository::isValidUuid(''));
    }

    #[Test]
    public function continuationTellsTheModelWhatTheEditorDecided(): void
    {
        $applied = AgentPromptBuilder::continuationMessage(['outcome' => 'applied', 'label' => 'Write the meta description', 'result' => 'Saved.']);
        self::assertStringContainsString('confirmed "Write the meta description"', $applied);
        self::assertStringContainsString('Result: Saved.', $applied);
        self::assertStringContainsString('Continue with the remaining steps', $applied);
        self::assertStringContainsString('Do not create another content element of the same CType', $applied);
        self::assertStringContainsString('Never claim a file or image is attached', $applied);
        self::assertStringNotContainsString('Remaining: attach fileUid', $applied);

        $declined = AgentPromptBuilder::continuationMessage(['outcome' => 'declined', 'label' => 'Delete a redirect']);
        self::assertStringContainsString('declined "Delete a redirect"', $declined);
        self::assertStringContainsString('Do not repeat it', $declined);
    }

    #[Test]
    public function continuationRemindsToAttachAnUnattachedFileToNewContent(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'autoRan' => false,
                    'details' => ['fileUid' => 87, 'fileName' => '1790575760.png'],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied 3 of 3 fields.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 477, 'values' => ['CType' => 'textmedia', 'header' => 'Error vs Log']]],
                ],
            ],
        ];

        $message = AgentPromptBuilder::continuationMessage(
            ['outcome' => 'applied', 'label' => 'Change a record', 'result' => 'Applied 3 of 3 fields.'],
            $history,
        );

        self::assertStringContainsString('Remaining: attach fileUid 87 to tt_content uid 477', $message);
        self::assertStringContainsString('Do not confirm completion until that succeeds', $message);
        self::assertStringContainsString('CType "textmedia"', $message);
        self::assertStringContainsString('do not create another "textmedia"', $message);
        self::assertSame([87], AgentPromptBuilder::unattachedFileUids($history));
        self::assertSame(477, AgentPromptBuilder::latestAppliedContentElementUid($history));
        self::assertSame('textmedia', AgentPromptBuilder::lastAppliedTtContentCType($history));
    }

    #[Test]
    public function pendingAttachPrefersTextMediaOverALaterPlainTextElement(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 95],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 481, 'values' => ['CType' => 'textmedia', 'header' => 'Demo']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 482, 'values' => ['CType' => 'text', 'header' => 'Why Progress matters']]],
                ],
            ],
        ];

        self::assertSame(481, AgentPromptBuilder::latestAppliedContentElementUid($history));
        self::assertStringContainsString(
            'attach fileUid 95 to tt_content uid 481',
            AgentPromptBuilder::pendingImageAttachNote($history),
        );
    }

    #[Test]
    public function pendingAttachDoesNotTargetPlainTextWhenTextMediaIsStillMissing(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 114],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 521, 'values' => ['CType' => 'text', 'header' => 'Benefits']]],
                ],
            ],
        ];

        self::assertNull(AgentPromptBuilder::latestAppliedContentElementUid($history));
        $note = AgentPromptBuilder::pendingImageAttachNote($history);
        self::assertStringContainsString('CType textmedia', $note);
        self::assertStringContainsString('fileUid 114', $note);
        self::assertStringNotContainsString('tt_content uid 521', $note);
    }

    #[Test]
    public function unattachedFileFromEarlierDoesNotBlockAnUnrelatedRequest(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 114],
                ],
            ],
            ['role' => 'user', 'content' => 'Rename the header of element 5 to Welcome.', 'meta' => ['type' => 'message']],
        ];

        self::assertFalse(AgentPromptBuilder::hasBlockingRemainingWork($history, [], 'Rename the header of element 5 to Welcome.'));
        self::assertTrue(AgentPromptBuilder::hasBlockingRemainingWork($history, [], 'Now attach the image to the page.'));
    }

    #[Test]
    public function continuationRemindsToAttachImageWhenTextMediaHasNoFileYet(): void
    {
        $history = [
            [
                'role' => 'user',
                'content' => 'Add Text & Media with a relevant image about AI.',
                'meta' => ['type' => 'message'],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 546, 'values' => ['CType' => 'textmedia', 'header' => 'AI']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Bullets applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 548, 'values' => ['CType' => 'bullets']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'plan',
                'meta' => ['plan' => [
                    ['title' => 'Text & Media', 'status' => 'completed'],
                    ['title' => 'Attach an image to Text & Media element', 'status' => 'in_progress'],
                ]],
            ],
        ];

        $message = AgentPromptBuilder::continuationMessage(
            ['outcome' => 'applied', 'label' => 'Change a record', 'result' => 'Applied 3 of 3 fields.'],
            $history,
        );

        self::assertStringContainsString('Attach an image', $message);
        self::assertStringContainsString('file_reference_add', $message);
        self::assertSame(546, AgentPromptBuilder::unattachedMediaContentElementUid($history));
    }

    #[Test]
    public function continuationAfterImageOnlyRemindsToCreateTextMediaBeforeAttach(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'autoRan' => false,
                    'details' => ['fileUid' => 114],
                ],
            ],
        ];

        $message = AgentPromptBuilder::continuationMessage(
            ['outcome' => 'applied', 'label' => 'Generate an image', 'result' => 'Image saved.'],
            $history,
        );

        self::assertStringContainsString('CType textmedia', $message);
        self::assertStringContainsString('fileUid 114', $message);
        self::assertStringNotContainsString('file_reference_add to tt_content uid', $message);
    }

    #[Test]
    public function continuationSkipsAttachReminderWhenFileReferenceAlreadySucceeded(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 87],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 477, 'values' => ['header' => 'Error vs Log']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Attached.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 'file_reference_add',
                    'success' => true,
                    'details' => ['table' => 'tt_content', 'uid' => 477, 'fileUids' => '87', 'referenceUids' => [1]],
                ],
            ],
        ];

        $message = AgentPromptBuilder::continuationMessage(
            ['outcome' => 'applied', 'label' => 'Attach file', 'result' => 'Attached.'],
            $history,
        );

        self::assertStringNotContainsString('Remaining: attach fileUid', $message);
        self::assertSame([], AgentPromptBuilder::unattachedFileUids($history));
    }

    #[Test]
    public function clarificationOptionsAreCleanedAndCapped(): void
    {
        $options = AskClarificationTool::normalizeOptions([' German ', 'German', '', ['x'], 'French', 3, str_repeat('a', 100), 'b', 'c', 'd', 'e']);

        self::assertSame(['German', 'French', '3', str_repeat('a', 80), 'b', 'c'], $options);
    }

    #[Test]
    public function choicesPastedIntoTheQuestionBecomeOptions(): void
    {
        [$question, $options] = AskClarificationTool::liftInlineOptions('Which element? ["RT A", "RT B"]', []);

        self::assertSame('Which element?', $question);
        self::assertSame(['RT A', 'RT B'], $options);
        self::assertSame(['Which?', ['X']], AskClarificationTool::liftInlineOptions('Which?', ['X']));
    }

    #[Test]
    public function historyReplaysCardsAsShortNotes(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $history = $builder->buildHistory([
            ['role' => 'user', 'content' => 'Improve the SEO', 'meta' => []],
            ['role' => 'assistant', 'content' => 'Read it.', 'meta' => ['type' => 'tool_result', 'toolCallLabel' => 'Read the page']],
            ['role' => 'assistant', 'content' => 'New title.', 'meta' => ['type' => 'inline_draft', 'draft' => ['editorLabel' => 'Change a record', 'applied' => true]]],
            ['role' => 'assistant', 'content' => 'Oops', 'meta' => ['type' => 'error']],
            ['role' => 'assistant', 'content' => 'Which language?', 'meta' => ['type' => 'clarification', 'options' => ['German', 'French']]],
        ], 1);

        self::assertSame([
            ['role' => 'user', 'content' => 'Improve the SEO'],
            ['role' => 'assistant', 'content' => '[Read the page] Read it.'],
            ['role' => 'assistant', 'content' => '[Prepared change: Change a record — applied] New title.'],
            ['role' => 'assistant', 'content' => 'Which language? (options: German, French)'],
        ], $history);
    }

    #[Test]
    public function historyTellsTheModelThatAFailedCardWouldFailAgain(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $history = $builder->buildHistory([[
            'role' => 'assistant',
            'content' => 'upload from https://no-such-host.invalid/a.jpg to user_upload',
            'meta' => ['type' => 'inline_draft', 'draft' => [
                'editorLabel' => 'Upload a file from a URL',
                'failed' => true,
                'failureMessage' => 'Could not resolve host "no-such-host.invalid".',
            ]],
        ]]);

        self::assertSame(
            '[Prepared change: Upload a file from a URL — failed and would fail again with the same arguments: Could not resolve host "no-such-host.invalid".] upload from https://no-such-host.invalid/a.jpg to user_upload',
            $history[0]['content'],
        );
    }

    #[Test]
    public function historyOmitsDraftReviewBoilerplateSoModelsDoNotParrotIt(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $history = $builder->buildHistory([[
            'role' => 'assistant',
            'content' => 'Review the proposed changes for Change a record before anything is written.',
            'meta' => ['type' => 'inline_draft', 'draft' => ['editorLabel' => 'Change a record', 'applied' => true]],
        ]]);

        self::assertSame(
            [['role' => 'assistant', 'content' => '[Prepared change: Change a record — applied]']],
            $history,
        );
        self::assertSame(
            '[Prepared change: Change a record — applied]',
            AgentPromptBuilder::preparedChangeHistoryNote(
                'Change a record',
                'applied',
                'Review the proposed changes for Change a record before anything is written.',
            ),
        );
        self::assertTrue(AgentPromptBuilder::isCardHistoryEcho(
            '[Prepared change: Change a record — applied] Review the proposed changes for Change a record before anything is written.',
        ));
        self::assertTrue(AgentPromptBuilder::isDraftReviewBoilerplate(
            'Review the proposed changes for Change a record before anything is written.',
        ));
        self::assertFalse(AgentPromptBuilder::isCardHistoryEcho('All requested content elements have been added.'));
    }

    #[Test]
    public function bothWordingsOfTheDraftReviewLineAreRecognisedAsBoilerplate(): void
    {
        self::assertTrue(AgentPromptBuilder::isDraftReviewBoilerplate('Review this change before anything is written.'));
        self::assertTrue(AgentPromptBuilder::isCardHistoryEcho('Review this change before anything is written'));
        self::assertTrue(AgentPromptBuilder::isDraftReviewBoilerplate('Review the proposed changes for Rename Page before anything is written.'));
        self::assertFalse(AgentPromptBuilder::isDraftReviewBoilerplate('Please review this change with the team.'));
    }

    #[Test]
    public function anAppliedChangeTellsTheModelTheNewRecordUid(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $history = $builder->buildHistory([[
            'role' => 'assistant',
            'content' => 'Applied 2 of 2 fields.',
            'meta' => ['type' => 'readback_result', 'readback' => [['table' => 'pages', 'uid' => 145, 'values' => ['title' => 'AI Universe vs Symfony', 'pid' => 12]]]],
        ]]);

        self::assertSame('[Applied] Applied 2 of 2 fields. Records: pages uid 145 "AI Universe vs Symfony" (pid 12).', $history[0]['content']);
    }

    #[Test]
    public function aConfirmedToolResultTellsTheModelTheNewIds(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $history = $builder->buildHistory([[
            'role' => 'assistant',
            'content' => 'Content element created.',
            'meta' => ['type' => 'tool_result', 'toolCallLabel' => 'Create a content element', 'autoRan' => false, 'details' => ['record' => ['table' => 'tt_content', 'uid' => 812, 'pid' => 128], 'fileUid' => 55]],
        ]]);

        self::assertSame('[Create a content element] Content element created. Ids: fileUid 55, table tt_content, uid 812, pid 128.', $history[0]['content']);
        self::assertStringContainsString(
            'uid 812',
            AgentPromptBuilder::continuationMessage(['outcome' => 'applied', 'label' => 'Create a content element', 'result' => 'Created.'], [
                ['role' => 'assistant', 'content' => 'Created.', 'meta' => ['type' => 'tool_result', 'autoRan' => false, 'details' => ['uid' => 812, 'pid' => 128]]],
            ]),
        );
    }

    #[Test]
    public function historyKeepsTheNewestMessagesWithinTheBudget(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $messages = [];
        for ($i = 1; $i <= 10; ++$i) {
            $messages[] = ['role' => $i % 2 === 1 ? 'user' : 'assistant', 'content' => sprintf('m%02d ', $i) . str_repeat('x', 296), 'meta' => []];
        }

        $history = $builder->buildHistory($messages, 0, 1000);

        self::assertSame('[7 older message(s) of this conversation are left out.]', $history[0]['content']);
        self::assertCount(4, $history);
        self::assertStringStartsWith('m08', $history[1]['content']);
        self::assertStringStartsWith('m10', $history[3]['content']);
    }

    #[Test]
    public function aSummaryReplacesTheOlderMessages(): void
    {
        $builder = (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor();
        $history = $builder->buildHistory([
            ['role' => 'user', 'content' => 'very old', 'meta' => []],
            ['role' => 'assistant', 'content' => '- first summary', 'meta' => ['type' => 'summary']],
            ['role' => 'user', 'content' => 'old', 'meta' => []],
            ['role' => 'assistant', 'content' => '- the editor translated page 12', 'meta' => ['type' => 'summary']],
            ['role' => 'user', 'content' => 'And now page 14?', 'meta' => []],
        ]);

        self::assertSame([
            ['role' => 'user', 'content' => "[Summary of the earlier conversation]\n- the editor translated page 12"],
            ['role' => 'user', 'content' => 'And now page 14?'],
        ], $history);
    }

    #[Test]
    public function continuationAfterAnEditAsksForNothingNew(): void
    {
        $history = [['role' => 'user', 'content' => 'Change the header of content element uid 153 to the text QA Phantom Check R2.', 'meta' => []]];
        $message = AgentPromptBuilder::continuationMessage(
            ['outcome' => 'applied', 'label' => 'Change header', 'result' => 'Saved.'],
            $history,
        );

        self::assertStringContainsString('do not create any record or content element', $message);
        self::assertStringNotContainsString('prepare the next distinct type', $message);
    }

    #[Test]
    public function aReplySentTwiceIsShownOnce(): void
    {
        $text = 'You are not allowed to change this kind of record with your backend account.';

        self::assertSame($text, AgentPromptBuilder::collapseRepeatedReply($text . $text));
        self::assertSame($text, AgentPromptBuilder::collapseRepeatedReply($text . ' ' . $text));
        self::assertSame($text, AgentPromptBuilder::collapseRepeatedReply($text . "\n\n" . $text));
        self::assertSame($text . ' Ask an administrator.', AgentPromptBuilder::collapseRepeatedReply($text . ' Ask an administrator.'));
        self::assertSame('Done.', AgentPromptBuilder::collapseRepeatedReply('Done.'));
    }

    #[Test]
    public function aLeakedToolCallIsNotShownToTheEditor(): void
    {
        $leaked = '{"pageId":99999} to=t3ai_generate_all_seo 天天中彩票实名_code:46 】!【I can’t generate SEO texts for page 99999 because it does not exist.';

        self::assertSame(
            'I can’t generate SEO texts for page 99999 because it does not exist.',
            AgentPromptBuilder::stripLeakedToolCall($leaked),
        );
        self::assertSame(99999, AgentPromptBuilder::leakedMissingPageUid($leaked));
        self::assertSame(
            'The SEO card is ready for this page.',
            AgentPromptBuilder::stripLeakedToolCall('{"pageId":5} to=t3ai_generate_all_seo The SEO card is ready for this page.'),
        );
        self::assertSame(0, AgentPromptBuilder::leakedMissingPageUid('{"pageId":5} to=t3ai_generate_all_seo The SEO card is ready for this page.'));
        self::assertSame('Page 2 is ready.', AgentPromptBuilder::stripLeakedToolCall('Page 2 is ready.'));
    }

    #[Test]
    public function theFollowUpAfterAConfirmedCardIsNotARequest(): void
    {
        $followUp = '[The editor confirmed "Create Text element" and it was applied.] Continue.';

        self::assertSame([], \NITSAN\NsT3AF\Agent\Service\AgentRequestChecklist::parse($followUp));
        self::assertSame(
            'Change the header of element 5 to Hello.',
            AgentPromptBuilder::latestUserRequestText([
                ['role' => 'user', 'content' => 'Change the header of element 5 to Hello.', 'meta' => []],
                ['role' => 'user', 'content' => $followUp, 'meta' => []],
            ]),
        );
    }

    #[Test]
    public function anEmptyListInTheQuestionIsDropped(): void
    {
        [$question, $options] = AskClarificationTool::liftInlineOptions('[]I need the target language confirmed.', []);

        self::assertSame('I need the target language confirmed.', $question);
        self::assertSame([], $options);
    }
}
