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

namespace NITSAN\NsT3AF\Credits\Exception;

/**
 * HTTP 402 from Charge/Embed — no fallback to BYO while credits mode is ON.
 *
 * @api
 */
final class InsufficientCreditsException extends CreditsApiException
{
    /**
     * @param array<string, mixed> $context Charge 402 body fields (cost_units, credits, pricing, …).
     */
    public function __construct(
        string $message = 'Insufficient credits',
        public readonly string $topupUrl = '',
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        if ($topupUrl !== '') {
            $context['topup_url'] = $topupUrl;
        }

        parent::__construct(
            'insufficient_credits',
            402,
            $message,
            $context,
            $previous,
        );
    }
}
