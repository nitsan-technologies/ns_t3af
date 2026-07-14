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

namespace NITSAN\NsT3AF\Domain\Repository;

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;

/**
 * @internal
 */
interface BrandContextProfileRepositoryInterface
{
    /**
     * @return list<BrandContextProfile>
     */
    public function findAllByStoragePid(int $storagePid, bool $includeHidden = false): array;

    public function findByUid(int $uid): ?BrandContextProfile;

    public function findDefault(int $storagePid): ?BrandContextProfile;

    public function countByStoragePid(int $storagePid): int;

    public function setDefault(int $uid, int $storagePid): void;

    public function setEnabled(int $uid, bool $enabled): void;

    public function findByUidIncludingHidden(int $uid): ?BrandContextProfile;

    /**
     * @param array<string, int|float|string|null> $values
     */
    public function save(int $uid, array $values): int;

    public function delete(int $uid): void;

    public function belongsToStorage(int $uid, int $storagePid): bool;
}
