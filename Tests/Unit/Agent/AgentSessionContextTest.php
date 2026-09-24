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
use NITSAN\NsT3AF\Agent\Controller\AgentAjaxController;
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
        $controller = (new \ReflectionClass(AgentAjaxController::class))->newInstanceWithoutConstructor();
        $message = new \ReflectionMethod(AgentAjaxController::class, 'continuationMessage');

        $applied = (string) $message->invoke($controller, ['outcome' => 'applied', 'label' => 'Write the meta description', 'result' => 'Saved.']);
        self::assertStringContainsString('confirmed "Write the meta description"', $applied);
        self::assertStringContainsString('Result: Saved.', $applied);
        self::assertStringContainsString('Continue with the remaining steps', $applied);

        $declined = (string) $message->invoke($controller, ['outcome' => 'declined', 'label' => 'Delete a redirect']);
        self::assertStringContainsString('declined "Delete a redirect"', $declined);
        self::assertStringContainsString('Do not repeat it', $declined);
    }

    #[Test]
    public function clarificationOptionsAreCleanedAndCapped(): void
    {
        $options = AskClarificationTool::normalizeOptions([' German ', 'German', '', ['x'], 'French', 3, str_repeat('a', 100), 'b', 'c', 'd', 'e']);

        self::assertSame(['German', 'French', '3', str_repeat('a', 80), 'b', 'c'], $options);
    }
}
