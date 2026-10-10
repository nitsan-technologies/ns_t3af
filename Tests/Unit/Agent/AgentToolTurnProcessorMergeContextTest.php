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

use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolTurnProcessorMergeContextTest extends TestCase
{
    #[Test]
    public function mergeContextLeavesAnSeoQueueListWithoutTheOpenPage(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['pageIds' => [3, 4, 7]],
            ['pageId' => 2, 'module' => 'web_layout'],
            't3ai_mass_seo_queue_add',
        );

        self::assertSame([3, 4, 7], $merged['pageIds']);
        self::assertArrayNotHasKey('pageId', $merged);
    }

    #[Test]
    public function mergeContextTurnsAUidListInPageUrlIntoPageIds(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['pageUrl' => '45,133,134'],
            ['pageId' => 2, 'module' => 't3af_dashboard'],
            't3ai_mass_seo_queue_add',
        );

        self::assertSame([45, 133, 134], $merged['pageIds']);
        self::assertArrayNotHasKey('pageUrl', $merged);
        self::assertArrayNotHasKey('pageId', $merged);
    }

    #[Test]
    public function mergeContextUsesTheOpenPageWhenSeoQueueRemoveOmitsPageIds(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 2, 'module' => 'web_layout'],
            't3ai_mass_seo_queue_remove',
        );

        self::assertSame([2], $merged['pageIds']);
        self::assertArrayNotHasKey('pageId', $merged);
        self::assertArrayNotHasKey('pid', $merged);
    }

    #[Test]
    public function mergeContextKeepsAnExplicitSeoQueueRemoveList(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['pageIds' => [14]],
            ['pageId' => 2, 'module' => 'web_layout'],
            't3ai_mass_seo_queue_remove',
        );

        self::assertSame([14], $merged['pageIds']);
        self::assertArrayNotHasKey('pageId', $merged);
    }

    #[Test]
    public function mergeContextDoesNotCopyPageIdIntoUidForContentTools(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 7, 'module' => 'web_layout'],
            'content_get',
        );

        self::assertSame(7, $merged['pageId']);
        self::assertSame(7, $merged['pid']);
        self::assertArrayNotHasKey('uid', $merged);
    }

    #[Test]
    public function mergeContextMapsQueryToSearchForSearchTools(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke($processor, ['query' => 'QA Mounted'], ['pageId' => 3], 'pages_search');

        self::assertSame('QA Mounted', $merged['search']);
        self::assertArrayNotHasKey('query', $merged);
        self::assertArrayNotHasKey('pid', $merged);
    }

    #[Test]
    public function mergeContextKeepsAnExplicitSearchWord(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke($processor, ['search' => 'QA', 'query' => 'other'], [], 'content_search');

        self::assertSame('QA', $merged['search']);
        self::assertArrayNotHasKey('query', $merged);
    }

    #[Test]
    public function mergeContextUsesTheLockedPageInsteadOfTheOpenPage(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['pageId' => 2],
            ['pageId' => 2, 'lockedPageId' => 4],
            't3ai_generate_all_seo',
        );

        self::assertSame(4, $merged['pageId']);
    }

    #[Test]
    public function mergeContextMapsPageIdToUidForPagesGet(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 7, 'module' => 'web_layout'],
            'pages_get',
        );

        self::assertSame(7, $merged['pageId']);
        self::assertSame(7, $merged['uid']);
    }

    #[Test]
    public function mergeContextMapsPageIdToUidForPagesCopyButKeepsExplicitUid(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['uid' => 99],
            ['pageId' => 7],
            'pages_copy',
        );

        self::assertSame(99, $merged['uid']);
        self::assertSame(7, $merged['pageId']);
    }

    #[Test]
    public function mergeContextDoesNotCopyPageIdIntoUidWithoutToolName(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 7, 'module' => 'web_layout'],
        );

        self::assertSame(7, $merged['pageId']);
        self::assertSame(7, $merged['pid']);
        self::assertArrayNotHasKey('uid', $merged);
    }

    #[Test]
    public function mergeContextUsesFocusedRecordUid(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            [
                'pageId' => 7,
                'record' => ['table' => 'tt_content', 'uid' => 20],
            ],
        );

        self::assertSame(20, $merged['uid']);
        self::assertSame('tt_content', $merged['table']);
        self::assertSame(7, $merged['pageId']);
    }

    #[Test]
    public function mergeContextKeepsExplicitUidOverRecord(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['uid' => 99],
            [
                'pageId' => 7,
                'record' => ['table' => 'tt_content', 'uid' => 20],
            ],
        );

        self::assertSame(99, $merged['uid']);
    }

    #[Test]
    public function mergeContextDoesNotForcePidOntoSearchTools(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['search' => 'Camino'],
            ['pageId' => 7],
            'content_search',
        );

        self::assertSame(7, $merged['pageId']);
        self::assertSame('Camino', $merged['search']);
        self::assertArrayNotHasKey('pid', $merged);
    }

    #[Test]
    public function mergeContextStillSetsPidForNonSearchTools(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 7],
            'content_list',
        );

        self::assertSame(7, $merged['pid']);
    }

    #[Test]
    public function mergeContextInjectsAiProviderFromAgentSelection(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [],
            ['pageId' => 7],
            't3ai_generate_all_seo',
            'openai',
        );

        self::assertSame('openai', $merged['aiProvider']);
    }

    #[Test]
    public function mergeContextDoesNotInjectDefaultOrEmptyProvider(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $empty = $method->invoke($processor, [], ['pageId' => 7], 't3ai_generate_all_seo', '');
        $default = $method->invoke($processor, [], ['pageId' => 7], 't3ai_generate_all_seo', 'default');

        self::assertArrayNotHasKey('aiProvider', $empty);
        self::assertArrayNotHasKey('aiProvider', $default);
    }

    #[Test]
    public function mergeContextKeepsExplicitAiProvider(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['aiProvider' => 'claude'],
            ['pageId' => 7],
            't3ai_generate_all_seo',
            'openai',
        );

        self::assertSame('claude', $merged['aiProvider']);
    }

    #[Test]
    public function mergeContextDropsPidZeroOnRecordSearch(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [
                'tableName' => 'tx_news_domain_model_news',
                'search' => '',
                'pid' => 0,
            ],
            ['pageId' => 224],
            'record_search',
        );

        self::assertArrayNotHasKey('pid', $merged);
    }

    #[Test]
    public function mergeContextDropsNewsPidWhenItIsTheOpenPage(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [
                'tableName' => 'tx_news_domain_model_news',
                'search' => 'Summer Sale',
                'pid' => 224,
            ],
            ['pageId' => 224],
            'record_search',
        );

        self::assertArrayNotHasKey('pid', $merged);
    }

    #[Test]
    public function mergeContextKeepsNewsPidWhenItIsAStorageFolder(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            [
                'tableName' => 'tx_news_domain_model_news',
                'search' => 'Summer Sale',
                'pid' => 173,
            ],
            ['pageId' => 224],
            'record_search',
        );

        self::assertSame(173, $merged['pid']);
    }

    #[Test]
    public function mergeContextDoesNotInjectLayoutPageOntoGenerateImage(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'mergeContextArguments');

        $merged = $method->invoke(
            $processor,
            ['prompt' => 'A sunny office'],
            ['pageId' => 224, 'module' => 'web_layout'],
            't3ai_generate_image',
        );

        self::assertArrayNotHasKey('pageId', $merged);
        self::assertArrayNotHasKey('pid', $merged);
    }

    #[Test]
    public function enrichPlannedToolArgumentsTagsNewsArticleForGenerateImage(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'enrichPlannedToolArguments');
        $history = [
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tx_news_domain_model_news', 'uid' => 42]],
                ],
            ],
        ];

        $enriched = $method->invoke(
            $processor,
            't3ai_generate_image',
            ['pageId' => 224, 'prompt' => 'Team photo'],
            $history,
        );

        self::assertSame(42, $enriched['newsArticleUid']);
        self::assertArrayNotHasKey('pageId', $enriched);
    }

    #[Test]
    public function enrichPlannedToolArgumentsResolvesPlaceholderFileUidsFromHistory(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'enrichPlannedToolArguments');
        $history = [
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 88],
                ],
            ],
        ];

        $enriched = $method->invoke(
            $processor,
            'file_reference_add',
            [
                'table' => 'tx_news_domain_model_news',
                'uid' => 42,
                'fieldName' => 'fal_media',
                'fileUids' => '[imageUid]',
            ],
            $history,
        );

        self::assertSame('88', $enriched['fileUids']);
    }

    #[Test]
    public function enrichPlannedToolArgumentsResolvesGeneratedImagePlaceholderForMetadata(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'enrichPlannedToolArguments');
        $history = [
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 159],
                ],
            ],
        ];

        $enriched = $method->invoke(
            $processor,
            't3aa_update_file_metadata',
            ['fileUrl' => '/generated-image.jpg', 'altText' => 'Celebration'],
            $history,
        );

        self::assertSame(159, $enriched['fileUid']);
        self::assertArrayNotHasKey('fileUrl', $enriched);
    }

    #[Test]
    public function enrichPlannedToolArgumentsRefusesSecondAttachWhenOneIsAlreadyOpen(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'enrichPlannedToolArguments');
        $history = [
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 159],
                ],
            ],
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'inline_draft',
                    'tool' => 'file_reference_add',
                    'draft' => ['tool' => 'file_reference_add', 'discarded' => false],
                ],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already open');

        $method->invoke(
            $processor,
            'file_reference_add',
            [
                'table' => 'tx_news_domain_model_news',
                'uid' => 43,
                'fieldName' => 'fal_media',
                'fileUids' => '159',
            ],
            $history,
        );
    }

    #[Test]
    public function enrichPlannedToolArgumentsRefusesAttachWhenFileAlreadyLinkedInHistory(): void
    {
        $processor = (new \ReflectionClass(AgentToolTurnProcessor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentToolTurnProcessor::class, 'enrichPlannedToolArguments');
        $history = [
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 159],
                ],
            ],
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 'file_reference_add',
                    'success' => true,
                    'details' => ['fileUids' => [159]],
                ],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already attached');

        $method->invoke(
            $processor,
            'file_reference_add',
            [
                'table' => 'tx_news_domain_model_news',
                'uid' => 43,
                'fieldName' => 'fal_media',
                'fileUids' => '159',
            ],
            $history,
        );
    }
}
