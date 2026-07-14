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

/**
 * Calculates profile completeness across seven configured sections.
 *
 * @internal
 */
final class BrandContextCompletenessCalculator
{
    /** @var list<array{id: string, labelKey: string}> */
    public const SECTIONS = [
        ['id' => 'identity', 'labelKey' => 'module.aiContext.section.identity'],
        ['id' => 'voice', 'labelKey' => 'module.aiContext.section.voice'],
        ['id' => 'audience', 'labelKey' => 'module.aiContext.section.audience'],
        ['id' => 'rules', 'labelKey' => 'module.aiContext.section.rules'],
        ['id' => 'keywords', 'labelKey' => 'module.aiContext.section.keywords'],
        ['id' => 'sample', 'labelKey' => 'module.aiContext.section.sample'],
    ];

    /**
     * @return array{
     *   percent: int,
     *   completed: int,
     *   total: int,
     *   sections: list<array{id: string, labelKey: string, complete: bool}>
     * }
     */
    public function calculate(BrandContextProfile $profile): array
    {
        $checks = [
            'identity' => $this->isIdentityComplete($profile),
            'voice' => $this->isVoiceComplete($profile),
            'audience' => $this->isAudienceComplete($profile),
            'rules' => $this->isRulesComplete($profile),
            'keywords' => $profile->keywords !== [],
            'sample' => $profile->sampleContent !== '',
        ];

        $sections = [];
        foreach (self::SECTIONS as $section) {
            $sections[] = [
                'id' => $section['id'],
                'labelKey' => $section['labelKey'],
                'complete' => (bool) ($checks[$section['id']] ?? false),
            ];
        }

        $completed = count(array_filter($checks, static fn(bool $value): bool => $value));
        $total = count($checks);

        return [
            'percent' => $total > 0 ? (int) min(100, (int) round(100 * $completed / $total)) : 0,
            'completed' => $completed,
            'total' => $total,
            'sections' => $sections,
        ];
    }

    public function calculatePercent(BrandContextProfile $profile): int
    {
        return $this->calculate($profile)['percent'];
    }

    private function isIdentityComplete(BrandContextProfile $profile): bool
    {
        if ($profile->brandName === '') {
            return false;
        }

        return $profile->tagline !== '' || $profile->description !== '';
    }

    private function isVoiceComplete(BrandContextProfile $profile): bool
    {
        return $profile->toneTags !== [] || $profile->voiceNotes !== '';
    }

    private function isAudienceComplete(BrandContextProfile $profile): bool
    {
        foreach ($profile->personas as $persona) {
            $name = trim((string) ($persona['name'] ?? ''));
            $level = trim((string) ($persona['level'] ?? ''));
            if ($name !== '' && $level !== '') {
                return true;
            }
        }

        return false;
    }

    private function isRulesComplete(BrandContextProfile $profile): bool
    {
        foreach ($profile->contentRules as $rule) {
            if (trim((string) ($rule['text'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
