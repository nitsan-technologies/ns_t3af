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

use NITSAN\NsT3AF\Bootstrap\T3afPharBootstrap;
use NITSAN\NsT3AF\DependencyInjection\AdapterCompilerPass;
use NITSAN\NsT3AF\DependencyInjection\McpCapabilityCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (
    ContainerConfigurator $configurator,
    ContainerBuilder $containerBuilder,
): void {
    // Load bundled t3af.phar BEFORE the compiler pass runs so
    // SymfonyAiPlatformDiscovery can see NITSAN\T3af\Runtime\PlatformRegistry
    // during container compile. ext_localconf.php is too late — the container
    // is already cached by then. No-op in Composer mode.
    T3afPharBootstrap::register();

    $containerBuilder->addCompilerPass(new AdapterCompilerPass());
    $containerBuilder->addCompilerPass(new McpCapabilityCompilerPass());
};
