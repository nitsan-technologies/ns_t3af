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

namespace NITSAN\NsT3AF\Credits\Exception;

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;

/**
 * Idempotent replay returned success with `content_removed: true`: the original answer was
 * scrubbed under the data-retention policy and must not be saved or shown as empty content.
 * Callers should regenerate with a fresh request_uuid. No new charge was made.
 *
 * @api
 */
final class CreditsContentRemovedException extends CreditsApiException
{
    /**
     * @param array<string, mixed> $context Replay metadata (warnings, cost_units, request_uuid, …).
     */
    public function __construct(string $message = '', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct(
            CreditsApiErrorCodes::CONTENT_REMOVED,
            CreditsApiErrorCodes::httpStatus(CreditsApiErrorCodes::CONTENT_REMOVED),
            $message,
            $context,
            $previous,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function isContentRemoved(array $payload): bool
    {
        return ($payload['content_removed'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $context = [];
        foreach (['warnings', 'request_uuid', 'cost_units', 'credits'] as $key) {
            if (array_key_exists($key, $payload)) {
                $context[$key] = $payload[$key];
            }
        }

        return new self('', $context);
    }
}
