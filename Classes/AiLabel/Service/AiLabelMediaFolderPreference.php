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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Per-backend-user default FAL folder for the AI Label Media tab.
 */
final class AiLabelMediaFolderPreference
{
    public const STORAGE_KEY = 't3af_ailabel';

    public function get(BackendUserAuthentication $beUser): string
    {
        $raw = $beUser->getModuleData(self::STORAGE_KEY);
        if (!is_array($raw)) {
            return '';
        }

        $folder = $raw['mediaFolder'] ?? '';

        return is_string($folder) ? $folder : '';
    }

    public function set(BackendUserAuthentication $beUser, string $folder): void
    {
        if ($folder === '' || $this->isSame($this->get($beUser), $folder)) {
            return;
        }

        $beUser->pushModuleData(self::STORAGE_KEY, ['mediaFolder' => $folder]);
    }

    public function isSame(string $left, string $right): bool
    {
        return $left !== '' && rtrim($left, '/') === rtrim($right, '/');
    }
}
