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

namespace NITSAN\NsT3AF\Utility;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds backend page-tree breadcrumb data for module dashboards.
 */
final class PagePathUtility
{
    /**
     * @return array{
     *   pagePath: string,
     *   pageTitle: string,
     *   iconHtml: string,
     *   doktype: int,
     *   iconIdentifier: string,
     *   overlay: string
     * }
     */
    public static function getCurrentPagePathData(int $pageId): array
    {
        $pageRecord = BackendUtility::readPageAccess($pageId, '1=1');
        $dokType = 1;
        $overlay = $iconIdentifier = $pagePath = $iconHtml = '';
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        if (is_array($pageRecord) && isset($pageRecord['uid'])) {
            $pagePath = substr($pageRecord['_thePathFull'] ?? '', 0, -1);
            $pos = strrpos($pagePath, (string) ($pageRecord['title'] ?? ''));
            if ($pos !== false) {
                $pagePath = substr($pagePath, 0, $pos);
            }
            $pageIcon = enum_exists(IconSize::class)
                ? $iconFactory->getIconForRecord('pages', $pageRecord, IconSize::SMALL)
                : self::legacyRecordIcon($iconFactory, $pageRecord);
            $iconIdentifier = $pageIcon->getIdentifier();
            $dokType = (int) ($pageRecord['doktype'] ?? 1);
            $iconHtml = $pageIcon->render();
            if (isset($pageRecord['hidden']) && (int) $pageRecord['hidden'] === 1) {
                $overlay = 'overlay-hidden';
            }
        }

        if ($pageId === 0) {
            $pageIcon = enum_exists(IconSize::class)
                ? $iconFactory->getIcon('actions-brand-typo3', IconSize::SMALL)
                : self::legacyIcon($iconFactory, 'actions-brand-typo3');
            $iconHtml = $pageIcon->render();
            $iconIdentifier = 'actions-brand-typo3';
        }

        return [
            'pagePath' => htmlspecialchars($pagePath),
            'pageTitle' => (string) ($pageRecord['title'] ?? ''),
            'iconHtml' => $iconHtml,
            'doktype' => $dokType,
            'iconIdentifier' => $iconIdentifier,
            'overlay' => $overlay,
        ];
    }

    /**
     * TYPO3 12 accepts the legacy string size while TYPO3 13+ requires IconSize.
     *
     * @param array<string, mixed> $record
     */
    private static function legacyRecordIcon(IconFactory $iconFactory, array $record): \TYPO3\CMS\Core\Imaging\Icon
    {
        $icon = (new \ReflectionMethod($iconFactory, 'getIconForRecord'))
            ->invoke($iconFactory, 'pages', $record, 'small');
        if (!$icon instanceof \TYPO3\CMS\Core\Imaging\Icon) {
            throw new \UnexpectedValueException('IconFactory::getIconForRecord() returned an invalid icon.');
        }

        return $icon;
    }

    private static function legacyIcon(IconFactory $iconFactory, string $identifier): \TYPO3\CMS\Core\Imaging\Icon
    {
        $icon = (new \ReflectionMethod($iconFactory, 'getIcon'))
            ->invoke($iconFactory, $identifier, 'small');
        if (!$icon instanceof \TYPO3\CMS\Core\Imaging\Icon) {
            throw new \UnexpectedValueException('IconFactory::getIcon() returned an invalid icon.');
        }

        return $icon;
    }
}
