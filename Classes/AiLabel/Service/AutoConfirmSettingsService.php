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
 * Auto-confirmation from Settings plus EXTCONF allow-list and hold rules.
 */
final class AutoConfirmSettingsService
{
    /**
     * @var list<string>
     */
    private const OWN_SOURCES = ['ns_t3ai', 'ns_t3aa', 'ns_t3af'];

    public function __construct(
        private readonly ?AiLabelSettingsService $settingsService = null,
    ) {}

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

    public function isAutoConfirmAllowed(string $recordingSource, string $table, bool $publicInterest): bool
    {
        if ($this->isHeld($publicInterest, $recordingSource)) {
            return false;
        }

        if ($this->isDetectedSource($recordingSource) && $this->detectedEnabled()) {
            return true;
        }

        $ownMode = $this->ownMode();
        $extconfHit = in_array($recordingSource, $this->autoConfirmSources(), true);
        $builtinOwn = in_array($recordingSource, self::OWN_SOURCES, true);

        if (!$builtinOwn && !$extconfHit) {
            return false;
        }

        if ($ownMode === 'off') {
            return $extconfHit;
        }

        return $this->domainAllows($ownMode, $table);
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

    public function isDetectedSource(string $recordingSource): bool
    {
        return str_starts_with($recordingSource, 'detected');
    }

    private function isHeld(bool $publicInterest, string $recordingSource): bool
    {
        $hold = $this->holdSetting();
        $holdList = $this->holdList();

        if (in_array($recordingSource, $holdList, true)) {
            return true;
        }

        return $publicInterest
            && ($hold === 'public_interest_text' || in_array('public_interest_text', $holdList, true));
    }

    private function ownMode(): string
    {
        $mode = (string) ($this->settingsService?->all()['autoConfirmOwn'] ?? 'off');

        return in_array($mode, ['off', 'media', 'text', 'both'], true) ? $mode : 'off';
    }

    private function detectedEnabled(): bool
    {
        return (string) ($this->settingsService?->all()['autoConfirmDetected'] ?? 'off') === 'on';
    }

    private function holdSetting(): string
    {
        return (string) ($this->settingsService?->all()['autoConfirmHold'] ?? 'public_interest_text');
    }

    private function domainAllows(string $ownMode, string $table): bool
    {
        $isMedia = $table === 'sys_file_metadata';

        return match ($ownMode) {
            'media' => $isMedia,
            'text' => !$isMedia,
            'both' => true,
            default => false,
        };
    }
}
