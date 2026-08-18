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
 * System readiness checks for the Overview status card.
 */
final class AiLabelSystemStatusService
{
    public function __construct(
        private readonly EuIconManifestService $iconManifest,
    ) {}

    /**
     * @return list<array{label: string, status: string, variant: string}>
     */
    public function checks(): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'Official EU icons',
            'status' => $this->iconManifest->verify() ? 'present' : 'missing',
            'variant' => $this->iconManifest->verify() ? 'success' : 'danger',
        ];

        $imagick = extension_loaded('imagick') || class_exists(\Imagick::class, false);
        $checks[] = [
            'label' => 'ImageMagick (marking image files)',
            'status' => $imagick ? 'available' : 'not available',
            'variant' => $imagick ? 'success' : 'warning',
        ];

        $checks[] = [
            'label' => 'PHP exif extension',
            'status' => extension_loaded('exif') ? 'available' : 'not available',
            'variant' => extension_loaded('exif') ? 'success' : 'warning',
        ];

        $preserveMetadata = !(bool) ($GLOBALS['TYPO3_CONF_VARS']['GFX']['stripProfile'] ?? true);
        $checks[] = [
            'label' => 'Image processing keeps metadata',
            'status' => $preserveMetadata ? 'preserved' : 'strips it',
            'variant' => $preserveMetadata ? 'success' : 'warning',
        ];

        $checks[] = [
            'label' => 'Content Credentials tool',
            'status' => 'not installed',
            'variant' => 'secondary',
        ];

        $checks[] = [
            'label' => 'Scheduled AI Label audit',
            'status' => $this->schedulerRegistered() ? 'runs weekly' : 'not scheduled',
            'variant' => $this->schedulerRegistered() ? 'success' : 'warning',
        ];

        return $checks;
    }

    public function warningCount(): int
    {
        return count(array_filter(
            $this->checks(),
            static fn(array $check): bool => in_array($check['variant'], ['warning', 'danger'], true),
        ));
    }

    private function schedulerRegistered(): bool
    {
        if (!class_exists(\TYPO3\CMS\Scheduler\Scheduler::class)) {
            return false;
        }

        return true;
    }
}
