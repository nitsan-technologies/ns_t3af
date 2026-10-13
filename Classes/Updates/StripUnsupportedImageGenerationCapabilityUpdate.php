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

namespace NITSAN\NsT3AF\Updates;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepository;
use NITSAN\NsT3AF\Provider\Capability;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Removes image_generation from chat-only providers (Gemini, Mistral, Claude, …)
 * that could be ticked in the UI but are never used by the image tool.
 *
 * @internal
 */
#[UpgradeWizard('nst3afStripUnsupportedImageGenerationCapability')]
final class StripUnsupportedImageGenerationCapabilityUpdate implements UpgradeWizardInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'AI Foundation: drop image generation tick from chat-only providers';
    }

    public function getDescription(): string
    {
        return 'Providers such as Gemini or Mistral could have "image generation" ticked even though'
            . ' the image tool cannot use them. This removes that capability from those rows.';
    }

    public function executeUpdate(): bool
    {
        foreach ($this->rowsNeedingStrip() as $row) {
            $adapterType = Provider::normalizeAdapterType((string) ($row['adapter_type'] ?? ''));
            $caps = Provider::capabilitiesForAdapter($adapterType, Capability::fromCsv((string) ($row['capabilities'] ?? '')));
            $this->connectionPool->getConnectionForTable(ProviderRepository::TABLE)->update(
                ProviderRepository::TABLE,
                ['capabilities' => Capability::toCsv($caps)],
                ['uid' => (int) $row['uid']],
            );
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        return $this->rowsNeedingStrip() !== [];
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsNeedingStrip(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(ProviderRepository::TABLE);
        $rows = $qb->select('uid', 'adapter_type', 'capabilities')
            ->from(ProviderRepository::TABLE)
            ->where(
                $qb->expr()->like(
                    'capabilities',
                    $qb->createNamedParameter('%' . $qb->escapeLikeWildcards(Capability::IMAGE_GENERATION) . '%'),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $needs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $adapterType = Provider::normalizeAdapterType((string) ($row['adapter_type'] ?? ''));
            if (Provider::adapterSupportsImageGeneration($adapterType)) {
                continue;
            }
            $caps = Capability::fromCsv((string) ($row['capabilities'] ?? ''));
            if (!in_array(Capability::IMAGE_GENERATION, $caps, true)) {
                continue;
            }
            $needs[] = $row;
        }

        return $needs;
    }
}
