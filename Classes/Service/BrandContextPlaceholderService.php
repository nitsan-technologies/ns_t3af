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
     * @param array<string, string> $map
     */
    public function replace(string $text, array $map): string
    {
        if ($text === '' || $map === []) {
            return $text;
        }

        return str_replace(array_keys($map), array_values($map), $text);
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
