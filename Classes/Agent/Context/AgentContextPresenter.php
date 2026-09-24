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

namespace NITSAN\NsT3AF\Agent\Context;

use NITSAN\NsT3AF\Access\RecordAccessGate;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The editor's current backend context for the AI Agent: what the window shows (chips)
 * and what the model gets (details: readable module, page with slug and parent, language
 * with the site's languages, open record with its label, workspace, folder).
 *
 * Built from the untrusted client hints through {@see AgentContextResolver}, which keeps
 * the permission checks (unreadable pages and records are dropped).
 *
 * @internal
 */
final readonly class AgentContextPresenter
{
    public function __construct(
        private AgentContextResolver $contextResolver,
        private AgentTranslator $translator,
        private WorkspaceListService $workspaceListService,
        private SiteFinder $siteFinder,
        private ModuleProvider $moduleProvider,
        private RecordAccessGate $recordAccessGate,
    ) {}

    /**
     * @param array<string, mixed> $clientContext
     * @return array<string, mixed>
     */
    public function present(array $clientContext, ?BackendUserAuthentication $user): array
    {
        $resolved = $this->contextResolver->resolve($clientContext, $user);
        $storageUid = max(0, (int) ($clientContext['storageUid'] ?? 0));
        $folderIdentifier = trim((string) ($clientContext['folderIdentifier'] ?? ''));

        $details = [
            'module' => ['route' => $resolved->module, 'label' => $this->moduleLabel($resolved->module, $user)],
            'page' => $resolved->pageId > 0 ? $this->pageDetails($resolved->pageId) : null,
            'language' => null,
            'siteLanguages' => $this->siteLanguages($resolved->pageId, $user),
            'record' => $resolved->focusedRecord !== null ? $this->recordDetails($resolved->focusedRecord, $user) : null,
            'workspace' => [
                'id' => $resolved->workspaceId,
                'title' => $this->workspaceListService->resolveTitle($resolved->workspaceId),
                'live' => $resolved->workspaceId === 0,
                // The editor works in Live; the agent writes into this draft workspace (agentWorkspaceMode).
                'fromLive' => $resolved->workspaceId > 0 && $user !== null && (int) $user->workspace === 0,
            ],
            'folder' => $storageUid > 0 && $folderIdentifier !== '' ? ['storageUid' => $storageUid, 'identifier' => $folderIdentifier] : null,
            'brand' => $resolved->brandContextProfileUid !== null
                ? ($resolved->brandName !== '' ? $resolved->brandName : 'Profile #' . $resolved->brandContextProfileUid)
                : null,
        ];
        foreach ($details['siteLanguages'] as $language) {
            if ($language['id'] === $resolved->languageId) {
                $details['language'] = $language;
            }
        }
        if ($details['language'] === null && $resolved->languageId > 0) {
            $details['language'] = ['id' => $resolved->languageId, 'title' => 'Language #' . $resolved->languageId];
        }

        return [
            'pageId' => $resolved->pageId,
            'module' => $resolved->module,
            'record' => $resolved->focusedRecord,
            'languageId' => $resolved->languageId,
            'workspaceId' => $resolved->workspaceId,
            'storageUid' => $storageUid,
            'folderIdentifier' => $folderIdentifier,
            'chips' => $this->chips($details),
            'details' => $details,
            'contextAware' => $resolved->pageId > 0
                || $resolved->focusedRecord !== null
                || $resolved->module !== ''
                || $resolved->languageId > 0
                || $resolved->brandContextProfileUid !== null
                || $details['folder'] !== null,
        ];
    }

    /**
     * Compact "Current context" block for the system prompt.
     *
     * @param array<string, mixed> $context output of {@see self::present()}
     */
    public static function promptBlock(array $context): string
    {
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        if ($details === []) {
            return '';
        }

        $lines = ['Current context (use it when the editor says "this page", "here", "this element"):'];
        $module = is_array($details['module'] ?? null) ? $details['module'] : [];
        if (($module['route'] ?? '') !== '') {
            $lines[] = sprintf('- Module: %s (%s)', $module['label'] ?? $module['route'], $module['route']);
        }
        $page = is_array($details['page'] ?? null) ? $details['page'] : null;
        if ($page !== null) {
            $line = sprintf('- Page: "%s" [uid %d]', $page['title'] ?? '', (int) ($page['uid'] ?? 0));
            if (($page['slug'] ?? '') !== '') {
                $line .= ', slug ' . $page['slug'];
            }
            if (is_array($page['parent'] ?? null)) {
                $line .= sprintf(', parent "%s" [%d]', $page['parent']['title'] ?? '', (int) ($page['parent']['uid'] ?? 0));
            }
            $lines[] = $line;
        } else {
            $lines[] = '- Page: none selected';
        }
        $languages = is_array($details['siteLanguages'] ?? null) ? $details['siteLanguages'] : [];
        $language = is_array($details['language'] ?? null) ? $details['language'] : null;
        if ($language !== null || $languages !== []) {
            $line = '- Language: ' . ($language !== null ? sprintf('%s [%d]', $language['title'] ?? '', (int) ($language['id'] ?? 0)) : 'default');
            if ($languages !== []) {
                $line .= ' (site languages: ' . implode(', ', array_map(
                    static fn(array $l): string => sprintf('%s [%d]', $l['title'] ?? '', (int) ($l['id'] ?? 0)),
                    array_filter($languages, 'is_array'),
                )) . ')';
            }
            $lines[] = $line;
        }
        $record = is_array($details['record'] ?? null) ? $details['record'] : null;
        if ($record !== null) {
            $lines[] = sprintf('- Open record: %s [%d]%s', $record['table'] ?? '', (int) ($record['uid'] ?? 0), ($record['label'] ?? '') !== '' ? ' "' . $record['label'] . '"' : '');
        }
        $folder = is_array($details['folder'] ?? null) ? $details['folder'] : null;
        if ($folder !== null) {
            $lines[] = sprintf('- Folder: %d:%s', (int) ($folder['storageUid'] ?? 0), $folder['identifier'] ?? '');
        }
        $workspace = is_array($details['workspace'] ?? null) ? $details['workspace'] : null;
        if ($workspace !== null) {
            $lines[] = ($workspace['live'] ?? true) === true
                ? '- Workspace: Live — confirmed changes are visible on the website'
                : sprintf(
                    '- Workspace: "%s" [%d] — confirmed changes go to this draft workspace%s',
                    $workspace['title'] ?? '',
                    (int) ($workspace['id'] ?? 0),
                    ($workspace['fromLive'] ?? false) === true ? ' (the editor works in Live; nothing goes live until it is published)' : '',
                );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $details
     * @return list<array{key: string, label: string, value: string, hint: string}>
     */
    private function chips(array $details): array
    {
        $hint = $this->translator->translate('agent.context.chipHint');
        $chips = [];
        $add = function (string $key, string $value) use (&$chips, $hint): void {
            $chips[] = ['key' => $key, 'label' => $this->translator->translate('agent.context.' . $key), 'value' => $value, 'hint' => $hint];
        };

        if (is_array($details['page'] ?? null)) {
            $add('page', sprintf('%s [%d]', $details['page']['title'], $details['page']['uid']));
        }
        if (($details['module']['route'] ?? '') !== '') {
            $add('module', (string) $details['module']['label']);
        }
        if (is_array($details['language'] ?? null)) {
            $add('language', (string) $details['language']['title']);
        }
        if (is_array($details['record'] ?? null)) {
            $record = $details['record'];
            $add('record', trim(sprintf('%s %s #%d', $record['tableLabel'], $record['label'] !== '' ? '"' . $record['label'] . '"' : '', $record['uid'])));
        }
        if (is_array($details['folder'] ?? null)) {
            $add('folder', sprintf('%s [storage %d]', $details['folder']['identifier'], $details['folder']['storageUid']));
        }
        if (is_string($details['brand'] ?? null)) {
            $add('brand', $details['brand']);
        }
        $add('workspace', (string) ($details['workspace']['title'] ?? ''));

        return $chips;
    }

    /**
     * @return array{uid: int, title: string, slug: string, parent: array{uid: int, title: string}|null}
     */
    private function pageDetails(int $pageId): array
    {
        $row = BackendUtility::getRecord('pages', $pageId, 'uid,pid,title,slug') ?? [];
        $parentId = (int) ($row['pid'] ?? 0);
        $parent = $parentId > 0 ? BackendUtility::getRecord('pages', $parentId, 'uid,title') : null;

        return [
            'uid' => $pageId,
            'title' => trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : 'Page ' . $pageId,
            'slug' => (string) ($row['slug'] ?? ''),
            'parent' => is_array($parent) ? ['uid' => $parentId, 'title' => (string) ($parent['title'] ?? '')] : null,
        ];
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function siteLanguages(int $pageId, ?BackendUserAuthentication $user): array
    {
        if ($pageId <= 0 || $user === null) {
            return [];
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return [];
        }

        $languages = [];
        foreach ($site->getAvailableLanguages($user, false, $pageId) as $language) {
            $languages[] = ['id' => $language->getLanguageId(), 'title' => $language->getTitle()];
        }

        return $languages;
    }

    /**
     * @param array{table: string, uid: int} $record
     * @return array{table: string, uid: int, label: string, tableLabel: string}
     */
    private function recordDetails(array $record, ?BackendUserAuthentication $user): array
    {
        $table = $record['table'];
        $tableTitle = (string) ($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table);
        $label = '';
        if ($this->recordAccessGate->canSelectTable($user, $table) && isset($GLOBALS['TCA'][$table])) {
            $row = BackendUtility::getRecord($table, $record['uid']);
            $label = is_array($row) ? trim(BackendUtility::getRecordTitle($table, $row)) : '';
        }

        return [
            'table' => $table,
            'uid' => $record['uid'],
            'label' => mb_substr($label, 0, 80),
            'tableLabel' => $this->translateLabel($tableTitle) ?: $table,
        ];
    }

    public function moduleLabel(string $route, ?BackendUserAuthentication $user): string
    {
        if ($route === '') {
            return '';
        }
        try {
            $module = $this->moduleProvider->getModule($route, $user);
        } catch (\Throwable) {
            $module = null;
        }
        if ($module === null) {
            return $route;
        }

        return $this->translateLabel($module->getTitle()) ?: $route;
    }

    private function translateLabel(string $label): string
    {
        // "LLL:EXT:…" and TYPO3 v14 translation domains ("core.db.pages:title").
        if (!str_starts_with($label, 'LLL:') && preg_match('/^[A-Za-z0-9_.-]+\.[A-Za-z0-9_-]+:[A-Za-z0-9_.-]+$/', $label) !== 1) {
            return $label;
        }
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? trim($languageService->sL($label)) : '';
    }
}
