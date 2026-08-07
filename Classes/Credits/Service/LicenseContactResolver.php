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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\Contract\LicenseDataRepositoryInterface;
use NITSAN\NsT3AF\Utility\LicenseUtility;

/**
 * Resolve customer name/email from ns_license rows for Token / RefreshToken payloads.
 *
 * @internal
 */
final class LicenseContactResolver
{
    /** @var list<string> */
    private const AI_UNIVERSE_EXTENSION_KEYS = [
        'ns_t3ai',
        'ns_t3aa',
        'ns_t3cs',
        'ns_t3as',
        'ns_t3ac',
    ];

    public function __construct(
        private readonly ?LicenseDataRepositoryInterface $licenseRepository,
    ) {}

    /**
     * @return array{name?: string, email?: string}
     */
    public function resolve(): array
    {
        $licenseRepository = $this->licenseRepository;
        if ($licenseRepository === null) {
            return [];
        }

        $contact = $this->resolveForExtension($licenseRepository, LicenseUtility::EXTENSION_KEY);
        if ($contact !== []) {
            return $contact;
        }

        foreach (self::AI_UNIVERSE_EXTENSION_KEYS as $extensionKey) {
            $contact = $this->resolveForExtension($licenseRepository, $extensionKey);
            if ($contact !== []) {
                return $contact;
            }
        }

        return [];
    }

    /**
     * @return array{name?: string, email?: string}
     */
    private function resolveForExtension(
        LicenseDataRepositoryInterface $licenseRepository,
        string $extensionKey,
    ): array {
        foreach ($licenseRepository->fetchData($extensionKey) as $row) {
            if (!is_array($row) || !$this->isValidRow($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            if ($name === '' && $email === '') {
                continue;
            }

            $out = [];
            if ($name !== '') {
                $out['name'] = $name;
            }
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out['email'] = $email;
            }
            if ($out !== []) {
                return $out;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isValidRow(array $row): bool
    {
        if (trim((string) ($row['license_key'] ?? '')) === '') {
            return false;
        }
        if ((int) ($row['is_life_time'] ?? 0) === 1) {
            return true;
        }

        $expires = (int) ($row['expiration_date'] ?? 0);

        return $expires === 0 || $expires > time();
    }
}
