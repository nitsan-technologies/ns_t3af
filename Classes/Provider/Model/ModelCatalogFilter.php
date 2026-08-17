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

namespace NITSAN\NsT3AF\Provider\Model;

/**
 * Hides retired, preview, and superseded vendor model ids from wizard pills and
 * provider drawer lists. Symfony AI bundled catalogs lag vendor retirements.
 *
 * @internal
 */
final class ModelCatalogFilter
{
    /**
     * Vendor-retired ids still present in bundled Symfony catalogs (2026-08).
     *
     * @var list<string>
     */
    private const DENYLIST = [
        'claude-sonnet-4-20250514',
        'gemini-2.5-flash-lite-preview-09-2025',
    ];

    /**
     * @param list<string> $catalogIds Full candidate id set (live + catalog merge).
     */
    public function isListedModelId(string $id, array $catalogIds): bool
    {
        if ($id === '' || in_array($id, self::DENYLIST, true)) {
            return false;
        }

        $lower = strtolower($id);
        if (str_contains($lower, '-preview') || str_ends_with($lower, '-latest')) {
            return false;
        }

        return !$this->isSuperseded($lower, $catalogIds);
    }

    /**
     * @param list<string> $catalogIds
     */
    public function isWizardChatModelId(string $id, array $catalogIds): bool
    {
        if (!$this->isListedModelId($id, $catalogIds)) {
            return false;
        }

        return !$this->isNonChatModelId($id);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function filterListedModelIds(array $ids): array
    {
        return array_values(array_filter(
            $ids,
            fn(string $id): bool => $this->isListedModelId($id, $ids),
        ));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function filterWizardChatModelIds(array $ids): array
    {
        return array_values(array_filter(
            $ids,
            fn(string $id): bool => $this->isWizardChatModelId($id, $ids),
        ));
    }

    /**
     * @param list<string> $catalogIds
     */
    private function isSuperseded(string $lower, array $catalogIds): bool
    {
        $lookup = array_fill_keys($catalogIds, true);

        if (preg_match('/^claude-sonnet-4-\d{8}$/', $lower) === 1 && $this->hasAnyPrefix($lookup, [
            'claude-sonnet-4-5',
            'claude-sonnet-4-6',
            'claude-sonnet-5',
        ])) {
            return true;
        }

        if (preg_match('/^claude-opus-4-\d{8}$/', $lower) === 1 && $this->hasAnyPrefix($lookup, [
            'claude-opus-4-5',
            'claude-opus-4-6',
        ])) {
            return true;
        }

        if (preg_match('/^gemini-2\\.0-/', $lower) === 1 && $this->hasAnyPrefix($lookup, [
            'gemini-2.5',
            'gemini-3',
        ])) {
            return true;
        }

        if (preg_match('/^gpt-3\\.5-/', $lower) === 1 && $this->hasAnyPrefix($lookup, ['gpt-4o', 'gpt-4.1', 'gpt-5'])) {
            return true;
        }

        if (in_array($lower, ['gpt-4', 'gpt-4-turbo'], true) && $this->hasAnyPrefix($lookup, ['gpt-4o', 'gpt-4.1', 'gpt-5'])) {
            return true;
        }

        return preg_match('/-20240[23]\d{2}$/', $lower) === 1
            && $this->hasAnyPrefix($lookup, ['claude-sonnet-4', 'claude-haiku-4', 'gpt-4o', 'gpt-5', 'gemini-2.5']);
    }

    private function isNonChatModelId(string $id): bool
    {
        $lower = strtolower($id);

        return str_contains($lower, 'embed')
            || str_contains($lower, 'whisper')
            || str_contains($lower, 'tts')
            || str_contains($lower, 'image')
            || str_contains($lower, 'audio')
            || str_contains($lower, 'realtime')
            || str_contains($lower, 'instruct')
            || str_contains($lower, 'deep-research')
            || str_contains($lower, 'codex')
            || str_contains($lower, 'fable');
    }

    /**
     * @param array<string, bool> $lookup
     * @param list<string>        $prefixes
     */
    private function hasAnyPrefix(array $lookup, array $prefixes): bool
    {
        foreach (array_keys($lookup) as $candidate) {
            $candidateLower = strtolower($candidate);
            foreach ($prefixes as $prefix) {
                if (str_starts_with($candidateLower, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
