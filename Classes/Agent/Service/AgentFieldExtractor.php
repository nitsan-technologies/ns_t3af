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

namespace NITSAN\NsT3AF\Agent\Service;

/**
 * Extracts only NL-relevant fields from read-tool record payloads.
 *
 * @internal
 */
final class AgentFieldExtractor
{
    /** @var list<string> */
    private const ALWAYS_INCLUDE = ['uid', 'pid', 'title', 'header', 'name', 'table'];

    /** @var list<string> */
    private const LOW_RISK_WRITE_FIELDS = [
        'description',
        'abstract',
        'keywords',
        'seo_title',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
        'alternative',
        'alt',
    ];

    /**
     * @return array{facts: list<array{label: string, value: string}>, summary: string}
     */
    public function extract(string $userMessage, string $toolName, mixed $payload): array
    {
        $record = $this->normalizeRecord($payload);
        if ($record === []) {
            return ['facts' => [], 'summary' => ''];
        }

        $needles = $this->extractNeedles($userMessage);
        $facts = [];
        $usedKeys = [];

        foreach (self::ALWAYS_INCLUDE as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }
            $value = $this->stringify($record[$key]);
            if ($value === '') {
                continue;
            }
            $facts[] = ['label' => $key, 'value' => $value];
            $usedKeys[$key] = true;
        }

        foreach ($record as $key => $value) {
            if (isset($usedKeys[$key]) || !is_string($key)) {
                continue;
            }
            if (!$this->isRelevantField($key, $needles)) {
                continue;
            }
            $stringValue = $this->stringify($value);
            if ($stringValue === '') {
                continue;
            }
            $facts[] = ['label' => $key, 'value' => $stringValue];
            if (count($facts) >= 10) {
                break;
            }
        }

        $summary = $this->buildSummary($toolName, $facts);

        return ['facts' => $facts, 'summary' => $summary];
    }

    public function isLowRiskWriteField(string $fieldName): bool
    {
        $normalized = strtolower(trim($fieldName));

        return in_array($normalized, self::LOW_RISK_WRITE_FIELDS, true);
    }

    /**
     * @param list<array<string, mixed>> $draftFields
     */
    public function draftUsesOnlyLowRiskFields(array $draftFields): bool
    {
        if ($draftFields === []) {
            return false;
        }

        foreach ($draftFields as $field) {
            if (!is_array($field)) {
                return false;
            }
            $name = (string) ($field['field'] ?? $field['key'] ?? '');
            if ($name === '' || str_starts_with($name, '_')) {
                return false;
            }
            if (!$this->isLowRiskWriteField($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRecord(mixed $payload): array
    {
        if (is_array($payload)) {
            if (isset($payload['record']) && is_array($payload['record'])) {
                return $payload['record'];
            }
            if (isset($payload['data']) && is_array($payload['data'])) {
                return $payload['data'];
            }
            if ($this->looksAssociative($payload)) {
                return $payload;
            }
            if (isset($payload[0]) && is_array($payload[0])) {
                return $payload[0];
            }
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $this->normalizeRecord($decoded) : [];
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $payload
     */
    private function looksAssociative(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        return array_keys($payload) !== range(0, count($payload) - 1);
    }

    /**
     * @return list<string>
     */
    private function extractNeedles(string $userMessage): array
    {
        $normalized = strtolower($userMessage);
        $needles = [];
        if (preg_match_all('/\b[a-z][a-z0-9_]{2,}\b/', $normalized, $matches) > 0) {
            foreach ($matches[0] as $word) {
                if (in_array($word, ['what', 'show', 'tell', 'about', 'page', 'content', 'record', 'this', 'that', 'with'], true)) {
                    continue;
                }
                $needles[] = $word;
            }
        }

        return array_values(array_unique($needles));
    }

    /**
     * @param list<string> $needles
     */
    private function isRelevantField(string $key, array $needles): bool
    {
        $normalized = strtolower($key);
        foreach ($needles as $needle) {
            if ($needle === $normalized || str_contains($normalized, $needle) || str_contains($needle, $normalized)) {
                return true;
            }
        }

        return in_array($normalized, [
            'bodytext',
            'description',
            'abstract',
            'subtitle',
            'slug',
            'keywords',
            'seo_title',
            'og_title',
            'og_description',
            'alternative',
            'hidden',
            'starttime',
            'endtime',
        ], true);
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? '' : (strlen($trimmed) > 500 ? substr($trimmed, 0, 497) . '…' : $trimmed);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return '';
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function buildSummary(string $toolName, array $facts): string
    {
        if ($facts === []) {
            return '';
        }

        $parts = [];
        foreach ($facts as $fact) {
            $parts[] = $fact['label'] . ': ' . $fact['value'];
        }

        return $toolName . ' → ' . implode('; ', array_slice($parts, 0, 6));
    }
}
