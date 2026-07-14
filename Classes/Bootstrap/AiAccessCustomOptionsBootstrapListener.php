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

namespace NITSAN\NsT3AF\Bootstrap;

use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;

final class AiAccessCustomOptionsBootstrapListener
{
    public function __construct(
        private readonly AiAccessCustomOptionsBootstrap $bootstrap,
    ) {}

    public function __invoke(BootCompletedEvent $event): void
    {
        $this->bootstrap->register();
    }
}
