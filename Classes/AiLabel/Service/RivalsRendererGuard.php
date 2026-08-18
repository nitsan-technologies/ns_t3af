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

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * R18.3 duplicate suppression when a rival extension already renders the subject.
 */
final class RivalsRendererGuard
{
    /**
     * @param array<string, mixed> $record
     */
    public function shouldStandDown(string $table, array $record): bool
    {
        if ($this->b13AiLabelActive($table, (int) ($record['uid'] ?? 0))) {
            return true;
        }

        if ($table === 'sys_file_metadata' && $this->ntAimarkFileRendererActive()) {
            return true;
        }

        return false;
    }

    private function b13AiLabelActive(string $table, int $uid): bool
    {
        if (!ExtensionManagementUtility::isLoaded('ai_label') || $uid <= 0) {
            return false;
        }

        if (!class_exists(\B13\AiLabel\Service\AiLabelApi::class)) {
            return false;
        }

        try {
            return \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\B13\AiLabel\Service\AiLabelApi::class)
                ->isFlagged($table, $uid);
        } catch (\Throwable) {
            return false;
        }
    }

    private function ntAimarkFileRendererActive(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('nt_aimark')) {
            return false;
        }

        $settings = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelNtAimarkUseFileRenderer'] ?? null;
        if ($settings !== null) {
            return (bool) $settings;
        }

        return true;
    }
}
