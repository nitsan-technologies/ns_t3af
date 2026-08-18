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
 * Module settings persisted in EXTCONF (site-level via AdditionalConfiguration or Install Tool).
 */
final class AiLabelSettingsService
{
    private const EXTCONF_KEY = 'ns_t3af';

    public function all(): array
    {
        $extconf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXTCONF_KEY] ?? [];

        return [
            'labelPosition' => (string) ($extconf['ailabelLabelPosition'] ?? 'bottom_right'),
            'labelSize' => (string) ($extconf['ailabelLabelSize'] ?? 'medium'),
            'labelWording' => (string) ($extconf['ailabelLabelWording'] ?? 'show_site_language'),
            'markImageFile' => (string) ($extconf['ailabelMarkImageFile'] ?? 'content_element_only'),
            'machineReadable' => (string) ($extconf['ailabelMachineReadable'] ?? 'iptc'),
            'autoConfirmOwn' => (string) ($extconf['ailabelAutoConfirmOwn'] ?? 'off'),
            'autoConfirmDetected' => (string) ($extconf['ailabelAutoConfirmDetected'] ?? 'off'),
            'autoConfirmHold' => (string) ($extconf['ailabelAutoConfirmHold'] ?? 'public_interest_text'),
            'labelUnknownOrigin' => (string) ($extconf['ailabelLabelUnknownOrigin'] ?? 'no'),
            'secondInfoLayer' => (string) ($extconf['ailabelSecondInfoLayer'] ?? 'off'),
            'applicableTables' => implode(', ', (array) ($extconf['ailabelApplicableTables'] ?? [])),
            'holdList' => (array) ($extconf['ailabelHoldList'] ?? ['public_interest_text']),
            'autoConfirmSources' => (array) ($extconf['ailabelAutoConfirmSources'] ?? []),
            'folderDefaults' => (array) ($extconf['ailabelFolderDefaults'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        $extconf = &$GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXTCONF_KEY];
        if (!is_array($extconf)) {
            $extconf = [];
        }

        foreach ([
            'ailabelLabelPosition' => 'labelPosition',
            'ailabelLabelSize' => 'labelSize',
            'ailabelLabelWording' => 'labelWording',
            'ailabelMarkImageFile' => 'markImageFile',
            'ailabelMachineReadable' => 'machineReadable',
            'ailabelAutoConfirmOwn' => 'autoConfirmOwn',
            'ailabelAutoConfirmDetected' => 'autoConfirmDetected',
            'ailabelAutoConfirmHold' => 'autoConfirmHold',
            'ailabelLabelUnknownOrigin' => 'labelUnknownOrigin',
            'ailabelSecondInfoLayer' => 'secondInfoLayer',
        ] as $key => $inputKey) {
            if (isset($input[$inputKey])) {
                $extconf[$key] = (string) $input[$inputKey];
            }
        }

        if (isset($input['applicableTables'])) {
            $parts = array_map('trim', explode(',', (string) $input['applicableTables']));
            $extconf['ailabelApplicableTables'] = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        }
    }
}
