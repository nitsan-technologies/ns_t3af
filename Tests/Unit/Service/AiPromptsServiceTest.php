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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Contract\PromptCatalogProviderInterface;
use NITSAN\NsT3AF\Contract\PromptCategoryDescriptor;
use NITSAN\NsT3AF\Contract\PromptDefaultsSyncProviderInterface;
use NITSAN\NsT3AF\Prompt\AiPromptRepository;
use NITSAN\NsT3AF\Registry\PromptCatalogProviderRegistry;
use NITSAN\NsT3AF\Service\AiPromptsService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class AiPromptsServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    protected function setUp(): void
    {
        $this->originalTca = $GLOBALS['TCA'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
    }

    public function testBuildOverviewDataReturnsEmptyWhenNoProvidersAndNoTable(): void
    {
        $GLOBALS['TCA'] = [];
        $service = $this->createService(
            $this->createUnavailableRepository(),
            $this->createUnavailableRegistry(),
        );
        $result = $service->buildOverviewData([
            'search' => '',
            'extension' => 'all',
            'title' => '',
            'text' => '',
            'promptType' => 'all',
            'scope' => 'all',
        ]);

        self::assertSame([], $result['categories']);
        self::assertSame(0, $result['kpis']['totalPrompts']);
        self::assertSame(0, $result['kpis']['categoryCount']);
    }

    public function testBuildCategoryDetailReturnsEmptyRowsWhenNoProvider(): void
    {
        $GLOBALS['TCA'] = [];
        $service = $this->createService(
            $this->createUnavailableRepository(),
            $this->createUnavailableRegistry(),
        );
        $result = $service->buildCategoryDetail('content', [
            'title' => '',
            'text' => '',
            'promptType' => 'all',
            'scope' => 'all',
        ]);

        self::assertSame('content', $result['category']['id']);
        self::assertSame([], $result['rows']);
    }

    public function testMutatingOperationsAreNoOpsWhenNoPromptTableRegistered(): void
    {
        $GLOBALS['TCA'] = [];
        $service = $this->createService(
            $this->createRepository(),
            $this->createUnavailableRegistry(),
        );

        self::assertFalse($service->createPrompt('content', ['promptTitle' => 'A', 'promptText' => 'B']));
        self::assertFalse($service->updatePrompt('content', 1, ['promptTitle' => 'A', 'promptText' => 'B']));
        self::assertFalse($service->deletePrompt('content', 1));
        self::assertSame(['created' => 0], $service->synchronizeDefaults());
    }

    public function testValidateGlobalPromptPayloadSkipsSidebarCategory(): void
    {
        $provider = $this->createMock(PromptCatalogProviderInterface::class);
        $provider->method('supportsCategory')->willReturnCallback(
            static fn(string $id): bool => $id === 'sidebar',
        );
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('isSidebarCategory')->willReturnCallback(
            static fn(string $id): bool => $id === 'sidebar',
        );
        $provider->expects(self::never())->method('validateGlobalPrompt');

        $registry = $this->createRegistryWithProviders([$provider]);
        $service = $this->createService($this->createUnavailableRepository(), $registry);
        self::assertNull($service->validateGlobalPromptPayload('sidebar', [
            'promptType' => '',
            'scope' => '',
            'promptText' => 'Hello',
        ]));
    }

    public function testValidateGlobalPromptPayloadDelegatesToProvider(): void
    {
        $provider = $this->createMock(PromptCatalogProviderInterface::class);
        $provider->method('supportsCategory')->with('seo')->willReturn(true);
        $provider->method('isAvailable')->willReturn(true);
        $provider->expects(self::once())
            ->method('validateGlobalPrompt')
            ->with('seo', 'ai_keywords', 'seo', 'Generate [number] keywords for [Content].')
            ->willReturn(null);

        $registry = $this->createRegistryWithProviders([$provider]);
        $service = $this->createService($this->createUnavailableRepository(), $registry);
        self::assertNull($service->validateGlobalPromptPayload('seo', [
            'promptType' => 'ai_keywords',
            'scope' => 'seo',
            'promptText' => 'Generate [number] keywords for [Content].',
        ]));
    }

    public function testValidateGlobalPromptPayloadRequiresTypeAndScope(): void
    {
        $provider = $this->createMock(PromptCatalogProviderInterface::class);
        $provider->method('supportsCategory')->with('seo')->willReturn(true);
        $provider->method('isAvailable')->willReturn(true);
        $provider->expects(self::never())->method('validateGlobalPrompt');

        $registry = $this->createRegistryWithProviders([$provider]);
        $service = $this->createService($this->createUnavailableRepository(), $registry);
        self::assertSame('invalid_prompt_type', $service->validateGlobalPromptPayload('seo', [
            'promptType' => '',
            'scope' => 'seo',
            'promptText' => 'Hello',
        ]));
    }

    public function testGetCatalogForUiMergesProviderCatalogs(): void
    {
        $t3aiCatalog = [
            'available' => true,
            'scopes' => [['id' => 'seo', 'label' => 'SEO']],
            'typesByScope' => ['seo' => [['id' => 'ai_keywords', 'label' => 'Keywords']]],
            'variablesByType' => ['ai_keywords' => ['Content']],
            'defaultTextByType' => ['ai_keywords' => 'Default'],
        ];
        $t3aaCatalog = [
            'available' => true,
            'scopes' => [['id' => 'content', 'label' => 'Content']],
            'typesByScope' => ['content' => [['id' => 'content_simplify', 'label' => 'Simplify']]],
            'variablesByType' => ['content_simplify' => ['content', 'language']],
            'defaultTextByType' => ['content_simplify' => 'Summarize [content].'],
        ];

        $t3aiProvider = $this->createProviderWithCatalog('seo', 'seo', $t3aiCatalog);
        $t3aaProvider = $this->createProviderWithCatalog('t3aa_content', 'content', $t3aaCatalog);

        $service = $this->createService(
            $this->createUnavailableRepository(),
            $this->createRegistryWithProviders([$t3aiProvider, $t3aaProvider]),
        );
        $catalog = $service->getCatalogForUi();

        self::assertTrue($catalog['available']);
        self::assertArrayHasKey('byCategory', $catalog);
        self::assertArrayHasKey('seo', $catalog['byCategory']);
        self::assertArrayHasKey('t3aa_content', $catalog['byCategory']);
        self::assertSame('content_simplify', $catalog['byCategory']['t3aa_content']['typesByScope']['content'][0]['id']);
    }

    public function testBuildOverviewIncludesT3aaCategoriesWhenProviderAvailable(): void
    {
        $GLOBALS['TCA'] = [
            AiPromptRepository::TABLE => [],
        ];

        $t3aaProvider = $this->createMock(PromptCatalogProviderInterface::class);
        $t3aaProvider->method('isAvailable')->willReturn(true);
        $t3aaProvider->method('getExtensionKey')->willReturn('ns_t3aa');
        $t3aaProvider->method('getCategories')->willReturn([
            [
                'id' => 't3aa_content',
                'title' => 'T3AA Content Prompts',
                'extension' => 't3aa_content',
                'description' => '',
                'manageLabel' => '',
                'sourceTable' => AiPromptRepository::TABLE,
                'promptCount' => 1,
                'customPromptCount' => 0,
                'scopeCount' => 1,
            ],
            [
                'id' => 't3aa_filemeta',
                'title' => 'T3AA File Metadata Prompts',
                'extension' => 't3aa_filemeta',
                'description' => '',
                'manageLabel' => '',
                'sourceTable' => AiPromptRepository::TABLE,
                'promptCount' => 2,
                'customPromptCount' => 0,
                'scopeCount' => 1,
            ],
        ]);

        $service = $this->createService(
            $this->createRepository(),
            $this->createRegistryWithProviders([$t3aaProvider]),
        );
        $result = $service->buildOverviewData([
            'search' => '',
            'extension' => 'all',
            'title' => '',
            'text' => '',
            'promptType' => 'all',
            'scope' => 'all',
        ]);

        $categoryIds = array_map(static fn(array $category): string => (string) $category['id'], $result['categories']);
        self::assertContains('t3aa_content', $categoryIds);
        self::assertContains('t3aa_filemeta', $categoryIds);
        self::assertNotContains('t3aa_translation', $categoryIds);
        self::assertCount(1, $result['extensions']);
        self::assertSame('ns_t3aa', $result['extensions'][0]['key']);
        self::assertSame('module.aiPrompts.extension.ns_t3aa', $result['extensions'][0]['labelKey']);
        self::assertSame('ns_t3aa', $result['categories'][0]['providerExtension']);
    }

    /**
     * @param iterable<PromptDefaultsSyncProviderInterface> $defaultsSyncProviders
     */
    private function createService(
        AiPromptRepository $repository,
        PromptCatalogProviderRegistry $registry,
        iterable $defaultsSyncProviders = [],
    ): AiPromptsService {
        return new AiPromptsService($registry, $repository, $defaultsSyncProviders);
    }

    private function createRepository(): AiPromptRepository
    {
        return new AiPromptRepository($this->createMock(ConnectionPool::class));
    }

    private function createUnavailableRepository(): AiPromptRepository
    {
        return $this->createRepository();
    }

    private function createUnavailableRegistry(): PromptCatalogProviderRegistry
    {
        return $this->createRegistryWithProviders([]);
    }

    /**
     * @param list<PromptCatalogProviderInterface> $providers
     */
    private function createRegistryWithProviders(array $providers): PromptCatalogProviderRegistry
    {
        return new PromptCatalogProviderRegistry($providers);
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function createProviderWithCatalog(
        string $categoryId,
        string $scope,
        array $catalog,
    ): PromptCatalogProviderInterface {
        $provider = $this->createMock(PromptCatalogProviderInterface::class);
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('supportsCategory')->willReturnCallback(
            static fn(string $id): bool => $id === $categoryId,
        );
        $provider->method('resolveCategoryScope')->with($categoryId)->willReturn($scope);
        $provider->method('getCategories')->willReturn([
            (new PromptCategoryDescriptor(
                id: $categoryId,
                title: ucfirst($categoryId),
                extensionKey: 'test',
                description: '',
                manageLabel: '',
                sourceTable: AiPromptRepository::TABLE,
                scope: $scope,
            ))->toArray() + ['promptCount' => 0, 'customPromptCount' => 0, 'scopeCount' => 1],
        ]);
        $provider->method('buildUiCatalog')->willReturn($catalog);

        return $provider;
    }
}
