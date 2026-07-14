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
 * @internal
 */
final class CreditModeResolver
{
    public function __construct(
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly CreditsReleaseGateInterface $releaseGate = new CreditsReleaseGate(),
    ) {}

    public function isPubliclyAvailable(): bool
    {
        return $this->releaseGate->isPubliclyAvailable();
    }

    public function isEnabled(): bool
    {
        if (!$this->releaseGate->isPubliclyAvailable()) {
            return false;
        }

        return $this->runtimeSettings->isCreditModeEnabled();
    }

    public function isActive(): bool
    {
        if (!$this->releaseGate->isPubliclyAvailable()) {
            return false;
        }

        if (!$this->runtimeSettings->isCreditModeEnabled()) {
            return false;
        }

        if ($this->runtimeSettings->getLicenseKeys() === '') {
            return false;
        }

        $token = $this->runtimeSettings->getTokenPlain();

        return $token !== null && $token !== '';
    }
}
