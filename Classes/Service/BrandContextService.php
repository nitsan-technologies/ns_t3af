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

/**
 * Backend orchestration for Brand Context profiles.
 *
 * @internal
 */
final class BrandContextService
{
    /** @var list<string> */
    public const PLACEHOLDERS = [
        '{brand_context}',
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

    public function __construct(
        private readonly BrandContextProfileRepositoryInterface $profiles,
        private readonly BrandContextCompletenessCalculator $completenessCalculator,
        private readonly BrandContextFeatureSettingsService $featureSettings,
    ) {}

    /**
     * @return array{
     *   profileCount: int,
     *   defaultProfile: ?array<string, mixed>,
     *   profiles: list<array<string, mixed>>,
     *   placeholders: list<string>
     * }
     */
    public function buildListViewData(int $storagePid): array
    {
        $rows = $this->profiles->findAllByStoragePid($storagePid, includeHidden: true);
        $defaultProfile = $this->profiles->findDefault($storagePid);
        $profileCards = [];
        $defaultCard = null;

        $linkedFeaturesByUid = $this->buildLinkedFeaturesByUid($storagePid);

        foreach ($rows as $profile) {
            $card = $this->buildProfileCard($profile);
            $card['linkedFeatures'] = $linkedFeaturesByUid[$profile->uid] ?? [];
            $profileCards[] = $card;
            if ($defaultProfile !== null && $profile->uid === $defaultProfile->uid) {
                $defaultCard = $card;
            }
        }

        return [
            'profileCount' => count($profileCards),
            'defaultProfile' => $defaultCard,
            'profiles' => $profileCards,
            'placeholders' => self::PLACEHOLDERS,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createProfile(int $storagePid, array $payload): int
    {
        $setDefault = $this->shouldSetAsDefault($payload, $storagePid);
        $values = $this->normalizePayload($storagePid, $payload);
        $uid = $this->profiles->save(0, $values);
        $this->refreshCompleteness($uid);

        if ($setDefault) {
            $this->profiles->setDefault($uid, $storagePid);
        }

        return $uid;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateProfile(int $uid, int $storagePid, array $payload): bool
    {
        if (!$this->profiles->belongsToStorage($uid, $storagePid)) {
            return false;
        }

        $values = $this->normalizePayload($storagePid, $payload, false);
        $this->profiles->save($uid, $values);
        $this->refreshCompleteness($uid);

        return true;
    }

    public function deleteProfile(int $uid, int $storagePid): bool
    {
        if (!$this->profiles->belongsToStorage($uid, $storagePid)) {
            return false;
        }

        $this->profiles->delete($uid);

        return true;
    }

    public function setDefaultProfile(int $uid, int $storagePid): bool
    {
        if (!$this->profiles->belongsToStorage($uid, $storagePid)) {
            return false;
        }

        $this->profiles->setDefault($uid, $storagePid);
        $this->profiles->setEnabled($uid, true);

        return true;
    }

    public function setProfileEnabled(int $uid, int $storagePid, bool $enabled): bool
    {
        if (!$this->profiles->belongsToStorage($uid, $storagePid)) {
            return false;
        }

        $this->profiles->setEnabled($uid, $enabled);

        return true;
    }

    /**
     * Validates Quick Setup wizard step 6 (lighter required set).
     *
     * @param array<string, mixed> $payload
     */
    public function validateWizardPayload(array $payload): ?string
    {
        $brandName = trim((string) ($payload['brandName'] ?? $payload['brand_name'] ?? ''));
        if ($brandName === '') {
            return 'Brand name is required.';
        }

        $industry = trim((string) ($payload['industry'] ?? ''));
        if ($industry === '') {
            return 'Industry is required.';
        }

        $toneTags = $this->normalizeStringList(
            $payload['toneTags'] ?? $payload['tone_tags'] ?? [],
            BrandContextProfile::TONE_TAGS,
            5,
        );
        $toneCount = count($toneTags);
        if ($toneCount < 3 || $toneCount > 5) {
            return 'Pick 3–5 tone tags.';
        }

        return null;
    }

    /**
     * Creates a minimal profile from Quick Setup wizard step 6.
     *
     * @param array<string, mixed> $payload
     */
    public function createWizardProfile(int $storagePid, array $payload): ?int
    {
        if ($storagePid <= 0) {
            return null;
        }

        $brandName = trim((string) ($payload['brandName'] ?? $payload['brand_name'] ?? ''));
        $industry = trim((string) ($payload['industry'] ?? ''));
        $voiceNotes = trim((string) ($payload['voiceDescription'] ?? $payload['voiceNotes'] ?? $payload['voice_notes'] ?? ''));
        $toneTags = $payload['toneTags'] ?? $payload['tone_tags'] ?? [];

        if ($brandName === '' && $industry === '' && $voiceNotes === '' && $this->normalizeStringList($toneTags, BrandContextProfile::TONE_TAGS, 5) === []) {
            return null;
        }

        return $this->createProfile($storagePid, [
            'brandName' => $brandName,
            'industry' => $industry,
            'toneTags' => $toneTags,
            'voiceNotes' => $voiceNotes,
            'isDefault' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, int|float|string|null>
     */
    private function normalizePayload(int $storagePid, array $payload, bool $includePid = true): array
    {
        $values = [
            'brand_name' => $this->truncate(trim((string) ($payload['brandName'] ?? $payload['brand_name'] ?? '')), 60),
            'industry' => $this->truncate(trim((string) ($payload['industry'] ?? '')), 128),
            'website_url' => $this->truncate(trim((string) ($payload['websiteUrl'] ?? $payload['website_url'] ?? '')), 255),
            'tagline' => $this->truncate(trim((string) ($payload['tagline'] ?? '')), 160),
            'description' => $this->truncate(trim((string) ($payload['description'] ?? '')), 500),
            'voice_notes' => $this->truncate(trim((string) ($payload['voiceNotes'] ?? $payload['voice_notes'] ?? '')), 300),
            'language_code' => $this->truncate(trim((string) ($payload['languageCode'] ?? $payload['language_code'] ?? '')), 16),
            'sample_content' => $this->truncate(trim((string) ($payload['sampleContent'] ?? $payload['sample_content'] ?? '')), 600),
            'compliance_notes' => $this->truncate(trim((string) ($payload['complianceNotes'] ?? $payload['compliance_notes'] ?? '')), 400),
            'document_extract' => $this->truncate(trim((string) ($payload['documentExtract'] ?? $payload['document_extract'] ?? '')), 100000),
            'include_document_in_prompt' => $this->normalizeCheckbox(
                $payload['includeDocumentInPrompt'] ?? $payload['include_document_in_prompt'] ?? 0,
            ),
        ];

        if ($includePid) {
            $values['pid'] = $storagePid;
        }

        $values['tone_tags'] = BrandContextProfile::encodeJsonList(
            $this->normalizeStringList($payload['toneTags'] ?? $payload['tone_tags'] ?? [], BrandContextProfile::TONE_TAGS, 5),
        );
        $values['personas'] = BrandContextProfile::encodeJsonList(
            $this->normalizePersonas($payload['personas'] ?? []),
        );
        $values['content_rules'] = BrandContextProfile::encodeJsonList(
            $this->normalizeContentRules($payload['contentRules'] ?? $payload['content_rules'] ?? []),
        );
        $values['forbidden_words'] = BrandContextProfile::encodeJsonList(
            $this->normalizeStringList($payload['forbiddenWords'] ?? $payload['forbidden_words'] ?? [], null, 20),
        );
        $values['keywords'] = BrandContextProfile::encodeJsonList(
            $this->normalizeStringList($payload['keywords'] ?? [], null, 15),
        );
        $values['competitors'] = BrandContextProfile::encodeJsonList(
            $this->normalizeStringList($payload['competitors'] ?? [], null, 5),
        );

        return $values;
    }

    /**
     * Groups the per-feature brand-context overrides by profile uid, mapping each to its
     * human-readable AI Features label (e.g. uid 5 → ["AI SEO", "AI Content"]).
     *
     * @return array<int, list<string>>
     */
    private function buildLinkedFeaturesByUid(int $storagePid): array
    {
        $scopeLinks = $this->featureSettings->resolveAllScopeLinksForStorage($storagePid);
        if ($scopeLinks === []) {
            return [];
        }

        $scopeLabels = $this->featureSettings->getScopeLabels();
        $linkedFeaturesByUid = [];
        foreach ($scopeLinks as $scope => $uid) {
            $linkedFeaturesByUid[$uid][] = $scopeLabels[$scope] ?? $scope;
        }

        return $linkedFeaturesByUid;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfileCard(BrandContextProfile $profile): array
    {
        $completeness = $this->completenessCalculator->calculate($profile);

        return [
            'uid' => $profile->uid,
            'brandName' => $profile->brandName,
            'industry' => $profile->industry,
            'websiteUrl' => $profile->websiteUrl,
            'isDefault' => $profile->isDefault,
            'isEnabled' => $profile->isEnabled,
            'completeness' => $completeness,
            'createdLabel' => $profile->crdate > 0 ? date('Y-m-d', $profile->crdate) : '',
            'profileJson' => json_encode([
                'uid' => $profile->uid,
                'brandName' => $profile->brandName,
                'industry' => $profile->industry,
                'websiteUrl' => $profile->websiteUrl,
                'tagline' => $profile->tagline,
                'description' => $profile->description,
                'toneTags' => $profile->toneTags,
                'voiceNotes' => $profile->voiceNotes,
                'personas' => $profile->personas,
                'contentRules' => $profile->contentRules,
                'forbiddenWords' => $profile->forbiddenWords,
                'keywords' => $profile->keywords,
                'competitors' => $profile->competitors,
                'languageCode' => $profile->languageCode,
                'sampleContent' => $profile->sampleContent,
                'complianceNotes' => $profile->complianceNotes,
                'documentExtract' => $profile->documentExtract,
                'includeDocumentInPrompt' => $profile->includeDocumentInPrompt,
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function refreshCompleteness(int $uid): void
    {
        $profile = $this->profiles->findByUid($uid);
        if ($profile === null) {
            return;
        }

        $this->profiles->save($uid, [
            'completeness' => $this->completenessCalculator->calculatePercent($profile),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldSetAsDefault(array $payload, int $storagePid): bool
    {
        if (!empty($payload['isDefault']) || !empty($payload['is_default'])) {
            return true;
        }

        return $this->profiles->countByStoragePid($storagePid) === 0;
    }

    /**
     * @param mixed $raw
     * @param list<string>|null $allowed
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw, ?array $allowed, int $maxItems): array
    {
        $items = [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : (preg_split('/\s*,\s*/', $raw) ?: []);
        }
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $value = trim($item);
            if ($value === '') {
                continue;
            }
            if ($allowed !== null && !in_array($value, $allowed, true)) {
                continue;
            }
            $items[] = $value;
            if (count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param mixed $raw
     * @return list<array<string, string>>
     */
    private function normalizePersonas(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
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
     * @return list<array<string, string>>
     */
    private function normalizeContentRules(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $direction = trim((string) ($entry['direction'] ?? ''));
            $text = $this->truncate(trim((string) ($entry['text'] ?? '')), 100);
            if ($text === '' || !in_array($direction, ['always', 'never'], true)) {
                continue;
            }
            $rules[] = ['direction' => $direction, 'text' => $text];
            if (count($rules) >= 10) {
                break;
            }
        }

        return $rules;
    }

    private function normalizeCheckbox(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1 ? 1 : 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
        }

        return 0;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
