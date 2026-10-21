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

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Filter\FileExtensionFilter;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Refuse MCP file attaches that TYPO3 would strip via TCA allowed/disallowed (e.g. PDF on og_image).
 */
readonly class FileFieldAttachmentValidator
{
    /** Document-like types that must not attach to common-image-types fields (even when GFX allows them). */
    private const BLOCKED_ON_COMMON_IMAGE_TYPES = ['pdf', 'ai', 'eps', 'ps'];

    public function __construct(
        private TcaSchemaService $tcaSchemaService,
    ) {}

    public function assertFileAllowedForField(string $table, string $fieldName, File $file): void
    {
        $config = $this->tcaSchemaService->getColumnFieldConfig($table, $fieldName);
        if ($config === null) {
            throw new \InvalidArgumentException(
                sprintf('Field "%s" is not configured on table "%s".', $fieldName, $table),
                1790500007,
            );
        }

        $allowed = (string) ($config['allowed'] ?? '');
        $disallowed = (string) ($config['disallowed'] ?? '');
        $extension = strtolower($file->getExtension());

        if ($this->usesInstanceImageExtensionList($allowed, $disallowed)
            && in_array($extension, self::BLOCKED_ON_COMMON_IMAGE_TYPES, true)
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File "%s" (.%s) is not allowed for field "%s" on %s.',
                    $file->getName(),
                    $extension,
                    $fieldName,
                    $table,
                ),
                1790500007,
            );
        }

        if ($allowed === '' && $disallowed === '') {
            return;
        }

        $filter = GeneralUtility::makeInstance(FileExtensionFilter::class);
        if ($allowed !== '') {
            $expandedAllowed = self::expandFileExtensionList($allowed);
            if ($this->usesInstanceImageExtensionList($allowed, $disallowed)) {
                $expandedAllowed = $this->withoutBlockedDocumentExtensions($expandedAllowed);
            }
            $filter->setAllowedFileExtensions($expandedAllowed);
        }
        if ($disallowed !== '') {
            $filter->setDisallowedFileExtensions(self::expandFileExtensionList($disallowed));
        }

        if (!$filter->isAllowed($extension)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File "%s" (.%s) is not allowed for field "%s" on %s.',
                    $file->getName(),
                    $extension,
                    $fieldName,
                    $table,
                ),
                1790500007,
            );
        }
    }

    public static function expandFileExtensionList(string $fileExtensions): string
    {
        if (preg_match_all('/common-(image|text|media)-types/', $fileExtensions, $matches)) {
            foreach ($matches[1] as $key => $type) {
                $fileExtensions = str_replace(
                    $matches[0][$key],
                    $GLOBALS['TYPO3_CONF_VARS'][$type === 'image' ? 'GFX' : 'SYS'][$type . 'file_ext'] ?? '',
                    $fileExtensions,
                );
            }
        }

        return StringUtility::uniqueList($fileExtensions);
    }

    private function usesInstanceImageExtensionList(string $allowed, string $disallowed): bool
    {
        if ($allowed === '' || $disallowed !== '') {
            return false;
        }

        if (str_contains($allowed, 'common-image-types')) {
            return true;
        }

        $normalizedAllowed = $this->normalizeExtensionList($allowed);
        $instanceImages = $this->normalizeExtensionList(self::expandFileExtensionList('common-image-types'));

        return $normalizedAllowed !== [] && $normalizedAllowed === $instanceImages;
    }

    private function withoutBlockedDocumentExtensions(string $allowedList): string
    {
        $extensions = GeneralUtility::trimExplode(',', strtolower($allowedList), true);
        $extensions = array_values(array_filter(
            $extensions,
            static fn(string $ext): bool => !in_array($ext, self::BLOCKED_ON_COMMON_IMAGE_TYPES, true),
        ));

        return implode(',', $extensions);
    }

    /**
     * @return list<string>
     */
    private function normalizeExtensionList(string $list): array
    {
        $extensions = GeneralUtility::trimExplode(',', strtolower(StringUtility::uniqueList($list)), true);
        sort($extensions);

        return $extensions;
    }
}
