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
}
