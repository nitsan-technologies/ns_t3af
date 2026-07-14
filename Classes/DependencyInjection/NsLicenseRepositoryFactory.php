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

namespace NITSAN\NsT3AF\DependencyInjection;

use NITSAN\NsT3AF\Credits\Contract\LicenseDataRepositoryInterface;
use NITSAN\NsT3AF\Credits\Service\NsLicenseRepositoryAdapter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @internal
 */
final class NsLicenseRepositoryFactory
{
    private const NS_LICENSE_REPOSITORY_CLASS = 'NITSAN\\NsLicense\\Domain\\Repository\\NsLicenseRepository';

    public function __invoke(): ?LicenseDataRepositoryInterface
    {
        if (!class_exists(self::NS_LICENSE_REPOSITORY_CLASS)) {
            return null;
        }

        $repository = GeneralUtility::makeInstance(self::NS_LICENSE_REPOSITORY_CLASS);

        return new NsLicenseRepositoryAdapter($repository);
    }
}
