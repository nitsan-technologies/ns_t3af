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

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * IPTC Digital Source Type (trained algorithmic media) via Imagick when available.
 */
class IptcDigitalSourceTypeService
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

        $lower = strtolower($value);

        return str_contains($lower, 'trainedalgorithmicmedia')
            || str_contains($lower, 'algorithmicmedia');
    }

    /**
     * Broad read for upload detection (DigitalSourceType, EXIF history, other IPTC).
     */
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

    /**
     * Read-only DigitalSourceType only (no writable FAL temp copy on local storage).
     */
    public function readDigitalSourceType(FileInterface $file): ?string
    {
        if (!$this->imagickAvailable() || !str_starts_with((string) $file->getMimeType(), 'image/')) {
            return null;
        }

        try {
            $path = $file->getForLocalProcessing(false);
            $image = new \Imagick($path);
            $value = trim((string) $image->getImageProperty('iptc:DigitalSourceType'));
            $image->clear();
        } catch (\Throwable) {
            return null;
        }

        return $value !== '' ? $value : null;
    }

    public function writeTrainedAlgorithmicMedia(File $file): void
    {
        if (!$this->imagickAvailable() || !str_starts_with((string) $file->getMimeType(), 'image/')) {
            return;
        }

        $path = null;
        $image = null;

        try {
            $path = $file->getForLocalProcessing(true);
            $image = new \Imagick($path);
            $image->setImageProperty('iptc:DigitalSourceType', self::TRAINED_ALGORITHMIC_MEDIA);
            $image->writeImage($path);
            $file->getStorage()->replaceFile($file, $path);
        } catch (\Throwable) {
            return;
        } finally {
            if ($image instanceof \Imagick) {
                $image->clear();
            }
            $this->unlinkFalTemporaryFile($path);
        }
    }

    public function copyDigitalSourceType(FileInterface $source, string $targetPath): bool
    {
        $value = $this->readDigitalSourceType($source);
        if ($value === null || !$this->isAiGeneratedSource($value) || !$this->imagickAvailable()) {
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

    /**
     * Defense-in-depth: remove leftover FAL local-processing temps under var/transient.
     */
    public function unlinkFalTemporaryFile(?string $path): void
    {
        if ($path === null || $path === '' || !is_file($path)) {
            return;
        }

        $normalizedPath = str_replace('\\', '/', $path);
        if (str_contains($normalizedPath, 'fal-tempfile-')) {
            @unlink($path);

            return;
        }

        try {
            $varPath = Environment::getVarPath();
        } catch (\Throwable) {
            return;
        }
        if ($varPath === '') {
            return;
        }

        $transientRoot = str_replace(
            '\\',
            '/',
            rtrim($varPath, DIRECTORY_SEPARATOR) . '/transient/',
        );
        if (!str_starts_with($normalizedPath, $transientRoot)) {
            return;
        }

        @unlink($path);
    }
}
