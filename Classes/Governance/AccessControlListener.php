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

namespace NITSAN\NsT3AF\Governance;

use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Gates AI provider calls in the backend against access rules, budgets and
 * rate limits. Runs on {@see BeforeProviderRequestEvent}; a failed check
 * short-circuits the call via {@see BeforeProviderRequestEvent::cancelWithReason()}.
 *
 * Checks, in order:
 *   1. be_groups       — provider restricted to backend groups (admins bypass)
 *   2. capability       — customPermOptions per call kind (admins bypass;
 *                         skipped entirely when no nst3af:* perm is set)
 *   3. budget           — UserTSconfig nst3af.budget.* (no bypass)
 *   4. rate limit       — UserTSconfig nst3af.rateLimit.* (no bypass)
 *
 * Front-end / CLI / scheduler contexts have no backend user and pass through
 * untouched, preserving existing behaviour.
 *
 * @internal
 */
final class AccessControlListener
{
    private const PERMISSION_PREFIX = 'nst3af:';

    /** Maps {@see BeforeProviderRequestEvent::$callKind} to a capability permission key. */
    private const CAPABILITY_PERMISSIONS = [
        'complete' => 'nst3af:capability_chat',
        'stream' => 'nst3af:capability_streaming',
        'embed' => 'nst3af:capability_embeddings',
        'image_generation' => 'nst3af:capability_image_generation',
        'tts' => 'nst3af:capability_tts',
    ];

    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly RequestLogRepository $logRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(BeforeProviderRequestEvent $event): void
    {
        $user = $this->getBackendUser();
        if ($user === null) {
            return;
        }

        $isAdmin = $user->isAdmin();

        if (!$isAdmin && $this->checkBeGroups($event, $user)) {
            return;
        }
        if (!$isAdmin && $this->checkCapabilityPermission($event, $user)) {
            return;
        }

        $userId = (int) ($user->user['uid'] ?? 0);

        $budgetReason = $this->checkBudget($userId, $user);
        if ($budgetReason !== null) {
            $this->deny($event, $budgetReason);
            return;
        }

        $rateReason = $this->checkRateLimit($userId, $user);
        if ($rateReason !== null) {
            $this->deny($event, $rateReason);
        }
    }

    /**
     * @return bool True when the call was denied (event cancelled).
     */
    private function checkBeGroups(BeforeProviderRequestEvent $event, BackendUserAuthentication $user): bool
    {
        $allowed = $event->provider->beGroups;
        if ($allowed === []) {
            return false;
        }

        $userGroups = array_map('intval', $user->userGroupsUID);
        if (array_intersect($allowed, $userGroups) !== []) {
            return false;
        }

        $this->deny($event, sprintf(
            'Provider "%s" is restricted to other backend groups.',
            $event->provider->identifier,
        ));

        return true;
    }

    /**
     * @return bool True when the call was denied (event cancelled).
     */
    private function checkCapabilityPermission(BeforeProviderRequestEvent $event, BackendUserAuthentication $user): bool
    {
        $customOptions = (string) ($user->groupData['custom_options'] ?? '');
        if (!str_contains($customOptions, self::PERMISSION_PREFIX)) {
            // No capability gating configured on this instance — stay permissive.
            return false;
        }

        $permissionKey = self::CAPABILITY_PERMISSIONS[$event->callKind] ?? null;
        if ($permissionKey === null) {
            return false;
        }

        if ($user->check('custom_options', $permissionKey)) {
            return false;
        }

        $this->deny($event, sprintf(
            'Missing AI capability permission "%s" for this request.',
            $permissionKey,
        ));

        return true;
    }

    private function checkBudget(int $userId, BackendUserAuthentication $user): ?string
    {
        $budgetConfig = $this->flatten($this->section($user, 'budget.'));
        if ($budgetConfig === []) {
            return null;
        }

        $result = $this->budgetService->checkBudget($userId, $budgetConfig);

        return $result->allowed ? null : $result->reason;
    }

    private function checkRateLimit(int $userId, BackendUserAuthentication $user): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $rateConfig = $this->section($user, 'rateLimit.');
        $perMinute = (int) ($rateConfig['requestsPerMinute'] ?? 0);
        if ($perMinute <= 0) {
            return null;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $count = $this->logRepository->countRecentRequestsByUser($userId, $now - 60);
        if ($count < $perMinute) {
            return null;
        }

        return sprintf('Rate limit exceeded (%d/%d requests per minute).', $count, $perMinute);
    }

    private function deny(BeforeProviderRequestEvent $event, string $reason): void
    {
        $event->cancelWithReason($reason);
        $this->logger->info('ns_t3af governance denied a request.', [
            'provider' => $event->provider->identifier,
            'callKind' => $event->callKind,
            'reason' => $reason,
        ]);
    }

    /**
     * Read a `nst3af.<section>` TSconfig subtree as an array.
     *
     * @return array<string, mixed>
     */
    private function section(BackendUserAuthentication $user, string $section): array
    {
        $root = $user->getTSConfig()['nst3af.'] ?? null;
        if (!is_array($root)) {
            return [];
        }
        $sub = $root[$section] ?? null;

        return is_array($sub) ? $sub : [];
    }

    /**
     * Drop trailing dots from TSconfig keys so leaf values are addressable.
     *
     * @param array<string, mixed> $values
     * @return array<string, scalar>
     */
    private function flatten(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $out[rtrim((string) $key, '.')] = $value;
        }

        return $out;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
