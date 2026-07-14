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

use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Full read/write contract for the provider table.
 *
 * @internal Implemented by {@see ProviderRepository}.
 */
interface ProviderRepositoryInterface extends ProviderLookupInterface
{
    /**
     * Whether any row (including hidden or soft-deleted) occupies the
     * `(pid, identifier)` pair enforced by `provider_identifier_per_site`.
     */
    public function identifierExistsAtStoragePid(string $identifier, int $storagePid): bool;

    /**
     * Incomplete wizard draft: same storage pid and adapter, no API key stored yet.
     */
    public function findReusableWizardDraft(int $storagePid, string $adapterType): ?Provider;

    public function findByUid(int $uid): ?Provider;

    /**
     * @return list<Provider>
     */
    public function findAll(bool $includeHidden = false): array;

    /**
     * @return list<Provider>
     */
    public function findAllByStoragePid(int $storagePid, bool $includeHidden = false): array;

    /**
     * @param array<string, int|float|string|null> $values
     */
    public function save(int $uid, array $values): int;

    public function softDelete(int $uid): void;

    public function setDefault(int $uid, int $storagePid): void;

    /**
     * @param array<string, int|float|string|null> $values
     */
    public function updateStatus(int $uid, array $values): void;
}
