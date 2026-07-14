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

use NITSAN\NsT3AF\Domain\Repository\GroupSettingsRepository;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Enforces per-group limits from tx_nst3af_group_settings on AI provider calls.
 *
 * Usage counters are per backend user (each member inherits the strictest group cap).
 *
 * @internal
 */
final class GroupLimitsListener
{
    public function __construct(
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly RequestLogRepository $requestLogRepository,
    ) {}

    public function __invoke(BeforeProviderRequestEvent $event): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || $user->isAdmin()) {
            return;
        }

        $limits = $this->resolveStrictestLimits($user);
        if ($limits === null) {
            return;
        }

        $userId = (int) ($user->user['uid'] ?? 0);
        if ($userId > 0) {
            $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
            $limits['daily_requests_used'] = $this->requestLogRepository->countRecentRequestsByUser(
                $userId,
                (int) strtotime('today', $now),
            );
            $limits['credits_used_month'] = (int) $this->requestLogRepository->sumCreditsUsedByUserSince(
                $userId,
                (int) strtotime('first day of this month 00:00:00', $now),
            );
        }

        if ($limits['provider_allowlist_enabled'] && $limits['allowed_providers'] !== []) {
            $providerId = $event->provider->identifier;
            if (!in_array($providerId, $limits['allowed_providers'], true)) {
                $event->cancelWithReason('Group policy blocks this AI provider.');
            }
        }

        if ($limits['daily_request_cap'] > 0 && $limits['daily_requests_used'] >= $limits['daily_request_cap']) {
            $event->cancelWithReason('Daily AI request limit reached for your backend user group.');
        }

        if ($limits['credit_cap_monthly'] > 0 && $limits['credits_used_month'] >= $limits['credit_cap_monthly']) {
            $event->cancelWithReason('Monthly AI credit cap reached for your backend user group.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveStrictestLimits(BackendUserAuthentication $user): ?array
    {
        $groupUids = array_map('intval', $user->userGroupsUID);
        $merged = null;

        foreach ($groupUids as $groupUid) {
            $row = $this->groupSettingsRepository->findByBeGroupUid($groupUid);
            if ($row === null || (int) ($row['configured'] ?? 0) !== 1) {
                continue;
            }

            $json = $row['limits_json'] ?? '';
            $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $allowed = [];
            if (!empty($decoded['providerAllowlistEnabled']) && is_array($decoded['allowedProviders'] ?? null)) {
                $allowed = $decoded['allowedProviders'];
            }

            $candidate = [
                'provider_allowlist_enabled' => !empty($decoded['providerAllowlistEnabled']),
                'allowed_providers' => $allowed,
                'credit_cap_monthly' => (int) ($row['credit_cap_monthly'] ?? 0),
                'daily_request_cap' => (int) ($row['daily_request_cap'] ?? 0),
                'credits_used_month' => 0,
                'daily_requests_used' => 0,
            ];

            if ($merged === null) {
                $merged = $candidate;
                continue;
            }

            if ($candidate['credit_cap_monthly'] > 0 && ($merged['credit_cap_monthly'] === 0 || $candidate['credit_cap_monthly'] < $merged['credit_cap_monthly'])) {
                $merged['credit_cap_monthly'] = $candidate['credit_cap_monthly'];
            }
            if ($candidate['daily_request_cap'] > 0 && ($merged['daily_request_cap'] === 0 || $candidate['daily_request_cap'] < $merged['daily_request_cap'])) {
                $merged['daily_request_cap'] = $candidate['daily_request_cap'];
            }
            if ($candidate['provider_allowlist_enabled']) {
                $merged['provider_allowlist_enabled'] = true;
                $merged['allowed_providers'] = $merged['allowed_providers'] === []
                    ? $candidate['allowed_providers']
                    : array_values(array_intersect($merged['allowed_providers'], $candidate['allowed_providers']));
            }
        }

        return $merged;
    }
}
