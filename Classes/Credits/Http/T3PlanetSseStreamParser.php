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

namespace NITSAN\NsT3AF\Credits\Http;

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;

/**
 * Parses normalized T3Planet Stream.php SSE events into token deltas and a usage payload.
 *
 * @internal
 */
final class T3PlanetSseStreamParser
{
    /**
     * @param \Generator<int, string, mixed, void> $lines Raw lines from {@see T3PlanetHttpClient::stream()}
     * @return \Generator<int, string, mixed, array<string, mixed>>
     */
    public function parse(\Generator $lines): \Generator
    {
        $eventName = '';
        $dataLines = [];
        $sawToken = false;
        $usagePayload = null;

        foreach ($lines as $line) {
            if ($line === '') {
                foreach ($this->flushSseEvent($eventName, $dataLines, $sawToken, $usagePayload) as $item) {
                    if (is_string($item)) {
                        yield $item;
                    } else {
                        $usagePayload = $item;
                    }
                }
                $eventName = '';
                $dataLines = [];
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                foreach ($this->flushSseEvent($eventName, $dataLines, $sawToken, $usagePayload) as $item) {
                    if (is_string($item)) {
                        yield $item;
                    } else {
                        $usagePayload = $item;
                    }
                }
                $eventName = '';
                $dataLines = [];
                $eventName = trim(substr($line, 6));
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, 5));
            }
        }

        foreach ($this->flushSseEvent($eventName, $dataLines, $sawToken, $usagePayload) as $item) {
            if (is_string($item)) {
                yield $item;
            } else {
                $usagePayload = $item;
            }
        }

        if ($usagePayload === null) {
            if ($sawToken) {
                throw new CreditsApiException(
                    CreditsApiErrorCodes::INVALID_RESPONSE,
                    0,
                    'Stream ended without a usage event after tokens were received',
                );
            }

            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                0,
                'Stream ended without a usage event',
            );
        }

        if (($usagePayload['status'] ?? true) === false) {
            $this->throwUpstreamUsageFailure($usagePayload);
        }

        return $usagePayload;
    }

    /**
     * @param list<string> $dataLines
     * @param array<string, mixed>|null $usagePayload
     * @return \Generator<int, string|array<string, mixed>>
     */
    private function flushSseEvent(
        string $eventName,
        array $dataLines,
        bool &$sawToken,
        ?array &$usagePayload,
    ): \Generator {
        if ($eventName === '' && $dataLines === []) {
            return;
        }

        $data = implode("\n", $dataLines);
        $eventName = $eventName !== '' ? $eventName : 'message';

        if ($eventName === 'token') {
            $decoded = $this->decodeJsonData($data);
            $delta = (string) ($decoded['delta'] ?? '');
            if ($delta !== '') {
                $sawToken = true;
                yield $delta;
            }
        } elseif ($eventName === 'usage') {
            $usagePayload = $this->decodeJsonData($data);
            yield $usagePayload;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonData(string $data): array
    {
        if ($data === '' || $data === '{}') {
            return [];
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::INVALID_RESPONSE,
                0,
                'Invalid JSON in SSE data frame',
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $usagePayload
     */
    private function throwUpstreamUsageFailure(array $usagePayload): never
    {
        $message = (string) ($usagePayload['upstream_message'] ?? $usagePayload['message'] ?? CreditsApiErrorCodes::UPSTREAM_AI_ERROR);
        $extra = [];
        foreach (
            [
                'content',
                'cost',
                'cost_units',
                'credits',
                'charged',
                'tokens_input',
                'tokens_output',
                'tokens_total',
                'request_uuid',
                'pricing',
            ] as $key
        ) {
            if (array_key_exists($key, $usagePayload)) {
                $extra[$key] = $usagePayload[$key];
            }
        }

        throw new CreditsApiException(
            (string) ($usagePayload['error_code'] ?? CreditsApiErrorCodes::UPSTREAM_AI_ERROR),
            502,
            $message,
            $extra,
        );
    }
}
