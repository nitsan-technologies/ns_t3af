<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace NITSAN\NsT3AF\Tests\Functional\Governance;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\UsageBudgetRepository;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Governance\AccessControlListener;
use NITSAN\NsT3AF\Provider\Capability;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TC-04: AccessControlListener blocks over-budget backend requests.
 */
final class AccessControlListenerFunctionalTest extends FunctionalTestCase
{
    private const EDITOR_UID = 2;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->getConnectionPool()->getConnectionForTable('be_users')->update(
            'be_users',
            [
                'TSconfig' => implode("\n", [
                    'nst3af.budget.maxRequests = 1',
                    'nst3af.budget.period = monthly',
                ]),
            ],
            ['uid' => self::EDITOR_UID],
            ['uid' => Connection::PARAM_INT],
        );
        $this->setUpBackendUser(self::EDITOR_UID);
    }

    #[Test]
    public function overBudgetUserTSconfigCancelsBeforeProviderRequest(): void
    {
        $usageBudgetRepository = $this->get(UsageBudgetRepository::class);
        $usageBudgetRepository->recordUsage(self::EDITOR_UID, 'monthly', 10, 0.01);

        $listener = new AccessControlListener(
            new \NITSAN\NsT3AF\Governance\BudgetService($usageBudgetRepository),
            $this->get(\NITSAN\NsT3AF\Domain\Repository\RequestLogRepository::class),
            new NullLogger(),
        );

        $event = new BeforeProviderRequestEvent(
            $this->makeProvider(),
            'hello',
            new AiOptions(),
            'complete',
        );

        ($listener)($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('Request budget exceeded', (string) $event->getCancellationReason());
    }

    private function makeProvider(): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'openai-prod',
            title: 'OpenAI Prod',
            adapterType: 'symfony.openai',
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: [Capability::CHAT],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: true,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
        );
    }
}
