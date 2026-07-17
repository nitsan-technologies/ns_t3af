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
 * Short-lived cache for T3Planet Credits dashboard HTTP payloads.
 *
 * @internal
 */
interface CreditsApiResponseCacheInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $scope, string $domain, #[\SensitiveParameter] string $bearerToken): ?array;

    /**
     * @param array<string, mixed> $payload
     */
    public function set(
        string $scope,
        string $domain,
        #[\SensitiveParameter]
        string $bearerToken,
        array $payload,
        int $lifetimeSeconds,
    ): void;

    public function flush(): void;
}
