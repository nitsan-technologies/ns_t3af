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
 * Parses composer slash commands without matching path slashes inside @file tokens.
 *
 * Free-text after the tool name is returned as `remainder` for {@see AgentSlashArgumentBinder}.
 * JSON bodies are decoded into `arguments` immediately.
 *
 * @internal
 */
final class AgentMessageParser
{
    /**
     * Remove @table:uid and @file:storage:identifier tokens; collapse whitespace.
     */
    public function stripComposerTokens(string $message): string
    {
        $message = preg_replace('/@file:\d+:\S+/i', '', $message) ?? $message;
        $message = preg_replace('/@[a-z0-9_]+:\d+/i', '', $message) ?? $message;
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        return $message;
    }

    /**
     * Like stripComposerTokens(), but keeps record references readable ("@pages:224" becomes
     * "page #224") so an instruction such as "compare @pages:224 with @pages:225" still says
     * which records it is about. File tokens are dropped.
     */
    public function describeComposerTokens(string $message): string
    {
        $message = preg_replace('/@file:\d+:\S+/i', '', $message) ?? $message;
        $message = preg_replace_callback(
            '/@([a-z0-9_]+):(\d+)/i',
            static fn(array $m): string => (strtolower($m[1]) === 'pages' ? 'page' : strtolower($m[1])) . ' #' . $m[2],
            $message,
        ) ?? $message;

        return trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    }

    /**
     * Slash commands must lead the message (after stripping attachments), e.g. "/pages_get 49".
     *
     * @return array{name: string, arguments: array<string, mixed>, remainder: string}
     */
    public function extractSlashCommand(string $message): array
    {
        $empty = ['name' => '', 'arguments' => [], 'remainder' => ''];
        $stripped = $this->stripComposerTokens($message);
        if ($stripped === '' || !str_starts_with($stripped, '/')) {
            return $empty;
        }

        if (preg_match('#^/(\S+)(?:\s+(.*))?$#s', $stripped, $matches) !== 1) {
            return $empty;
        }

        $name = trim($matches[1]);
        $rest = trim($matches[2] ?? '');
        if ($name === '') {
            return $empty;
        }

        if ($rest === '') {
            return ['name' => $name, 'arguments' => [], 'remainder' => ''];
        }

        if (str_starts_with($rest, '{')) {
            try {
                $decoded = json_decode($rest, true, 512, JSON_THROW_ON_ERROR);

                return [
                    'name' => $name,
                    'arguments' => is_array($decoded) ? $decoded : [],
                    'remainder' => '',
                ];
            } catch (\JsonException) {
                return ['name' => $name, 'arguments' => [], 'remainder' => ''];
            }
        }

        return [
            'name' => $name,
            'arguments' => [],
            'remainder' => $rest,
        ];
    }
}
