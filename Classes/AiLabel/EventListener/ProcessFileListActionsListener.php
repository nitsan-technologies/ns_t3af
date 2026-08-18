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

namespace NITSAN\NsT3AF\AiLabel\EventListener;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * R16.2 file module row action deep link into AI Label tab.
 */
final class ProcessFileListActionsListener
{
    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        if (!$event->isFile()) {
            return;
        }

        $file = $event->getResource();
        $metaData = $file->getMetaData();
        if ($metaData === null) {
            return;
        }

        $uid = (int) $metaData->getUid();
        if ($uid <= 0) {
            return;
        }

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $url = (string) $uriBuilder->buildUriFromRoute('t3af_dashboard.ai_label.media', [
            'fileMetadataUid' => $uid,
            'folder' => dirname($file->getIdentifier()) . '/',
        ]);

        $actions = $event->getActionItems();
        $actions[] = [
            'identifier' => 'nst3af-open-ai-label',
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:ailabel.file_module.open',
            'iconIdentifier' => 'actions-view',
            'href' => $url,
        ];
        $event->setActionItems($actions);
    }
}
