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

namespace NITSAN\NsT3AF\Agent\Service;

use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Editor-facing names for records and fields ("Content element „Welcome“ · Header"), and the
 * links shown after a change (open the page, edit the record, view it on the website).
 *
 * @internal
 */
readonly class AgentRecordLabeler
{
    public function __construct(
        private UriBuilder $uriBuilder,
        private AgentTranslator $translator,
    ) {}

    public function tableLabel(string $table): string
    {
        $label = (string) ($GLOBALS['TCA'][$table]['ctrl']['title'] ?? '');

        return $this->translateLabel($label) ?: $table;
    }

    public function fieldLabel(string $table, string $field): string
    {
        $label = (string) ($GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? '');

        return rtrim($this->translateLabel($label) ?: $field, ':');
    }

    /**
     * Record title ("Welcome"), empty for records that do not exist yet.
     */
    public function recordTitle(string $table, int $uid): string
    {
        if ($uid <= 0 || !isset($GLOBALS['TCA'][$table])) {
            return '';
        }
        try {
            $row = BackendUtility::getRecord($table, $uid);
        } catch (\Throwable) {
            return '';
        }

        return is_array($row) ? mb_substr(trim(BackendUtility::getRecordTitle($table, $row)), 0, 80) : '';
    }

    /**
     * "Content element „Welcome“" / "Page „About us“" / "New content element".
     */
    public function recordLabel(string $table, int $uid): string
    {
        $title = $this->recordTitle($table, $uid);
        if ($uid <= 0) {
            return $this->translator->translate('agent.record.new', [$this->tableLabel($table)]);
        }

        return $title !== '' ? sprintf('%s „%s“', $this->tableLabel($table), $title) : sprintf('%s #%d', $this->tableLabel($table), $uid);
    }

    /**
     * @return list<array{kind: string, label: string, href: string}>
     */
    public function links(string $table, int $uid): array
    {
        if ($uid <= 0 || !isset($GLOBALS['TCA'][$table])) {
            return [];
        }
        $row = BackendUtility::getRecord($table, $uid, 'uid,pid');
        if (!is_array($row)) {
            return [];
        }

        $links = [];
        $pageId = $table === 'pages' ? $uid : (int) ($row['pid'] ?? 0);
        if ($pageId > 0) {
            $links[] = [
                'kind' => 'module',
                'label' => $this->translator->translate('agent.link.openPage'),
                'href' => (string) $this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageId]),
            ];
        }
        $links[] = [
            'kind' => 'module',
            'label' => $this->translator->translate('agent.link.edit'),
            'href' => (string) $this->uriBuilder->buildUriFromRoute('record_edit', ['edit' => [$table => [$uid => 'edit']]]),
        ];
        if ($pageId > 0) {
            try {
                $preview = PreviewUriBuilder::create($pageId)->buildUri();
            } catch (\Throwable) {
                $preview = null;
            }
            if ($preview !== null) {
                $links[] = ['kind' => 'frontend', 'label' => $this->translator->translate('agent.link.view'), 'href' => (string) $preview];
            }
        }

        return $links;
    }

    private function translateLabel(string $label): string
    {
        // "LLL:EXT:…" and TYPO3 v14 translation domains ("core.db.pages:title").
        if (!str_starts_with($label, 'LLL:') && preg_match('/^[A-Za-z0-9_.-]+\.[A-Za-z0-9_-]+:[A-Za-z0-9_.-]+$/', $label) !== 1) {
            return trim($label);
        }
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? trim($languageService->sL($label)) : '';
    }
}
