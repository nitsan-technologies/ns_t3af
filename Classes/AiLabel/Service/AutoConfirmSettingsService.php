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

namespace NITSAN\NsT3AF\AiLabel\Service;

/**
 * T22/T23 auto-confirmation settings (source-scoped hold list and folder defaults).
 */
final class AutoConfirmSettingsService
{
    /**
     * @return list<string>
     */
    public function holdList(): array
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelHoldList'] ?? [];
        if (!is_array($configured)) {
            return ['public_interest_text'];
        }

        return array_values(array_filter(array_map('strval', $configured)));
    }

    /**
     * @return list<string>
     */
    public function autoConfirmSources(): array
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelAutoConfirmSources'] ?? [];
        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $configured)));
    }

    public function isAutoConfirmAllowed(string $recordingSource, bool $publicInterest): bool
    {
        if (in_array($recordingSource, $this->holdList(), true)) {
            return false;
        }

        if ($publicInterest && in_array('public_interest_text', $this->holdList(), true)) {
            return false;
        }

        $sources = $this->autoConfirmSources();

        return $sources !== [] && in_array($recordingSource, $sources, true);
    }

    /**
     * @return list<string>
     */
    public function folderDefaults(): array
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelFolderDefaults'] ?? [];
        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $configured)));
    }
}
