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

namespace NITSAN\NsT3AF\Controller\Backend;

use NITSAN\NsT3AF\Access\AiUniverseRecordMap;
use NITSAN\NsT3AF\Access\RecordAccessEnforcer;
use NITSAN\NsT3AF\Service\BrandContextFeatureSettingsService;
use NITSAN\NsT3AF\Service\ExtensionExtConfCategoryService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * AJAX endpoints for AI Features drawer: load/save extension configuration via scope providers.
 */
final class FeatureSettingsController
{
    public function __construct(
        private readonly ExtensionExtConfCategoryService $extensionExtConfCategoryService,
        private readonly BrandContextFeatureSettingsService $brandContextFeatureSettings,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly RecordAccessEnforcer $recordAccessEnforcer,
    ) {}

    public function getAction(ServerRequestInterface $request): ResponseInterface
    {
        $siteError = $this->requireSiteStorage($request);
        if ($siteError instanceof JsonResponse) {
            return $siteError;
        }

        $extensionKey = $this->resolveExtensionKey($request->getQueryParams());
        $scope = (string) ($request->getQueryParams()['scope'] ?? '');

        if (!$this->extensionExtConfCategoryService->isAvailable($extensionKey)) {
            return $this->errorResponse(
                $this->missingExtensionLabelKey($extensionKey),
                sprintf('%s extension is not loaded.', $extensionKey),
                503,
            );
        }
        if (!$this->extensionExtConfCategoryService->isValidScope($extensionKey, $scope)) {
            return $this->errorResponse(
                'module.aiFeatures.errorInvalidScope',
                'Invalid settings scope.',
                400,
            );
        }

        $html = $this->extensionExtConfCategoryService->renderCategoryForm($extensionKey, $scope, $request);
        if ($html === '') {
            return $this->errorResponse(
                'module.aiFeatures.errorEmptyForm',
                'No settings found for this scope.',
                404,
            );
        }

        $resolution = $this->siteStorageContext->resolveFromRequest($request);
        $brandContextHtml = '';
        if ($this->brandContextFeatureSettings->supportsScopeOverride($extensionKey, $scope)) {
            $brandContextHtml = $this->brandContextFeatureSettings->renderOverrideSelect(
                $resolution->storagePid,
                $extensionKey,
                $scope,
            );
        }

        return new JsonResponse([
            'success' => true,
            'html' => $brandContextHtml . $html,
        ]);
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($denied = $this->recordAccessEnforcer->denyUnlessCanModifyCatalogId(
            $user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication ? $user : null,
            AiUniverseRecordMap::EXTENSION_SETTINGS,
        )) {
            return $denied;
        }

        $siteError = $this->requireSiteStorage($request);
        if ($siteError instanceof JsonResponse) {
            return $siteError;
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $extensionKey = $this->resolveExtensionKey($body);
        $scope = (string) ($body['scope'] ?? '');

        if (!$this->extensionExtConfCategoryService->isAvailable($extensionKey)) {
            return $this->errorResponse(
                $this->missingExtensionLabelKey($extensionKey),
                sprintf('%s extension is not loaded.', $extensionKey),
                503,
            );
        }
        if (!$this->extensionExtConfCategoryService->isValidScope($extensionKey, $scope)) {
            return $this->errorResponse(
                'module.aiFeatures.errorInvalidScope',
                'Invalid settings scope.',
                400,
            );
        }

        try {
            $resolution = $this->siteStorageContext->resolveFromRequest($request);
            if (
                $this->brandContextFeatureSettings->supportsScopeOverride($extensionKey, $scope)
                && array_key_exists('brandContextProfileUid', $body)
            ) {
                $this->brandContextFeatureSettings->saveProfileUid(
                    $resolution->storagePid,
                    $extensionKey,
                    $scope,
                    max(0, (int) $body['brandContextProfileUid']),
                );
                unset($body['brandContextProfileUid']);
            }

            $result = $this->extensionExtConfCategoryService->saveSettings($extensionKey, $body);
            $lang = $this->getLanguageService();
            if (($result['success'] ?? true) === false) {
                $title = $lang->sL((string) ($result['title'] ?? ''));
                $message = (string) ($result['message'] ?? '');
                if ($message !== '' && str_starts_with($message, 'LLL:')) {
                    $message = $lang->sL($message);
                }

                return new JsonResponse([
                    'success' => false,
                    'title' => $title !== '' && !str_starts_with($title, 'LLL:') ? $title : $lang->sL(
                        'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:module.aiFeatures.saveErrorTitle',
                    ),
                    'message' => $message !== '' ? $message : 'Validation failed.',
                    'severity' => (int) ($result['severity'] ?? 2),
                ], 400);
            }

            return new JsonResponse([
                'success' => true,
                'title' => $lang->sL($result['title']),
                'message' => $lang->sL($result['message']),
                'severity' => $result['severity'],
            ]);
        } catch (\Throwable $exception) {
            return $this->errorResponse(
                'module.aiFeatures.saveErrorTitle',
                'Could not save settings: ' . $exception->getMessage(),
                500,
                is_string($exception->getMessage()) ? $exception->getMessage() : '',
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveExtensionKey(array $params): string
    {
        $extensionKey = trim((string) ($params['extension'] ?? ''));
        if ($extensionKey !== '' && $this->extensionExtConfCategoryService->isSupportedExtension($extensionKey)) {
            return $extensionKey;
        }

        return $this->extensionExtConfCategoryService->resolveDefaultExtensionKey();
    }

    private function requireSiteStorage(ServerRequestInterface $request): ?JsonResponse
    {
        $resolution = $this->siteStorageContext->resolveFromRequest($request);
        if ($resolution->isResolved()) {
            return null;
        }

        $labelKey = $resolution->reason === 'page_not_in_site'
            ? 'module.siteStorage.pageNotInSite'
            : 'module.siteStorage.pageRequired';

        return $this->errorResponse(
            $labelKey,
            $resolution->reason === 'page_not_in_site'
                ? 'The selected page is not part of a configured site.'
                : 'Select a page from the page tree to manage site-specific AI settings.',
            400,
        );
    }

    private function missingExtensionLabelKey(string $extensionKey): string
    {
        return $this->extensionExtConfCategoryService->getUnavailableLabelKey($extensionKey);
    }

    private function errorResponse(string $labelKey, string $fallback, int $status, string $detail = ''): JsonResponse
    {
        $lang = $this->getLanguageService();
        $message = $lang->sL('LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:' . $labelKey);
        if ($message === '' || str_contains($message, 'module.')) {
            $message = $fallback;
        }
        if ($detail !== '') {
            $message .= ' ' . $detail;
        }

        return new JsonResponse([
            'success' => false,
            'title' => $lang->sL('LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:module.aiFeatures.saveErrorTitle'),
            'message' => $message,
            'severity' => 2,
        ], $status);
    }

    private function getLanguageService(): LanguageService
    {
        return $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER']);
    }
}
