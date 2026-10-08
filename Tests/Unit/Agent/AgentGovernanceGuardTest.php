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

use NITSAN\NsT3AF\Agent\Service\AgentGovernanceGuard;
use NITSAN\NsT3AF\Agent\Service\AgentTurnRepository;
use NITSAN\NsT3AF\Domain\Repository\GroupSettingsRepository;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Provider allowlists of several groups (tickets 14zervyucgm, 14zervyucgp).
 *
 * @internal
 */
final class AgentGovernanceGuardTest extends TestCase
{
    use AgentTranslatorTrait;

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function singleGroupAllowlistIsEnforced(): void
    {
        $guard = $this->guard([8 => ['mistral-qa']]);
        $user = $this->editor([8]);

        self::assertSame(['mistral-qa'], $guard->allowedProviders($user));
    }

    #[Test]
    public function groupsWithDisjointAllowlistsAllowNoProvider(): void
    {
        $guard = $this->guard([8 => ['mistral-qa'], 9 => ['openai']]);
        $user = $this->editor([8, 9]);

        $allowed = $guard->allowedProviders($user);
        self::assertSame([AgentGovernanceGuard::NO_PROVIDER_ALLOWED], $allowed);
        self::assertNotNull($guard->assertTurnAllowed($user, ['provider' => 'Mistral']));
        self::assertNotNull($guard->assertTurnAllowed($user, ['provider' => 'mistral-qa']));
    }

    #[Test]
    public function groupsWithOverlappingAllowlistsAllowTheCommonProviders(): void
    {
        $guard = $this->guard([8 => ['a', 'b'], 9 => ['b', 'c']]);

        self::assertSame(['b'], $guard->allowedProviders($this->editor([8, 9])));
    }

    #[Test]
    public function groupWithoutAllowlistDoesNotLoosenOrBreakTheOther(): void
    {
        $guard = $this->guard([8 => ['a'], 9 => []]);

        self::assertSame(['a'], $guard->allowedProviders($this->editor([8, 9])));
        self::assertSame(['a'], $guard->allowedProviders($this->editor([9, 8])));
    }

    #[Test]
    public function defaultPlaceholderIsNotComparedWithTheAllowlist(): void
    {
        $guard = $this->guard([8 => ['mistral-qa']]);
        $user = $this->editor([8]);

        self::assertNull($guard->assertTurnAllowed($user, ['provider' => 'default']));
        self::assertNull($guard->assertTurnAllowed($user, ['provider' => 'mistral-qa']));
        self::assertNotNull($guard->assertTurnAllowed($user, ['provider' => 'openai']));
    }

    #[Test]
    public function maskingCoversSummariesFactsAndDetailsNotOnlyTheText(): void
    {
        $guard = $this->guard([]);
        $presented = $guard->maskPresentedResult([
            'content' => 'Mail anna@example.com',
            'summary' => 'Contact anna@example.com',
            'llmSummary' => 'Anna (anna@example.com) called +49 170 1234567',
            'error' => null,
            'facts' => [['label' => 'Mail', 'value' => 'anna@example.com']],
            'details' => ['rows' => [['email' => 'anna@example.com']]],
        ]);

        $encoded = json_encode($presented, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('anna@example.com', $encoded);
        self::assertStringNotContainsString('1234567', $encoded);
        self::assertNull($presented['error']);
    }

    /**
     * @param array<int, list<string>> $allowlistByGroup
     */
    private function guard(array $allowlistByGroup): AgentGovernanceGuard
    {
        $groups = $this->createMock(GroupSettingsRepository::class);
        $groups->method('findByBeGroupUid')->willReturnCallback(
            static fn(int $uid): ?array => isset($allowlistByGroup[$uid])
                ? [
                    'configured' => 1,
                    'limits_json' => json_encode([
                        'providerAllowlistEnabled' => $allowlistByGroup[$uid] !== [],
                        'allowedProviders' => $allowlistByGroup[$uid],
                    ], JSON_THROW_ON_ERROR),
                ]
                : null,
        );

        return new AgentGovernanceGuard(
            $groups,
            $this->createMock(RequestLogRepository::class),
            (new \ReflectionClass(AgentTurnRepository::class))->newInstanceWithoutConstructor(),
            $this->createAgentTranslator(),
        );
    }

    /**
     * @param list<int> $groupUids
     */
    private function editor(array $groupUids): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->userGroupsUID = $groupUids;
        $user->user = ['uid' => 11];

        return $user;
    }
}
