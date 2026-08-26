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

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * IPTC Digital Source Type (trained algorithmic media) via Imagick when available.
 */
final class IptcDigitalSourceTypeService
{
    public const TRAINED_ALGORITHMIC_MEDIA = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    public function imagickAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class, false);
    }

    public function isAiGeneratedSource(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return str_contains($value, 'trainedAlgorithmicMedia')
            || str_contains($value, 'algorithmicMedia');
    }

    public function read(FileInterface $file): ?string
    {
        if (!$this->imagickAvailable() || !str_starts_with((string) $file->getMimeType(), 'image/')) {
            return null;
        }

        try {
            $path = $file->getForLocalProcessing(false);
            $image = new \Imagick($path);
            $parts = [
                (string) $image->getImageProperty('iptc:DigitalSourceType'),
                (string) $image->getImageProperty('exif:ImageHistory'),
            ];
            foreach ($image->getImageProperties('iptc:*') as $property) {
                $parts[] = (string) $property;
            }
            $image->clear();
        } catch (\Throwable) {
            return null;
        }

        $joined = trim(implode(' ', array_filter($parts)));

        return $joined !== '' ? $joined : null;
    }

    public function writeTrainedAlgorithmicMedia(File $file): void
    {
        if (!$this->imagickAvailable() || !str_starts_with((string) $file->getMimeType(), 'image/')) {
            return;
        }

        try {
            $path = $file->getForLocalProcessing(true);
            $image = new \Imagick($path);
            $image->setImageProperty('iptc:DigitalSourceType', self::TRAINED_ALGORITHMIC_MEDIA);
            $image->writeImage($path);
            $image->clear();
        } catch (\Throwable) {
            return;
        }
    }

    public function copyDigitalSourceType(FileInterface $source, string $targetPath): bool
    {
        $value = $this->read($source);
        if ($value === null || !$this->imagickAvailable()) {
            return false;
        }

        try {
            $image = new \Imagick($targetPath);
            $image->setImageProperty('iptc:DigitalSourceType', $value);
            $image->writeImage($targetPath);
            $image->clear();
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
