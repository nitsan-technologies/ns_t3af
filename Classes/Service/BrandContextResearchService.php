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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Dto\BrandContextResearchResult;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Auto-research: fetch website content, call site default provider, map JSON to form fields.
 *
 * @internal
 */
final class BrandContextResearchService
{
    private const PROMPT_FILE = 'EXT:ns_t3af/Resources/Private/Templates/BrandContext/ResearchPrompt.txt';

    public function __construct(
        private readonly AiServiceInterface $aiService,
        private readonly BrandContextWebsiteFetcher $websiteFetcher,
    ) {}

    public function research(string $websiteUrl, int $pageId): BrandContextResearchResult
    {
        $websiteUrl = trim($websiteUrl);
        if ($websiteUrl === '') {
            throw new \InvalidArgumentException('Website URL is required.');
        }

        $fetch = $this->websiteFetcher->fetchText($websiteUrl);
        $prompt = $this->buildPrompt($websiteUrl, $fetch['content']);

        $response = $this->aiService->complete($prompt, new AiOptions(
            temperature: 0.2,
            maxTokens: 4096,
            noCache: true,
            extensionKey: 'ns_t3af',
            featureKey: 'brand_context.research',
            featureLabel: 'Brand Context Auto-Research',
            requestSource: 'backend_module',
            pageId: $pageId > 0 ? $pageId : null,
            extra: ['skipBrandContext' => true],
        ));

        $parsed = $this->parseJsonResponse($response->content);
        $fields = $this->mapFields($parsed, $websiteUrl);
        $confidence = $this->normalizeConfidence($parsed['confidence'] ?? []);

        return new BrandContextResearchResult(
            fields: $fields,
            confidence: $confidence,
            manualRequired: BrandContextResearchResult::MANUAL_REQUIRED,
            contentFetched: (bool) $fetch['fetched'],
            fetchNotice: $fetch['notice'],
        );
    }

    private function buildPrompt(string $websiteUrl, string $websiteContent): string
    {
        $template = (string) GeneralUtility::getFileAbsFileName(self::PROMPT_FILE);
        if ($template === '' || !is_readable($template)) {
            throw new \RuntimeException('Brand research prompt template is missing.');
        }

        $content = file_get_contents($template);
        if ($content === false) {
            throw new \RuntimeException('Brand research prompt template could not be read.');
        }

        $contentBlock = $websiteContent !== ''
            ? "WEBSITE CONTENT (server-fetched, use as primary source):\n" . $websiteContent
            : 'No website content was fetched. Infer cautiously from the URL only and use LOW confidence.';

        return str_replace(
            ['{{WEBSITE_URL}}', '{{WEBSITE_CONTENT_BLOCK}}'],
            [$websiteUrl, $contentBlock],
            $content,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function parseJsonResponse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \RuntimeException('AI returned an empty response.');
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $matches)) {
            $raw = trim($matches[1]);
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('AI response was not valid JSON: ' . $exception->getMessage());
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('AI response JSON must be an object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $parsed
     *
     * @return array<string, mixed>
     */
    public function mapFields(array $parsed, string $fallbackUrl): array
    {
        $industry = $this->normalizeIndustry((string) ($parsed['industry'] ?? ''));
        $toneTags = $this->normalizeToneTags($parsed['tone_tags'] ?? []);
        $personas = $this->normalizePersonas($parsed['personas'] ?? []);
        $keywords = $this->normalizeStringList($parsed['keywords'] ?? [], 15, 50);
        $competitors = $this->normalizeStringList($parsed['competitors'] ?? [], 5, 60);

        return [
            'brandName' => $this->truncate((string) ($parsed['brand_name'] ?? ''), 60),
            'industry' => $industry,
            'websiteUrl' => $this->truncate((string) ($parsed['website_url'] ?? $fallbackUrl), 255),
            'tagline' => $this->truncate((string) ($parsed['one_line_description'] ?? ''), 160),
            'description' => $this->truncate((string) ($parsed['what_brand_sells'] ?? ''), 500),
            'toneTags' => $toneTags,
            'voiceNotes' => $this->truncate((string) ($parsed['write_like_this'] ?? ''), 300),
            'personas' => $personas,
            'keywords' => $keywords,
            'competitors' => $competitors,
            'languageCode' => $this->normalizeLanguageCode((string) ($parsed['language'] ?? '')),
        ];
    }

    /**
     * @param mixed $raw
     *
     * @return array<string, string>
     */
    public function normalizeConfidence(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $allowed = ['HIGH', 'MEDIUM', 'LOW'];
        $map = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $level = strtoupper(trim($value));
            if (in_array($level, $allowed, true)) {
                $map[$key] = $level;
            }
        }

        return $map;
    }

    private function normalizeIndustry(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        foreach (BrandContextProfile::INDUSTRIES as $industry) {
            if (strcasecmp($industry, $value) === 0) {
                return $industry;
            }
        }

        return 'Other';
    }

    /**
     * @param mixed $raw
     *
     * @return list<string>
     */
    private function normalizeToneTags(mixed $raw): array
    {
        $items = [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $tag = trim($item);
            if ($tag === '' || !in_array($tag, BrandContextProfile::TONE_TAGS, true)) {
                continue;
            }
            $items[] = $tag;
            if (count($items) >= 5) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param mixed $raw
     *
     * @return list<array<string, string>>
     */
    private function normalizePersonas(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $personas = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $this->truncate(trim((string) ($entry['name'] ?? '')), 50);
            $level = trim((string) ($entry['level'] ?? ''));
            if ($name === '' || !in_array($level, BrandContextProfile::PERSONA_LEVELS, true)) {
                continue;
            }
            $persona = ['name' => $name, 'level' => $level];
            $role = $this->truncate(trim((string) ($entry['role'] ?? '')), 50);
            if ($role !== '') {
                $persona['role'] = $role;
            }
            $painPoints = $this->truncate(trim((string) ($entry['painPoints'] ?? '')), 300);
            if ($painPoints !== '') {
                $persona['painPoints'] = $painPoints;
            }
            $caresAbout = $this->truncate(trim((string) ($entry['caresAbout'] ?? '')), 300);
            if ($caresAbout !== '') {
                $persona['caresAbout'] = $caresAbout;
            }
            $personas[] = $persona;
            if (count($personas) >= 3) {
                break;
            }
        }

        return $personas;
    }

    /**
     * @param mixed $raw
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw, int $maxItems, int $maxLength): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $value = $this->truncate(trim($item), $maxLength);
            if ($value === '') {
                continue;
            }
            $items[] = $value;
            if (count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }

    private function normalizeLanguageCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        if (isset(BrandContextProfile::LANGUAGES[$lower])) {
            return $lower;
        }

        if (preg_match('/^([a-z]{2})(?:[-_][a-z]{2})?$/i', $value, $matches)) {
            $code = strtolower($matches[1]);
            if (isset(BrandContextProfile::LANGUAGES[$code])) {
                return $code;
            }
        }

        foreach (BrandContextProfile::LANGUAGES as $code => $label) {
            if (stripos($value, $label) !== false || stripos($label, $value) !== false) {
                return $code;
            }
        }

        return '';
    }

    private function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
