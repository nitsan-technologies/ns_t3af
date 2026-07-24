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

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;
use NITSAN\NsT3AF\Mcp\Service\McpServerStatusService;
use NITSAN\NsT3AF\Service\BrandContextCompletenessCalculator;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\EnvironmentRequirementService;
use NITSAN\NsT3AF\Service\PromptOverviewProviderInterface;
use NITSAN\NsT3AF\Service\SchedulerCliCommandCatalogService;
use NITSAN\NsT3AF\Service\SchedulerCliTaskService;
use NITSAN\NsT3AF\Service\SetupChecklistService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Tests\Unit\Credits\StubCreditsReleaseGate;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SetupChecklistServiceTest extends TestCase
{
    private function schedulerCliTaskService(): SchedulerCliTaskService
    {
        return new SchedulerCliTaskService(
            $this->createMock(ConnectionPool::class),
            new SchedulerCliCommandCatalogService($this->createMock(CommandRegistry::class), ProviderTestStubs::emptyMcpToolsCardRegistry()),
        );
    }

    private function environmentRequirementsReady(): EnvironmentRequirementService
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']
            ?? ('unit-test-key-' . str_repeat('x', 32));

        return new EnvironmentRequirementService();
    }

    private function checklistDependencies(
        CreditModeResolver $creditMode,
        McpServerStatusService $mcp,
        PromptOverviewProviderInterface $prompts,
    ): SetupChecklistService {
        return new SetupChecklistService(
            $this->createMock(ProviderRepositoryInterface::class),
            $creditMode,
            $mcp,
            $prompts,
            $this->schedulerCliTaskService(),
            $this->createMock(BrandContextProfileRepositoryInterface::class),
            new SiteStorageContext($this->createMock(SiteFinder::class)),
            new BrandContextCompletenessCalculator(),
            $this->environmentRequirementsReady(),
        );
    }

    public function testCreditsModeBuildsSevenItems(): void
    {
        $creditMode = $this->creditModeResolver(creditMode: 1, tokenEnc: 'enc');

        $mcp = $this->createMock(McpServerStatusService::class);
        $mcp->method('build')->willReturn(['online' => 1]);

        $prompts = $this->createMock(PromptOverviewProviderInterface::class);
        $prompts->method('buildOverviewData')->willReturn(['kpis' => ['totalPrompts' => 3]]);

        $service = $this->checklistDependencies($creditMode, $mcp, $prompts);

        $result = $service->build(
            ['totals' => ['totalRequests' => 5], 'scheduledTasks' => ['total' => 1, 'active' => 1, 'failing' => 0]],
            RequestLogProviderScope::Credits,
            true,
            ['loaded' => true],
            $this->createMock(ServerRequestInterface::class),
        );

        self::assertCount(7, $result['items']);
    }

    public function testOwnKeysModeBuildsEightItemsIncludingBudget(): void
    {
        $provider = Provider::fromRow([
            'identifier' => 'openai',
            'title' => 'OpenAI',
            'is_enabled' => 1,
            'api_key' => 'cipher',
            'is_default' => 1,
            'model_id' => 'gpt-4o',
        ]);

        $providers = $this->createMock(ProviderRepositoryInterface::class);
        $providers->method('findAllByStoragePid')->with(68, false)->willReturn([$provider]);
        $providers->method('findDefault')->with(68)->willReturn($provider);

        $mcp = $this->createMock(McpServerStatusService::class);
        $mcp->method('build')->willReturn(['online' => 0]);

        $prompts = $this->createMock(PromptOverviewProviderInterface::class);
        $prompts->method('buildOverviewData')->willReturn(['kpis' => ['totalPrompts' => 0]]);

        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $site = $this->createMock(\TYPO3\CMS\Core\Site\Entity\Site::class);
        $site->method('getRootPageId')->willReturn(68);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        $request = (new \TYPO3\CMS\Core\Http\ServerRequest('http://localhost/'))
            ->withQueryParams(['id' => '70']);

        $service = new SetupChecklistService(
            $providers,
            $this->creditModeResolver(creditMode: 0),
            $mcp,
            $prompts,
            $this->schedulerCliTaskService(),
            $this->createMock(BrandContextProfileRepositoryInterface::class),
            new SiteStorageContext($siteFinder),
            new BrandContextCompletenessCalculator(),
            $this->environmentRequirementsReady(),
        );

        $result = $service->build(
            ['totals' => ['totalRequests' => 0], 'scheduledTasks' => ['total' => 0, 'active' => 0, 'failing' => 0]],
            RequestLogProviderScope::OwnKeys,
            false,
            ['loaded' => false],
            $request,
        );

        self::assertCount(8, $result['items']);
        self::assertSame('checklist.budget.title', $result['items'][5]['titleKey']);
    }

    public function testFailingEnvironmentRequirementsArePrepended(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';

        $creditMode = $this->creditModeResolver(creditMode: 1, tokenEnc: 'enc');
        $mcp = $this->createMock(McpServerStatusService::class);
        $mcp->method('build')->willReturn(['online' => 1]);
        $prompts = $this->createMock(PromptOverviewProviderInterface::class);
        $prompts->method('buildOverviewData')->willReturn(['kpis' => ['totalPrompts' => 3]]);

        $service = new SetupChecklistService(
            $this->createMock(ProviderRepositoryInterface::class),
            $creditMode,
            $mcp,
            $prompts,
            $this->schedulerCliTaskService(),
            $this->createMock(BrandContextProfileRepositoryInterface::class),
            new SiteStorageContext($this->createMock(SiteFinder::class)),
            new BrandContextCompletenessCalculator(),
            new EnvironmentRequirementService(),
        );

        $result = $service->build(
            ['totals' => ['totalRequests' => 5], 'scheduledTasks' => ['total' => 1, 'active' => 1, 'failing' => 0]],
            RequestLogProviderScope::Credits,
            true,
            ['loaded' => true],
            $this->createMock(ServerRequestInterface::class),
        );

        self::assertGreaterThanOrEqual(8, count($result['items']));
        self::assertSame('checklist.env.encryptionKey.title', $result['items'][0]['titleKey']);
        self::assertSame('error', $result['items'][0]['status']);
        self::assertSame('t3af_dashboard.providers', $result['items'][0]['actionRoute']);
    }

    private function creditModeResolver(int $creditMode, string $tokenEnc = ''): CreditModeResolver
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'credit_mode' => $creditMode,
            'license_keys' => $creditMode === 1 ? 'key' : '',
            'token_enc' => $tokenEnc,
        ]);
        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new ExtensionConfiguration(),
        );

        return new CreditModeResolver($runtime, new StubCreditsReleaseGate($creditMode === 1));
    }
}
