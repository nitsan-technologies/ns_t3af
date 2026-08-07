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

namespace NITSAN\NsT3AF\Tests\Unit\Access\Support;

use NITSAN\NsT3AF\Access\Enum\BulkOpsLevel;
use NITSAN\NsT3AF\Access\Enum\FeatureLevel;
use NITSAN\NsT3AF\Access\Enum\RecordAccess;
use NITSAN\NsT3AF\Contract\GroupPresetContributorInterface;

/**
 * Stand-in contributors so ns_t3af unit tests do not depend on child package autoload.
 */
final class StubGroupPresetContributors
{
    /**
     * @return list<GroupPresetContributorInterface>
     */
    public static function all(): array
    {
        return [
            new StubT3AiGroupPresetContributor(),
            new StubT3AaGroupPresetContributor(),
        ];
    }
}

final class StubT3AiGroupPresetContributor implements GroupPresetContributorInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function contribute(string $presetId): array
    {
        return match ($presetId) {
            'consumer' => [
                'modules' => ['t3ai'],
                'features' => [
                    'content' => FeatureLevel::Use->value,
                    'seo' => FeatureLevel::Use->value,
                    'translation' => FeatureLevel::Use->value,
                    'media' => FeatureLevel::Use->value,
                    'prompts' => FeatureLevel::Use->value,
                    'bulkOps' => BulkOpsLevel::Disabled->value,
                ],
            ],
            'editor' => [
                'modules' => ['t3ai'],
                'features' => [
                    'content' => FeatureLevel::Manage->value,
                    'seo' => FeatureLevel::Manage->value,
                    'translation' => FeatureLevel::Use->value,
                    'media' => FeatureLevel::Use->value,
                    'prompts' => FeatureLevel::Manage->value,
                    'bulkOps' => BulkOpsLevel::Scoped->value,
                ],
                'records' => [
                    'translationGlossary' => RecordAccess::Read,
                ],
            ],
            'manager' => [
                'modules' => ['t3ai'],
                'features' => [
                    'content' => FeatureLevel::Manage->value,
                    'seo' => FeatureLevel::Manage->value,
                    'translation' => FeatureLevel::Manage->value,
                    'media' => FeatureLevel::Manage->value,
                    'prompts' => FeatureLevel::Manage->value,
                    'bulkOps' => BulkOpsLevel::Any->value,
                ],
                'records' => [
                    'translationGlossary' => RecordAccess::ReadWrite,
                    'bulkTranslation' => RecordAccess::ReadWrite,
                    'bulkSeo' => RecordAccess::ReadWrite,
                ],
            ],
            default => [],
        };
    }
}

final class StubT3AaGroupPresetContributor implements GroupPresetContributorInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function contribute(string $presetId): array
    {
        if ($presetId !== 'manager') {
            return [];
        }

        return [
            'modules' => ['t3aa'],
            'features' => [
                't3aaPageSpeed' => FeatureLevel::Manage->value,
                't3aaFileMeta' => FeatureLevel::Manage->value,
                't3aaMedia' => FeatureLevel::Manage->value,
            ],
        ];
    }
}
