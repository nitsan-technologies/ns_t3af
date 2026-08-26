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

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Asserts shipped EU icons match the source manifest hash.
 */
final class EuIconManifestService
{
    private const ICON_DIR = 'EXT:ns_t3af/Resources/Public/Icons/EuAiLabel/';
    private const SOURCE_MANIFEST = 'EXT:ns_t3af/Configuration/BuildInputs/eu-icons-manifest.sha256';

    /**
     * @return list<string>
     */
    public function expectedHashes(): array
    {
        $manifestPath = $this->resolveManifestPath();
        if ($manifestPath === '') {
            throw new \RuntimeException('EU icon manifest missing');
        }

        $hashes = [];
        foreach (file($manifestPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (isset($parts[0]) && preg_match('/^[a-f0-9]{64}$/', $parts[0])) {
                $hashes[] = $parts[0];
            }
        }

        if (count($hashes) !== 12) {
            throw new \RuntimeException('Expected 12 EU icon hashes, found ' . count($hashes));
        }

        sort($hashes);

        return $hashes;
    }

    /**
     * @return list<string>
     */
    public function shippedHashes(): array
    {
        $dir = $this->resolveIconDirectory();
        if ($dir === '') {
            throw new \RuntimeException('EU icon directory missing');
        }

        $hashes = [];
        foreach (glob($dir . '/*.svg') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $hash = hash_file('sha256', $file);
            if (!is_string($hash)) {
                continue;
            }
            $hashes[] = $hash;
        }

        if (count($hashes) !== 12) {
            throw new \RuntimeException('Expected 12 shipped EU icons, found ' . count($hashes));
        }

        sort($hashes);

        return $hashes;
    }

    public function verify(): bool
    {
        return $this->expectedHashes() === $this->shippedHashes();
    }

    private function resolveManifestPath(): string
    {
        $fallback = dirname(__DIR__, 3) . '/Configuration/BuildInputs/eu-icons-manifest.sha256';
        if (is_file($fallback)) {
            return $fallback;
        }

        $absolute = GeneralUtility::getFileAbsFileName(self::SOURCE_MANIFEST);
        if ($absolute !== '' && is_file($absolute)) {
            return $absolute;
        }

        return '';
    }

    private function resolveIconDirectory(): string
    {
        $fallback = dirname(__DIR__, 3) . '/Resources/Public/Icons/EuAiLabel/';
        if (is_dir($fallback)) {
            return $fallback;
        }

        $absolute = GeneralUtility::getFileAbsFileName(self::ICON_DIR);
        if ($absolute !== '' && is_dir($absolute)) {
            return rtrim($absolute, '/') . '/';
        }

        return '';
    }
}
