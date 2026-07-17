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
use NITSAN\NsT3AF\Domain\Repository\GroupSettingsRepository;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Governance\GroupLimitsListener;
use NITSAN\NsT3AF\Provider\Capability;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class GroupLimitsListenerTest extends TestCase
{
    /** @var mixed */
    private mixed $previousBeUser = null;

    protected function setUp(): void
    {
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['EXEC_TIME'] = strtotime('2026-07-17 12:00:00');
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

    public function testAdminBypassesGroupLimits(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(isAdmin: true, groups: [10]);
        $groups = $this->createMock(GroupSettingsRepository::class);
        $groups->expects(self::never())->method('findByBeGroupUid');

        $event = $this->makeEvent();
        ($this->makeListener($groups))($event);

        self::assertFalse($event->isCancelled());
    }

    public function testDeniesProviderNotOnAllowlist(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(isAdmin: false, groups: [10]);
        $groups = $this->createMock(GroupSettingsRepository::class);
        $groups->method('findByBeGroupUid')->with(10)->willReturn([
            'configured' => 1,
            'credit_cap_monthly' => 0,
            'daily_request_cap' => 0,
            'limits_json' => json_encode([
                'providerAllowlistEnabled' => true,
                'allowedProviders' => ['other-provider'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $event = $this->makeEvent(identifier: 'openai-prod');
        ($this->makeListener($groups))($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('Group policy blocks this AI provider', (string) $event->getCancellationReason());
    }

    public function testDeniesWhenDailyRequestCapReached(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(isAdmin: false, groups: [10]);
        $groups = $this->createMock(GroupSettingsRepository::class);
        $groups->method('findByBeGroupUid')->willReturn([
            'configured' => 1,
            'credit_cap_monthly' => 0,
            'daily_request_cap' => 3,
            'limits_json' => '{}',
        ]);

        $logs = $this->createMock(RequestLogRepository::class);
        $logs->method('countRecentRequestsByUser')->willReturn(3);
        $logs->method('sumCreditsUsedByUserSince')->willReturn(0.0);

        $event = $this->makeEvent();
        ($this->makeListener($groups, $logs))($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('Daily AI request limit', (string) $event->getCancellationReason());
    }

    public function testDeniesWhenMonthlyCreditCapReached(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(isAdmin: false, groups: [10]);
        $groups = $this->createMock(GroupSettingsRepository::class);
        $groups->method('findByBeGroupUid')->willReturn([
            'configured' => 1,
            'credit_cap_monthly' => 100,
            'daily_request_cap' => 0,
            'limits_json' => '{}',
        ]);

        $logs = $this->createMock(RequestLogRepository::class);
        $logs->method('countRecentRequestsByUser')->willReturn(0);
        $logs->method('sumCreditsUsedByUserSince')->willReturn(100.0);

        $event = $this->makeEvent();
        ($this->makeListener($groups, $logs))($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('Monthly AI credit cap', (string) $event->getCancellationReason());
    }

    public function testUsesStrictestDailyCapAcrossGroups(): void
    {
        $GLOBALS['BE_USER'] = $this->makeUser(isAdmin: false, groups: [10, 20]);
        $groups = $this->createMock(GroupSettingsRepository::class);
        $groups->method('findByBeGroupUid')->willReturnMap([
            [10, [
                'configured' => 1,
                'credit_cap_monthly' => 0,
                'daily_request_cap' => 10,
                'limits_json' => '{}',
            ]],
            [20, [
                'configured' => 1,
                'credit_cap_monthly' => 0,
                'daily_request_cap' => 2,
                'limits_json' => '{}',
            ]],
        ]);

        $logs = $this->createMock(RequestLogRepository::class);
        $logs->method('countRecentRequestsByUser')->willReturn(2);
        $logs->method('sumCreditsUsedByUserSince')->willReturn(0.0);

        $event = $this->makeEvent();
        ($this->makeListener($groups, $logs))($event);

        self::assertTrue($event->isCancelled());
        self::assertStringContainsString('Daily AI request limit', (string) $event->getCancellationReason());
    }

    private function makeListener(
        ?GroupSettingsRepository $groups = null,
        ?RequestLogRepository $logs = null,
    ): GroupLimitsListener {
        return new GroupLimitsListener(
            $groups ?? $this->createMock(GroupSettingsRepository::class),
            $logs ?? $this->createMock(RequestLogRepository::class),
        );
    }

    private function makeEvent(string $identifier = 'openai-prod'): BeforeProviderRequestEvent
    {
        $provider = new Provider(
            uid: 1,
            pid: 1,
            identifier: $identifier,
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
        );

        return new BeforeProviderRequestEvent($provider, 'hello', new AiOptions(), 'complete');
    }

    /**
     * @param list<int> $groups
     */
    private function makeUser(bool $isAdmin, array $groups): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->user = ['uid' => 5];
        $user->userGroupsUID = $groups;

        return $user;
    }
}
