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

use ReflectionMethod;
use ReflectionNamedType;

/**
 * Adds the required parameters and one example when a slash call omits an argument.
 *
 * @internal
 */
final class AgentMissingArgumentHint
{
    public static function append(string $toolName, object $handler, string $message): string
    {
        if (preg_match('/^Missing required argument: ([A-Za-z_][A-Za-z0-9_]*)$/', $message, $match) !== 1) {
            return $message;
        }

        $missing = $match[1];
        $required = [];
        $example = [];
        $reflection = new ReflectionMethod($handler, 'execute');
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable() || $parameter->allowsNull()) {
                continue;
            }
            $name = $parameter->getName();
            $required[] = $name;
            $example[$name] = self::exampleValue($parameter);
        }
        if (!isset($example[$missing])) {
            $required = [$missing];
            $example = [$missing => 1];
        }

        $json = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return $message;
        }

        return rtrim($message, ". \t")
            . '. Required: ' . implode(', ', $required)
            . '. Example: /' . $toolName . ' ' . $json;
    }

    private static function exampleValue(\ReflectionParameter $parameter): int|string
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
            return 1;
        }

        return '…';
    }
}
