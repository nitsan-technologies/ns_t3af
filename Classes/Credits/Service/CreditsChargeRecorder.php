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

/**
 * Persists a successful T3Planet charge into the local receipt mirror.
 *
 * @internal
 */
class CreditsChargeRecorder
{
    public function __construct(
        private readonly LocalReceiptCache $receiptCache,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function record(string $requestUuid, string $featureKey, array $payload): void
    {
        if (($payload['status'] ?? false) !== true) {
            return;
        }

        $this->receiptCache->storeFromCharge($requestUuid, $featureKey, $payload);
    }
}
