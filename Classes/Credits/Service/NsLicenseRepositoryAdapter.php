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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\Contract\LicenseDataRepositoryInterface;

/**
 * @internal
 */
final class NsLicenseRepositoryAdapter implements LicenseDataRepositoryInterface
{
    public function __construct(
        private readonly object $repository,
    ) {}

    public function fetchData(string $extensionKey): array
    {
        if (!method_exists($this->repository, 'fetchData')) {
            return [];
        }

        $rows = $this->repository->fetchData($extensionKey);

        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    public function fetchAllData(): array
    {
        if (!method_exists($this->repository, 'fetchData')) {
            return [];
        }

        $rows = $this->repository->fetchData('');

        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }
}
