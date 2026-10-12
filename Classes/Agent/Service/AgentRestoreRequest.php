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

namespace NITSAN\NsT3AF\Agent\Service;

/**
 * A request to put an applied change back, instead of generating a new one.
 *
 * @internal
 */
final class AgentRestoreRequest
{
    public static function matches(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        return preg_match(
            '/(?:'
            . '(?<![\p{L}\p{N}_])undo(?![\p{L}\p{N}_])'
            . '|(?<![\p{L}\p{N}_])revert(?![\p{L}\p{N}_])'
            . '|restore the (?:old|previous|original)'
            . '|put the old\b.{0,80}?\bback\b'
            . '|r(?:ü|ue)ckg(?:ä|ae)ngig'
            . '|wiederherstellen'
            . '|zur(?:ü|ue)cksetzen'
            . '|(?<![\p{L}\p{N}_])alten?\b.{0,80}?\bzur(?:ü|ue)ck'
            . ')/iu',
            $text,
        ) === 1;
    }

    /**
     * The newest applied change that still offers Undo.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function latestChangeId(array $messages): ?string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $meta = $messages[$index]['meta'] ?? null;
            if (!is_array($meta) || ($meta['undone'] ?? false) === true || ($meta['undoable'] ?? true) === false) {
                continue;
            }
            $changeId = trim((string) ($meta['changeId'] ?? ''));
            if ($changeId !== '') {
                return $changeId;
            }
        }

        return null;
    }
}
