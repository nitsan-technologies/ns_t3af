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
 * Fail-closed injection point for compliance copy.
 */
final class ComplianceStringsService
{
    private const JSON_PATH = 'EXT:ns_t3af/Configuration/BuildInputs/compliance-strings.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function get(string $path, string $locale = 'en'): string
    {
        $data = $this->load();
        $segments = explode('.', $path);
        $node = $data;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                throw new \RuntimeException('Missing compliance string: ' . $path);
            }
            $node = $node[$segment];
        }

        if (is_string($node)) {
            return $node;
        }

        if (is_array($node)) {
            $key = $locale === 'de' ? 'de' : 'en';
            $value = $node[$key] ?? '';
            if ($value === '') {
                throw new \RuntimeException('Missing compliance string locale ' . $key . ' for: ' . $path);
            }

            return $value;
        }

        throw new \RuntimeException('Invalid compliance string node: ' . $path);
    }

    public function applicationDate(): string
    {
        return $this->get('article50.applicationDate');
    }

    /**
     * @return list<string>
     */
    public function bannedWords(): array
    {
        $data = $this->load();
        $words = $data['bannedOnRenderedSurfaces']['words'] ?? null;
        if (!is_array($words) || $words === []) {
            throw new \RuntimeException('Missing bannedOnRenderedSurfaces.words');
        }

        return array_values(array_map('strval', $words));
    }

    private function resolveJsonPath(): string
    {
        $fallback = dirname(__DIR__, 3) . '/Configuration/BuildInputs/compliance-strings.json';
        if (is_file($fallback)) {
            return $fallback;
        }

        $absolute = GeneralUtility::getFileAbsFileName(self::JSON_PATH);
        if ($absolute !== '' && is_file($absolute)) {
            return $absolute;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $absolute = $this->resolveJsonPath();
        if ($absolute === '') {
            throw new \RuntimeException('Compliance strings file missing: ' . self::JSON_PATH);
        }

        $json = file_get_contents($absolute);
        if ($json === false || trim($json) === '') {
            throw new \RuntimeException('Compliance strings file empty');
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Compliance strings file invalid JSON');
        }

        $this->data = $decoded;

        return $this->data;
    }
}
