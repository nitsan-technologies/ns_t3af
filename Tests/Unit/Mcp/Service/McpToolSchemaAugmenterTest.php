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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpContentParam;
use NITSAN\NsT3AF\Mcp\Attribute\McpDualModeTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpNewsStorageTarget;
use NITSAN\NsT3AF\Mcp\Attribute\McpParentPageTarget;
use NITSAN\NsT3AF\Mcp\Contract\McpDualModeContentToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\McpConnectedProviderEnumResolver;
use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolSchemaAugmenter;
use NITSAN\NsT3AF\Mcp\Service\McpWorkspaceEnumResolver;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Tool\Content\ContentListTool;
use NITSAN\NsT3AF\Mcp\Tool\File\DirectoryCreateTool;
use NITSAN\NsT3AF\Mcp\Tool\File\FileReferenceAddTool;
use NITSAN\NsT3AF\Mcp\Tool\Pages\PagesGetTool;
use NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool;
use NITSAN\NsT3AF\Mcp\Tool\Schema\TableSchemaTool;
use NITSAN\NsT3AF\Mcp\Tool\Workspace\WorkspaceListTool;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpToolSchemaAugmenterTest extends TestCase
{
    #[Test]
    public function augmentAddsWorkspaceAndProviderProperties(): void
    {
        $augmenter = $this->createAugmenter();

        $schema = $augmenter->augment([
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer'],
            ],
        ]);

        self::assertArrayHasKey('workspaceId', $schema['properties']);
        self::assertSame([0, 3], $schema['properties']['workspaceId']['enum']);
        self::assertArrayHasKey('aiProvider', $schema['properties']);
        self::assertSame(['openai.default', 'gemini.main'], $schema['properties']['aiProvider']['enum']);
        self::assertArrayHasKey('pageId', $schema['properties']);
    }

    #[Test]
    public function standardParameterDefinitionsIncludeGlobalParams(): void
    {
        $augmenter = $this->createAugmenter();

        $definitions = $augmenter->standardParameterDefinitions();
        $names = array_column($definitions, 'name');

        self::assertSame(['workspaceId', 'aiProvider'], $names);
        self::assertFalse($definitions[0]['required']);
        self::assertFalse($definitions[1]['required']);
    }

    #[Test]
    public function augmentCanOmitAiProvider(): void
    {
        $augmenter = $this->createAugmenter();

        $schema = $augmenter->augment(['type' => 'object', 'properties' => []], false);

        self::assertArrayHasKey('workspaceId', $schema['properties']);
        self::assertArrayNotHasKey('aiProvider', $schema['properties']);
    }

    #[Test]
    public function standardParameterDefinitionsCanOmitAiProvider(): void
    {
        $augmenter = $this->createAugmenter();

        $definitions = $augmenter->standardParameterDefinitions(false);

        self::assertSame(['workspaceId'], array_column($definitions, 'name'));
    }

    #[Test]
    public function generateForHandlerOmitsWorkspaceIdForFalStorageTools(): void
    {
        $augmenter = $this->createAugmenter();

        $falSchema = $augmenter->generateForHandler([DirectoryCreateTool::class, 'execute']);
        $referenceSchema = $augmenter->generateForHandler([FileReferenceAddTool::class, 'execute']);

        self::assertArrayNotHasKey('workspaceId', $falSchema['properties']);
        self::assertArrayNotHasKey('aiProvider', $falSchema['properties']);
        self::assertTrue(is_subclass_of(DirectoryCreateTool::class, McpFalStorageToolInterface::class));

        self::assertArrayHasKey('workspaceId', $referenceSchema['properties']);
        self::assertArrayNotHasKey('aiProvider', $referenceSchema['properties']);
    }

    #[Test]
    public function standardParameterDefinitionsCanOmitWorkspaceId(): void
    {
        $augmenter = $this->createAugmenter();

        $definitions = $augmenter->standardParameterDefinitions(includeAiProvider: false, includeWorkspaceId: false);

        self::assertSame([], $definitions);
    }

    #[Test]
    public function generateForHandlerOmitsAiProviderForNonAiTools(): void
    {
        $augmenter = $this->createAugmenter();

        $schema = $augmenter->generateForHandler([WorkspaceListTool::class, 'execute']);

        self::assertArrayHasKey('workspaceId', $schema['properties']);
        self::assertArrayNotHasKey('aiProvider', $schema['properties']);
        self::assertTrue(is_subclass_of(WorkspaceListTool::class, McpNonAiToolInterface::class));
    }

    #[Test]
    public function generateForHandlerOmitsAiProviderForCoreContentTools(): void
    {
        $augmenter = $this->createAugmenter();

        foreach (
            [
                ContentListTool::class,
                PagesGetTool::class,
                WriteTableTool::class,
                TableSchemaTool::class,
            ] as $handlerClass
        ) {
            $schema = $augmenter->generateForHandler([$handlerClass, 'execute']);

            self::assertArrayNotHasKey('aiProvider', $schema['properties'], $handlerClass);
            self::assertTrue(is_subclass_of($handlerClass, McpNonAiToolInterface::class), $handlerClass);
        }
    }

    #[Test]
    public function dualModeToolRequiresContentParamInContextMode(): void
    {
        $augmenter = $this->createAugmenter(McpModeResolver::MODE_CONTEXT);

        $schema = $augmenter->generateForHandler([DualModeSchemaTestTool::class, 'execute']);

        self::assertContains('value', $schema['required'] ?? []);
        self::assertArrayNotHasKey('aiProvider', $schema['properties']);
    }

    #[Test]
    public function dualModeToolOmitsContentParamRequirementInNativeMode(): void
    {
        $augmenter = $this->createAugmenter(McpModeResolver::MODE_NATIVE);

        $schema = $augmenter->generateForHandler([DualModeSchemaTestTool::class, 'execute']);

        self::assertNotContains('value', $schema['required'] ?? []);
        self::assertArrayHasKey('aiProvider', $schema['properties']);
    }

    #[Test]
    public function parentPageTargetRequiresParentPageIdInSchema(): void
    {
        $augmenter = $this->createAugmenter(McpModeResolver::MODE_NATIVE);

        $schema = $augmenter->generateForHandler([ParentPageSchemaTestTool::class, 'execute']);

        self::assertContains('parentPageId', $schema['required'] ?? []);
        self::assertArrayHasKey('parentPageUrl', $schema['properties']);
    }

    #[Test]
    public function newsStorageTargetRequiresPageIdAndOmitsPageUrl(): void
    {
        $augmenter = $this->createAugmenter(McpModeResolver::MODE_NATIVE);

        $schema = $augmenter->generateForHandler([NewsStorageSchemaTestTool::class, 'execute']);

        self::assertContains('pageId', $schema['required'] ?? []);
        self::assertArrayNotHasKey('pageUrl', $schema['properties']);
        self::assertSame('Storage Id', $schema['properties']['pageId']['title'] ?? null);
    }

    #[Test]
    public function generateForDynamicCallableBuildsWorkspaceOnlySchema(): void
    {
        $augmenter = $this->createAugmenter();
        $handler = static fn(int $pid = 0, int $limit = 20): string => '{}';

        $schema = $augmenter->generateForDynamicCallable($handler);

        self::assertArrayHasKey('workspaceId', $schema['properties']);
        self::assertArrayHasKey('pid', $schema['properties']);
        self::assertArrayNotHasKey('aiProvider', $schema['properties']);
    }

    #[Test]
    public function generateForHandlerDelegatesDynamicCallables(): void
    {
        $augmenter = $this->createAugmenter();
        $handler = static fn(int $pid = 0): string => '{}';

        $schema = $augmenter->generateForHandler($handler);

        self::assertArrayHasKey('workspaceId', $schema['properties']);
        self::assertArrayNotHasKey('aiProvider', $schema['properties']);
    }

    #[Test]
    public function generateForClassHandlerIsUsedForTaggedTools(): void
    {
        $augmenter = $this->createAugmenter(McpModeResolver::MODE_NATIVE);

        $schema = $augmenter->generateForClassHandler([DualModeSchemaTestTool::class, 'execute']);

        self::assertNotContains('value', $schema['required'] ?? []);
        self::assertArrayHasKey('aiProvider', $schema['properties']);
    }

    private function createAugmenter(string $mode = McpModeResolver::MODE_CONTEXT): McpToolSchemaAugmenter
    {
        $workspaceList = $this->createMock(WorkspaceListService::class);
        $workspaceList->method('list')->willReturn([
            ['uid' => 0, 'title' => 'LIVE'],
            ['uid' => 3, 'title' => 'Draft'],
        ]);

        $providerResolver = $this->createMock(McpConnectedProviderEnumResolver::class);
        $providerResolver->method('resolveEnum')->willReturn(['openai.default', 'gemini.main']);
        $providerResolver->method('buildDescription')->willReturn('Provider description');

        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAllIgnorePid')->with('ns_t3af')->willReturn(['mcpMode' => $mode]);

        return new McpToolSchemaAugmenter(
            new McpWorkspaceEnumResolver($workspaceList),
            $providerResolver,
            new McpModeResolver($settings),
        );
    }
}

#[McpDualModeTool(
    contextDescription: 'Context mode description.',
    nativeDescription: 'Native mode description.',
)]
final class DualModeSchemaTestTool implements McpDualModeContentToolInterface
{
    #[McpTool(name: 'test_dual_mode_schema', description: 'Fallback description.')]
    public function execute(
        #[McpContentParam]
        string $value = '',
        int $pageId = 0,
    ): string {
        return '';
    }
}

final class ParentPageSchemaTestTool implements McpDualModeContentToolInterface
{
    #[McpTool(name: 'test_parent_page_schema', description: 'Test parent page schema.')]
    public function execute(
        #[McpContentParam]
        string $payload = '',
        #[McpParentPageTarget]
        ?int $parentPageId = null,
        #[McpParentPageTarget]
        string $parentPageUrl = '',
    ): string {
        return '';
    }
}

final class NewsStorageSchemaTestTool implements McpDualModeContentToolInterface
{
    #[McpTool(name: 'test_news_storage_schema', description: 'Test news storage schema.')]
    public function execute(
        #[McpContentParam]
        string $payload = '',
        string $topic = '',
        #[McpNewsStorageTarget]
        ?int $pageId = null,
        string $pageUrl = '',
    ): string {
        return '';
    }
}
