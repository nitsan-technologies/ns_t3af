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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Access\RecordAccessGate;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;
use NITSAN\NsT3AF\Credits\Service\CreditsDashboardService;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Event\ProviderTestConnectionEvent;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Provider\Model\ModelDiscoveryServiceInterface;
use NITSAN\NsT3AF\Service\AiUniverseActivityLogService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\ModuleStateService;
use NITSAN\NsT3AF\Service\ProviderFormService;
use NITSAN\NsT3AF\Service\ProviderImportService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\WizardExtensionCatalogService;
use NITSAN\NsT3AF\Service\WizardProgressService;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * AI providers: HTML list + drawer, and JSON AJAX endpoints for the same UI.
 *
 * Module routes: {@see Configuration/Backend/Modules.php}
 * AJAX routes: {@see Configuration/Backend/AjaxRoutes.php}
 *
 * @internal
 */
final class ProviderController extends AbstractAiUniverseModuleController
{
    private const TEMPLATE_LIST = 'Provider/List';

    private const TEMPLATE_DRAWER = 'Provider/Drawer';

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        ModuleTabUtility $moduleTabUtility,
        ProviderRepositoryInterface $providerRepository,
        private readonly AdapterRegistry $adapters,
        private readonly CredentialCipher $cipher,
        private readonly ProviderFormService $formService,
        private readonly AiUniverseActivityLogService $activityLogService,
        private readonly EventDispatcherInterface $events,
        private readonly ModelDiscoveryServiceInterface $modelDiscovery,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly CreditsDashboardService $creditsDashboardService,
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly CreditsReturnUrlBuilder $creditsReturnUrlBuilder,
        PageRenderer $pageRenderer,
        CreditOverviewLineService $creditOverviewLine,
        ModuleStateService $moduleStateService,
        WizardProviderCatalog $wizardProviderCatalog,
        WizardExtensionCatalogService $wizardExtensionCatalog,
        SiteStorageContext $siteStorageContext,
        WizardProgressService $wizardProgress,
        private readonly ProviderImportService $providerImportService,
        private readonly RecordAccessGate $recordAccessGate,
        private readonly ExtensionAvailability $extensionAvailability,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $moduleTabUtility,
            $providerRepository,
            $pageRenderer,
            $creditOverviewLine,
            $moduleStateService,
            $wizardProviderCatalog,
            $wizardExtensionCatalog,
            $siteStorageContext,
            $wizardProgress,
        );
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $resolution = $this->resolveSiteStorage($request);
        $routeParams = $this->routeParamsForPage($request);
        $view = $this->createModuleView($request, 'providers');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/provider-import.js');

        if (!$resolution->isResolved()) {
            $view->assignMultiple([
                'formErrors' => [],
                'flash' => $this->flashFromQuery($request),
            ]);

            return $view->renderResponse('Provider/PageSelectionRequired');
        }

        $storagePid = $resolution->storagePid;
        $providers = $this->providerRepository->findAllByStoragePid($storagePid, includeHidden: true);
        $providerRows = [];
        $defaultIdentifier = null;
        $activeProviderCount = 0;
        $adapterLabelMap = [];
        foreach ($this->adapters->all() as $type => $adapter) {
            $adapterLabelMap[$type] = $this->formatAdapterUiLabel($type, $adapter->getDisplayName());
        }
        foreach ($providers as $provider) {
            $providerRows[] = [
                'provider' => $provider,
                'adapterLabel' => $adapterLabelMap[$provider->adapterType]
                    ?? $this->formatFallbackAdapterLabel($provider->adapterType),
                'editUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.providers.edit',
                    array_merge($routeParams, ['uid' => $provider->uid]),
                ),
            ];
            if ($provider->isEnabled) {
                $activeProviderCount++;
            }
            if ($provider->isDefault) {
                $defaultIdentifier = $provider->identifier;
            }
        }

        $providersUri = $this->creditsReturnUrlBuilder->fromRoute('t3af_dashboard.providers', $routeParams);
        $creditsDashboard = $this->creditsDashboardService->buildForProvidersPage($providersUri);

        $importSites = $this->siteStorageContext->listConfiguredSites($storagePid);
        foreach ($importSites as &$site) {
            $site['providerCount'] = count($this->providerImportService->listProvidersForSite($site['storagePid']));
        }
        unset($site);
        $importSites = array_values(array_filter(
            $importSites,
            static fn(array $site): bool => ($site['providerCount'] ?? 0) > 0,
        ));

        $backendUser = $this->getBackendUser();
        $canModifyProviders = $this->recordAccessGate->canModifyTable(
            $backendUser,
            'tx_nst3af_provider',
        );

        $view->assignMultiple([
            'providers' => $providers,
            'providerRows' => $providerRows,
            'adapters' => $this->adapterChoices(),
            'activeProviderCount' => $activeProviderCount,
            'defaultIdentifier' => $defaultIdentifier,
            'formErrors' => [],
            'flash' => $this->flashFromQuery($request),
            'newProviderUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers.new', $routeParams),
            'saveUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers.save', $routeParams),
            'deleteUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers.delete', $routeParams),
            'importSitesJson' => json_encode(
                $importSites,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
            ) ?: '[]',
            'siteStoragePid' => $storagePid,
            'creditsModeEnabled' => self::fluidFlag($this->creditModeResolver->isEnabled()),
            'creditsModeActive' => self::fluidFlag($this->creditModeResolver->isActive()),
            'creditsFeatureAvailable' => self::fluidFlag($this->creditModeResolver->isPubliclyAvailable()),
            'providersReadOnly' => self::fluidFlag($this->creditModeResolver->isActive() || !$canModifyProviders),
            'canModifyProviders' => self::fluidFlag($canModifyProviders),
            'creditsDashboard' => $creditsDashboard,
            'providersReturnUrl' => $providersUri,
            'creditsBearerToken' => $this->runtimeSettings->getTokenPlain() ?? '',
            'environmentRequirementAlerts' => $this->credentialEnvironmentAlerts(),
            ...$this->embeddingModelFieldViewData(),
        ]);

        return $view->renderResponse(self::TEMPLATE_LIST);
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyWhenProvidersReadOnly($request)) {
            return $denied;
        }

        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers', $this->routeParamsForPage($request)),
            );
        }

        $routeParams = $this->routeParamsForPage($request);
        $view = $this->createModuleView($request, 'providers');
        $view->assignMultiple([
            'provider' => null,
            'adapterRows' => $this->buildAdapterRows(),
            'allCapabilities' => Capability::ALL,
            'errors' => [],
            'apiKeyMasked' => '',
            'showEndpointField' => self::fluidFlag(false),
            'showApiKeyField' => self::fluidFlag(true),
            'saveUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers.save', $routeParams),
            'cancelUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers', $routeParams),
            'environmentRequirementAlerts' => $this->credentialEnvironmentAlerts(),
            ...$this->embeddingModelFieldViewData(),
        ]);

        return $view->renderResponse(self::TEMPLATE_DRAWER);
    }

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyWhenProvidersReadOnly($request)) {
            return $denied;
        }

        $resolution = $this->resolveSiteStorage($request);
        $routeParams = $this->routeParamsForPage($request);
        $uid = (int) ($request->getQueryParams()['uid'] ?? 0);
        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null || ($resolution->isResolved() && $provider->pid !== $resolution->storagePid)) {
            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.providers',
                    array_merge($routeParams, ['flash' => 'not-found']),
                ),
            );
        }

        $view = $this->createModuleView($request, 'providers');
        $view->assignMultiple([
            'provider' => $provider,
            'adapterRows' => $this->buildAdapterRows(),
            'allCapabilities' => Capability::ALL,
            'errors' => [],
            'apiKeyMasked' => $this->maskedApiKeyForProvider($provider),
            'showEndpointField' => self::fluidFlag($this->shouldShowEndpointField($provider)),
            'showApiKeyField' => self::fluidFlag($this->shouldShowApiKeyField($provider)),
            'saveUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers.save', $routeParams),
            'cancelUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers', $routeParams),
            'environmentRequirementAlerts' => $this->credentialEnvironmentAlerts(),
            ...$this->embeddingModelFieldViewData(),
        ]);

        return $view->renderResponse(self::TEMPLATE_DRAWER);
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyWhenProvidersReadOnly($request)) {
            return $denied;
        }

        $resolution = $this->resolveSiteStorage($request);
        $routeParams = $this->routeParamsForPage($request);
        /** @var array<string, mixed> $body */
        $body = (array) $request->getParsedBody();
        $uid = (int) ($body['uid'] ?? 0);

        if (!$resolution->isResolved()) {
            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers', $routeParams),
            );
        }

        $result = $this->formService->save($uid, $body, $resolution->storagePid);

        if (!$result->ok) {
            $view = $this->createModuleView($request, 'providers');
            $existingProvider = $uid > 0 ? $this->providerRepository->findByUid($uid) : null;
            $view->assignMultiple([
                'provider' => $existingProvider,
                'adapterRows' => $this->buildAdapterRows(),
                'allCapabilities' => Capability::ALL,
                'errors' => $result->errors,
                'submitted' => $body,
                'apiKeyMasked' => $this->maskedApiKeyForProvider($existingProvider),
                'showEndpointField' => self::fluidFlag($this->shouldShowEndpointField($existingProvider, $body)),
                'showApiKeyField' => self::fluidFlag($this->shouldShowApiKeyField($existingProvider, $body)),
                'saveUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers.save', $routeParams),
                'cancelUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers', $routeParams),
                'environmentRequirementAlerts' => $this->credentialEnvironmentAlerts(),
                ...$this->embeddingModelFieldViewData(),
            ]);

            return $view->renderResponse(self::TEMPLATE_DRAWER);
        }

        $provider = $this->providerRepository->findByUid($result->uid);
        if ($provider !== null) {
            $this->activityLogService->logProviderSaved(
                $result->uid,
                $provider->identifier,
                $provider->title,
                $uid === 0,
            );
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.providers',
                array_merge($routeParams, ['flash' => 'saved']),
            ),
        );
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyWhenProvidersReadOnly($request)) {
            return $denied;
        }

        $resolution = $this->resolveSiteStorage($request);
        $routeParams = $this->routeParamsForPage($request);
        $body = $request->getParsedBody();
        $uid = (int) (is_array($body) ? ($body['uid'] ?? 0) : 0);

        if ($uid > 0 && $resolution->isResolved()) {
            $provider = $this->providerRepository->findByUid($uid);
            if ($provider instanceof Provider && $provider->pid === $resolution->storagePid) {
                $this->providerRepository->softDelete($uid);
            }
            if ($provider !== null) {
                $this->activityLogService->logProviderDeleted($uid, $provider->identifier, $provider->title);
            }
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.providers',
                array_merge($routeParams, ['flash' => 'deleted']),
            ),
        );
    }

    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        // testAction persists a verified draft API key — same guard as save/delete (S-03).
        if ($denied = $this->denyJsonWhenProvidersReadOnly()) {
            return $denied;
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $uid = (int) ($body['uid'] ?? $request->getQueryParams()['uid'] ?? 0);
        $draftPlainKey = trim((string) ($body['apiKey'] ?? ''));

        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Provider not found.'], 404);
        }

        $draftCipher = null;
        if ($draftPlainKey !== '') {
            try {
                $draftCipher = $this->cipher->encrypt($draftPlainKey);
                $provider = $provider->withApiKeyCipher($draftCipher);
            } catch (CipherException $e) {
                return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
            }
        }

        if (!$this->adapters->has($provider->adapterType)) {
            $result = VerifyResult::failure(sprintf('Adapter type "%s" is not registered.', $provider->adapterType));
        } else {
            $result = $this->adapters->get($provider->adapterType)->testConnection($provider);
        }

        $statusValues = [
            'last_status' => $result->ok ? 'connected' : 'disconnected',
            'last_status_at' => time(),
            'last_status_message' => $result->message ?? '',
        ];
        if ($draftCipher !== null) {
            // A freshly entered key only proves itself worth storing once it verifies — never persist an untested draft.
            if ($result->ok) {
                $this->providerRepository->save($uid, $statusValues + ['api_key' => $draftCipher]);
            }
        } else {
            $this->providerRepository->updateStatus($uid, $statusValues);
        }
        $this->events->dispatch(new ProviderTestConnectionEvent($provider, $result));

        return new JsonResponse([
            'ok' => $result->ok,
            'message' => $result->message,
            'models' => $result->models,
            'latencyMs' => $result->latencyMs,
        ]);
    }

    public function setDefaultAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyJsonWhenProvidersReadOnly()) {
            return $denied;
        }

        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return new JsonResponse(['ok' => false, 'message' => 'Select a page from the page tree first.'], 400);
        }

        $body = $request->getParsedBody();
        $uid = (int) (is_array($body) ? ($body['uid'] ?? 0) : 0);
        if ($uid <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Missing uid.'], 400);
        }
        $provider = $this->providerRepository->findByUid($uid);
        if ($provider === null || $provider->pid !== $resolution->storagePid) {
            return new JsonResponse(['ok' => false, 'message' => 'Provider not found.'], 404);
        }

        $this->providerRepository->setDefault($uid, $resolution->storagePid);
        $this->activityLogService->logProviderSetDefault($provider->identifier, $provider->title);


        return new JsonResponse(['ok' => true, 'identifier' => $provider->identifier]);
    }

    public function importSourcesAction(ServerRequestInterface $request): ResponseInterface
    {
        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return new JsonResponse(['ok' => false, 'message' => 'Select a page from the page tree first.'], 400);
        }

        $sourceStoragePid = (int) ($request->getQueryParams()['sourceStoragePid'] ?? 0);
        if ($sourceStoragePid <= 0 || $sourceStoragePid === $resolution->storagePid) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid source site.'], 400);
        }

        $providers = $this->providerImportService->listProvidersForSite($sourceStoragePid);
        $rows = array_map(static fn(Provider $provider): array => [
            'uid' => $provider->uid,
            'identifier' => $provider->identifier,
            'title' => $provider->title,
            'adapterType' => $provider->adapterType,
            'modelId' => $provider->modelId,
            'isDefault' => $provider->isDefault,
        ], $providers);

        return new JsonResponse(['ok' => true, 'providers' => $rows]);
    }

    public function importExecuteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyJsonWhenProvidersReadOnly()) {
            return $denied;
        }

        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return new JsonResponse(['ok' => false, 'message' => 'Select a page from the page tree first.'], 400);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        $sourceStoragePid = (int) ($body['sourceStoragePid'] ?? 0);
        $uids = $body['uids'] ?? [];
        if (!is_array($uids)) {
            $uids = [];
        }
        $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $uid): bool => $uid > 0));

        if ($sourceStoragePid <= 0 || $sourceStoragePid === $resolution->storagePid || $uids === []) {
            return new JsonResponse(['ok' => false, 'message' => 'Select at least one provider to import.'], 400);
        }

        try {
            $result = $this->providerImportService->importProviders(
                $uids,
                $sourceStoragePid,
                $resolution->storagePid,
            );
        } catch (UniqueConstraintViolationException $exception) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'A provider with this identifier already exists. Run the upgrade wizard'
                    . ' "drop legacy global provider identifier unique index" in the Install Tool, then retry.',
            ], 409);
        }

        return new JsonResponse([
            'ok' => true,
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);
    }

    public function searchAction(ServerRequestInterface $request): ResponseInterface
    {
        $resolution = $this->resolveSiteStorage($request);
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $providers = $resolution->isResolved()
            ? $this->providerRepository->findAllByStoragePid($resolution->storagePid, includeHidden: true)
            : [];
        $matches = array_filter($providers, static function ($p) use ($q): bool {
            if ($q === '') {
                return true;
            }
            $needle = mb_strtolower($q);

            return str_contains(mb_strtolower($p->identifier), $needle)
                || str_contains(mb_strtolower($p->title), $needle)
                || str_contains(mb_strtolower($p->modelId), $needle);
        });

        $rows = array_map(static fn($p): array => [
            'uid' => $p->uid,
            'identifier' => $p->identifier,
            'title' => $p->title,
            'adapterType' => $p->adapterType,
            'modelId' => $p->modelId,
            'isDefault' => $p->isDefault,
            'priority' => $p->priority,
            'lastStatus' => $p->lastStatus,
        ], array_values($matches));

        return new JsonResponse(['rows' => $rows, 'count' => count($rows)]);
    }

    /**
     * Returns the merged model list for one provider (persisted or transient).
     *
     * Query params:
     *  - `uid`         : provider uid; 0 / missing → use posted draft.
     *  - `refresh`     : `1` to bypass cache and re-probe.
     *  - `adapterType` : draft adapter type (when uid=0).
     *  - `endpoint`    : draft endpoint URL.
     *  - `apiKey`      : draft API key (plaintext; encrypted in memory only).
     */
    public function modelsAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $params = is_array($body) ? array_merge($query, $body) : $query;

        $uid = (int) ($params['uid'] ?? 0);
        $refresh = (string) ($params['refresh'] ?? '') === '1';

        if ($uid > 0) {
            $provider = $this->providerRepository->findByUid($uid);
            if ($provider === null) {
                return new JsonResponse(['ok' => false, 'message' => 'Provider not found.'], 404);
            }
        } else {
            $adapterType = Provider::normalizeAdapterType((string) ($params['adapterType'] ?? ''));
            if ($adapterType === '') {
                return new JsonResponse(['ok' => false, 'message' => 'adapterType is required.'], 400);
            }
            $provider = $this->buildTransient($adapterType, $params);
        }

        $models = $this->modelDiscovery->discover($provider, $refresh);

        return new JsonResponse([
            'ok' => true,
            'count' => count($models),
            'models' => array_map(static fn($m): array => $m->toArray(), $models),
        ]);
    }

    /**
     * Preset chip / select ordering — mirrors design mockups; unknown adapters append alphabetically.
     *
     * @return list<string>
     */
    private function presetAdapterOrder(): array
    {
        return [
            'symfony.openai',
            'symfony.anthropic',
            'symfony.ollama',
            'symfony.azure',
            'symfony.gemini',
            'symfony.openrouter',
            Provider::ADAPTER_OPENAI_COMPATIBLE,
        ];
    }

    /**
     * @return list<array{type: string, label: string, defaultEndpoint: string}>
     */
    private function buildAdapterRows(): array
    {
        $all = $this->adapters->all();
        $rowsByType = [];
        foreach ($all as $type => $adapter) {
            $rowsByType[$type] = [
                'type' => $type,
                'label' => $this->formatAdapterUiLabel($type, $adapter->getDisplayName()),
                'defaultEndpoint' => $adapter->getDefaultEndpoint(),
            ];
        }

        $ordered = [];
        foreach ($this->presetAdapterOrder() as $type) {
            if (isset($rowsByType[$type])) {
                $ordered[] = $rowsByType[$type];
                unset($rowsByType[$type]);
            }
        }

        ksort($rowsByType);
        foreach ($rowsByType as $row) {
            $ordered[] = $row;
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function shouldShowEndpointField(?Provider $provider, array $submitted = []): bool
    {
        $adapterType = $this->drawerAdapterType($provider, $submitted);

        return Provider::adapterRequiresEndpoint($adapterType);
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function shouldShowApiKeyField(?Provider $provider, array $submitted = []): bool
    {
        return Provider::adapterRequiresApiKey($this->drawerAdapterType($provider, $submitted));
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function drawerAdapterType(?Provider $provider, array $submitted = []): string
    {
        $raw = (string) ($submitted['adapter_type'] ?? ($provider !== null ? $provider->adapterType : ''));

        return Provider::normalizeAdapterType($raw);
    }

    /**
     * @return array{showEmbeddingModelField: int}
     */
    private function embeddingModelFieldViewData(): array
    {
        return [
            'showEmbeddingModelField' => self::fluidFlag(
                $this->extensionAvailability->isEmbeddingModelConfigurationAvailable(),
            ),
        ];
    }

    private function formatFallbackAdapterLabel(string $type): string
    {
        $parts = explode('.', $type, 2);
        $name = $parts[1] ?? $parts[0];
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    private function formatAdapterUiLabel(string $type, string $registryDisplayName): string
    {
        if ($type === Provider::ADAPTER_OPENAI_COMPATIBLE) {
            return 'Custom / Other';
        }

        $stripped = preg_replace('#\s*\(Symfony AI\)\s*$#i', '', $registryDisplayName);

        return ($stripped !== null && $stripped !== '') ? $stripped : $registryDisplayName;
    }

    private function maskedApiKeyForProvider(?Provider $provider): string
    {
        if ($provider === null || trim($provider->apiKeyCipher) === '' || !$this->cipher->isEncrypted($provider->apiKeyCipher)) {
            return '';
        }

        try {
            return $this->cipher->mask($this->cipher->decrypt($provider->apiKeyCipher));
        } catch (\Throwable) {
            return '••••••••';
        }
    }

    /**
     * @return array<string, string> Map of adapter type → display name.
     */
    private function adapterChoices(): array
    {
        $out = [];
        foreach ($this->adapters->all() as $type => $adapter) {
            $out[$type] = $adapter->getDisplayName();
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildTransient(string $adapterType, array $params): Provider
    {
        $endpoint = trim((string) ($params['endpoint'] ?? ''));
        if ($endpoint === '' && $this->adapters->has($adapterType)) {
            $endpoint = trim($this->adapters->get($adapterType)->getDefaultEndpoint());
        }
        $plainKey = (string) ($params['apiKey'] ?? '');
        $cipher = '';
        if ($plainKey !== '') {
            try {
                $cipher = $this->cipher->encrypt($plainKey);
            } catch (CipherException) {
                $cipher = '';
            }
        }

        return new Provider(
            uid: 0,
            pid: 0,
            identifier: 'draft',
            title: 'draft',
            adapterType: $adapterType,
            endpointUrl: $endpoint,
            apiKeyCipher: $cipher,
            modelId: '',
            embeddingModelId: '',
            capabilities: [],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: Provider::LAST_STATUS_UNKNOWN,
            lastStatusAt: 0,
            lastStatusMessage: Provider::LAST_STATUS_UNKNOWN,
            beGroups: [],
        );
    }

    private function denyWhenProvidersReadOnly(ServerRequestInterface $request): ?ResponseInterface
    {
        if ($this->recordAccessGate->canModifyTable($this->getBackendUser(), 'tx_nst3af_provider')) {
            return null;
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.providers',
                array_merge($this->routeParamsForPage($request), ['flash' => 'access-denied']),
            ),
        );
    }

    /**
     * JSON variant of {@see denyWhenProvidersReadOnly()} for AJAX actions that
     * persist provider changes (test/setDefault/importExecute).
     */
    private function denyJsonWhenProvidersReadOnly(): ?ResponseInterface
    {
        if ($this->recordAccessGate->canModifyTable($this->getBackendUser(), 'tx_nst3af_provider')) {
            return null;
        }

        return new JsonResponse(['ok' => false, 'message' => 'access-denied'], 403);
    }
}
