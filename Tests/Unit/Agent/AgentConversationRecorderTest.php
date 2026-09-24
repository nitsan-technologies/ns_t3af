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

use NITSAN\NsT3AF\Agent\Service\AgentConversationRecorder;
use NITSAN\NsT3AF\Agent\Service\AgentConversationSummarizer;
use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The server keeps the stored conversation in step with card actions.
 *
 * @internal
 */
final class AgentConversationRecorderTest extends TestCase
{
    use AgentTranslatorTrait;

    private AgentConversationRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $translator = $this->createAgentTranslator();
        $this->recorder = new AgentConversationRecorder($translator, new AgentToolEditorLabelService($translator));
    }

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function appliedRecordDraftIsMarkedAndGetsAReadback(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Fix the description', 'meta' => []],
            ['role' => 'assistant', 'content' => 'New description.', 'meta' => ['type' => 'inline_draft', 'correlationId' => 'c1', 'draft' => [
                'draftId' => 'd1',
                'fields' => [['key' => 'pages:1:description'], ['key' => 'pages:1:title']],
            ]]],
        ];

        $updated = $this->recorder->applied($messages, 'd1', [
            'appliedCount' => 1,
            'totalCount' => 2,
            'readback' => [['table' => 'pages', 'uid' => 1, 'values' => ['description' => 'x']]],
            'changeId' => 'ch1',
        ], ['message' => 'Applied 1 of 2 fields.', 'links' => [['record' => 'Page', 'links' => []]], 'keptFieldKeys' => ['pages:1:description']]);

        self::assertCount(3, $updated);
        self::assertTrue($updated[1]['meta']['draft']['applied']);
        self::assertTrue($updated[1]['meta']['draft']['fields'][0]['kept']);
        self::assertFalse($updated[1]['meta']['draft']['fields'][1]['kept']);
        self::assertSame('readback_result', $updated[2]['meta']['type']);
        self::assertSame('Applied 1 of 2 fields.', $updated[2]['content']);
        self::assertSame('ch1', $updated[2]['meta']['changeId']);
        self::assertSame('c1', $updated[2]['meta']['correlationId']);
        self::assertCount(1, $updated[2]['meta']['links']);
    }

    #[Test]
    public function confirmedToolCardBecomesItsResult(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => 'Translate the whole page.', 'meta' => ['type' => 'inline_draft', 'fromRunner' => true, 'draft' => [
                'draftId' => 'd2',
                'kind' => 'tool_confirmation',
                'tool' => 't3ai_translate_page',
                'severity' => 'write',
            ]]],
        ];

        $updated = $this->recorder->applied($messages, 'd2', [
            'tool' => 't3ai_translate_page',
            'presentation' => ['content' => 'Translated page "Home".', 'success' => true, 'facts' => [], 'details' => ['pageId' => 1]],
        ], ['message' => 'Tool ran successfully.']);

        self::assertCount(1, $updated);
        self::assertSame('Translated page "Home".', $updated[0]['content']);
        self::assertSame('tool_result', $updated[0]['meta']['type']);
        self::assertSame('Translate the whole page', $updated[0]['meta']['toolCallLabel']);
        self::assertTrue($updated[0]['meta']['fromRunner']);
        self::assertSame(['pageId' => 1], $updated[0]['meta']['details']);
    }

    #[Test]
    public function appliedSuggestionsKeepTheChoice(): void
    {
        $messages = [['role' => 'assistant', 'content' => 'Suggestions', 'meta' => ['type' => 'suggestions', 'draftId' => 's1']]];

        $updated = $this->recorder->applied($messages, 's1', ['appliedCount' => 2, 'changeId' => 'ch2', 'appliedValues' => ['seo_title' => 'A']], [
            'selections' => ['seo_title' => 1],
            'edits' => [],
        ]);

        self::assertTrue($updated[0]['meta']['applied']);
        self::assertSame(['seo_title' => 1], $updated[0]['meta']['selections']);
        self::assertSame('readback_result', $updated[1]['meta']['type']);
        self::assertSame(['seo_title' => 'A'], $updated[1]['meta']['appliedValues']);
    }

    #[Test]
    public function declineArmAndUndoAreRecorded(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => 'Delete it?', 'meta' => ['type' => 'inline_draft', 'draft' => ['draftId' => 'd3']]],
            ['role' => 'assistant', 'content' => 'Pick one', 'meta' => ['type' => 'suggestions', 'draftId' => 's2']],
        ];

        $armed = $this->recorder->armed($messages, 'd3');
        self::assertTrue($armed[0]['meta']['draft']['destructiveArmed']);

        $declined = $this->recorder->declined($this->recorder->declined($messages, 'd3'), 's2');
        self::assertTrue($declined[0]['meta']['draft']['discarded']);
        self::assertTrue($declined[1]['meta']['discarded']);
        self::assertSame('Draft discarded. Nothing was written.', $declined[0]['content']);

        $undone = $this->recorder->undone($messages, 'Change undone.');
        self::assertSame(['role' => 'assistant', 'content' => 'Change undone.', 'meta' => ['type' => 'info']], $undone[2]);

        self::assertSame($messages, $this->recorder->declined($messages, 'unknown'));
        self::assertSame($messages, $this->recorder->applied($messages, '', [], []));
    }

    #[Test]
    public function summarizingNeedsFourNewVisibleMessages(): void
    {
        $visible = ['role' => 'user', 'content' => 'x', 'meta' => []];
        $hidden = ['role' => 'user', 'content' => 'continue', 'meta' => ['hidden' => true]];
        $summary = ['role' => 'assistant', 'content' => '- done', 'meta' => ['type' => 'summary']];

        self::assertFalse(AgentConversationSummarizer::canSummarize([$visible, $visible, $visible, $hidden]));
        self::assertTrue(AgentConversationSummarizer::canSummarize([$visible, $visible, $visible, $visible]));
        self::assertFalse(AgentConversationSummarizer::canSummarize([$visible, $visible, $visible, $visible, $summary, $visible]));
    }

    #[Test]
    public function summaryUsesTheSiteDefaultForTheDefaultEntry(): void
    {
        $seen = [];
        $aiService = $this->createMock(AiServiceInterface::class);
        $aiService->method('complete')->willReturnCallback(static function (string $prompt, AiOptions $options) use (&$seen): AiResponse {
            $seen[] = $options->providerIdentifier;

            return new AiResponse('- summary', 'model', 'p');
        });
        $summarizer = new AgentConversationSummarizer(
            $aiService,
            (new \ReflectionClass(AgentPromptBuilder::class))->newInstanceWithoutConstructor(),
            new AgentLanguageResolver($this->createMock(SiteFinder::class)),
        );
        $messages = [['role' => 'user', 'content' => 'Hi', 'meta' => []], ['role' => 'assistant', 'content' => 'Hello', 'meta' => []]];

        $summarizer->summarize($messages, 'default');
        $summarizer->summarize($messages, '');
        $message = $summarizer->summarize($messages, 'openai-1');

        self::assertSame([null, null, 'openai-1'], $seen);
        self::assertSame('summary', $message['meta']['type']);
        self::assertSame('- summary', $message['content']);
    }
}
