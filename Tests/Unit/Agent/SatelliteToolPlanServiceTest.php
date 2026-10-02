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

use NITSAN\NsT3AF\Agent\Service\DeclaredToolSeverityLookup;
use NITSAN\NsT3AF\Agent\Service\SatelliteToolPlanService;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SatelliteToolPlanServiceTest extends TestCase
{
    use AgentTranslatorTrait;

    private SatelliteToolPlanService $service;

    protected function setUp(): void
    {
        $this->service = new SatelliteToolPlanService(
            $this->severityLookup([
                't3ai_generate_meta_description' => ToolSeverity::Write,
                't3ai_apply_schema_markup' => ToolSeverity::Write,
                't3ai_generate_all_seo' => ToolSeverity::Write,
                't3cs_save_datasource' => ToolSeverity::Write,
                't3cs_list_datasources' => ToolSeverity::Read,
                't3ac_chatbot_settings' => ToolSeverity::Write,
                't3ac_chatbot_settings_get' => ToolSeverity::Read,
                'pages_get' => ToolSeverity::Read,
            ]),
            new McpConfirmationPlanBuilder(),
            $this->createAgentTranslator(),
        );
    }

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
    }

    /**
     * @param array<string, ToolSeverity> $map
     */
    private function severityLookup(array $map): DeclaredToolSeverityLookup
    {
        $lookup = $this->createMock(DeclaredToolSeverityLookup::class);
        $lookup->method('severityFor')->willReturnCallback(
            static fn(string $name): ?ToolSeverity => $map[$name] ?? null,
        );

        return $lookup;
    }

    #[Test]
    public function supportsWriteSatelliteToolsOnly(): void
    {
        self::assertTrue($this->service->supports('t3ai_generate_meta_description'));
        self::assertTrue($this->service->supports('t3cs_save_datasource'));
        self::assertFalse($this->service->supports('t3cs_list_datasources'));
        self::assertFalse($this->service->supports('pages_get'));
    }

    #[Test]
    public function usesDeclaredSeverityNotTheToolName(): void
    {
        // "settings" used to be guessed as read-only; the declared Write wins.
        self::assertTrue($this->service->supports('t3ac_chatbot_settings'));
        self::assertFalse($this->service->supports('t3ac_chatbot_settings_get'));
        // Undeclared satellite tools are never planned (and stay locked).
        self::assertFalse($this->service->supports('t3ai_undeclared_generate_thing'));
    }

    #[Test]
    public function planBuildsConfirmationDraft(): void
    {
        $plan = $this->service->plan('t3ai_apply_schema_markup', ['pageId' => 3]);

        self::assertSame('update', $plan->action);
        self::assertSame('t3ai_apply_schema_markup', $plan->toolName);
        self::assertSame(SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION, $plan->context['planKind'] ?? null);
        self::assertSame(['pageId' => 3], $plan->context['arguments'] ?? null);
        self::assertNotSame('', $plan->context['summary'] ?? '');
    }

    #[Test]
    public function planDropsRedundantContextFillersFromDisplayArguments(): void
    {
        $plan = $this->service->plan('t3ai_generate_all_seo', ['pageId' => 8, 'pid' => 8, 'uid' => 8]);
        $displayArguments = is_array($plan->context['displayArguments'] ?? null)
            ? $plan->context['displayArguments']
            : [];

        self::assertSame([['key' => 'pageId', 'value' => '8', 'label' => 'Page']], $displayArguments);
    }

    #[Test]
    public function childToolsWithoutOwnSummaryUseTheirEditorLabel(): void
    {
        $service = new SatelliteToolPlanService(
            $this->severityLookup(['t3ai_translate_page' => ToolSeverity::Write]),
            new McpConfirmationPlanBuilder(),
            $this->createAgentTranslator(),
        );
        $plan = $service->plan('t3ai_translate_page', ['pageId' => 0, 'languageUids' => [1, 2], 'includeContent' => true]);

        self::assertSame('Translate the whole page.', $plan->context['summary'] ?? null);
        self::assertSame(
            [
                ['key' => 'pageId', 'value' => '0', 'label' => 'Page'],
                ['key' => 'languageUids', 'value' => '1, 2', 'label' => 'Languages'],
                ['key' => 'includeContent', 'value' => 'Yes', 'label' => 'Also content elements'],
            ],
            $plan->context['displayArguments'] ?? null,
        );
    }
}
