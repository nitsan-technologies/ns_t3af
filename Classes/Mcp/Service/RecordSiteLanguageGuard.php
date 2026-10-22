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

namespace NITSAN\NsT3AF\Mcp\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Validates sys_language_uid (and related TCA language fields) against the target page's site.
 */
readonly class RecordSiteLanguageGuard
{
    public function __construct(
        private TcaSchemaService $tcaSchemaService,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @param array<string, mixed> $fields
     */
    public function assertCreateLanguageAllowed(string $table, array $fields, int $pageId): void
    {
        $config = $this->tcaSchemaService->getTranslationConfig($table);
        $languageField = $config['languageField'];
        if ($languageField === null || !array_key_exists($languageField, $fields)) {
            return;
        }

        $languageUid = (int) $fields[$languageField];
        if ($languageUid === 0) {
            return;
        }

        if ($languageUid === -1) {
            return;
        }

        if ($pageId <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Cannot validate language for %s: target page id must be greater than zero.', $table),
            );
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            throw new \InvalidArgumentException(
                sprintf('Page %d has no site configuration; language %d is not allowed.', $pageId, $languageUid),
            );
        }

        $allowed = [];
        foreach ($site->getAvailableLanguages($GLOBALS['BE_USER'] ?? null, false, $pageId) as $siteLanguage) {
            $allowed[$siteLanguage->getLanguageId()] = true;
        }

        if (!isset($allowed[$languageUid])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Language %d is not configured for the site of page %d. Use site_languages_list for allowed language ids.',
                    $languageUid,
                    $pageId,
                ),
            );
        }
    }

    /**
     * Connected translations must be created with records_apply localize, not write_table create.
     *
     * @param array<string, mixed> $fields
     */
    public function assertCreateNotLocalizedRecord(string $table, array $fields): void
    {
        $config = $this->tcaSchemaService->getTranslationConfig($table);
        $languageField = $config['languageField'];
        $pointerField = $config['transOrigPointerField'];
        if ($languageField === null && $pointerField === null) {
            return;
        }

        $languageUid = $languageField !== null && array_key_exists($languageField, $fields)
            ? (int) $fields[$languageField]
            : 0;
        $parentUid = $pointerField !== null && array_key_exists($pointerField, $fields)
            ? (int) $fields[$pointerField]
            : 0;

        if ($languageUid <= 0 && $parentUid <= 0) {
            return;
        }

        throw new \InvalidArgumentException(
            sprintf(
                'Creating a localized %s record via write_table is not supported (sys_language_uid=%d, translation parent=%d). Use records_apply with cmd "localize" instead.',
                $table,
                $languageUid,
                $parentUid,
            ),
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    public function findTranslationFieldsPresentOnUpdate(string $table, array $fields): array
    {
        $present = [];
        foreach ($this->tcaSchemaService->getTranslationFieldsBlockedOnUpdate($table) as $fieldName) {
            if (array_key_exists($fieldName, $fields)) {
                $present[] = $fieldName;
            }
        }

        return $present;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function stripTranslationFieldsForUpdate(string $table, array $fields): array
    {
        foreach ($this->tcaSchemaService->getTranslationFieldsBlockedOnUpdate($table) as $fieldName) {
            unset($fields[$fieldName]);
        }

        return $fields;
    }
}
