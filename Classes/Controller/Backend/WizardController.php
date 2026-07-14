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

use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Service\AiUniverseActivityLogService;
use NITSAN\NsT3AF\Service\BrandContextService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\ExtensionExtConfCategoryService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\WizardFeatureToggleService;
use NITSAN\NsT3AF\Service\WizardProgressService;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSchemaService;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX endpoint that finalizes the "Quick setup" wizard: applies the choices
 * collected across its 8 steps in one atomic save (mode, default provider +
 * API key, extension feature toggles, brand context, MCP server enable flag).
 *
 * AJAX route: {@see Configuration/Backend/AjaxRoutes.php} `nst3af_wizard_finalize`
 *
 * @internal
 */
final class WizardController
{
    public function __construct(
        private readonly CreditModeResolver $creditModeResolver,
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly ProviderRepositoryInterface $providerRepository,
        private readonly AdapterRegistry $adapters,
        private readonly CredentialCipher $cipher,
        private readonly ExtensionExtConfCategoryService $extensionExtConfCategoryService,
        private readonly ExtensionSettingsRegistry $extensionSettingsRegistry,
        private readonly ExtensionSettingsSchemaService $extensionSettingsSchemaService,
        private readonly AdvancedSettingsService $mcpAdvancedSettings,
        private readonly WizardProviderCatalog $wizardProviderCatalog,
        private readonly AiUniverseActivityLogService $activityLogService,
        private readonly BrandContextService $brandContextService,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly WizardProgressService $wizardProgress,
        private readonly WizardFeatureToggleService $wizardFeatureToggleService,
    ) {}

    public function ensureProviderAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $catalogId = trim((string) ($body['catalogId'] ?? ''));
        if ($catalogId === '') {
            return new JsonResponse(['ok' => false, 'message' => 'Catalog id is required.'], 400);
        }

