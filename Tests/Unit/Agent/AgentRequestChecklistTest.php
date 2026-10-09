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
use NITSAN\NsT3AF\Agent\Service\AgentRequestChecklist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AgentRequestChecklist::class)]
final class AgentRequestChecklistTest extends TestCase
{
    #[Test]
    public function rewritingTheTextOfAnExistingBlockIsNotACreateRequest(): void
    {
        self::assertSame([], AgentRequestChecklist::parse('Rewrite the text of the block "QA visible element" so it sounds friendlier for customers.'));
        self::assertSame([], AgentRequestChecklist::parse('Rewrite the text of this text element so it is shorter.'));
        self::assertSame([], AgentRequestChecklist::parse('Formuliere den Text des Textblocks freundlicher um und kürze ihn.'));
        // A real create request still counts.
        self::assertNotSame([], AgentRequestChecklist::parse('Add a text block with our opening hours.'));
    }

    #[Test]
    public function parseNumberedAiPromptIntoCreatesAndAttach(): void
    {
        $message = <<<'TXT'
Add 3 different TYPO3 Core content elements to this page. Use the topic **"Artificial Intelligence in Modern Web Development"** for all content.

1. **Text & Media** – Add a suitable heading, 2–3 paragraphs explaining how AI is changing modern web development, and a relevant image.
2. **Text** – Add a heading and a few paragraphs covering the benefits of using AI in web development.
3. **Bullets** – Add a heading and a bullet-point list of 4–5 key ways AI can help web developers.
TXT;
        $steps = AgentRequestChecklist::parse($message);
        self::assertSame(['create', 'create', 'create', 'attach_image'], array_column($steps, 'kind'));
        self::assertSame(['textmedia', 'text', 'bullets'], array_column(array_slice($steps, 0, 3), 'cType'));
        self::assertSame('in_progress', $steps[0]['status']);
        self::assertSame('pending', $steps[3]['status']);
    }

    #[Test]
    public function parseSustainablePromptWithImagesAndTables(): void
    {
        $message = <<<'TXT'
1. **Text** – Add a suitable heading and 2–3 paragraphs.
2. **Images** – Add a heading, a short description, and a relevant image.
3. **Tables** – Add a heading and a simple comparison table.
TXT;
        $steps = AgentRequestChecklist::parse($message);
        self::assertSame(['text', 'textpic', 'table'], array_column(array_filter($steps, static fn(array $s): bool => ($s['kind'] ?? '') === 'create'), 'cType'));
        self::assertContains('attach_image', array_column($steps, 'kind'));
    }

    #[Test]
    public function parseUnnumberedTwoTypes(): void
    {
        $steps = AgentRequestChecklist::parse(
            'Add a Text & Media element and a separate Text element about AI on this page',
        );
        self::assertSame(['textmedia', 'text'], array_column(array_filter($steps, static fn(array $s): bool => ($s['kind'] ?? '') === 'create'), 'cType'));
    }

