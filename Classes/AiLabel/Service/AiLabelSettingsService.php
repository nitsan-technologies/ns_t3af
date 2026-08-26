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

use NITSAN\NsT3AF\Settings\ExtensionSettingsBootstrapReader;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;

/**
 * AI Label module settings persisted in tx_nst3af_extension_setting (global).
 */
final class AiLabelSettingsService
{
    private const EXTENSION_KEY = 'ns_t3af';

    /**
     * @var array<string, string>
     */
    private const FORM_TO_STORAGE = [
        'labelPosition' => 'ailabelLabelPosition',
        'labelSize' => 'ailabelLabelSize',
        'labelWording' => 'ailabelLabelWording',
        'markImageFile' => 'ailabelMarkImageFile',
        'machineReadable' => 'ailabelMachineReadable',
        'autoConfirmOwn' => 'ailabelAutoConfirmOwn',
        'autoConfirmDetected' => 'ailabelAutoConfirmDetected',
        'autoConfirmHold' => 'ailabelAutoConfirmHold',
        'labelUnknownOrigin' => 'ailabelLabelUnknownOrigin',
        'secondInfoLayer' => 'ailabelSecondInfoLayer',
    ];

    public function __construct(
        private readonly ExtensionSettingsService $extensionSettingsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = $this->storedValues();
        $extconf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXTENSION_KEY] ?? [];

        return [
            'labelPosition' => $stored['ailabelLabelPosition'],
            'labelSize' => $stored['ailabelLabelSize'],
            'labelWording' => $stored['ailabelLabelWording'],
            'markImageFile' => $stored['ailabelMarkImageFile'],
            'machineReadable' => $stored['ailabelMachineReadable'],
            'autoConfirmOwn' => $stored['ailabelAutoConfirmOwn'],
            'autoConfirmDetected' => $stored['ailabelAutoConfirmDetected'],
            'autoConfirmHold' => $stored['ailabelAutoConfirmHold'],
            'labelUnknownOrigin' => $stored['ailabelLabelUnknownOrigin'],
            'secondInfoLayer' => $stored['ailabelSecondInfoLayer'],
            'applicableTables' => $stored['ailabelApplicableTables'],
            'holdList' => (array) ($extconf['ailabelHoldList'] ?? ['public_interest_text']),
            'autoConfirmSources' => (array) ($extconf['ailabelAutoConfirmSources'] ?? []),
            'folderDefaults' => (array) ($extconf['ailabelFolderDefaults'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    public function getConfiguredApplicableTables(): array
    {
        $raw = trim($this->storedValues()['ailabelApplicableTables']);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $table): bool => $table !== '',
        ));
    }

    public function isMediaOverlayEnabled(): bool
    {
        return ($this->all()['markImageFile'] ?? 'content_element_only') === 'overlay';
    }

    public function mediaPositionClass(): string
    {
        $position = (string) ($this->all()['labelPosition'] ?? 'bottom_right');

        return match ($position) {
            'bottom_left' => 'nst3af-ailabel-media--pos-bottom-left',
            'top_right' => 'nst3af-ailabel-media--pos-top-right',
            'top_left' => 'nst3af-ailabel-media--pos-top-left',
            default => 'nst3af-ailabel-media--pos-bottom-right',
        };
    }

    public function mediaWrapperClass(): string
    {
        return 'nst3af-ailabel-media ' . $this->mediaPositionClass();
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        $values = [];
        foreach (self::FORM_TO_STORAGE as $inputKey => $storageKey) {
            if (isset($input[$inputKey])) {
                $values[$storageKey] = (string) $input[$inputKey];
            }
        }

        if (isset($input['applicableTables'])) {
            $parts = array_map('trim', explode(',', (string) $input['applicableTables']));
            $values['ailabelApplicableTables'] = implode(', ', array_values(array_filter(
                $parts,
                static fn(string $part): bool => $part !== '',
            )));
        }

        if ($values === []) {
            return;
        }

        $this->extensionSettingsService->mergeGlobal(self::EXTENSION_KEY, $values);
    }

    /**
     * @return array<string, string>
     */
    private function storedValues(): array
    {
        $defaults = ExtensionSettingsBootstrapReader::getDefaults(self::EXTENSION_KEY);
        $stored = $this->extensionSettingsService->getAllIgnorePid(self::EXTENSION_KEY);

        $values = [];
        foreach (self::FORM_TO_STORAGE as $storageKey) {
            $values[$storageKey] = (string) ($stored[$storageKey] ?? $defaults[$storageKey] ?? '');
        }
        $values['ailabelApplicableTables'] = (string) ($stored['ailabelApplicableTables'] ?? $defaults['ailabelApplicableTables'] ?? '');

        return $values;
    }
}
