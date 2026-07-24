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

namespace NITSAN\NsT3AF\Tests\Unit\Governance;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Domain\Repository\UsageBudgetRepository;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Governance\AccessControlListener;
use NITSAN\NsT3AF\Governance\BudgetService;
use NITSAN\NsT3AF\Provider\Capability;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AccessControlListenerTest extends TestCase
{
    /** @var mixed */
    private mixed $previousBeUser = null;

    protected function setUp(): void
    {
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['EXEC_TIME'] = time();
    }

    protected function tearDown(): void
    {
        if ($this->previousBeUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBeUser;
        }
    }

    public function testPassesThroughWhenNoBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $event = $this->makeEvent();

        ($this->makeListener())($event);

        self::assertFalse($event->isCancelled());
    }

    public function testDeniesWhenProviderRestrictedToOtherBeGroups(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(isAdmin: false, groups: [10]);
        $event = $this->makeEvent(beGroups: [99]);

        ($this->makeListener())($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('restricted to other backend groups', (string) $event->getCancellationReason());
    }

    public function testAdminBypassesBeGroupRestriction(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(isAdmin: true, groups: []);
        $event = $this->makeEvent(beGroups: [99]);

        ($this->makeListener())($event);

        self::assertFalse($event->isCancelled());
    }

    public function testDeniesWhenCapabilityPermissionMissing(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(
            isAdmin: false,
            groups: [10],
            customOptions: 'nst3af:capability_chat',
            capabilityCheckResult: false,
        );
        $event = $this->makeEvent(callKind: 'embed');

        ($this->makeListener())($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('nst3af:capability_embeddings', (string) $event->getCancellationReason());
    }

    public function testDeniesImageGenerationWhenCapabilityPermissionMissing(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(
            isAdmin: false,
            groups: [10],
            customOptions: 'nst3af:capability_chat',
            capabilityCheckResult: false,
        );
        $event = $this->makeEvent(callKind: 'image_generation');

        ($this->makeListener())($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('nst3af:capability_image_generation', (string) $event->getCancellationReason());
    }

    public function testDeniesTtsWhenCapabilityPermissionMissing(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(
            isAdmin: false,
            groups: [10],
            customOptions: 'nst3af:capability_chat',
            capabilityCheckResult: false,
        );
        $event = $this->makeEvent(callKind: 'tts');

        ($this->makeListener())($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('nst3af:capability_tts', (string) $event->getCancellationReason());
    }

    /**
     * TC-04 / CM-04: explicit budget 0 must cancel the provider request.
     */
    public function testDeniesWhenBudgetIsZero(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(
            isAdmin: false,
            groups: [10],
            tsConfig: [
                'nst3af.' => [
                    'budget.' => [
                        'maxRequests' => '0',
                        'period' => 'monthly',
                    ],
                ],
            ],
        );

        $usage = $this->createMock(UsageBudgetRepository::class);
        $usage->expects(self::once())
            ->method('getCurrentUsage')
            ->with(5, 'monthly')
            ->willReturn(['tokens_used' => 0, 'cost_used' => 0.0, 'requests_used' => 0]);

        $event = $this->makeEvent();
        ($this->makeListener(budget: new BudgetService($usage)))($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('Request budget exceeded', (string) $event->getCancellationReason());
    }

    public function testAdminIsStillSubjectToBudgetLimits(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(
            isAdmin: true,
            groups: [],
            tsConfig: [
                'nst3af.' => [
                    'budget.' => [
                        'maxRequests' => '1',
                        'period' => 'daily',
                    ],
                ],
            ],
        );

        $usage = $this->createMock(UsageBudgetRepository::class);
        $usage->method('getCurrentUsage')->willReturn([
            'tokens_used' => 0,
            'cost_used' => 0.0,
            'requests_used' => 1,
        ]);

        $event = $this->makeEvent();
        ($this->makeListener(budget: new BudgetService($usage)))($event);

        self::assertTrue($event->isCancelled());
    }

    public function testDeniesWhenRateLimitExceeded(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(
            isAdmin: false,
            groups: [10],
            tsConfig: [
                'nst3af.' => [
                    'rateLimit.' => [
                        'requestsPerMinute' => '2',
                    ],
                ],
            ],
        );

        $logs = $this->createMock(RequestLogRepository::class);
        $logs->method('countRecentRequestsByUser')->willReturn(2);

        $event = $this->makeEvent();
        ($this->makeListener(logRepository: $logs))($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('Rate limit exceeded', (string) $event->getCancellationReason());
    }

    public function testAllowsWhenRateLimitNotReached(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(
            isAdmin: false,
            groups: [10],
            tsConfig: [
                'nst3af.' => [
                    'rateLimit.' => [
                        'requestsPerMinute' => '5',
                    ],
                ],
            ],
        );

        $logs = $this->createMock(RequestLogRepository::class);
        $logs->method('countRecentRequestsByUser')->willReturn(4);

        $event = $this->makeEvent();
        ($this->makeListener(logRepository: $logs))($event);

        self::assertFalse($event->isCancelled());
    }

    private function makeListener(
        ?BudgetService $budget = null,
        ?RequestLogRepository $logRepository = null,
    ): AccessControlListener {
        $budget ??= new BudgetService($this->createMock(UsageBudgetRepository::class));

        return new AccessControlListener(
            $budget,
            $logRepository ?? $this->createMock(RequestLogRepository::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * @param list<int> $beGroups
     */
    private function makeEvent(array $beGroups = [], string $callKind = 'complete'): BeforeProviderRequestEvent
    {
        $provider = new Provider(
            uid: 1,
            pid: 1,
            identifier: 'openai-prod',
            title: 'OpenAI',
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: [Capability::CHAT],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: true,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: Provider::LAST_STATUS_UNKNOWN,
            lastStatusAt: 0,
            lastStatusMessage: '',
            beGroups: $beGroups,
        );

        return new BeforeProviderRequestEvent($provider, 'hello', new AiOptions(), $callKind);
    }

    /**
     * @param list<int> $groups
     * @param array<string, mixed> $tsConfig
     */
    private function makeUser(
        bool $isAdmin,
        array $groups,
        string $customOptions = '',
        array $tsConfig = [],
        ?bool $capabilityCheckResult = null,
    ): BackendUserAuthentication {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->user = ['uid' => 5];
        $user->userGroupsUID = $groups;
        $user->groupData = ['custom_options' => $customOptions];
        $user->method('getTSConfig')->willReturn($tsConfig);
        if ($capabilityCheckResult !== null) {
            $user->method('check')->willReturn($capabilityCheckResult);
        }

        return $user;
    }
}
