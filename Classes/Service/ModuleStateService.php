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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Per-backend-user persistence of "where the user last was" inside the
 * AI Foundation module. One record under a single shared `uc.moduleData`
 * identifier so every sub-route sees the same state.
 *
 * Schema:
 *  - lastTab: one of the keys in {@see \NITSAN\NsT3AF\Utility\ModuleTabUtility::TABS}
 *  - period:  dashboard date-range preset (today|yesterday|7d|14d|30d|custom)
 *  - from:    YYYY-MM-DD lower bound when period === custom, '' otherwise
 *  - to:      YYYY-MM-DD upper bound when period === custom, '' otherwise
 *
 * @internal
 */
final class ModuleStateService
{
    public const STORAGE_KEY = 't3af';

    /**
     * @var array{lastTab: string, period: string, from: string, to: string}
     */
    public const DEFAULTS = [
        'lastTab' => 'dashboard',
        'period' => '7d',
        'from' => '',
        'to' => '',
    ];

    /**
     * @return array{lastTab: string, period: string, from: string, to: string}
     */
    public function read(BackendUserAuthentication $beUser): array
    {
        $raw = $beUser->getModuleData(self::STORAGE_KEY);
        if (!is_array($raw)) {
            return self::DEFAULTS;
        }

        return [
            'lastTab' => is_string($raw['lastTab'] ?? null) && $raw['lastTab'] !== ''
                ? $raw['lastTab']
                : self::DEFAULTS['lastTab'],
            'period' => is_string($raw['period'] ?? null) && $raw['period'] !== ''
                ? $raw['period']
                : self::DEFAULTS['period'],
            'from' => is_string($raw['from'] ?? null) ? $raw['from'] : self::DEFAULTS['from'],
            'to' => is_string($raw['to'] ?? null) ? $raw['to'] : self::DEFAULTS['to'],
        ];
    }

    public function setLastTab(BackendUserAuthentication $beUser, string $tabKey): void
    {
        if ($tabKey === '') {
            return;
        }
        $state = $this->read($beUser);
        if ($state['lastTab'] === $tabKey) {
            return;
        }
        $state['lastTab'] = $tabKey;
        $beUser->pushModuleData(self::STORAGE_KEY, $state);
    }

    public function setPeriod(
        BackendUserAuthentication $beUser,
        string $preset,
        string $from = '',
        string $to = '',
    ): void {
        if ($preset === '') {
            return;
        }
        $state = $this->read($beUser);
        if ($state['period'] === $preset && $state['from'] === $from && $state['to'] === $to) {
            return;
        }
        $state['period'] = $preset;
        $state['from'] = $from;
        $state['to'] = $to;
        $beUser->pushModuleData(self::STORAGE_KEY, $state);
    }
}
