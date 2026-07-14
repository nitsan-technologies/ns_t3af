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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;

/**
 * Instance-level Quick Setup wizard progress in tx_nst3af_runtime_setting.
 *
 * @internal
 */
final class WizardProgressService
{
    private const MIN_STEP = 1;

    private const MAX_STEP = 8;

    public function __construct(
        private readonly RuntimeSettingsService $runtimeSettings,
    ) {}

    public function isCompleted(): bool
    {
        return (int) ($this->runtimeSettings->findSingleton()['wizard_completed'] ?? 0) === 1;
    }

    public function getLastStep(): int
    {
        return $this->clampStep((int) ($this->runtimeSettings->findSingleton()['wizard_last_step'] ?? self::MIN_STEP));
    }

    public function getMaxStep(): int
    {
        $last = $this->getLastStep();
        $max = $this->clampStep((int) ($this->runtimeSettings->findSingleton()['wizard_max_step'] ?? self::MIN_STEP));

        return max($last, $max);
    }

    public function saveProgress(int $lastStep, int $maxStep): void
    {
        $last = $this->clampStep($lastStep);
        $max = max($last, $this->clampStep($maxStep));

        $this->runtimeSettings->save([
            'wizard_last_step' => $last,
            'wizard_max_step' => $max,
        ]);
    }

    public function markCompleted(): void
    {
        $this->runtimeSettings->save([
            'wizard_completed' => 1,
            'wizard_last_step' => self::MAX_STEP,
            'wizard_max_step' => self::MAX_STEP,
        ]);
    }

    private function clampStep(int $step): int
    {
        return min(self::MAX_STEP, max(self::MIN_STEP, $step));
    }
}