        $modelId = trim((string) ($body['modelId'] ?? ''));
        $storagePid = $this->resolveWizardStoragePid($request);
        if ($storagePid === null || $storagePid <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'No valid site root found for setup.'], 400);
        }

        $uid = $this->wizardProviderCatalog->ensureProviderUid($catalogId, $modelId, $storagePid);
        if ($uid === null || $uid <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Unknown or unavailable provider catalog entry.'], 404);
        }

        return new JsonResponse(['ok' => true, 'uid' => $uid]);
    }

    public function progressAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->wizardProgress->isCompleted()) {
            return new JsonResponse(['ok' => true]);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $lastStep = (int) ($body['lastStep'] ?? 0);
        $maxStep = (int) ($body['maxStep'] ?? 0);
        if ($lastStep < 1 || $lastStep > 8 || $maxStep < 1 || $maxStep > 8 || $maxStep < $lastStep) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid wizard step progress.'], 400);
        }

        $this->wizardProgress->saveProgress($lastStep, $maxStep);

        return new JsonResponse(['ok' => true]);
    }

    public function finalizeAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $mode = (string) ($body['mode'] ?? 'ownkeys') === 'credits' ? 'credits' : 'ownkeys';
        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            $mode = 'ownkeys';
        }
        $this->runtimeSettings->save(['credit_mode' => $mode === 'credits' ? 1 : 0]);

        $storagePid = $this->resolveWizardStoragePid($request);
        if ($storagePid === null || $storagePid <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'No valid site root found for setup.'], 400);
        }

        $providerResult = null;
        if ($mode === 'ownkeys') {
            $providerResult = $this->applyProvider($body, $storagePid);
            if ($providerResult instanceof ResponseInterface) {
                return $providerResult;
            }
        }

        $this->applyExtensions($body, $storagePid);
        $brandContextResult = $this->applyBrandContext($body, $storagePid);
        if ($brandContextResult instanceof ResponseInterface) {
            return $brandContextResult;
        }
        $mcpEnabled = !empty($body['mcp']);
        $this->applyMcp($body);

        $this->activityLogService->logWizardFinalized($mode, $mcpEnabled);

        $this->wizardProgress->markCompleted();

        return new JsonResponse([
            'ok' => true,
            'mode' => $mode,
            'connectionOk' => $providerResult ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return bool|ResponseInterface `true`/`false` for the connection outcome, or an
     *                                early-exit error response when the provider can't be resolved.
     */
    private function applyProvider(array $body, int $storagePid): bool|ResponseInterface
    {
        $uid = (int) ($body['providerUid'] ?? 0);
        $catalogId = trim((string) ($body['providerCatalog'] ?? ''));
        $modelId = trim((string) ($body['modelId'] ?? ''));
        if ($uid <= 0 && $catalogId !== '') {
            $uid = $this->wizardProviderCatalog->ensureProviderUid($catalogId, $modelId, $storagePid) ?? 0;
        }
        if ($uid <= 0) {
            return false;
        }

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Provider not found.'], 404);
        }

        if ($provider->pid !== $storagePid) {
            $this->providerRepository->save($uid, ['pid' => $storagePid]);
        }

        $this->providerRepository->setDefault($uid, $storagePid);

        if ($modelId !== '') {
            $this->providerRepository->save($uid, ['model_id' => $modelId]);
            $provider = $this->providerRepository->findByUid($uid) ?? $provider;
        }

        $draftPlainKey = trim((string) ($body['apiKey'] ?? ''));
        if ($draftPlainKey === '') {
            return true;
        }

        try {
            $cipher = $this->cipher->encrypt($draftPlainKey);
        } catch (CipherException $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
        }
        $provider = $provider->withApiKeyCipher($cipher);

        if (!$this->adapters->has($provider->adapterType)) {
            $result = VerifyResult::failure(sprintf('Adapter type "%s" is not registered.', $provider->adapterType));
        } else {
            $result = $this->adapters->get($provider->adapterType)->testConnection($provider);
        }

        if ($result->ok) {
            $this->providerRepository->save($uid, [
                'api_key' => $cipher,
                'last_status' => 'connected',
                'last_status_at' => time(),
                'last_status_message' => $result->message ?? '',
            ]);
        }

        return $result->ok;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyExtensions(array $body, int $storagePid): void
    {
        $extensionToggles = $body['extensionToggles'] ?? null;
        if (!is_array($extensionToggles) || $storagePid <= 0) {
            return;
        }

        foreach ($extensionToggles as $extensionKey => $toggles) {
            if (!is_string($extensionKey) || $extensionKey === '' || !is_array($toggles)) {
                continue;
            }
            if (!$this->extensionSettingsRegistry->isManaged($extensionKey)
                || !$this->extensionExtConfCategoryService->isAvailable($extensionKey)) {
                continue;
            }

            $current = AiUniverseUtilityHelper::getExtensionConf($extensionKey);
            $schemaFields = $this->extensionSettingsSchemaService->getConstantsByFieldName($extensionKey, $current);
            $boolToggles = [];
            foreach ($toggles as $field => $enabled) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                $boolToggles[$field] = WizardFeatureToggleService::normalizeToggleEnabled($enabled);
            }

            $expanded = $this->wizardFeatureToggleService->expandTogglesForExtension($extensionKey, $boolToggles);
            $normalized = [];
            foreach ($expanded as $field => $value) {
                if ($this->wizardFeatureToggleService->isPersistableField(
                    $extensionKey,
                    $field,
                    $current,
                    $schemaFields,
                    $storagePid,
                )) {
                    $normalized[$field] = $value;
                }
            }

            if ($normalized === []) {
                continue;
            }

            // Site settings are keyed by site root page id — same pid as resolveWizardStoragePid().
            $normalized['pageId'] = $storagePid;
            $this->extensionExtConfCategoryService->saveSettings($extensionKey, $normalized);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBrandContext(array $body, int $storagePid): ?ResponseInterface
    {
        $brandContext = $body['brandContext'] ?? null;
        if (!is_array($brandContext)) {
            return new JsonResponse(['ok' => false, 'message' => 'Brand context is required.'], 400);
        }

        $validationError = $this->brandContextService->validateWizardPayload($brandContext);
        if ($validationError !== null) {
            return new JsonResponse(['ok' => false, 'message' => $validationError], 400);
        }

        $uid = $this->brandContextService->createWizardProfile($storagePid, $brandContext);
        if ($uid === null || $uid <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Could not save brand context profile.'], 500);
        }

        return null;
    }

    private function resolveWizardStoragePid(ServerRequestInterface $request): ?int
    {
        $pageId = $this->siteStorageContext->resolvePageIdFromRequest($request);
        if ($pageId > 0) {
            $storagePid = $this->siteStorageContext->resolveStoragePidFromPageId($pageId);
            if ($storagePid !== null && $storagePid > 0) {
                return $storagePid;
            }
        }

        return $this->siteStorageContext->resolveFirstRootStoragePid();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyMcp(array $body): void
    {
        if (!array_key_exists('mcp', $body)) {
            return;
        }
        $this->mcpAdvancedSettings->save(['enableMcpServer' => $body['mcp'] ? 1 : 0]);
    }
}
