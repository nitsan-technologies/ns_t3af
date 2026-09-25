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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Contract;

/**
 * Self-report hook for child extensions consumed by the AI Agent entitlement layer.
 *
 * The agent implements no licence check of its own (R16); it mirrors this answer.
 *
 * @api
 */
interface ExtensionOperationalStatusInterface
{
    public function extensionKey(): string;

    public function isOperational(): bool;

    public function toolCount(): int;
}
