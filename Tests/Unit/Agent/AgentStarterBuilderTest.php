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

use NITSAN\NsT3AF\Agent\Service\AgentStarterBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Starter chips fit the context and only offer what a permitted tool can do.
 *
 * @internal
 */
final class AgentStarterBuilderTest extends TestCase
{
    private const ALL_TOOLS = [
        'explain_capabilities', 'pages_tree', 'content_list', 'write_table', 't3ai_generate_all_seo',
        't3ai_translate_page', 't3aa_accessibility_scan_run', 't3ai_record_translate', 't3aa_update_file_metadata',
        't3aa_list_files_missing_alt_text', 't3ai_generate_image', 'workspace_changes_list', 'redirect_list',
    ];

    #[Test]
    public function aPageInThePageModuleGetsPageActions(): void
    {
        $chosen = AgentStarterBuilder::choose(self::ALL_TOOLS, [
            'module' => 'web_layout',
            'pageId' => 12,
            'languageId' => 0,
            'details' => ['siteLanguages' => [['id' => 0], ['id' => 2]]],
        ]);

        self::assertSame(['seo', 'translatePage', 'addContent', 'accessibility'], array_column($chosen, 'id'));
        self::assertSame('t3ai_generate_all_seo', $chosen[0]['tool']);
        self::assertSame('agent.starter.prompt.seo', $chosen[0]['labelKey']);
    }

    #[Test]
    public function translateIsOfferedOnlyFromTheDefaultLanguageOfAMultilingualSite(): void
    {
        $single = AgentStarterBuilder::choose(self::ALL_TOOLS, ['module' => 'web_layout', 'pageId' => 12, 'details' => ['siteLanguages' => [['id' => 0]]]]);
        $translated = AgentStarterBuilder::choose(self::ALL_TOOLS, ['module' => 'web_layout', 'pageId' => 12, 'languageId' => 2, 'details' => ['siteLanguages' => [['id' => 0], ['id' => 2]]]]);

        self::assertNotContains('translatePage', array_column($single, 'id'));
        self::assertNotContains('translatePage', array_column($translated, 'id'));
    }

    #[Test]
    public function anOpenContentElementComesFirst(): void
    {
        $chosen = AgentStarterBuilder::choose(self::ALL_TOOLS, [
            'module' => 'web_layout',
            'pageId' => 12,
            'record' => ['table' => 'tt_content', 'uid' => 7],
        ]);

        self::assertSame(['recordImprove', 'recordTranslate'], array_slice(array_column($chosen, 'id'), 0, 2));
    }

    #[Test]
    public function theFileModuleOffersImageActions(): void
    {
        $chosen = AgentStarterBuilder::choose(self::ALL_TOOLS, ['module' => 'media_management', 'record' => ['table' => 'sys_file', 'uid' => 3]]);

        self::assertSame(['fileAltText', 'missingAltText', 'generateImage', 'capabilities'], array_column($chosen, 'id'));
    }

    #[Test]
    public function missingToolsHideTheirChips(): void
    {
        $chosen = AgentStarterBuilder::choose(['explain_capabilities', 'pages_tree'], ['module' => 'web_layout', 'pageId' => 12]);

        self::assertSame(['capabilities'], array_column($chosen, 'id'));
        self::assertSame([], AgentStarterBuilder::choose([], ['module' => 'web_layout', 'pageId' => 12]));
    }

    #[Test]
    public function aDraftWorkspaceOffersItsChanges(): void
    {
        $chosen = AgentStarterBuilder::choose(['workspace_changes_list', 'explain_capabilities'], ['module' => 't3af_dashboard', 'workspaceId' => 1]);

        self::assertSame(['workspaceChanges', 'capabilities'], array_column($chosen, 'id'));
    }
}
