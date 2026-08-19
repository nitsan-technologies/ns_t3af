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

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\Api\AiLabelRecorderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Helper for child extensions to bind captures at persistence points.
 *
 * Child binds always store Involvement::AiGenerated. Editors change
 * involvement in the AI Label module. Visitor badges still require confirm.
 */
final class AiLabelBindHelper
{
    public static function bindPageRecord(int $uid, string $source = 'ns_t3ai'): void
    {
        self::bind('pages', $uid, $source);
    }

    public static function bindContentRecord(int $uid, string $source = 'ns_t3ai'): void
    {
        self::bind('tt_content', $uid, $source);
    }

    public static function bindFileMetadata(int $uid, string $source = 'ns_t3aa', bool $altTextOnly = false): void
    {
        if ($altTextOnly) {
            return;
        }
        self::bind('sys_file_metadata', $uid, $source);
    }

    public static function bindRecord(string $table, int $uid, string $source = 'api'): void
    {
        self::bind($table, $uid, $source);
    }

    private static function bind(string $table, int $uid, string $source): void
    {
        if ($uid <= 0 || !interface_exists(AiLabelRecorderInterface::class)) {
            return;
        }

        $recorder = GeneralUtility::makeInstance(AiLabelRecorderInterface::class);
        $correlationId = GenerationCorrelationRegistry::consume();
        if ($correlationId === null) {
            $recorder->recordOrigin($table, $uid, Involvement::AiGenerated, $source);
            return;
        }

        $recorder->bindGeneration(
            $correlationId,
            $table,
            $uid,
            Involvement::AiGenerated,
            $source,
        );
    }
}
