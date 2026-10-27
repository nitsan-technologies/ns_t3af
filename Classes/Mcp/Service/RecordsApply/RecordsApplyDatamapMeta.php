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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

/**
 * Internal datamap hints for records_apply (stripped before DataHandler).
 */
final class RecordsApplyDatamapMeta
{
    /** When set, RecordAppendOrderer must not rewrite a positive page pid (explicit beforeUid placement). */
    public const SKIP_APPEND_ORDER = '__nst3af_skip_append_order';

    public static function isMetaField(string $fieldName): bool
    {
        return $fieldName === self::SKIP_APPEND_ORDER;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function hasSkipAppendOrder(array $fields): bool
    {
        $value = $fields[self::SKIP_APPEND_ORDER] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function pullSkipAppendOrder(array &$fields): bool
    {
        if (!self::hasSkipAppendOrder($fields)) {
            return false;
        }

        unset($fields[self::SKIP_APPEND_ORDER]);

        return true;
    }
}
