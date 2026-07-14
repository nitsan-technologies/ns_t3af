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

namespace NITSAN\NsT3AF\Credits\Contract;

/**
 * Duck-type for {@see \NITSAN\NsLicense\Domain\Repository\NsLicenseRepository} when nitsan/ns-license is installed.
 *
 * @internal
 */
interface LicenseDataRepositoryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function fetchData(string $extensionKey): array;

    /**
     * All rows from ns_product_license (any activated T3Planet extension).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllData(): array;
}
