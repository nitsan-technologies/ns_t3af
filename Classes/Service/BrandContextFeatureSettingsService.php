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

use NITSAN\NsT3AF\Contract\BrandContextFeatureScopeProviderInterface;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Per-extension brand context profile override in AI Features drawer.
 *
 * @internal
 */
final class BrandContextFeatureSettingsService implements BrandContextProfileOverrideReaderInterface
{
    public const SETTING_KEY = 'brandContextProfileUid';

    private const LOCALLANG = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:';

    /**
     * @param iterable<BrandContextFeatureScopeProviderInterface> $scopeProviders
     */
    public function __construct(
        private readonly BrandContextProfileRepositoryInterface $profiles,
        private readonly ExtensionSettingsService $extensionSettings,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly iterable $scopeProviders = [],
    ) {}

    public function supportsScopeOverride(string $extensionKey, string $scope): bool
    {
        $provider = $this->findScopeProvider($extensionKey);

        return $provider?->supportsScope($scope) ?? false;
    }

    public function resolveProfileUid(int $storagePid, string $extensionKey, string $scope = ''): int
    {
        if ($storagePid <= 0 || $extensionKey === '') {
            return 0;
        }

        $stored = $this->extensionSettings->getStoredValues($extensionKey, $storagePid);
        $legacy = max(0, (int) ($stored[self::SETTING_KEY] ?? 0));

        $normalizedScope = $this->normalizeScope($extensionKey, $scope);
        if ($normalizedScope === '') {
            return $legacy;
        }

        $perScope = max(0, (int) ($stored[$this->settingKeyForScope($normalizedScope)] ?? 0));

        return $perScope > 0 ? $perScope : $legacy;
    }

    public function saveProfileUid(int $storagePid, string $extensionKey, string $scope, int $profileUid): void
    {
        if ($storagePid <= 0 || $extensionKey === '') {
            return;
        }

        if ($profileUid > 0 && !$this->profiles->belongsToStorage($profileUid, $storagePid)) {
            $profileUid = 0;
        }

        $normalizedScope = $this->normalizeScope($extensionKey, $scope);
        $key = $normalizedScope === '' ? self::SETTING_KEY : $this->settingKeyForScope($normalizedScope);

        $this->extensionSettings->merge(
            $extensionKey,
            [$key => (string) max(0, $profileUid)],
            $storagePid,
        );
    }

    /**
     * @return array<string, int>
     */
    public function resolveAllScopeLinksForStorage(int $storagePid): array
    {
        if ($storagePid <= 0) {
            return [];
        }

        $links = [];
        foreach ($this->scopeProviders as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->getSupportedScopes() as $scope) {
                $uid = $this->resolveProfileUid($storagePid, $provider->getExtensionKey(), $scope);
                if ($uid > 0) {
                    $links[$scope] = $uid;
                }
            }
        }

        return $links;
    }

    /**
     * @return array<string, int>
     */
    public function resolveAllScopeLinks(int $storagePid, string $extensionKey): array
    {
        if ($storagePid <= 0 || $extensionKey === '') {
            return [];
        }

        $provider = $this->findScopeProvider($extensionKey);
        if ($provider === null) {
            return [];
        }

        $links = [];
        foreach ($provider->getSupportedScopes() as $scope) {
            $uid = $this->resolveProfileUid($storagePid, $extensionKey, $scope);
            if ($uid > 0) {
                $links[$scope] = $uid;
            }
        }

        return $links;
    }

    /**
     * @return array<string, string>
     */
    public function getScopeLabels(): array
    {
        $lang = $this->getLanguageService();
        $labels = [];
        foreach ($this->scopeProviders as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->getScopeLabelKeys() as $scope => $labelKey) {
                $labels[$scope] = $lang->sL(self::LOCALLANG . $labelKey);
            }
        }

        return $labels;
    }

    public function renderOverrideSelect(int $storagePid, string $extensionKey, string $scope = ''): string
    {
        if ($storagePid <= 0) {
            return '';
        }

        $lang = $this->getLanguageService();
        $selectedUid = $this->resolveProfileUid($storagePid, $extensionKey, $scope);
        $profiles = $this->profiles->findAllByStoragePid($storagePid);

        $label = htmlspecialchars(
            $lang->sL(self::LOCALLANG . 'module.aiFeatures.brandContext.label'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $help = htmlspecialchars(
            $lang->sL(self::LOCALLANG . 'module.aiFeatures.brandContext.help'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $defaultLabel = htmlspecialchars(
            $lang->sL(self::LOCALLANG . 'module.aiFeatures.brandContext.useDefault'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $options = sprintf(
            '<option value="0"%s>%s</option>',
            $selectedUid === 0 ? ' selected="selected"' : '',
            $defaultLabel,
        );

        foreach ($profiles as $profile) {
            $name = htmlspecialchars($profile->brandName !== '' ? $profile->brandName : ('Profile #' . $profile->uid), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $selected = $profile->uid === $selectedUid ? ' selected="selected"' : '';
            $defaultSuffix = $profile->isDefault ? ' ★' : '';
            $options .= sprintf(
                '<option value="%d"%s>%s%s</option>',
                $profile->uid,
                $selected,
                $name,
                htmlspecialchars($defaultSuffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return <<<HTML
<div class="aiu-features-brand-context mb-3" data-aiu-features-brand-context>
    <div class="aiu-section__label">{$label}</div>
    <select name="brandContextProfileUid" class="form-select" aria-describedby="aiu-features-brand-context-help">
        {$options}
    </select>
    <p class="form-text text-variant mb-0" id="aiu-features-brand-context-help">{$help}</p>
</div>
HTML;
    }

    private function normalizeScope(string $extensionKey, string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '') {
            return '';
        }

        $provider = $this->findScopeProvider($extensionKey);

        return $provider?->supportsScope($scope) ? $scope : '';
    }

    private function settingKeyForScope(string $scope): string
    {
        return self::SETTING_KEY . '_' . $scope;
    }

    private function findScopeProvider(string $extensionKey): ?BrandContextFeatureScopeProviderInterface
    {
        foreach ($this->scopeProviders as $provider) {
            if ($provider->isAvailable() && $provider->getExtensionKey() === $extensionKey) {
                return $provider;
            }
        }

        return null;
    }

    private function getLanguageService(): LanguageService
    {
        return $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);
    }
}
