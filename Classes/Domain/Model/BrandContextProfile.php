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

namespace NITSAN\NsT3AF\Domain\Model;

/**
 * Read-only view of one row in `tx_nst3af_brand_context_profile`.
 *
 * @internal
 */
final readonly class BrandContextProfile
{
    /** @var list<string> */
    public const INDUSTRIES = [
        'Technology',
        'Healthcare',
        'Education',
        'E-Commerce',
        'Finance',
        'Manufacturing',
        'Legal',
        'Real Estate',
        'Non-Profit',
        'Other',
    ];

    /** @var list<string> */
    public const TONE_TAGS = [
        'Bold',
        'Authoritative',
        'Friendly',
        'Direct',
        'Professional',
        'Witty',
        'Empathetic',
        'Concise',
        'Formal',
        'Playful',
    ];

    /** @var list<string> */
    public const PERSONA_LEVELS = [
        'Beginner',
        'Intermediate',
        'Expert',
    ];

    /** @var array<string, string> */
    public const LANGUAGES = [
        'en' => 'English',
        'de' => 'German (DE)',
        'fr' => 'French (FR)',
        'es' => 'Spanish (ES)',
        'it' => 'Italian (IT)',
        'nl' => 'Dutch (NL)',
        'pt' => 'Portuguese (PT)',
        'pl' => 'Polish (PL)',
        'ja' => 'Japanese (JA)',
        'zh' => 'Chinese (ZH)',
    ];

    /**
     * @param list<string>              $toneTags
     * @param list<array<string,string>> $personas
     * @param list<array<string,string>> $contentRules
     * @param list<string>              $forbiddenWords
     * @param list<string>              $keywords
     * @param list<string>              $competitors
     */
    public function __construct(
        public int $uid,
        public int $pid,
        public string $brandName,
        public string $industry,
        public string $websiteUrl,
        public string $tagline,
        public string $description,
        public array $toneTags,
        public string $voiceNotes,
        public array $personas,
        public array $contentRules,
        public array $forbiddenWords,
        public array $keywords,
        public array $competitors,
        public string $languageCode,
        public string $sampleContent,
        public string $complianceNotes,
        public string $documentExtract,
        public bool $isDefault,
        public bool $isEnabled,
        public int $completeness,
        public int $crdate,
        public int $tstamp,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: (int) ($row['uid'] ?? 0),
            pid: (int) ($row['pid'] ?? 0),
            brandName: trim((string) ($row['brand_name'] ?? '')),
            industry: trim((string) ($row['industry'] ?? '')),
            websiteUrl: trim((string) ($row['website_url'] ?? '')),
            tagline: trim((string) ($row['tagline'] ?? '')),
            description: trim((string) ($row['description'] ?? '')),
            toneTags: self::decodeStringList($row['tone_tags'] ?? null),
            voiceNotes: trim((string) ($row['voice_notes'] ?? '')),
            personas: self::decodeObjectList($row['personas'] ?? null),
            contentRules: self::decodeObjectList($row['content_rules'] ?? null),
            forbiddenWords: self::decodeStringList($row['forbidden_words'] ?? null),
            keywords: self::decodeStringList($row['keywords'] ?? null),
            competitors: self::decodeStringList($row['competitors'] ?? null),
            languageCode: trim((string) ($row['language_code'] ?? '')),
            sampleContent: trim((string) ($row['sample_content'] ?? '')),
            complianceNotes: trim((string) ($row['compliance_notes'] ?? '')),
            documentExtract: trim((string) ($row['document_extract'] ?? '')),
            isDefault: (int) ($row['is_default'] ?? 0) === 1,
            isEnabled: (int) ($row['hidden'] ?? 0) === 0,
            completeness: (int) ($row['completeness'] ?? 0),
            crdate: (int) ($row['crdate'] ?? 0),
            tstamp: (int) ($row['tstamp'] ?? 0),
        );
    }

    /**
     * @return list<string>
     */
    public static function decodeStringList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_string($item) ? trim($item) : '',
            $decoded,
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return list<array<string, string>>
     */
    public static function decodeObjectList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $items = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = [];
            foreach ($entry as $key => $field) {
                if (is_string($key) && is_scalar($field)) {
                    $normalized[$key] = trim((string) $field);
                }
            }
            if ($normalized !== []) {
                $items[] = $normalized;
            }
        }

        return $items;
    }

    /**
     * @param list<string>|list<array<string, string>> $items
     */
    public static function encodeJsonList(array $items): string
    {
        if ($items === []) {
            return '';
        }

        return json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