    #[Test]
    public function reconcileMarksOnlyAppliedCTypesAndKeepsAttachOpen(): void
    {
        $request = "1. **Text & Media** – with an image.\n2. **Text** – benefits.\n3. **Bullets** – list.";
        $history = [
            ['role' => 'user', 'content' => $request, 'meta' => []],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 1, 'values' => ['CType' => 'textmedia']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 2, 'values' => ['CType' => 'text']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 3, 'values' => ['CType' => 'bullets']]],
                ],
            ],
        ];

        $steps = AgentRequestChecklist::reconcile($history, $request);
        self::assertTrue(AgentRequestChecklist::hasOpen($steps));
        self::assertSame('completed', $steps[0]['status']);
        self::assertSame('completed', $steps[1]['status']);
        self::assertSame('completed', $steps[2]['status']);
        self::assertSame('attach_image', $steps[3]['kind']);
        self::assertSame('in_progress', $steps[3]['status']);
        self::assertStringContainsString('file_reference_add', AgentRequestChecklist::nextActionHint($steps));
        self::assertTrue(AgentPromptBuilder::hasBlockingRemainingWork($history, [], $request));
    }

    #[Test]
    public function reconcileIgnoresEarlierRequestCreates(): void
    {
        $history = [
            ['role' => 'user', 'content' => "1. **Text** – old\n2. **Tables** – old", 'meta' => []],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 10, 'values' => ['CType' => 'text']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 11, 'values' => ['CType' => 'table']]],
                ],
            ],
            ['role' => 'user', 'content' => "1. **Text & Media** – with image\n2. **Text** – body\n3. **Bullets** – list", 'meta' => []],
        ];

        $steps = AgentRequestChecklist::reconcile($history);
        self::assertSame(['textmedia', 'text', 'bullets'], array_column(array_slice($steps, 0, 3), 'cType'));
        self::assertSame('in_progress', $steps[0]['status']);
        self::assertSame('pending', $steps[1]['status']);
    }

    #[Test]
    public function emptyOrSimpleMessagesYieldNoChecklist(): void
    {
        self::assertSame([], AgentRequestChecklist::parse('What is this page about?'));
        self::assertSame([], AgentRequestChecklist::parse('Translate this page to German'));
        self::assertSame([], AgentRequestChecklist::parse('Rename the header of element 5 to Welcome.'));
        self::assertNotSame([], AgentRequestChecklist::parse('Add a header and change the text.'));
    }

    #[Test]
    public function aQuestionAboutContentTypesIsNotARequestToCreateThem(): void
    {
        self::assertSame([], AgentRequestChecklist::parse(
            'Which content element types can an editor create on this page? Answer as a short numbered list with the type names in bold.',
        ));
    }

    #[Test]
    public function readOnlyRequestsNamingAContentTypeYieldNoChecklist(): void
    {
        foreach ([
            'list the headers on this page',
            'Show me all tables on this page',
            'How many text elements does this page have?',
            'Zeige alle Überschriften dieser Seite',
        ] as $message) {
            self::assertSame([], AgentRequestChecklist::parse($message), $message);
        }
    }

    #[Test]
    public function createRequestsStillYieldAChecklist(): void
    {
        $steps = AgentRequestChecklist::parse('Add a header and a table to this page');
        self::assertSame(['header', 'table'], array_column($steps, 'cType'));
    }

    #[Test]
    public function aHeaderNamedAsAFieldOfTheElementIsNotASecondElement(): void
    {
        $steps = AgentRequestChecklist::parse('Create a new text element on this page with the header QA R5 create gap and the subheader Sub.');

        self::assertSame(['Create Text element'], array_column($steps, 'title'));
        self::assertSame([], AgentRequestChecklist::parse('Erstelle ein Textelement mit der Überschrift QA Test.'));
        // The value itself may contain the word "header" (ticket 14zervyucgh).
        $steps = AgentRequestChecklist::parse('Create a new Text content element on this page with the header QA Header Check Cx and the text Hello');
        self::assertSame(['Create Text element'], array_column($steps, 'title'));
        self::assertSame(
            ['Create Header element', 'Create Text element'],
            array_column(AgentRequestChecklist::parse('Add a header and a text element to this page'), 'title'),
        );
    }

    #[Test]
    public function aShortTextOrAttachedImagesStayFieldsOfTextAndMedia(): void
    {
        $prompts = [
            'Create a text & media content element with an AI-generated image of a red lighthouse at sunset and a short text about coastal travel',
            'Create a text & media content element with the heading Our Treats and attach the existing images sys_file uid 3 (cake.png) and uid 4 (csm_cabin.png)',
            'Create a text & media content element with the heading Frontend Check and a short sentence, attach the existing image sys_file uid 145 and set a sensible alt text',
        ];
        foreach ($prompts as $prompt) {
            $steps = AgentRequestChecklist::parse($prompt);
            self::assertSame(
                ['textmedia'],
                array_column(array_filter($steps, static fn(array $s): bool => ($s['kind'] ?? '') === 'create'), 'cType'),
                $prompt,
            );
            self::assertContains('attach_image', array_column($steps, 'kind'), $prompt);
        }

        $steps = AgentRequestChecklist::parse(
            'Create two elements: a text & media element and a text element about the coast',
        );
        self::assertSame(
            ['textmedia', 'text'],
            array_column(array_filter($steps, static fn(array $s): bool => ($s['kind'] ?? '') === 'create'), 'cType'),
        );
    }

    #[Test]
    public function germanTextAndMediaIsOneMediaElement(): void
    {
        $creates = static fn(string $prompt): array => array_column(
            array_filter(AgentRequestChecklist::parse($prompt), static fn(array $s): bool => ($s['kind'] ?? '') === 'create'),
            'cType',
        );

        self::assertSame(['textmedia'], $creates('Erstelle ein Text & Medien Element mit Bildern über unsere Torten und einem kurzen Text'));
        self::assertSame(['textmedia'], $creates('Erstelle ein Text und Medien Element mit der Überschrift Unsere Torten'));
        self::assertSame(['textpic'], $creates('Erstelle ein Text & Bilder Element mit der Überschrift Unsere Torten'));
        self::assertSame(['textmedia', 'text'], $creates('Erstelle ein Text & Medien Element und ein separates Text Element'));
    }

    #[Test]
    public function aTypedContinueKeepsTheRequestItContinues(): void
    {
        $request = 'Create a text & media content element with the heading Our Treats and attach the existing image sys_file uid 145';
        $history = [
            ['role' => 'user', 'content' => $request, 'meta' => ['type' => 'message']],
            ['role' => 'assistant', 'content' => 'I am not finished yet.', 'meta' => ['type' => 'nl_reply']],
            ['role' => 'user', 'content' => 'continue', 'meta' => ['type' => 'message']],
        ];

        self::assertSame($request, AgentPromptBuilder::latestUserRequestText($history));
        self::assertTrue(AgentPromptBuilder::isGoOnReply('Weiter!'));
        self::assertFalse(AgentPromptBuilder::isGoOnReply('continue with the table element'));
    }

    #[Test]
    public function attachHintNamesTheFilesTheEditorChose(): void
    {
        $request = 'Create new text & media content element with the heading Our Treats and attach the existing images sys_file uid 114 (ebnodwdxc.png) and uid 115 (Migrate_From_Wordpress_to_TYPO3.png).';
        $steps = AgentRequestChecklist::reconcile([
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 250, 'values' => ['CType' => 'textmedia']]],
                ],
            ],
        ], $request);

        $block = AgentRequestChecklist::promptBlock($steps, $request);
        self::assertStringContainsString('file_reference_add with fileUids "114,115"', $block);
        self::assertStringContainsString('do not upload, download or generate', $block);
        self::assertStringContainsString('generate or upload an image', AgentRequestChecklist::promptBlock($steps, 'Create a text & media element with an image'));
    }

    #[Test]
    public function anAltTextRequestStaysOpenUntilTheAltTextIsSet(): void
    {
        $request = 'Create a text & media content element with the heading Frontend Check and a short sentence, attach the existing image sys_file uid 145 and set a sensible alt text for the image';
        $created = [
            'role' => 'assistant',
            'content' => 'Applied.',
            'meta' => ['type' => 'readback_result', 'readback' => [['table' => 'tt_content', 'uid' => 172, 'values' => ['CType' => 'textmedia']]]],
        ];
        $attached = static fn(array $details): array => [
            'role' => 'assistant',
            'content' => 'Attached.',
            'meta' => ['type' => 'tool_result', 'tool' => 'file_reference_add', 'success' => true, 'details' => ['table' => 'tt_content', 'uid' => 172, 'referenceUids' => [78], ...$details]],
        ];

        $steps = AgentRequestChecklist::parse($request);
        self::assertSame(['create', 'attach_image', 'alt_text'], array_column($steps, 'kind'));
        self::assertStringContainsString('Pass alternative', AgentRequestChecklist::promptBlock(AgentRequestChecklist::reconcile([$created], $request), $request));

        $withoutAlt = AgentRequestChecklist::reconcile([$created, $attached([])], $request);
        self::assertSame(['completed', 'completed', 'in_progress'], array_column($withoutAlt, 'status'));
        self::assertTrue(AgentPromptBuilder::hasBlockingRemainingWork([$created, $attached([])], [], $request));
        self::assertStringContainsString('tell the editor the alt text is not set', AgentRequestChecklist::nextActionHint($withoutAlt));

        $withAlt = AgentRequestChecklist::reconcile([$created, $attached(['alternative' => 'Snowy mountain lake'])], $request);
        self::assertFalse(AgentRequestChecklist::hasOpen($withAlt));

        $generated = [
            'role' => 'assistant',
            'content' => 'Image generated.',
            'meta' => ['type' => 'tool_result', 'tool' => 't3ai_generate_image', 'success' => true, 'details' => ['fileUid' => 150, 'altText' => 'Team meeting in a bright office']],
        ];
        self::assertFalse(AgentRequestChecklist::hasOpen(AgentRequestChecklist::reconcile([$created, $generated, $attached([])], $request)));

        self::assertSame(['create', 'attach_image'], array_column(AgentRequestChecklist::parse('Create a text & media element with an image of a lake'), 'kind'));
        self::assertContains('alt_text', array_column(AgentRequestChecklist::parse('Erstelle ein Text & Media Element mit einem Bild und einem passenden Alternativtext'), 'kind'));
    }

    #[Test]
    public function aDeclinedCreateIsNotPushedAgain(): void
    {
        $request = 'Create a text & media content element with the heading Preview Test and attach the existing images sys_file uid 145 and 5';
        $declinedCard = static fn(string $tool, string $action, array $fields): array => [
            'role' => 'assistant',
            'content' => 'Draft discarded. Nothing was written.',
            'meta' => ['type' => 'inline_draft', 'tool' => $tool, 'draft' => ['tool' => $tool, 'action' => $action, 'discarded' => true, 'fields' => $fields]],
        ];
        $history = [
            ['role' => 'user', 'content' => $request, 'meta' => ['type' => 'message']],
            $declinedCard('write_table', 'create', [
                ['table' => 'tt_content', 'field' => 'colPos', 'proposed' => '0'],
                ['table' => 'tt_content', 'field' => 'CType', 'proposed' => 'textmedia'],
                ['table' => 'tt_content', 'field' => 'header', 'proposed' => 'Preview Test'],
            ]),
            ['role' => 'user', 'content' => '[The editor declined "Change a record". Nothing was written.] Do not repeat it.', 'meta' => ['type' => 'continuation', 'hidden' => true]],
        ];

        $steps = AgentRequestChecklist::reconcile($history, $request);

        self::assertSame(['failed', 'failed'], array_column($steps, 'status'));
        self::assertFalse(AgentPromptBuilder::hasBlockingRemainingWork($history, [], $request));
        self::assertStringContainsString(
            'nothing else remains',
            AgentPromptBuilder::continuationMessage(['outcome' => 'declined', 'label' => 'Change a record'], array_slice($history, 0, 2)),
        );

        $created = ['role' => 'assistant', 'content' => 'Applied.', 'meta' => ['type' => 'readback_result', 'readback' => [['table' => 'tt_content', 'uid' => 260, 'values' => ['CType' => 'textmedia']]]]];
        $attachDeclined = $declinedCard('file_reference_add', '', []);
        self::assertSame(['completed', 'failed'], array_column(AgentRequestChecklist::reconcile([$history[0], $created, $attachDeclined], $request), 'status'));
    }

    #[Test]
    public function filesALookupOnlyShowedAreNotWaitingToBeAttached(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'content' => 'This folder contains 2 file(s).',
                'meta' => ['type' => 'tool_result', 'tool' => 'file_list', 'severity' => 'read', 'success' => true, 'previews' => [['fileUid' => 128], ['fileUid' => 129]]],
            ],
            [
                'role' => 'assistant',
                'content' => 'Image generated.',
                'meta' => ['type' => 'tool_result', 'tool' => 't3ai_generate_image', 'severity' => 'write', 'success' => true, 'previews' => [['fileUid' => 150]]],
            ],
        ];

        self::assertSame([150], AgentPromptBuilder::unattachedFileUids($history));
    }
}
