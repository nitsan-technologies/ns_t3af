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

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Editor-facing labels for MCP tools (human titles, not snake_case ids).
 *
 * @internal
 */
final readonly class AgentToolEditorLabelService
{
    /** @var array<string, string> */
    private const LABEL_KEYS = [
        'pages_get' => 'agent.starter.inspectPage',
        'pages_list' => 'agent.starter.listChildPages',
        'pages_search' => 'agent.starter.searchPages',
        'pages_tree' => 'agent.starter.pageTree',
        'content_list' => 'agent.starter.listContent',
        'record_search' => 'agent.starter.recordSearch',
        'file_list' => 'agent.starter.fileList',
        'file_get_info' => 'agent.starter.fileInfo',
        'file_search' => 'agent.starter.fileSearch',
        'file_upload' => 'agent.starter.fileUpload',
        'redirect_list' => 'agent.starter.redirectList',
        'redirect_get' => 'agent.starter.redirectGet',
        'scheduler_list' => 'agent.starter.schedulerList',
        'scheduler_get' => 'agent.starter.schedulerGet',
        't3aa_list_files_missing_alt_text' => 'agent.tool.listFilesMissingAltText',
        't3aa_update_file_metadata' => 'agent.tool.updateFileMetadata',
        't3aa_get_file_metadata' => 'agent.tool.getFileMetadata',
    ];

    /** @var list<string> */
    private const DESCRIPTION_PREFIX_STRIPS = [
        't3aa_',
        't3ai_',
        't3cs_',
        't3as_',
    ];

    /**
     * @param array<string, mixed> $tool
     */
    public function resolve(array $tool): string
    {
        return $this->resolveByName(
            (string) ($tool['name'] ?? ''),
            (string) ($tool['description'] ?? ''),
        );
    }

    public function resolveByName(string $toolName, string $description = ''): string
    {
        $toolName = trim($toolName);
        if ($toolName === '') {
            return '';
        }

        $labelKey = self::LABEL_KEYS[$toolName] ?? '';
        if ($labelKey !== '') {
            $translated = $this->translate($labelKey);
            if ($translated !== '' && $translated !== $labelKey) {
                return $translated;
            }
        }

        $fromDescription = $this->labelFromDescription($description);
        if ($fromDescription !== null) {
            return $fromDescription;
        }

        return $this->humanizeToolName($toolName);
    }

    private function labelFromDescription(string $description): ?string
    {
        $description = trim($description);
        if ($description === '') {
            return null;
        }

        $sentence = preg_split('/[.!?]\s+/u', $description, 2)[0] ?? $description;
        $sentence = trim((string) $sentence);
        if ($sentence === '' || !$this->isEditorFriendlyDescription($sentence)) {
            return null;
        }

        return rtrim($sentence, '.');
    }

    private function isEditorFriendlyDescription(string $text): bool
    {
        if (strlen($text) > 140) {
            return false;
        }

        $technicalNeedles = [
            'sys_',
            'tt_content',
            'sys_file',
            'JSON object',
            'DataHandler',
            'sysLanguageUid',
            'Returns file uid',
            'Returns nested',
            'metadata_uid',
        ];

        foreach ($technicalNeedles as $needle) {
            if (stripos($text, $needle) !== false) {
                return false;
            }
        }

        return true;
    }

    private function humanizeToolName(string $name): string
    {
        $normalized = strtolower(trim($name));
        foreach (self::DESCRIPTION_PREFIX_STRIPS as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
                break;
            }
        }

        $normalized = str_replace('_', ' ', $normalized);

        return $normalized !== '' ? ucfirst($normalized) : $name;
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        $value = $languageService->sL('LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key);

        return $value !== '' ? $value : $key;
    }
}
