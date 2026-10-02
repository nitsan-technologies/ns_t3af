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

namespace NITSAN\NsT3AF\Provider\SymfonyAi;

/**
 * Reads answer text, reasoning and tool calls from a Symfony AI result.
 *
 * Since symfony/ai 0.13 a model that reasons (OpenAI gpt-5 / o-series via the Responses API,
 * Anthropic extended thinking, Gemini thinking) returns a MultiPartResult (thinking + text,
 * or thinking + tool calls). `asText()` on the deferred result throws for that, so reading
 * only `asText()` or `getContent()` yields an empty answer. This reader walks the parts.
 *
 * Classes are matched by short name, so the scoped vendor build (NITSAN\T3af\Vendor\…) works too.
 *
 * @internal
 */
final class SymfonyAiResultReader
{
    /**
     * @param object $result DeferredResult or any ResultInterface
     */
    public static function text(object $result): string
    {
        $text = '';
        foreach (self::parts($result) as $part) {
            if (self::is($part, 'TextResult') && method_exists($part, 'getContent')) {
                $content = $part->getContent();
                $text .= is_string($content) ? $content : '';
            }
        }

        return $text;
    }

    public static function thinking(object $result): string
    {
        $thinking = [];
        foreach (self::parts($result) as $part) {
            if (self::is($part, 'ThinkingResult') && method_exists($part, 'getContent')) {
                $content = $part->getContent();
                if (is_string($content) && trim($content) !== '') {
                    $thinking[] = trim($content);
                }
            }
        }

        return implode("\n\n", $thinking);
    }

    /**
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    public static function toolCalls(object $result): array
    {
        $calls = [];
        foreach (self::parts($result) as $part) {
            if (!self::is($part, 'ToolCallResult') || !method_exists($part, 'getContent')) {
                continue;
            }
            $content = $part->getContent();
            foreach (is_array($content) ? $content : [] as $call) {
                if (!is_object($call) || !method_exists($call, 'getName') || !method_exists($call, 'getId') || !method_exists($call, 'getArguments')) {
                    continue;
                }
                $name = (string) $call->getName();
                if ($name === '') {
                    continue;
                }
                $arguments = $call->getArguments();
                $normalized = [];
                foreach (is_array($arguments) ? $arguments : [] as $key => $value) {
                    $normalized[(string) $key] = $value;
                }
                $id = (string) $call->getId();
                $calls[] = ['id' => $id !== '' ? $id : uniqid('call_', true), 'name' => $name, 'arguments' => $normalized];
            }
        }

        return $calls;
    }

    /**
     * @return list<object>
     */
    private static function parts(object $result): array
    {
        $inner = $result;
        if (!self::is($result, 'TextResult') && !self::is($result, 'MultiPartResult') && method_exists($result, 'getResult')) {
            try {
                $resolved = $result->getResult();
                $inner = is_object($resolved) ? $resolved : $result;
            } catch (\Throwable) {
                return [];
            }
        }

        if (self::is($inner, 'MultiPartResult') && method_exists($inner, 'getContent')) {
            $content = $inner->getContent();

            return array_values(array_filter(is_array($content) ? $content : [], 'is_object'));
        }

        return [$inner];
    }

    private static function is(object $value, string $shortName): bool
    {
        $class = $value::class;

        return $class === $shortName || str_ends_with($class, '\\' . $shortName);
    }
}
