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

namespace NITSAN\NsT3AF\Event;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Dispatched when an adapter call throws.
 *
 * Listeners may invoke a fallback chain, raise alerts, or downgrade the
 * provider's `last_status`. Implementations are expected to be cheap — the
 * underlying request has already failed.
 *
 * @api
 */
final class ProviderRequestFailedEvent
{
    public function __construct(
        public readonly Provider $provider,
        public readonly \Throwable $error,
        public readonly string $callKind,
        public readonly ?string $creditsReason = null,
        public readonly AiOptions $options = new AiOptions(),
        public readonly string $prompt = '',
    ) {}
}
