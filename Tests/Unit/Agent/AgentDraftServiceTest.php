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

use NITSAN\NsT3AF\Agent\Service\AgentCreatePlacement;
use NITSAN\NsT3AF\Agent\Service\AgentDraftService;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentRecordLabeler;
use NITSAN\NsT3AF\Agent\Service\SatelliteToolPlanService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentDraftServiceTest extends TestCase
{
    use AgentTranslatorTrait;
    private AgentDraftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AgentDraftService(new AgentLowRiskFieldMatrix());
    }

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function buildDraftCardMapsPlanFields(): void
    {
        $plan = new ToolPlan('update', 'write_table', [
            new ToolPlanField(
                ToolPlanField::buildKey('tt_content', 42, 'header'),
                'tt_content',
                42,
                'header',
                'Old title',
                'New title',
            ),
        ]);

        $card = $this->service->buildDraftCard($plan, 'write');

        self::assertSame('write_table', $card['tool']);
        self::assertSame('update', $card['action']);
        self::assertSame('write', $card['severity']);
        self::assertCount(1, $card['fields']);
        self::assertSame('tt_content:42:header', $card['fields'][0]['key']);
        self::assertSame('Old title', $card['fields'][0]['current']);
        self::assertSame('New title', $card['fields'][0]['proposed']);
        self::assertTrue($card['fields'][0]['kept']);
        self::assertFalse($card['fields'][0]['safe']);
        self::assertSame(0, $card['safeFieldCount']);
        self::assertNotSame('', $card['draftId']);
    }

    #[Test]
    public function buildDraftCardMarksSafeSeoFields(): void
    {
        $plan = new ToolPlan('update', 'write_table', [
            new ToolPlanField(
                ToolPlanField::buildKey('pages', 1, 'description'),
                'pages',
                1,
                'description',
                'Old',
                'New',
            ),
        ]);

        $card = $this->service->buildDraftCard($plan, 'write');

        self::assertTrue($card['fields'][0]['safe']);
        self::assertSame(1, $card['safeFieldCount']);
    }

    #[Test]
    public function buildDraftCardBuildsToolConfirmationPayload(): void
    {
        $plan = new ToolPlan('update', 't3ai_generate_all_seo', [], [
            'planKind' => SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION,
            'summary' => 'Generate and apply all SEO metadata for page 8.',
            'displayArguments' => [['key' => 'pageId', 'value' => '8']],
            'arguments' => ['pageId' => 8],
        ]);

        $card = $this->service->buildDraftCard($plan, 'write');

        self::assertSame(SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION, $card['kind']);
        self::assertSame([], $card['fields']);
        self::assertSame('Generate and apply all SEO metadata for page 8.', $card['summary']);
        self::assertSame([['key' => 'pageId', 'value' => '8']], $card['arguments']);
    }

    #[Test]
    public function buildDraftCardShowsWhereACreateWillBePlaced(): void
    {
        $labeler = $this->createMock(AgentRecordLabeler::class);
        $labeler->method('recordLabel')->willReturnCallback(
            static fn(string $table, int $uid): string => match ($uid) {
                1 => 'Page „Home“',
                68 => 'Page „Page 1“',
                default => 'Page #' . $uid,
            },
        );
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(['pid' => 1]);
        $placement = new AgentCreatePlacement($this->createAgentTranslator(), $labeler, $records);

        $service = new AgentDraftService(new AgentLowRiskFieldMatrix(), null, $placement);
        $card = $service->buildDraftCard(new ToolPlan('create', 'write_table', [
            new ToolPlanField('pages:0:title', 'pages', 0, 'title', null, 'Page between 1 and 2'),
        ], ['pid' => -68]), 'write');

        self::assertSame('Location: inside Page „Home“ [1], after Page „Page 1“ [68]', $card['location']);
    }
}
