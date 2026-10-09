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

use NITSAN\NsT3AF\Agent\Service\AgentConversationTitleService;
use NITSAN\NsT3AF\Agent\Service\AgentCoreToolSet;
use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentToolDocumentBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class AgentModuleToolPartitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
    }

    #[Test]
    public function fileModulePutsFileToolsBeforeBackendUserTools(): void
    {
        $tools = [
            $this->tool('backend_user_list', modules: []),
            $this->tool('backend_group_get', modules: []),
            $this->tool('file_list', modules: ['file'], category: 'files'),
            $this->tool('file_search', modules: ['file'], category: 'files'),
            $this->tool('pages_get', modules: []),
        ];

        $parts = $this->coreSet()->partitionByModule($tools, 'media_management');

        $moduleNames = array_map(static fn(array $t): string => (string) $t['name'], $parts['module']);
        $restNames = array_map(static fn(array $t): string => (string) $t['name'], $parts['rest']);

        self::assertSame(['file_list', 'file_search'], $moduleNames);
        self::assertSame(['backend_user_list', 'backend_group_get', 'pages_get'], $restNames);
    }

    #[Test]
    public function titleNormalizeCapsWordsAndChars(): void
    {
        $service = new AgentConversationTitleService(
            $this->createMock(AiServiceInterface::class),
            new AgentLanguageResolver($this->createMock(SiteFinder::class)),
        );

        self::assertSame('Add content element please now extra', $service->normalizeTitle('  "Add content element please now extra words"  '));
        self::assertSame('Short', $service->normalizeTitle('Short'));
    }

    /**
     * @param list<string> $modules
     * @return array<string, mixed>
     */
    private function tool(string $name, array $modules = [], string $category = ''): array
    {
        return [
            'name' => $name,
            'description' => $name,
            'category' => $category,
            'intent' => ['modules' => $modules, 'category' => $category],
        ];
    }

    private function coreSet(): AgentCoreToolSet
    {
        $translator = (new \ReflectionClass(AgentTranslator::class))->newInstanceWithoutConstructor();

        return new AgentCoreToolSet(
            new AgentToolDocumentBuilder(
                new AgentToolEditorLabelService($translator),
                new McpToolMetadataService(),
            ),
        );
    }

    #[Test]
    public function slashMenuListsNameMatchesBeforeDescriptionMatches(): void
    {
        $ranked = AgentCoreToolSet::rankByNameMatch([
            ['name' => 'pages_copy', 'editorLabel' => 'Copy a page', 'description' => 'Copy a page to a new tree position'],
            ['name' => 'pages_tree', 'editorLabel' => 'Read the page tree', 'description' => 'Get the page tree'],
        ], 'tree');

        self::assertSame(['pages_tree', 'pages_copy'], array_column($ranked, 'name'));
    }
}
