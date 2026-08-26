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

namespace NITSAN\NsT3AF\AiLabel\ViewHelpers;

use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * CSS classes for the media overlay wrapper (position from module settings).
 */
final class MediaWrapperClassViewHelper extends AbstractViewHelper
{
    public function render(): string
    {
        return GeneralUtility::makeInstance(AiLabelSettingsService::class)->mediaWrapperClass();
    }
}
