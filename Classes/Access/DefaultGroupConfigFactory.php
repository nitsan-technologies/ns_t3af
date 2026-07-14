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

namespace NITSAN\NsT3AF\Access;

use NITSAN\NsT3AF\Access\Dto\GroupConfig;

final class DefaultGroupConfigFactory
{
    public function __construct(
        private readonly WizardBootstrapFactory $wizardBootstrapFactory,
    ) {}

    public function create(): GroupConfig
    {
        return $this->wizardBootstrapFactory->createDefaultConfig();
    }

    /**
     * @return array<string, bool>
     */
    public function defaultModules(): array
    {
        return $this->wizardBootstrapFactory->defaultModules();
    }

    /**
     * @return array<string, string>
     */
    public function defaultFeatures(): array
    {
        return $this->wizardBootstrapFactory->defaultFeatures();
    }

    /**
     * @return array<string, string>
     */
    public function defaultRecords(): array
    {
        return $this->wizardBootstrapFactory->defaultRecords();
    }

    public static function createFromFactory(WizardBootstrapFactory $factory): GroupConfig
    {
        return $factory->createDefaultConfig();
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultModulesFromFactory(WizardBootstrapFactory $factory): array
    {
        return $factory->defaultModules();
    }

    /**
     * @return array<string, string>
     */
    public static function defaultFeaturesFromFactory(WizardBootstrapFactory $factory): array
    {
        return $factory->defaultFeatures();
    }

    /**
     * @return array<string, string>
     */
    public static function defaultRecordsFromFactory(WizardBootstrapFactory $factory): array
    {
        return $factory->defaultRecords();
    }
}
