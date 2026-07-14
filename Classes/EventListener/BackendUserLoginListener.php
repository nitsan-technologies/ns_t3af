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

namespace NITSAN\NsT3AF\EventListener;

use NITSAN\NsLicense\Service\LicenseService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Event\AfterUserLoggedInEvent;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// @extensionScannerIgnoreLine
final class BackendUserLoginListener
{
    public function __invoke(AfterUserLoggedInEvent $event): void
    {
        if (!$event->getUser() instanceof BackendUserAuthentication) {
            return;
        }

        if (!GeneralUtility::makeInstance(PackageManager::class)->isPackageActive('ns_license')) {
            return;
        }

        GeneralUtility::makeInstance(LicenseService::class)
            ->connectToServer('ns_t3af', 0);
    }
}
