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

namespace NITSAN\NsT3AF\Bootstrap;

use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;

/**
 * Registers FluidEmail template/layout root paths only when API quota email
 * notifications are enabled in ns_t3af extension settings.
 */
final class MailTemplatePathsBootstrapListener
{
    private const MAIL_PATH_PRIORITY = 1779278704;

    public function __invoke(BootCompletedEvent $event): void
    {
        $extConf = AiUniverseUtilityHelper::getExtensionConfIgnorePid('ns_t3af');
        if (empty($extConf['enableApiQuotaEmailNotification'])) {
            return;
        }

        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][self::MAIL_PATH_PRIORITY]
            = 'EXT:ns_t3af/Resources/Private/Templates/Email/';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['layoutRootPaths'][self::MAIL_PATH_PRIORITY]
            = 'EXT:ns_t3af/Resources/Private/Layouts/Email/';
    }
}
