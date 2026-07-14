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

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds dashboard AI Context overview bar data.
 *
 * @internal
 */
final class BrandContextDashboardPresenter
{
    public function __construct(
        private readonly BrandContextProfileRepositoryInterface $profiles,
        private readonly BrandContextCompletenessCalculator $completenessCalculator,
        private readonly SiteStorageContext $siteStorageContext,
    ) {}

    /**
     * @return array{
     *   hasDefault: int,
     *   profileCount: int,
     *   brandName: string,
     *   industry: string,
     *   completenessPercent: int,
     *   completenessCompleted: int,
     *   completenessTotal: int,
     *   progressDash: string,
     *   sections: list<array{id: string, labelKey: string, complete: int}>,
     *   aiContextUri: string,
     *   siteResolved: int
     * }
     */
    public function build(ServerRequestInterface $request, string $aiContextUri): array
    {
        $resolution = $this->siteStorageContext->resolveFromRequest($request);
        $storagePid = $resolution->isResolved() ? $resolution->storagePid : 0;

        $defaultProfile = $storagePid > 0 ? $this->profiles->findDefault($storagePid) : null;
        $profileCount = $storagePid > 0 ? $this->profiles->countByStoragePid($storagePid) : 0;

        $brandName = '';
        $industry = '';
        $completenessPercent = 0;
        $completenessCompleted = 0;
        $completenessTotal = count(BrandContextCompletenessCalculator::SECTIONS);
        $sections = $this->buildSectionRows(null);

        if ($defaultProfile !== null) {
            $brandName = $defaultProfile->brandName;
            $industry = $defaultProfile->industry;
            $completeness = $this->completenessCalculator->calculate($defaultProfile);
            $completenessPercent = $completeness['percent'];
            $completenessCompleted = $completeness['completed'];
            $completenessTotal = $completeness['total'];
            $sections = $this->buildSectionRows($defaultProfile);
        }

        return [
            'hasDefault' => self::fluidFlag($defaultProfile !== null),
            'profileCount' => $profileCount,
            'brandName' => $brandName,
            'industry' => $industry,
            'completenessPercent' => $completenessPercent,
            'completenessCompleted' => $completenessCompleted,
            'completenessTotal' => $completenessTotal,
            'progressDash' => (string) (int) max(0, min(97, round($completenessPercent * 97 / 100))),
            'sections' => $sections,
            'aiContextUri' => $aiContextUri,
            'siteResolved' => self::fluidFlag($resolution->isResolved()),
        ];
    }

    /**
     * @return list<array{id: string, labelKey: string, complete: int}>
     */
    private function buildSectionRows(?BrandContextProfile $profile): array
    {
        $completeness = $profile !== null
            ? $this->completenessCalculator->calculate($profile)
            : null;

        $rows = [];
        foreach (BrandContextCompletenessCalculator::SECTIONS as $section) {
            $complete = false;
            if ($completeness !== null) {
                foreach ($completeness['sections'] as $resolvedSection) {
                    if (($resolvedSection['id'] ?? '') === $section['id']) {
                        $complete = (bool) ($resolvedSection['complete'] ?? false);
                        break;
                    }
                }
            }

            $rows[] = [
                'id' => $section['id'],
                'labelKey' => $section['labelKey'],
                'complete' => self::fluidFlag($complete),
            ];
        }

        return $rows;
    }

    private static function fluidFlag(bool $value): int
    {
        return $value ? 1 : 0;
    }
}
