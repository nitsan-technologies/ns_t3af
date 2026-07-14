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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Resolves be_groups page and language scope fields to human-readable labels for Access Roles UI.
 */
final class BeGroupScopeResolver
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @param array<string, mixed> $groupRow
     * @return array{
     *     pageScope: array{auto: bool, items: list<array{uid: int, label: string}>, display: string},
     *     languageScope: array{auto: bool, items: list<array{uid: int, label: string}>, display: string}
     * }
     */
    public function resolve(array $groupRow): array
    {
        return [
            'pageScope' => $this->resolvePageScope((string) ($groupRow['db_mountpoints'] ?? '')),
            'languageScope' => $this->resolveLanguageScope((string) ($groupRow['allowed_languages'] ?? '')),
        ];
    }

    /**
     * @return array{auto: bool, items: list<array{uid: int, label: string}>, display: string}
     */
    private function resolvePageScope(string $raw): array
    {
        $uids = $this->parseCsvIntegers($raw, requirePositive: true);
        if ($uids === []) {
            return [
                'auto' => true,
                'items' => [],
                'display' => '',
            ];
        }

        $items = [];
        foreach ($uids as $uid) {
            $items[] = [
                'uid' => $uid,
                'label' => $this->resolvePageLabel($uid),
            ];
        }

        return [
            'auto' => false,
            'items' => $items,
            'display' => implode(', ', array_column($items, 'label')),
        ];
    }

    /**
     * @return array{auto: bool, items: list<array{uid: int, label: string}>, display: string}
     */
    private function resolveLanguageScope(string $raw): array
    {
        if (trim($raw) === '') {
            return [
                'auto' => true,
                'items' => [],
                'display' => '',
            ];
        }

        $uids = $this->parseCsvIntegers($raw, requirePositive: false);
        $items = [];
        foreach ($uids as $uid) {
            $items[] = [
                'uid' => $uid,
                'label' => $this->resolveLanguageLabel($uid),
            ];
        }

        return [
            'auto' => false,
            'items' => $items,
            'display' => implode(', ', array_column($items, 'label')),
        ];
    }

    private function resolvePageLabel(int $uid): string
    {
        $page = BackendUtility::getRecord('pages', $uid, 'uid,title');
        if (is_array($page)) {
            $title = trim((string) ($page['title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($uid);
            $rootPageId = $site->getRootPageId();
            if ($rootPageId > 0) {
                $rootPage = BackendUtility::getRecord('pages', $rootPageId, 'uid,title');
                if (is_array($rootPage)) {
                    $rootTitle = trim((string) ($rootPage['title'] ?? ''));
                    if ($rootTitle !== '') {
                        return $rootTitle;
                    }
                }
            }
        } catch (\Throwable) {
            // Page may be outside a site configuration.
        }

        return 'Page #' . $uid;
    }

    private function resolveLanguageLabel(int $uid): string
    {
        if ($uid === 0) {
            return 'Default';
        }

        $language = BackendUtility::getRecord('sys_language', $uid, 'uid,title');
        if (is_array($language)) {
            $title = trim((string) ($language['title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
        }

        return 'Language #' . $uid;
    }

    /**
     * @return list<int>
     */
    private function parseCsvIntegers(string $raw, bool $requirePositive): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !preg_match('/^-?\d+$/', $part)) {
                continue;
            }
            $uid = (int) $part;
            if ($requirePositive && $uid <= 0) {
                continue;
            }
            if (!$requirePositive && $uid < 0) {
                continue;
            }
            $out[] = $uid;
        }

        return array_values(array_unique($out));
    }
}
