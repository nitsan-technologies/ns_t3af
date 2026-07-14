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

namespace NITSAN\NsT3AF\Access\Dto;

use NITSAN\NsT3AF\Access\Enum\LoggingPolicy;
use NITSAN\NsT3AF\Access\PayloadBoolean;

final class LimitsConfig
{
    /**
     * @param list<string> $allowedProviders
     */
    public function __construct(
        public bool $providerAllowlistEnabled = false,
        public array $allowedProviders = [],
        public bool $allowModelOverride = true,
        public bool $creditCapEnabled = false,
        public int $creditCapMonthly = 500,
        public bool $dailyRequestCapEnabled = false,
        public int $dailyRequestCap = 100,
        public bool $bulkPageLimitEnabled = false,
        public int $bulkPageLimit = 25,
        public bool $schedulerBatchLimitEnabled = false,
        public int $schedulerBatchLimit = 500,
        public bool $workspaceEnforcement = false,
        public ?string $lockedContextProfile = null,
        public ?string $requiredBrandVoice = null,
        public bool $qualityThresholdEnabled = false,
        public int $qualityThresholdScore = 70,
        public LoggingPolicy $loggingPolicy = LoggingPolicy::Always,
        public int $logRetentionDays = 30,
        public bool $piiMasking = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            providerAllowlistEnabled: PayloadBoolean::parse($data['providerAllowlistEnabled'] ?? false),
            allowedProviders: is_array($data['allowedProviders'] ?? null)
                ? array_values(array_map(static fn($v) => (string) $v, $data['allowedProviders']))
                : [],
            allowModelOverride: array_key_exists('allowModelOverride', $data)
                ? PayloadBoolean::parse($data['allowModelOverride'])
                : true,
            creditCapEnabled: PayloadBoolean::parse($data['creditCapEnabled'] ?? false),
            creditCapMonthly: (int) ($data['creditCapMonthly'] ?? 500),
            dailyRequestCapEnabled: PayloadBoolean::parse($data['dailyRequestCapEnabled'] ?? false),
            dailyRequestCap: (int) ($data['dailyRequestCap'] ?? 100),
            bulkPageLimitEnabled: PayloadBoolean::parse($data['bulkPageLimitEnabled'] ?? false),
            bulkPageLimit: (int) ($data['bulkPageLimit'] ?? 25),
            schedulerBatchLimitEnabled: PayloadBoolean::parse($data['schedulerBatchLimitEnabled'] ?? false),
            schedulerBatchLimit: (int) ($data['schedulerBatchLimit'] ?? 500),
            workspaceEnforcement: PayloadBoolean::parse($data['workspaceEnforcement'] ?? false),
            lockedContextProfile: isset($data['lockedContextProfile']) && $data['lockedContextProfile'] !== ''
                ? (string) $data['lockedContextProfile']
                : null,
            requiredBrandVoice: isset($data['requiredBrandVoice']) && $data['requiredBrandVoice'] !== ''
                ? (string) $data['requiredBrandVoice']
                : null,
            qualityThresholdEnabled: PayloadBoolean::parse($data['qualityThresholdEnabled'] ?? false),
            qualityThresholdScore: (int) ($data['qualityThresholdScore'] ?? 70),
            loggingPolicy: LoggingPolicy::tryFromString((string) ($data['loggingPolicy'] ?? 'always')),
            logRetentionDays: (int) ($data['logRetentionDays'] ?? 30),
            piiMasking: PayloadBoolean::parse($data['piiMasking'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'providerAllowlistEnabled' => $this->providerAllowlistEnabled,
            'allowedProviders' => $this->allowedProviders,
            'allowModelOverride' => $this->allowModelOverride,
            'creditCapEnabled' => $this->creditCapEnabled,
            'creditCapMonthly' => $this->creditCapMonthly,
            'dailyRequestCapEnabled' => $this->dailyRequestCapEnabled,
            'dailyRequestCap' => $this->dailyRequestCap,
            'bulkPageLimitEnabled' => $this->bulkPageLimitEnabled,
            'bulkPageLimit' => $this->bulkPageLimit,
            'schedulerBatchLimitEnabled' => $this->schedulerBatchLimitEnabled,
            'schedulerBatchLimit' => $this->schedulerBatchLimit,
            'workspaceEnforcement' => $this->workspaceEnforcement,
            'lockedContextProfile' => $this->lockedContextProfile,
            'requiredBrandVoice' => $this->requiredBrandVoice,
            'qualityThresholdEnabled' => $this->qualityThresholdEnabled,
            'qualityThresholdScore' => $this->qualityThresholdScore,
            'loggingPolicy' => $this->loggingPolicy->value,
            'logRetentionDays' => $this->logRetentionDays,
            'piiMasking' => $this->piiMasking,
        ];
    }
}
