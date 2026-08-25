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

namespace NITSAN\NsT3AF\Controller\Backend;

use NITSAN\NsT3AF\AiLabel\Dto\AiLabelFilters;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelBulkActionService;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelFolderTreeService;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelMediaFolderPreference;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelMediaListService;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelRecordDrawerService;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelStatisticsService;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSystemStatusService;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelTextListService;
use NITSAN\NsT3AF\AiLabel\Service\AutoConfirmSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\CoverageScoreService;
use NITSAN\NsT3AF\AiLabel\Service\EuIconManifestService;
use NITSAN\NsT3AF\AiLabel\Service\EvidenceExportService;
use NITSAN\NsT3AF\AiLabel\Service\OriginRecorder;
use NITSAN\NsT3AF\Pagination\FixedTotalPaginator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AiLabelController extends AbstractAiUniverseModuleController
{
    public function __construct(
        \TYPO3\CMS\Backend\Template\ModuleTemplateFactory $moduleTemplateFactory,
        \TYPO3\CMS\Backend\Routing\UriBuilder $uriBuilder,
        \NITSAN\NsT3AF\Utility\ModuleTabUtility $moduleTabUtility,
        \NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface $providerRepository,
        \TYPO3\CMS\Core\Page\PageRenderer $pageRenderer,
        \NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService $creditOverviewLine,
        \NITSAN\NsT3AF\Service\ModuleStateService $moduleStateService,
        \NITSAN\NsT3AF\Service\WizardProviderCatalog $wizardProviderCatalog,
        \NITSAN\NsT3AF\Service\WizardExtensionCatalogService $wizardExtensionCatalog,
        \NITSAN\NsT3AF\Service\SiteStorageContext $siteStorageContext,
        \NITSAN\NsT3AF\Service\WizardProgressService $wizardProgress,
        private readonly AiLabelStatisticsService $statisticsService,
        private readonly CoverageScoreService $coverageScoreService,
        private readonly EuIconManifestService $iconManifest,
        private readonly OriginRecorder $originRecorder,
        private readonly ComplianceStringsService $complianceStrings,
        private readonly AiLabelFolderTreeService $folderTreeService,
        private readonly AiLabelMediaListService $mediaListService,
        private readonly AiLabelTextListService $textListService,
        private readonly AiLabelBulkActionService $bulkActionService,
        private readonly AiLabelSystemStatusService $systemStatusService,
        private readonly AiLabelSettingsService $settingsService,
        private readonly AiLabelRecordDrawerService $recordDrawerService,
        private readonly EvidenceExportService $evidenceExportService,
        private readonly AiLabelMediaFolderPreference $mediaFolderPreference,
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

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiLabel');
        $coverage = $this->coverageScoreService->compute($this->originRecorder, $this->iconManifest);
        $statistics = $this->statisticsService->compute();

        $view->assignMultiple(array_merge(
            $this->commonAssigns($request, 'overview'),
            [
                'coverage' => $coverage,
                'statistics' => $statistics,
                'systemStatus' => $this->systemStatusService->checks(),
                'systemWarningCount' => $this->systemStatusService->warningCount(),
                'productCaveat' => $this->complianceStrings->get('caveat'),
                'coverageCaveat' => $this->complianceStrings->get('coverageCaveat'),
                'coverageClosingLine' => $this->complianceStrings->get('coverageClosingLine'),
                'unboundGenerations' => $this->originRecorder->listUnboundGenerations(),
            ],
        ));

        return $view->renderResponse('AiLabel/Overview');
    }

    public function mediaAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiLabel');
        $params = array_merge($request->getQueryParams(), is_array($request->getParsedBody()) ? $request->getParsedBody() : []);
        $backendUser = $this->getBackendUser();
        $savedFolder = $backendUser !== null ? $this->mediaFolderPreference->get($backendUser) : '';
        if (trim((string) ($params['folder'] ?? '')) === '' && $savedFolder !== '') {
            $params['folder'] = $savedFolder;
        }
        $filters = AiLabelFilters::fromRequestParams($params);
        $folderTree = $this->folderTreeService->buildTree($filters->folder);
        $activeFolder = $this->folderTreeService->resolveActiveFolder($filters->folder);
        $filters = AiLabelFilters::fromRequestParams(array_merge($params, ['folder' => $activeFolder]));
        $mediaList = $this->mediaListService->list($filters);
        $mediaList['rows'] = $this->attachRecordEditUris($request, $mediaList['rows'], 'media');

        $view->assignMultiple(array_merge(
            $this->commonAssigns($request, 'media'),
            [
                'filters' => $filters,
                'isDefaultMediaFolder' => $this->mediaFolderPreference->isSame($savedFolder, $activeFolder),
                'setDefaultFolderUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_label.media_default',
                    $this->routeParamsForPage($request),
                ),
                'filterRouteParams' => $filters->toRouteParams(),
                'listRoute' => 't3af_dashboard.ai_label.media',
                'aiLabelPagination' => $this->buildListPagination($mediaList, $filters),
                'folderTree' => $folderTree,
                'mediaList' => $mediaList,
                'bulkActionUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.bulk'),
                'undoUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.undo'),
                'recordEditBaseUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.record_edit'),
                'exportUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_label.export',
                    ['format' => 'csv', 'scope' => 'media'],
                ),
                'refreshUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_label.media',
                    array_merge($this->routeParamsForPage($request), $filters->toRouteParams()),
                ),
            ],
        ));

        return $view->renderResponse('AiLabel/Media');
    }

    public function textsAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiLabel');
        $params = array_merge($request->getQueryParams(), is_array($request->getParsedBody()) ? $request->getParsedBody() : []);
        $filters = AiLabelFilters::fromRequestParams($params);
        $textList = $this->textListService->list($filters);
        $textList['rows'] = $this->attachRecordEditUris($request, $textList['rows'], 'texts');

        $view->assignMultiple(array_merge(
            $this->commonAssigns($request, 'texts'),
            [
                'filters' => $filters,
                'filterRouteParams' => $filters->toRouteParams(),
                'listRoute' => 't3af_dashboard.ai_label.texts',
                'aiLabelPagination' => $this->buildListPagination($textList, $filters),
                'textList' => $textList,
                'bulkActionUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.bulk'),
                'undoUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.undo'),
                'recordEditBaseUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.record_edit'),
                'exportUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_label.export',
                    ['format' => 'csv', 'scope' => 'texts'],
                ),
                'refreshUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_label.texts',
                    array_merge($this->routeParamsForPage($request), $filters->toRouteParams()),
                ),
            ],
        ));

        return $view->renderResponse('AiLabel/Texts');
    }

    public function settingsAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiLabel');
        $autoConfirm = GeneralUtility::makeInstance(AutoConfirmSettingsService::class);

        $view->assignMultiple(array_merge(
            $this->commonAssigns($request, 'settings'),
            [
                'settings' => $this->settingsService->all(),
                'holdList' => $autoConfirm->holdList(),
                'autoConfirmSources' => $autoConfirm->autoConfirmSources(),
                'folderDefaults' => $autoConfirm->folderDefaults(),
                'settingsSaveUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.settings_save'),
                'exportUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_label.export',
                    ['format' => 'csv', 'scope' => 'all'],
                ),
            ],
        ));

        return $view->renderResponse('AiLabel/Settings');
    }

    public function bulkAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $action = (string) ($body['action'] ?? '');
        $refs = $body['refs'] ?? [];
        if (!is_array($refs)) {
            $refs = [];
        }
        $payload = (string) ($body['payload'] ?? '');
        $returnTab = (string) ($body['returnTab'] ?? 'media');
        $backendUserId = (int) ($this->getBackendUser()?->user['uid'] ?? 0);

        $result = $this->bulkActionService->execute($action, array_values(array_map('strval', $refs)), $backendUserId, $payload);

        return $this->redirectToSubTab($request, $returnTab, $result['processed'] > 0 ? 'bulk-done' : 'bulk-none');
    }

    public function undoAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $returnTab = (string) ($body['returnTab'] ?? 'media');
        $backendUserId = (int) ($this->getBackendUser()?->user['uid'] ?? 0);
        $restored = $this->bulkActionService->undo($backendUserId);

        return $this->redirectToSubTab($request, $returnTab, $restored > 0 ? 'undo-done' : 'undo-none');
    }

    public function settingsSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $this->settingsService->save($body);
        $flash = $this->settingsService->getConfiguredApplicableTables() !== []
            ? 'settings-saved-schema'
            : 'settings-saved';

        return $this->redirectToSubTab($request, 'settings', $flash);
    }

    public function mediaDefaultFolderAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $folder = trim((string) ($body['folder'] ?? ''));
        $backendUser = $this->getBackendUser();
        if ($backendUser !== null && $folder !== '') {
            $this->mediaFolderPreference->set(
                $backendUser,
                $this->folderTreeService->resolveActiveFolder($folder),
            );
        }

        return $this->redirectToSubTab($request, 'media', 'media-folder-default');
    }

    public function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        $scope = (string) ($request->getQueryParams()['scope'] ?? 'all');
        if (!in_array($scope, ['all', 'media', 'texts'], true)) {
            $scope = 'all';
        }
        $csv = $this->evidenceExportService->toCsv($this->evidenceExportService->collectRows($scope));
        $response = new Response();
        $response->getBody()->write($csv);
        $filename = match ($scope) {
            'media' => 'ai-label-evidence-media.csv',
            'texts' => 'ai-label-evidence-texts.csv',
            default => 'ai-label-evidence.csv',
        };

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function recordEditAction(ServerRequestInterface $request): ResponseInterface
    {
        $ref = (string) ($request->getQueryParams()['ref'] ?? '');
        [$table, $uid] = $this->parseRef($ref);
        $record = $this->recordDrawerService->load($table, $uid);
        if ($record === null) {
            $response = new Response();
            $response->getBody()->write('<div class="callout callout-danger m-3">Record not found.</div>');

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $view = $this->createModuleView($request, 'aiLabel');
        $view->assignMultiple([
            'record' => $record,
            'errors' => [],
            'submitted' => [],
            'saveUri' => (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.ai_label.record_save',
                $this->routeParamsForPage($request),
            ),
            'returnTab' => (string) ($request->getQueryParams()['returnTab'] ?? 'media'),
        ]);

        return $view->renderResponse('AiLabel/RecordDrawer');
    }

    public function recordSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $ref = (string) ($body['ref'] ?? '');
        $returnTab = (string) ($body['returnTab'] ?? 'media');
        [$table, $uid] = $this->parseRef($ref);

        $result = $this->recordDrawerService->save($table, $uid, $body);
        if (!$result['ok'] && isset($result['errors']['schema'])) {
            return $this->redirectToSubTab($request, $returnTab, 'schema-missing');
        }
        if (!$result['ok']) {
            $record = $this->recordDrawerService->load($table, $uid);
            $view = $this->createModuleView($request, 'aiLabel');
            $view->assignMultiple([
                'record' => $record ?? ['ref' => $ref, 'title' => $ref],
                'errors' => $result['errors'],
                'submitted' => $body,
                'saveUri' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_label.record_save',
                    $this->routeParamsForPage($request),
                ),
                'returnTab' => $returnTab,
            ]);

            return $view->renderResponse('AiLabel/RecordDrawer');
        }

        return $this->redirectToSubTab($request, $returnTab, 'record-saved');
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int} $listResult
     * @return array{pagination: SimplePagination, paginator: FixedTotalPaginator}
     */
    private function buildListPagination(array $listResult, AiLabelFilters $filters): array
    {
        $paginator = new FixedTotalPaginator(
            (int) ($listResult['total'] ?? 0),
            $listResult['rows'] ?? [],
            $filters->page,
            $filters->max,
        );

        return [
            'pagination' => new SimplePagination($paginator),
            'paginator' => $paginator,
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseRef(string $ref): array
    {
        if (!str_contains($ref, ':')) {
            return ['', 0];
        }

        [$table, $uid] = explode(':', $ref, 2);

        return [trim($table), (int) $uid];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachRecordEditUris(ServerRequestInterface $request, array $rows, string $returnTab): array
    {
        foreach ($rows as &$row) {
            $table = (string) ($row['table'] ?? '');
            $uid = (int) ($row['uid'] ?? 0);
            if ($table === '' || $uid <= 0) {
                continue;
            }
            $row['editUri'] = (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.ai_label.record_edit',
                array_merge(
                    $this->routeParamsForPage($request),
                    ['ref' => $table . ':' . $uid, 'returnTab' => $returnTab],
                ),
            );
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function commonAssigns(ServerRequestInterface $request, string $activeSubTab): array
    {
        $statistics = $this->statisticsService->compute();
        $tabContent = $this->moduleTabUtility->buildTabContent(
            'aiLabel',
            fn(string $key): string => $this->translateModule($key),
        );

        return [
            'activeSubTab' => $activeSubTab,
            'tabHeading' => $tabContent['tabHeading'],
            'tabIntro' => $tabContent['tabIntro'],
            'statistics' => $statistics,
            'currentPageId' => $this->siteStorageContext->resolvePageIdFromRequest($request),
            'flash' => $this->flashFromQuery($request),
        ];
    }

    private function redirectToSubTab(ServerRequestInterface $request, string $subTab, string $flash): ResponseInterface
    {
        $route = match ($subTab) {
            'texts' => 't3af_dashboard.ai_label.texts',
            'settings' => 't3af_dashboard.ai_label.settings',
            'overview' => 't3af_dashboard.ai_label',
            default => 't3af_dashboard.ai_label.media',
        };

        $params = array_merge(
            $this->routeParamsForPage($request),
            $request->getQueryParams(),
            ['flash' => $flash],
        );
        unset($params['action'], $params['controller'], $params['refs']);

        $uri = (string) $this->uriBuilder->buildUriFromRoute($route, $params);

        return new RedirectResponse($uri);
    }
}
