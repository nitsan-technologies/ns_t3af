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

use NITSAN\NsT3AF\Api\CreditsPricing;

/**
 * Resolves and caches the shared `pricing` object from Balance / Features API payloads.
 *
 * @internal
 */
final class CreditsPricingResolver
{
    private ?CreditsPricing $resolved = null;

    /**
     * @param array<string, mixed> $apiPayload Balance.php, Features.php, Products.php, etc.
     */
    public function rememberFromPayload(array $apiPayload): void
    {
        if (!isset($apiPayload['pricing']) || !is_array($apiPayload['pricing'])) {
            return;
        }

        $this->resolved = CreditsPricing::fromArray($apiPayload);
    }

    public function resolve(): CreditsPricing
    {
        return $this->resolved ?? CreditsPricing::default();
    }

    public function reset(): void
    {
        $this->resolved = null;
    }
}
