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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;

/**
 * Copies AI provider rows from one site root to another.
 *
 * @internal
 */
final class ProviderImportService
{
    public function __construct(
        private readonly ProviderRepositoryInterface $repository,
    ) {}

    /**
     * @return list<Provider>
     */
    public function listProvidersForSite(int $sourceStoragePid): array
    {
        if ($sourceStoragePid <= 0) {
            return [];
        }

        return $this->repository->findAllByStoragePid($sourceStoragePid, includeHidden: true);
    }

    /**
     * @param list<int> $sourceUids
     * @return array{imported: int, skipped: list<array{uid: int, identifier: string, reason: string}>}
     */
    public function importProviders(array $sourceUids, int $sourceStoragePid, int $targetStoragePid): array
    {
        if ($targetStoragePid <= 0 || $sourceStoragePid <= 0 || $sourceStoragePid === $targetStoragePid) {
            return ['imported' => 0, 'skipped' => []];
        }

        $imported = 0;
        $skipped = [];
        $hasDefault = $this->repository->findDefault($targetStoragePid) !== null;

        foreach ($sourceUids as $sourceUid) {
            $sourceUid = (int) $sourceUid;
            if ($sourceUid <= 0) {
                continue;
            }

            $source = $this->repository->findByUid($sourceUid);
            if (!$source instanceof Provider || $source->pid !== $sourceStoragePid) {
                $skipped[] = ['uid' => $sourceUid, 'identifier' => '', 'reason' => 'not_found'];
                continue;
            }

            $identifier = $this->allocateIdentifier($source->identifier, $targetStoragePid);
            if ($identifier === null) {
                $skipped[] = [
                    'uid' => $sourceUid,
                    'identifier' => $source->identifier,
                    'reason' => 'identifier_exists',
                ];
                continue;
            }

            $isDefault = $source->isDefault && !$hasDefault;
            $newUid = $this->repository->save(0, [
                'pid' => $targetStoragePid,
                'identifier' => $identifier,
                'title' => $source->title,
                'adapter_type' => $source->adapterType,
                'endpoint_url' => $source->endpointUrl,
                'api_key' => $source->apiKeyCipher,
                'model_id' => $source->modelId,
                'embedding_model_id' => $source->embeddingModelId,
                'capabilities' => implode(',', $source->capabilities),
                'temperature' => $source->temperature,
                'system_prompt' => $source->systemPrompt,
                'is_default' => $isDefault ? 1 : 0,
                'priority' => $source->priority,
                'last_used_at' => 0,
                'last_status' => $source->lastStatus,
                'last_status_at' => $source->lastStatusAt,
                'last_status_message' => $source->lastStatusMessage,
                'be_groups' => implode(',', $source->beGroups),
                'is_enabled' => $source->isEnabled ? 1 : 0,
                'enabled_for_dashboard' => $source->enabledForDashboard ? 1 : 0,
                'pricing_input_per_1m' => $source->pricingInputPer1m,
                'pricing_output_per_1m' => $source->pricingOutputPer1m,
                'pricing_currency' => $source->pricingCurrency,
                'retention_days_override' => $source->retentionDaysOverride,
                'cost_center' => $source->costCenter,
                'privacy_level' => $source->privacyLevel,
                'no_rerouting' => $source->noRerouting ? 1 : 0,
                'hidden' => 0,
                'deleted' => 0,
            ]);

            if ($isDefault) {
                $this->repository->setDefault($newUid, $targetStoragePid);
                $hasDefault = true;
            }

            ++$imported;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function allocateIdentifier(string $baseIdentifier, int $targetStoragePid): ?string
    {
        $baseIdentifier = trim($baseIdentifier);
        if ($baseIdentifier === '') {
            return null;
        }

        $candidate = $baseIdentifier;
        if ($this->repository->findByIdentifier($candidate, $targetStoragePid) === null) {
            return $candidate;
        }

        $suffix = 2;
        while ($suffix < 100) {
            $candidate = $this->trimIdentifier($baseIdentifier . '-' . $suffix);
            if ($this->repository->findByIdentifier($candidate, $targetStoragePid) === null) {
                return $candidate;
            }
            ++$suffix;
        }

        return null;
    }

    private function trimIdentifier(string $identifier): string
    {
        return mb_substr($identifier, 0, 64);
    }
}
