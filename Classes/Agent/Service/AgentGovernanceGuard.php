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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Access\Dto\LimitsConfig;
use NITSAN\NsT3AF\Domain\Repository\GroupSettingsRepository;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Agent-only governance: group limits, model override, PII masking, turn guard (T15/T16).
 *
 * @internal
 */
final readonly class AgentGovernanceGuard
{
    public const TURN_GUARD_WARN = 20;
    public const TURN_GUARD_ABORT = 40;

    public function __construct(
        private GroupSettingsRepository $groupSettingsRepository,
        private RequestLogRepository $requestLogRepository,
        private AgentTurnRepository $agentTurnRepository,
    ) {}

    /**
     * @param array<string, mixed> $body
     */
    public function assertTurnAllowed(BackendUserAuthentication $user, array $body): ?string
    {
        if ($user->isAdmin()) {
            return null;
        }

        $limits = $this->resolveStrictestLimits($user);
        if ($limits === null) {
            return null;
        }

        $userId = (int) ($user->user['uid'] ?? 0);
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());

        if ($limits->providerAllowlistEnabled && $limits->allowedProviders !== []) {
            $provider = trim((string) ($body['provider'] ?? $body['providerId'] ?? ''));
            if ($provider !== '' && !in_array($provider, $limits->allowedProviders, true)) {
                return $this->translate('agent.governance.providerBlocked');
            }
        }

        if (!$limits->allowModelOverride) {
            $model = trim((string) ($body['model'] ?? $body['modelId'] ?? ''));
            if ($model !== '') {
                return $this->translate('agent.governance.modelOverrideBlocked');
            }
        }

        if ($limits->dailyRequestCapEnabled && $limits->dailyRequestCap > 0 && $userId > 0) {
            $used = $this->agentTurnRepository->countTurnsToday($userId);
            if ($used >= $limits->dailyRequestCap) {
                return $this->translate('agent.governance.dailyCapReached');
            }
        }

        if ($limits->creditCapEnabled && $limits->creditCapMonthly > 0 && $userId > 0) {
            $usedCredits = (int) $this->requestLogRepository->sumCreditsUsedByUserSince(
                $userId,
                (int) strtotime('first day of this month 00:00:00', $now),
            );
            if ($usedCredits >= $limits->creditCapMonthly) {
                return $this->translate('agent.governance.creditCapReached');
            }
        }

        return null;
    }

    /**
     * @return array{allowed: bool, level: string, message: string|null}
     */
    public function evaluateTurnGuard(int $toolCallCount): array
    {
        if ($toolCallCount >= self::TURN_GUARD_ABORT) {
            return [
                'allowed' => false,
                'level' => 'abort',
                'message' => $this->translate('agent.governance.turnGuardAbort', [self::TURN_GUARD_ABORT]),
            ];
        }

        if ($toolCallCount >= self::TURN_GUARD_WARN) {
            return [
                'allowed' => true,
                'level' => 'warn',
                'message' => $this->translate('agent.governance.turnGuardWarn', [self::TURN_GUARD_WARN, $toolCallCount]),
            ];
        }

        return [
            'allowed' => true,
            'level' => 'ok',
            'message' => null,
        ];
    }

    public function requiresPiiMasking(BackendUserAuthentication $user): bool
    {
        if ($user->isAdmin()) {
            return false;
        }

        $limits = $this->resolveStrictestLimits($user);

        return $limits !== null && $limits->piiMasking;
    }

    public function maskPii(string $content): string
    {
        $masked = preg_replace(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            '[email redacted]',
            $content,
        ) ?? $content;

        return preg_replace(
            '/\b(?:\+?\d{1,3}[\s-]?)?(?:\(\d{2,4}\)|\d{2,4})[\s-]?\d{3,4}[\s-]?\d{3,4}\b/',
            '[phone redacted]',
            $masked,
        ) ?? $masked;
    }

    public function resolveSchedulerBatchLimit(BackendUserAuthentication $user): int
    {
        if ($user->isAdmin()) {
            return 0;
        }

        $limits = $this->resolveStrictestLimits($user);
        if ($limits === null || !$limits->schedulerBatchLimitEnabled) {
            return 0;
        }

        return max(0, $limits->schedulerBatchLimit);
    }

    public function requiresWorkspaceEnforcement(BackendUserAuthentication $user): bool
    {
        if ($user->isAdmin()) {
            return false;
        }

        $limits = $this->resolveStrictestLimits($user);

        return $limits !== null && $limits->workspaceEnforcement;
    }

    public function assertDraftApplyAllowed(BackendUserAuthentication $user, int $workspaceId): ?string
    {
        if (!$this->requiresWorkspaceEnforcement($user)) {
            return null;
        }

        if ($workspaceId > 0) {
            return null;
        }

        return $this->translate('agent.governance.workspaceEnforcementBlocked');
    }

    private function resolveStrictestLimits(BackendUserAuthentication $user): ?LimitsConfig
    {
        $merged = null;

        foreach (array_map('intval', $user->userGroupsUID) as $groupUid) {
            $row = $this->groupSettingsRepository->findByBeGroupUid($groupUid);
            if ($row === null || (int) ($row['configured'] ?? 0) !== 1) {
                continue;
            }

            $json = $row['limits_json'] ?? '';
            $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $candidate = LimitsConfig::fromArray($decoded);

            if ($merged === null) {
                $merged = $candidate;
                continue;
            }

            $merged = $this->mergeStrictest($merged, $candidate);
        }

        return $merged;
    }

    private function mergeStrictest(LimitsConfig $a, LimitsConfig $b): LimitsConfig
    {
        $creditCap = $this->strictestCap($a->creditCapEnabled, $a->creditCapMonthly, $b->creditCapEnabled, $b->creditCapMonthly);
        $dailyCap = $this->strictestCap($a->dailyRequestCapEnabled, $a->dailyRequestCap, $b->dailyRequestCapEnabled, $b->dailyRequestCap);
        $schedulerCap = $this->strictestCap(
            $a->schedulerBatchLimitEnabled,
            $a->schedulerBatchLimit,
            $b->schedulerBatchLimitEnabled,
            $b->schedulerBatchLimit,
        );

        $allowedProviders = $a->allowedProviders;
        if ($b->providerAllowlistEnabled) {
            $allowedProviders = $allowedProviders === []
                ? $b->allowedProviders
                : array_values(array_intersect($allowedProviders, $b->allowedProviders));
        }

        return new LimitsConfig(
            providerAllowlistEnabled: $a->providerAllowlistEnabled || $b->providerAllowlistEnabled,
            allowedProviders: $allowedProviders,
            allowModelOverride: $a->allowModelOverride && $b->allowModelOverride,
            creditCapEnabled: $creditCap['enabled'],
            creditCapMonthly: $creditCap['value'],
            dailyRequestCapEnabled: $dailyCap['enabled'],
            dailyRequestCap: $dailyCap['value'],
            bulkPageLimitEnabled: $a->bulkPageLimitEnabled || $b->bulkPageLimitEnabled,
            bulkPageLimit: min(
                $a->bulkPageLimitEnabled ? $a->bulkPageLimit : PHP_INT_MAX,
                $b->bulkPageLimitEnabled ? $b->bulkPageLimit : PHP_INT_MAX,
            ),
            schedulerBatchLimitEnabled: $schedulerCap['enabled'],
            schedulerBatchLimit: $schedulerCap['value'],
            workspaceEnforcement: $a->workspaceEnforcement || $b->workspaceEnforcement,
            lockedContextProfile: $a->lockedContextProfile ?? $b->lockedContextProfile,
            requiredBrandVoice: $a->requiredBrandVoice ?? $b->requiredBrandVoice,
            qualityThresholdEnabled: $a->qualityThresholdEnabled || $b->qualityThresholdEnabled,
            qualityThresholdScore: min($a->qualityThresholdScore, $b->qualityThresholdScore),
            loggingPolicy: $a->loggingPolicy,
            logRetentionDays: min($a->logRetentionDays, $b->logRetentionDays),
            piiMasking: $a->piiMasking || $b->piiMasking,
        );
    }

    /**
     * @return array{enabled: bool, value: int}
     */
    private function strictestCap(bool $enabledA, int $valueA, bool $enabledB, int $valueB): array
    {
        if (!$enabledA && !$enabledB) {
            return ['enabled' => false, 'value' => 0];
        }

        $candidates = [];
        if ($enabledA && $valueA > 0) {
            $candidates[] = $valueA;
        }
        if ($enabledB && $valueB > 0) {
            $candidates[] = $valueB;
        }

        if ($candidates === []) {
            return ['enabled' => true, 'value' => 0];
        }

        return ['enabled' => true, 'value' => min($candidates)];
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key;
        $value = $languageService instanceof LanguageService
            ? (string) $languageService->sL($label)
            : $key;

        if ($value === '' || $value === $label) {
            $value = $key;
        }

        if ($arguments === []) {
            return $value;
        }

        return sprintf($value, ...array_map(static fn(int|string $argument): string => (string) $argument, $arguments));
    }
}
