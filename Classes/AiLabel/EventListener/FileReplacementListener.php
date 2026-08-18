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

use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * T7: file replacement clears confirmation, retains origin.
 */
final class FileReplacementListener
{
    public function __invoke(AfterFileReplacedEvent $event): void
    {
        $file = $event->getFile();
        if (!$file instanceof File) {
            return;
        }

        $uid = (int) ($file->getMetaData()->get()['uid'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        GeneralUtility::makeInstance(ConfirmationService::class)
            ->clearConfirmation('sys_file_metadata', $uid);
    }
}
