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

use NITSAN\NsT3AF\Contract\FeatureProviderFormOptionsInterface;
use NITSAN\NsT3AF\Registry\ExtensionSettingsScopeRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSchemaService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSecretRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSecretService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Reads and writes extension configuration for AI Features drawer scopes (provider-driven).
 *
 * Field definitions come from each extension's Configuration/ExtensionSettings/schema.php;
 * values persist in tx_nst3af_extension_setting.
 */
final class ExtensionExtConfCategoryService
{
    /**
     * @var array<string, FeatureProviderFormOptionsInterface>
     */
    private array $featureProviderFormOptionsByExtension = [];

    /**
     * @param iterable<FeatureProviderFormOptionsInterface> $featureProviderFormOptionsProviders
     */
    public function __construct(
        private readonly ExtensionSettingsService $extensionSettingsService,
        private readonly ExtensionSettingsSchemaService $extensionSettingsSchemaService,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly ExtensionSettingsSecretRegistry $secretRegistry,
        private readonly ExtensionSettingsSecretService $secretService,
        private readonly ExtensionSettingsScopeRegistry $scopeRegistry,
        iterable $featureProviderFormOptionsProviders = [],
    ) {
        foreach ($featureProviderFormOptionsProviders as $provider) {
            if (!$provider instanceof FeatureProviderFormOptionsInterface) {
                continue;
            }
            $this->featureProviderFormOptionsByExtension[$provider->getExtensionKey()] = $provider;
        }
    }

    public function isSupportedExtension(string $extensionKey): bool
    {
        return $this->scopeRegistry->supportsExtension($extensionKey);
    }

    public function isAvailable(string $extensionKey): bool
    {
        return $this->isSupportedExtension($extensionKey)
            && ExtensionManagementUtility::isLoaded($extensionKey);
    }

    public function isValidScope(string $extensionKey, string $scope): bool
    {
        return $this->scopeRegistry->isValidScope($extensionKey, $scope);
    }

    public function resolveDefaultExtensionKey(): string
    {
        foreach ($this->scopeRegistry->getManagedExtensionKeys() as $extensionKey) {
            if ($this->isAvailable($extensionKey)) {
                return $extensionKey;
            }
        }

        return 'ns_t3af';
    }

