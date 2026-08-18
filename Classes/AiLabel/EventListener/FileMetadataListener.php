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

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Service\OriginRecorder;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataUpdatedEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * FAL path recorder (T3).
 */
final class FileMetadataListener
{
    public function __invoke(AfterFileMetaDataUpdatedEvent $event): void
    {
        $record = $event->getRecord();
        $uid = (int) ($record['uid'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $involvementValue = (string) ($record['tx_nst3af_ailabel_involvement'] ?? '');
        if ($involvementValue === '') {
            return;
        }

        $involvement = Involvement::tryFrom($involvementValue);
        if ($involvement === null) {
            return;
        }

        GeneralUtility::makeInstance(OriginRecorder::class)->recordOrigin(
            'sys_file_metadata',
            $uid,
            $involvement,
            'fal_metadata',
        );
    }
}
