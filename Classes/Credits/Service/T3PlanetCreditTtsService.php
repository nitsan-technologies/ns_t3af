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

use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Api\TtsResponse;
use NITSAN\NsT3AF\Api\TtsServiceInterface;

/**
 * Routes TTS through T3Planet Credits when active; otherwise forwards to the inner service.
 *
 * Decoration is always wired; {@see CreditModeResolver::isActive()} short-circuits to
 * the inner {@see \NITSAN\NsT3AF\Service\TtsService} (local provider adapters)
 * when credits mode is off, so there is zero credits HTTP traffic in own-keys mode.
 *
 * @internal
 */
final class T3PlanetCreditTtsService implements TtsServiceInterface
{
    public function __construct(
        private readonly TtsServiceInterface $inner,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly ProxyTtsExecutor $proxyTtsExecutor,
    ) {}

    public function speak(string $text, TtsOptions $options = new TtsOptions()): TtsResponse
    {
        if ($this->creditModeResolver->isActive()) {
            return $this->proxyTtsExecutor->speak($text, $options);
        }

        return $this->inner->speak($text, $options);
    }
}