    public function getUnavailableLabelKey(string $extensionKey): string
    {
        return $this->scopeRegistry->getUnavailableLabelKey($extensionKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCategoryConfiguration(string $extensionKey, string $category, ?int $storagePid = null): array
    {
        $parsed = $this->parseExtensionConfiguration($extensionKey, $storagePid);
        $configuration = $parsed[$category] ?? [];
        $normalized = [];
        foreach ($configuration as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function resolveCategoriesForScope(string $extensionKey, string $scope): array
    {
        return $this->scopeRegistry->resolveCategoriesForScope($extensionKey, $scope);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getExtensionDataForScope(string $extensionKey, string $scope, ?int $storagePid = null): array
    {
        if ($this->scopeRegistry->getFieldFilterDefinitions($extensionKey, $scope) !== []) {
            return $this->getFieldFilteredExtensionData($extensionKey, $scope, $storagePid);
        }

        $categories = $this->resolveCategoriesForScope($extensionKey, $scope);
        if ($categories === []) {
            return [];
        }

        if (count($categories) === 1) {
            return $this->getCategoryConfiguration($extensionKey, $categories[0], $storagePid);
        }

        $merged = [];
        $sectionIndex = 0;
        foreach ($categories as $category) {
            $categoryData = $this->getCategoryConfiguration($extensionKey, $category, $storagePid);
            foreach ($categoryData as $subcategory) {
                $merged['drawer_section_' . $sectionIndex++] = $subcategory;
            }
        }

        return $merged;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getFieldFilteredExtensionData(string $extensionKey, string $scope, ?int $storagePid = null): array
    {
        $definitions = $this->scopeRegistry->getFieldFilterDefinitions($extensionKey, $scope);
        if ($definitions === []) {
            return [];
        }

        $parsed = $this->parseExtensionConfiguration($extensionKey, $storagePid);
        $merged = [];
        $sectionIndex = 0;

        foreach ($definitions as $definition) {
            $category = strtolower((string) ($definition['category'] ?? ''));
            if ($category === '') {
                continue;
            }
            $fieldNames = $definition['fields'] ?? [];
            $includeAllFields = in_array('*', $fieldNames, true);
            $allowedFields = $includeAllFields
                ? []
                : array_fill_keys($fieldNames, true);
            $excludedFields = array_fill_keys($definition['exclude'] ?? [], true);
            $categoryData = $parsed[$category] ?? [];

            foreach ($categoryData as $subcategory) {
                if (!is_array($subcategory)) {
                    continue;
                }
                $filteredItems = [];
                foreach ($subcategory['items'] ?? [] as $sortKey => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? '');
                    if ($name === '' || isset($excludedFields[$name])) {
                        continue;
                    }
                    if ($includeAllFields || isset($allowedFields[$name])) {
                        $filteredItems[$sortKey] = $item;
                    }
                }
                if ($filteredItems === []) {
                    continue;
                }
                $merged['drawer_section_' . $sectionIndex++] = [
                    'label' => $subcategory['label'] ?? '',
                    'items' => $filteredItems,
                ];
            }
        }

        return $merged;
    }

    private function isPaletteScope(string $extensionKey, string $scope): bool
    {
        return $this->scopeRegistry->hasPaletteScope($extensionKey, $scope);
    }

    private function renderPaletteForm(
        string $extensionKey,
        string $scope,
        ?ServerRequestInterface $request = null,
        ?int $storagePid = null,
    ): string {
        $definitions = $this->scopeRegistry->getPaletteDefinitions($extensionKey, $scope);
        if ($definitions === []) {
            return '';
        }

        $prependedExtensionData = [];
        if ($this->scopeRegistry->getFieldFilterDefinitions($extensionKey, $scope) !== []) {
            $prependedExtensionData = $this->prepareExtensionDataForRender(
                $this->applyScopeMutations(
                    $extensionKey,
                    $scope,
                    $this->getFieldFilteredExtensionData($extensionKey, $scope, $storagePid),
                ),
            );
        }

        $palettes = [];
        foreach ($definitions as $definition) {
            $panelScope = (string) ($definition['scope'] ?? '');
            if ($panelScope === '') {
                continue;
            }
            $extensionData = $this->prepareExtensionDataForRender(
                $this->applyScopeMutations(
                    $extensionKey,
                    $panelScope,
                    $this->getExtensionDataForScope($extensionKey, $panelScope, $storagePid),
                ),
            );
            if ($extensionData === []) {
                continue;
            }
            $palettes[] = [
                'id' => (string) ($definition['id'] ?? ''),
                'label' => (string) ($definition['label'] ?? ''),
                'extensionData' => $extensionData,
            ];
        }

        if ($palettes === []) {
            return '';
        }

        $variables = [
            'extensionKey' => $extensionKey,
            'prependedExtensionData' => $prependedExtensionData,
            'palettes' => $palettes,
        ];

        try {
            return $this->renderNamedTemplate('AiFeatures/ExtConfMediaPaletteForm', $variables, $request);
        } catch (\Throwable) {
            return '';
        }
    }

    public function renderCategoryForm(string $extensionKey, string $category, ?ServerRequestInterface $request = null): string
    {
        $storagePid = $this->resolveStoragePidFromRequest($request);
        if ($this->isPaletteScope($extensionKey, $category)) {
            return $this->renderPaletteForm($extensionKey, $category, $request, $storagePid);
        }

        $extensionData = $this->prepareExtensionDataForRender(
            $this->applyScopeMutations(
                $extensionKey,
                $category,
                $this->getExtensionDataForScope($extensionKey, $category, $storagePid),
            ),
        );
        if ($extensionData === []) {
            return '';
        }

        $variables = [
            'extensionKey' => $extensionKey,
            'extensionData' => $extensionData,
        ];

        try {
            $html = $this->renderTemplate($variables, $request);
            if (trim($html) !== '') {
                return $html;
            }
        } catch (\Throwable) {
            // Fluid rendering failed — fall back to parsed field markup.
        }

        return $this->renderFallbackHtml($extensionData);
    }

    /**
     * @param array<string, mixed> $submitted
     * @return array{success: bool, title: string, message: string, severity: int}
     */
    public function saveSettings(string $extensionKey, array $submitted): array
    {
        $storagePid = $this->resolveStoragePidFromSubmitted($submitted);
        unset($submitted['scope'], $submitted['extension'], $submitted['id'], $submitted['pageId']);
        $normalized = [];
        foreach ($submitted as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = is_scalar($value) ? (string) $value : '';
        }

        $normalized = $this->normalizeSubmittedIntPlusFields($extensionKey, $normalized);

        $validationErrors = $this->validateSubmittedSettings($extensionKey, $normalized);
        if ($validationErrors !== []) {
            return [
                'success' => false,
                'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:module.aiFeatures.saveErrorTitle',
                'message' => implode(' ', $validationErrors),
                'severity' => 2,
            ];
        }

        $storagePidForSave = $storagePid > 0 ? $storagePid : null;
        if ($storagePid > 0 && !$this->extensionSettingsService->isSiteSettingsInitialized($storagePid)) {
            $this->extensionSettingsService->initializeSiteSettings($storagePid, $extensionKey, $normalized);
        } else {
            $this->extensionSettingsService->merge($extensionKey, $normalized, $storagePidForSave);
        }

        $messageKey = $this->scopeRegistry->getSaveSuccessMessageKey($extensionKey);

        return [
            'success' => true,
            'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:module.aiFeatures.saveSuccessTitle',
            'message' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:' . $messageKey,
            'severity' => 0,
        ];
    }

    /**
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function parseExtensionConfiguration(string $extensionKey, ?int $storagePid = null): array
    {
        if (!$this->isAvailable($extensionKey)) {
            return [];
        }

        $values = $this->extensionSettingsService->getAllForDisplay($extensionKey, $storagePid);
        $decrypted = $this->extensionSettingsService->getAll($extensionKey, $storagePid);

        return $this->applySecretFieldMetadata(
            $extensionKey,
            $this->extensionSettingsSchemaService->getDisplayConstants($extensionKey, $values),
            $decrypted,
        );
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $extensionData
     * @param array<string, string> $decryptedValues
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function applySecretFieldMetadata(string $extensionKey, array $extensionData, array $decryptedValues): array
    {
        foreach ($extensionData as &$category) {
            if (!is_array($category)) {
                continue;
            }
            foreach ($category as &$subcategory) {
                if (!is_array($subcategory['items'] ?? null)) {
                    continue;
                }
                foreach ($subcategory['items'] as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? '');
                    if ($name === '' || !$this->secretRegistry->isSecret($extensionKey, $name)) {
                        continue;
                    }
                    $item['isSecret'] = true;
                    $item['storedSecretMask'] = $this->secretService->maskLabel($extensionKey, $name, $decryptedValues);
                }
                unset($item);
            }
            unset($subcategory);
        }
        unset($category);

        return $extensionData;
    }

    private function resolveStoragePidFromRequest(?ServerRequestInterface $request): ?int
    {
        if ($request === null) {
            return null;
        }
        $resolution = $this->siteStorageContext->resolveFromRequest($request);

        return $resolution->isResolved() ? $resolution->storagePid : null;
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function resolveStoragePidFromSubmitted(array $submitted): int
    {
        $pageId = (int) ($submitted['id'] ?? $submitted['pageId'] ?? 0);
        if ($pageId <= 0) {
            return 0;
        }
        $storagePid = $this->siteStorageContext->resolveStoragePidFromPageId($pageId);

        return $storagePid ?? 0;
    }

    /**
     * @param array<string, array<string, mixed>> $extensionData
     * @return array<string, array<string, mixed>>
     */
    private function applyScopeMutations(string $extensionKey, string $scope, array $extensionData): array
    {
        $formOptions = $this->featureProviderFormOptionsByExtension[$extensionKey] ?? null;
        if ($formOptions === null) {
            return $extensionData;
        }

        $bindings = array_values(array_filter(
            $formOptions->getManagedFieldBindings(),
            static fn(array $binding): bool => ($binding['scope'] ?? '') === $scope,
        ));
        if ($bindings === []) {
            return $extensionData;
        }

        $bindingsByField = [];
        foreach ($bindings as $binding) {
            $field = (string) ($binding['field'] ?? '');
            if ($field !== '') {
                $bindingsByField[$field] = $binding;
            }
        }

        foreach ($extensionData as &$subcategory) {
            if (!is_array($subcategory['items'] ?? null)) {
                continue;
            }
            foreach ($subcategory['items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = (string) ($item['name'] ?? '');
                if ($name === '' || !isset($bindingsByField[$name])) {
                    continue;
                }
                $currentValue = (string) ($item['value'] ?? 'default');
                $item['labelValueArray'] = $formOptions->buildProviderOptions($scope, $name, $currentValue);
                $item['description'] = $formOptions->providerOverrideHint($scope, $name);
            }
            unset($item);
        }
        unset($subcategory);

        return $extensionData;
    }

    /**
     * @param array<string, mixed> $extensionData
     * @return array<string, mixed>
     */
    private function prepareExtensionDataForRender(array $extensionData): array
    {
        foreach ($extensionData as &$subcategory) {
            if (!is_array($subcategory['items'] ?? null)) {
                continue;
            }
            foreach ($subcategory['items'] as &$item) {
                if (!is_array($item) || ($item['type'] ?? '') !== 'user' || empty($item['html'])) {
                    continue;
                }
                $item['html'] = $this->transformLegacyUserFieldHtml((string) $item['html']);
            }
            unset($item);
        }
        unset($subcategory);

        return $extensionData;
    }

    /**
     * Extension-manager userFunc returns label-above-checkbox markup with form-check-input
     * (invisible without .form-check). Rewrite to native Universe checkbox rows.
     */
    private function transformLegacyUserFieldHtml(string $html): string
    {
        if (!str_contains($html, 'form-group-dashed')) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $internalErrors = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="aiu-ext-conf-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new \DOMXPath($document);
        $groups = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " form-group-dashed ")]');
        if ($groups === false || $groups->length === 0) {
            return $html;
        }

        $rows = [];
        foreach ($groups as $group) {
            if (!$group instanceof \DOMElement) {
                continue;
            }
            $labelNodes = $xpath->query('.//label[contains(@class,"form-label")]//span', $group);
            $checkboxNodes = $xpath->query('.//input[@type="checkbox"]', $group);
            $hiddenNodes = $xpath->query('.//input[@type="hidden"]', $group);
            $labelNode = $labelNodes !== false ? $labelNodes->item(0) : null;
            $checkbox = $checkboxNodes !== false ? $checkboxNodes->item(0) : null;
            $hidden = $hiddenNodes !== false ? $hiddenNodes->item(0) : null;
            if (!$labelNode instanceof \DOMNode || !$checkbox instanceof \DOMElement) {
                continue;
            }

            $labelText = trim($labelNode->textContent ?? '');
            $name = $checkbox->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $value = $checkbox->getAttribute('value') !== '' ? $checkbox->getAttribute('value') : '1';
            $checked = $checkbox->hasAttribute('checked');
            $hiddenName = $hidden instanceof \DOMElement && $hidden->getAttribute('name') !== ''
                ? $hidden->getAttribute('name')
                : $name;

            $fieldId = 'aiu-em-userfunc-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
            $row = '<div class="form-check">';
            $row .= '<input type="hidden" name="' . htmlspecialchars($hiddenName) . '" value="0" />';
            $row .= '<input class="form-check-input" type="checkbox" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '"';
            if ($checked) {
                $row .= ' checked="checked"';
            }
            $row .= ' />';
            $row .= '<label class="form-check-label" for="' . htmlspecialchars($fieldId) . '">' . htmlspecialchars($labelText) . '</label>';
            $row .= '</div>';
            $rows[] = $row;
        }

        if ($rows === []) {
            return $html;
        }

        return '<div class="d-flex flex-column gap-2">' . implode('', $rows) . '</div>';
    }

    /**
     * @param array<string, array<string, mixed>> $extensionData
     */
    private function renderFallbackHtml(array $extensionData): string
    {
        $extensionData = $this->prepareExtensionDataForRender($extensionData);
        $out = [];
        foreach ($extensionData as $subcategory) {
            if (!is_array($subcategory)) {
                continue;
            }
            foreach ($subcategory['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $out[] = '<section class="aiu-section">';
                $out[] = $this->renderFallbackFieldHtml($item);
                $out[] = '</section>';
            }
        }

        return implode('', $out);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderFallbackFieldHtml(array $item): string
    {
        $type = (string) ($item['type'] ?? 'string');
        $label = (string) ($item['label'] ?? '');
        $name = (string) ($item['name'] ?? '');
        $description = (string) ($item['description'] ?? '');
        $typeHint = (string) ($item['typeHint'] ?? '');

        if ($type === 'user' && !empty($item['html'])) {
            $out = [];
            if ($label !== '') {
                $out[] = '<div class="aiu-section__label">' . htmlspecialchars($label) . '</div>';
            }
            if ($description !== '') {
                $out[] = '<p class="form-text">' . nl2br(htmlspecialchars($description)) . '</p>';
            }
            $out[] = (string) $item['html'];

            return implode('', $out);
        }

        if ($type === 'boolean') {
            $fieldId = 'aiu-em-' . htmlspecialchars($name);
            $out = ['<div class="form-check mb-3">'];
            $out[] = $this->renderBooleanInputsHtml($item, $fieldId);
            $out[] = '<label class="form-check-label" for="' . $fieldId . '">' . htmlspecialchars($label) . '</label>';
            $out[] = '</div>';
            if ($description !== '') {
                $out[] = '<p class="form-text">' . nl2br(htmlspecialchars($description)) . '</p>';
            }
            if ($typeHint !== '') {
                $out[] = '<p class="form-text">' . htmlspecialchars($typeHint) . '</p>';
            }

            return implode('', $out);
        }

        $fieldId = 'aiu-em-' . htmlspecialchars($name);
        $out = ['<div class="mb-3" data-aiu-features-ext-conf-field>'];
        if ($label !== '') {
            $labelHtml = htmlspecialchars($label);
            if (!empty($item['isSecret']) && trim((string) ($item['storedSecretMask'] ?? '')) !== '') {
                $configured = htmlspecialchars($this->translate('module.aiFeatures.secretKey.configured', 'Configured'));
                $labelHtml .= ' <span class="badge rounded-pill bg-success-subtle text-success-emphasis">'
                    . $configured . '</span>';
            }
            $out[] = '<label class="form-label" for="' . $fieldId . '">' . $labelHtml . '</label>';
        }
        $out[] = $this->renderScalarFieldHtml($item, $fieldId);
        if ($description !== '') {
            $out[] = '<div class="form-text">' . nl2br(htmlspecialchars($description)) . '</div>';
        }
        if ($typeHint !== '') {
            $out[] = '<div class="form-text">' . htmlspecialchars($typeHint) . '</div>';
        }
        $out[] = '</div>';

        return implode('', $out);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderBooleanInputsHtml(array $item, string $fieldId): string
    {
        $name = htmlspecialchars((string) ($item['name'] ?? ''));
        $rawValue = (string) ($item['value'] ?? '');
        $trueValue = htmlspecialchars((string) ($item['trueValue'] ?? '1'));
        $checked = $rawValue === (string) ($item['trueValue'] ?? '1') ? ' checked' : '';

        return '<input type="hidden" name="' . $name . '" value="0" />'
            . '<input class="form-check-input" type="checkbox" name="' . $name . '" id="' . $fieldId . '" value="' . $trueValue . '"' . $checked . ' />';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderScalarFieldHtml(array $item, string $fieldId): string
    {
        $name = htmlspecialchars((string) ($item['name'] ?? ''));
        $rawValue = (string) ($item['value'] ?? '');
        $value = htmlspecialchars($rawValue);
        $type = (string) ($item['type'] ?? 'string');

        if (!empty($item['isSecret'])) {
            return $this->renderSecretFieldHtml($item, $fieldId);
        }

        return match ($type) {
            'options' => $this->renderOptionsFieldHtml($name, $rawValue, $item, $fieldId),
            'int+', 'int' => '<input class="form-control" type="number" step="1" name="' . $name . '" id="' . $fieldId . '" value="' . $value . '"'
                . $this->renderNumberConstraintAttributes($item) . ' />',
            default => '<input class="form-control" type="text" name="' . $name . '" id="' . $fieldId . '" value="' . $value . '" />',
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderNumberConstraintAttributes(array $item): string
    {
        $type = (string) ($item['type'] ?? '');
        $attributes = [];
        if ($type === 'int+') {
            if (array_key_exists('typeIntPlusMin', $item)) {
                $attributes[] = 'min="' . (int) $item['typeIntPlusMin'] . '"';
            }
            if (!empty($item['typeIntPlusMax'])) {
                $attributes[] = 'max="' . (int) $item['typeIntPlusMax'] . '"';
            }
        } elseif ($type === 'int') {
            if (array_key_exists('typeIntMin', $item)) {
                $attributes[] = 'min="' . (int) $item['typeIntMin'] . '"';
            }
            if (array_key_exists('typeIntMax', $item)) {
                $attributes[] = 'max="' . (int) $item['typeIntMax'] . '"';
            }
        }

        return $attributes === [] ? '' : ' ' . implode(' ', $attributes);
    }

    /**
     * Empty int+ fields are optional counters/IDs; default to 0 so save validation passes.
     *
     * @param array<string, string> $submitted
     * @return array<string, string>
     */
    private function normalizeSubmittedIntPlusFields(string $extensionKey, array $submitted): array
    {
        $constantsByName = $this->getConstantsByFieldName($extensionKey);
        foreach ($submitted as $key => $value) {
            if ($value !== '') {
                continue;
            }
            $item = $constantsByName[$key] ?? null;
            if (!is_array($item) || ($item['type'] ?? '') !== 'int+') {
                continue;
            }
            $submitted[$key] = '0';
        }

        return $submitted;
    }

    /**
     * @param array<string, string> $submitted
     * @return list<string>
     */
    private function validateSubmittedSettings(string $extensionKey, array $submitted): array
    {
        $constantsByName = $this->getConstantsByFieldName($extensionKey);
        $errors = [];
        foreach ($submitted as $fieldName => $value) {
            $item = $constantsByName[$fieldName] ?? null;
            if (!is_array($item)) {
                continue;
            }
            $error = $this->validateConstantValue($item, $value);
            if ($error !== null) {
                $errors[] = $error;
            }
            $formOptions = $this->featureProviderFormOptionsByExtension[$extensionKey] ?? null;
            if ($formOptions !== null) {
                $allowed = $formOptions->allowedValuesForField($fieldName);
                if ($allowed !== [] && !in_array(trim($value), $allowed, true)) {
                    $label = trim((string) ($item['label'] ?? $fieldName));
                    $errors[] = sprintf('"%s" has an invalid provider override value.', $label !== '' ? $label : $fieldName);
                }
                $adapterError = $formOptions->validateOverrideValue($fieldName, $value);
                if ($adapterError !== null) {
                    $errors[] = $adapterError;
                }
            }
        }

        $formOptions = $this->featureProviderFormOptionsByExtension[$extensionKey] ?? null;
        if ($formOptions !== null) {
            foreach ($formOptions->validateSubmittedSettings($submitted) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getConstantsByFieldName(string $extensionKey): array
    {
        $parsed = $this->parseExtensionConfiguration($extensionKey);
        $byName = [];
        foreach ($parsed as $category) {
            if (!is_array($category)) {
                continue;
            }
            foreach ($category as $subcategory) {
                if (!is_array($subcategory)) {
                    continue;
                }
                foreach ($subcategory['items'] ?? [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? '');
                    if ($name !== '') {
                        $byName[$name] = $item;
                    }
                }
            }
        }

        return $byName;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function validateConstantValue(array $item, string $value): ?string
    {
        $type = (string) ($item['type'] ?? 'string');
        $label = trim((string) ($item['label'] ?? $item['name'] ?? 'Field'));
        if ($label === '') {
            $label = 'Field';
        }

        if ($type === 'int+') {
            if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
                return sprintf('"%s" must be a whole number.', $label);
            }
            $intValue = (int) $value;
            $min = array_key_exists('typeIntPlusMin', $item) ? (int) $item['typeIntPlusMin'] : 0;
            if ($intValue < $min) {
                return sprintf('"%s" must be at least %d.', $label, $min);
            }
            if (!empty($item['typeIntPlusMax']) && $intValue > (int) $item['typeIntPlusMax']) {
                return sprintf('"%s" must be at most %d.', $label, (int) $item['typeIntPlusMax']);
            }

            return null;
        }

        if ($type === 'int') {
            if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
                return sprintf('"%s" must be a whole number.', $label);
            }
            $intValue = (int) $value;
            if (array_key_exists('typeIntMin', $item) && $intValue < (int) $item['typeIntMin']) {
                return sprintf('"%s" must be at least %d.', $label, (int) $item['typeIntMin']);
            }
            if (array_key_exists('typeIntMax', $item) && $intValue > (int) $item['typeIntMax']) {
                return sprintf('"%s" must be at most %d.', $label, (int) $item['typeIntMax']);
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderOptionsFieldHtml(string $name, string $value, array $item, string $fieldId): string
    {
        $options = ['<select class="form-select" name="' . $name . '" id="' . $fieldId . '">'];
        foreach ($item['labelValueArray'] ?? [] as $labelAndValue) {
            if (!is_array($labelAndValue)) {
                continue;
            }
            $optionValue = htmlspecialchars((string) ($labelAndValue['value'] ?? ''));
            $optionLabel = htmlspecialchars((string) ($labelAndValue['label'] ?? $optionValue));
            $selected = ((string) ($labelAndValue['value'] ?? '') === $value) ? ' selected' : '';
            $options[] = '<option value="' . $optionValue . '"' . $selected . '>' . $optionLabel . '</option>';
        }
        $options[] = '</select>';

        return implode('', $options);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderTemplate(array $variables, ?ServerRequestInterface $request = null): string
    {
        return $this->renderNamedTemplate('AiFeatures/ExtConfCategoryForm', $variables, $request);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderNamedTemplate(string $template, array $variables, ?ServerRequestInterface $request = null): string
    {
        $templateRootPaths = ['EXT:ns_t3af/Resources/Private/Templates/'];
        $partialRootPaths = ['EXT:ns_t3af/Resources/Private/Partials/'];
        $layoutRootPaths = ['EXT:ns_t3af/Resources/Private/Layouts/'];

        if (interface_exists(ViewFactoryInterface::class) && class_exists(ViewFactoryData::class)) {
            $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
            $view = $viewFactory->create(new ViewFactoryData(
                templateRootPaths: $templateRootPaths,
                partialRootPaths: $partialRootPaths,
                layoutRootPaths: $layoutRootPaths,
                request: $request,
            ));
            $view->assignMultiple($variables);

            return $view->render($template);
        }

        if (!class_exists(StandaloneView::class)) {
            return '';
        }

        $view = GeneralUtility::makeInstance(StandaloneView::class);
        if ($request !== null) {
            $view->setRequest($request);
        }
        $view->setTemplateRootPaths($templateRootPaths);
        $view->setPartialRootPaths($partialRootPaths);
        $view->setLayoutRootPaths($layoutRootPaths);
        $view->setTemplate($template);
        $view->assignMultiple($variables);

        return $view->render();
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderSecretFieldHtml(array $item, string $fieldId): string
    {
        $name = htmlspecialchars((string) ($item['name'] ?? ''));
        $masked = trim((string) ($item['storedSecretMask'] ?? ''));
        $hasStored = $masked !== '';

        $out = [];
        if ($hasStored) {
            $storedTemplate = $this->translate(
                'module.aiFeatures.secretKey.stored',
                'API key configured (%s). Leave blank to keep the existing key, or enter a new value to replace it.',
            );
            $out[] = '<small class="aiu-field-subnote aiu-field-subnote--stored">'
                . htmlspecialchars(sprintf($storedTemplate, $masked))
                . '</small>';
        }

        $placeholder = $hasStored
            ? $this->translate('module.aiFeatures.secretKey.placeholderEdit', 'Leave blank to keep existing key')
            : $this->translate('module.aiFeatures.secretKey.placeholderNew', 'Enter your API key');

        $revealLabel = $this->translate('module.aiFeatures.secretKey.reveal', 'Show');

        $out[] = '<div class="aiu-field__inline">';
        $out[] = '<input class="form-control" type="password" name="' . $name . '" id="' . $fieldId . '" value=""'
            . ' data-aiu-secret-key-input'
            . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '"'
            . ' autocomplete="off" />';
        $out[] = '<button type="button" class="reveal" data-aiu-toggle-secret-reveal>'
            . htmlspecialchars($revealLabel) . '</button>';
        $out[] = '</div>';

        return implode('', $out);
    }

    private function translate(string $key, string $default = ''): string
    {
        $label = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:' . $key;
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang !== null) {
            $translated = trim((string) $lang->sL($label));
            if ($translated !== '' && !str_starts_with($translated, 'LLL:')) {
                return $translated;
            }
        }

        return $default;
    }
}
