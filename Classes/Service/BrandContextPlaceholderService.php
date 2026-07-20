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
 * Maps brand context profile fields to `{placeholder}` tokens.
 *
 * @internal
 */
final class BrandContextPlaceholderService
{
    /**
     * @var list<string> All tokens supported by {@see buildMap()}.
     *
     * `[brand_profile]` (CTX-06) is an inline full-block token: it expands to the
     * whole assembled brand block, unlike the `{brand_*}` field tokens. It is
     * intentionally excluded from {@see \NITSAN\NsT3AF\Service\BrandContextService::PLACEHOLDERS}
     * (the editor placeholder bar) because it is a block expansion, not a field.
     */
    private const TOKENS = [
        '{brand_context}',
        '[brand_profile]',
        '{brand_name}',
        '{brand_voice}',
        '{target_audience}',
        '{target_persona}',
        '{content_rules}',
        '{keywords}',
        '{forbidden_words}',
        '{competitors}',
        '{compliance_notes}',
    ];

    /**
     * Map with every known token resolved to an empty string. Used to strip
     * brand tokens from outgoing prompts when no profile resolves (CTX-04),
     * so literal `{brand_*}` placeholders never leak to the model.
     *
     * @return array<string, string>
     */
    public function buildEmptyMap(): array
    {
        return array_fill_keys(self::TOKENS, '');
    }

    /**
     * @return array<string, string>
     */
    public function buildMap(BrandContextProfile $profile): array
    {
        return [
            '{brand_context}' => '',
            '[brand_profile]' => '',
            '{brand_name}' => $profile->brandName,
            '{brand_voice}' => $this->formatBrandVoice($profile),
            '{target_audience}' => $this->formatPersonas($profile->personas),
            '{target_persona}' => $this->formatPrimaryPersona($profile->personas),
            '{content_rules}' => $this->formatContentRules($profile->contentRules),
            '{keywords}' => $this->formatList($profile->keywords),
            '{forbidden_words}' => $this->formatList($profile->forbiddenWords),
            '{competitors}' => $this->formatList($profile->competitors),
            '{compliance_notes}' => $profile->complianceNotes,
        ];
    }

    /**
     * Replace every token in a single pass (CTX-05).
     *
     * `strtr()` scans the input once and replaces longest keys first, so a token
     * value that happens to contain another token string is never re-scanned —
     * unlike sequential `str_replace(array, array)`, which could cascade.
     *
     * @param array<string, string> $map
     */
    public function replace(string $text, array $map): string
    {
        if ($text === '' || $map === []) {
            return $text;
        }

        return strtr($text, $map);
    }

    /**
     * @param list<array<string, string>> $personas
     */
    private function formatPrimaryPersona(array $personas): string
    {
        if ($personas === []) {
            return '';
        }

        return $this->formatPersonaLabel($personas[0]);
    }

    /**
     * @param list<array<string, string>> $personas
     */
    private function formatPersonas(array $personas): string
    {
        $labels = [];
        foreach ($personas as $persona) {
            $label = $this->formatPersonaLabel($persona);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return implode('; ', $labels);
    }

    /**
     * @param array<string, string> $persona
     */
    private function formatPersonaLabel(array $persona): string
    {
        $name = trim((string) ($persona['name'] ?? ''));
        $level = trim((string) ($persona['level'] ?? ''));
        if ($name === '' || $level === '') {
            return '';
        }

        $label = $name . ' (' . $level . ')';
        $role = trim((string) ($persona['role'] ?? ''));
        if ($role !== '') {
            $label .= ' — ' . $role;
        }

        return $label;
    }

    /**
     * @param list<array<string, string>> $rules
     */
    private function formatContentRules(array $rules): string
    {
        $lines = [];
        foreach ($rules as $rule) {
            $text = trim((string) ($rule['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $direction = trim((string) ($rule['direction'] ?? 'always'));
            $prefix = $direction === 'never' ? 'Never' : 'Always';
            $lines[] = '- ' . $prefix . ': ' . $text;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $items
     */
    private function formatList(array $items): string
    {
        if ($items === []) {
            return '';
        }

        return implode(', ', $items);
    }

    private function formatBrandVoice(BrandContextProfile $profile): string
    {
        $tags = $this->formatList($profile->toneTags);
        $voiceNotes = trim($profile->voiceNotes);

        if ($tags !== '' && $voiceNotes !== '') {
            return $tags . ' — ' . $voiceNotes;
        }

        return $tags !== '' ? $tags : $voiceNotes;
    }
}
