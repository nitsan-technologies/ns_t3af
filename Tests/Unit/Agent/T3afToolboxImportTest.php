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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * php -l does not catch a missing use: PHP resolves the short name in Runtime\.
 *
 * @internal
 */
final class T3afToolboxImportTest extends TestCase
{
    #[Test]
    public function t3afToolboxImportsEveryShortClassItCallsStatically(): void
    {
        $path = dirname(__DIR__, 3) . '/Classes/Agent/Runtime/T3afToolbox.php';
        $source = (string) file_get_contents($path);
        self::assertNotSame('', $source);
        self::assertStringContainsString(
            'use NITSAN\\NsT3AF\\Agent\\Service\\AgentRequestChecklist;',
            $source,
        );

        $imported = ['self' => true, 'parent' => true, 'static' => true];
        preg_match_all('/^use (?!function |const )([^;]+);/m', $source, $matches);
        foreach ($matches[1] as $use) {
            $use = trim($use);
            $alias = str_contains($use, ' as ')
                ? trim(substr($use, (int) strrpos($use, ' as ') + 4))
                : substr($use, (int) strrpos($use, '\\') + 1);
            $imported[$alias] = true;
        }

        $missing = [];
        $skipUntil = null;
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                if ($skipUntil !== null && $token === $skipUntil) {
                    $skipUntil = null;
                }

                continue;
            }
            if ($skipUntil !== null) {
                continue;
            }
            if (in_array($token[0], [T_NAMESPACE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                $skipUntil = $token[0] === T_USE || $token[0] === T_NAMESPACE ? ';' : null;
                if ($skipUntil === null) {
                    $next = $tokens[$i + 1] ?? null;
                    if (is_array($next) && $next[0] === T_STRING) {
                        $imported[$next[1]] = true;
                    }
                }

                continue;
            }
            if ($token[0] !== T_STRING) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_NS_SEPARATOR, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if (!is_array($next) || $next[0] !== T_DOUBLE_COLON) {
                continue;
            }
            if (!isset($imported[$token[1]])) {
                $missing[$token[1]] = true;
            }
        }

        self::assertSame([], array_keys($missing));
    }
}
