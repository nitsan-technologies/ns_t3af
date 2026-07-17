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

namespace NITSAN\NsT3AF\Tests\Functional\Controller\Backend;

use NITSAN\NsT3AF\Controller\Backend\ProviderController;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepository;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Service\ProviderFormService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ProviderControllerFunctionalTest extends FunctionalTestCase
{
    private const SITE_ROOT_PAGE_ID = 1;

    private const SITE_STORAGE_PID = 1;

    protected array $coreExtensionsToLoad = [
        'frontend',
        'workspaces',
        'scheduler',
        'extensionmanager',
    ];

    protected array $testExtensionsToLoad = [
        'ns_license',
        'ns_t3af',
    ];

    /**
     * @var array<string, non-empty-string>
     */
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/ns_t3af/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTestSite();
    }

    private function setUpTestSite(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $page = $connection->select(['uid'], 'pages', ['uid' => self::SITE_ROOT_PAGE_ID])->fetchAssociative();
        if ($page === false) {
            $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
            $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        }

        $this->setUpBackendUser(1);
        GeneralUtility::makeInstance(Context::class)->setAspect(
            'workspace',
            new WorkspaceAspect(0),
        );

        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);

        $cacheFile = $this->instancePath . '/typo3temp/var/cache/code/core/sites-configuration.php';
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $runtimeCache = $cacheManager->getCache('runtime');
        $runtimeCache->remove('sites-configuration');
        $runtimeCache->remove('sites-root-id-to-identifier');
        $cacheManager->getCache('core')->remove('sites-configuration');
    }

    private function requestWithSiteContext(ServerRequest $request): ServerRequest
    {
        $query = $request->getQueryParams();
        $query['id'] = self::SITE_ROOT_PAGE_ID;

        return $request->withQueryParams($query);
    }

    #[Test]
    public function containerResolvesProviderController(): void
    {
        self::assertInstanceOf(
            ProviderController::class,
            $this->get(ProviderController::class),
        );
    }

    #[Test]
    public function testActionReturns404WhenProviderMissing(): void
    {
        $request = (new ServerRequest())->withParsedBody(['uid' => 5])->withQueryParams([]);
        $response = $this->get(ProviderController::class)->testAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function testActionReturnsFailureWhenAdapterIsNotRegistered(): void
    {
        $uid = $this->insertMinimalProvider('openai-prod', 'OpenAI Prod');

        $request = (new ServerRequest())->withParsedBody(['uid' => $uid])->withQueryParams([]);
        $response = $this->get(ProviderController::class)->testAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"ok":false', (string) $response->getBody());
    }

    #[Test]
    public function modelsActionReturns400WhenTransientDraftMissingAdapterType(): void
    {
        $request = (new ServerRequest())->withQueryParams(['uid' => '0']);
        $response = $this->get(ProviderController::class)->modelsAction($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('adapterType', (string) $response->getBody());
    }

    #[Test]
    public function modelsActionReturnsOkJsonForOpenAiCompatibleTransient(): void
    {
        $request = (new ServerRequest())->withQueryParams([
            'uid' => '0',
            'adapterType' => Provider::ADAPTER_OPENAI_COMPATIBLE,
            'endpoint' => 'https://127.0.0.1:9/v1',
        ]);
        $response = $this->get(ProviderController::class)->modelsAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"ok":true', (string) $response->getBody());
    }

    #[Test]
    public function setDefaultActionRequiresUid(): void
    {
        $request = (new ServerRequest())->withParsedBody([])->withQueryParams([]);
        $response = $this->get(ProviderController::class)->setDefaultAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function searchActionFiltersByIdentifier(): void
    {
        $this->insertMinimalProvider('openai-prod', 'OpenAI Prod', 'gpt-4o');
        $this->insertMinimalProvider('claude-dev', 'Claude Dev', 'claude-opus');

        $request = $this->requestWithSiteContext(
            (new ServerRequest())->withParsedBody([])->withQueryParams(['q' => 'claude']),
        );

        $resolution = GeneralUtility::makeInstance(SiteStorageContext::class)->resolveFromRequest($request);
        self::assertTrue(
            $resolution->isResolved(),
            'Site storage must resolve for search test (reason: ' . $resolution->reason . ')',
        );

        $response = $this->get(ProviderController::class)->searchAction($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('claude-dev', $body);
        self::assertStringNotContainsString('openai-prod', $body);
    }

    #[Test]
    public function governanceFieldsPersistThroughFormService(): void
    {
        $groupUid = $this->insertBackendGroup('Editors');

        $result = $this->get(ProviderFormService::class)->save(0, [
            'identifier' => 'governed-provider',
            'title' => 'Governed Provider',
            'adapter_type' => Provider::ADAPTER_OPENAI_COMPATIBLE,
            'endpoint_url' => 'https://api.example.test/v1',
            'api_key' => 'sk-functional-test-secret',
            'model_id' => 'gpt-4o',
            'be_groups' => [(string) $groupUid],
            'privacy_level' => 'reduced',
            'no_rerouting' => '1',
        ], self::SITE_STORAGE_PID);

        self::assertTrue($result->ok, 'Save should succeed: ' . implode(', ', $result->errors));

        $provider = $this->get(ProviderRepositoryInterface::class)->findByUid($result->uid);

        self::assertInstanceOf(Provider::class, $provider);
        self::assertSame([$groupUid], $provider->beGroups);
        self::assertSame('reduced', $provider->privacyLevel);
        self::assertTrue($provider->noRerouting);
    }

    /**
     * @return positive-int
     */
    private function insertBackendGroup(string $title): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $connection->insert('be_groups', [
            'pid' => 0,
            'tstamp' => 1,
            'crdate' => 1,
            'deleted' => 0,
            'hidden' => 0,
            'title' => $title,
        ]);

        $uid = (int) $connection->lastInsertId();
        self::assertGreaterThan(0, $uid);

        return $uid;
    }

    /**
     * @return positive-int
     */
    private function insertMinimalProvider(
        string $identifier,
        string $title,
        string $modelId = 'gpt-4o',
        int $storagePid = self::SITE_STORAGE_PID,
    ): int {
        $connection = $this->getConnectionPool()->getConnectionForTable(ProviderRepository::TABLE);
        $connection->insert(
            ProviderRepository::TABLE,
            [
                'pid' => $storagePid,
                'tstamp' => 1,
                'crdate' => 1,
                'deleted' => 0,
                'hidden' => 0,
                'identifier' => $identifier,
                'title' => $title,
                'adapter_type' => 'symfony.openai',
                'endpoint_url' => '',
                'api_key' => '',
                'model_id' => $modelId,
                'capabilities' => '',
                'temperature' => 0.7,
                'system_prompt' => '',
                'is_default' => 0,
                'priority' => 50,
                'last_used_at' => 0,
                'last_status' => Provider::LAST_STATUS_UNKNOWN,
                'last_status_at' => 0,
                'last_status_message' => Provider::LAST_STATUS_UNKNOWN,
                'be_groups' => '',
                'is_enabled' => 1,
                'enabled_for_dashboard' => 1,
                'pricing_input_per_1m' => 0,
                'pricing_output_per_1m' => 0,
                'pricing_currency' => 'USD',
                'retention_days_override' => 0,
                'cost_center' => '',
            ],
        );

        $uid = (int) $connection->lastInsertId();

        self::assertGreaterThan(0, $uid);

        return $uid;
    }
}
