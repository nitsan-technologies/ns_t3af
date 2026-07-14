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

namespace NITSAN\NsT3AF\Contract;

use NITSAN\NsT3AF\Access\Enum\RecordAccess;

/**
 * Child hook: extend foundation role presets (consumer, editor, manager, admin).
 *
 * @api
 */
interface GroupPresetContributorInterface
{
    public function isAvailable(): bool;

    /**
     * @return array{
     *     modules?: list<string>,
     *     features?: array<string, string>,
     *     records?: array<string, RecordAccess>
     * }
     */
    public function contribute(string $presetId): array;
}
