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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Service\CreditsRetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditsRetryPolicyTest extends TestCase
{
    #[DataProvider('neverRetryCodes')]
    public function testNeverRetryCodes(string $code): void
    {
        $e = new CreditsApiException($code, 422, '', ['upstream_status' => 503]);
        self::assertTrue(CreditsRetryPolicy::isNeverRetry($e));
        self::assertFalse(CreditsRetryPolicy::shouldRetryUpstream($e, 0));
    }

    /** @return array<string, array{string}> */
    public static function neverRetryCodes(): array
    {
        return array_combine(
            $codes = ['context_length_exceeded', 'tools_unsupported', 'model_unknown', 'model_not_allowed', 'request_too_large', 'unsupported_content', 'insufficient_credits', 'plan_expired'],
            array_map(static fn(string $c): array => [$c], $codes),
        );
    }

    public function testTimeoutRetriesOnce(): void
    {
        $e = new CreditsApiException('upstream_ai_timeout', 502);
        self::assertTrue(CreditsRetryPolicy::shouldRetryUpstream($e, 0));
        self::assertFalse(CreditsRetryPolicy::shouldRetryUpstream($e, 1));
    }

    public function testUpstreamErrorRetriesOnlyOnServerSideStatus(): void
    {
        self::assertTrue(CreditsRetryPolicy::isTransientUpstream(new CreditsApiException('upstream_ai_error', 502, '', ['upstream_status' => 503])));
        self::assertTrue(CreditsRetryPolicy::isTransientUpstream(new CreditsApiException('upstream_ai_error', 502, '', ['upstream_status' => '500'])));
        self::assertTrue(CreditsRetryPolicy::isTransientUpstream(new CreditsApiException('upstream_ai_error', 502, '', ['upstream_status' => 408])));
        self::assertFalse(CreditsRetryPolicy::isTransientUpstream(new CreditsApiException('upstream_ai_error', 502, '', ['upstream_status' => 400])));
    }

    public function testUnknownUpstreamErrorCodeAloneDoesNotTriggerRetry(): void
    {
        self::assertFalse(CreditsRetryPolicy::isTransientUpstream(new CreditsApiException('upstream_ai_error', 502, '', ['upstream_error_code' => 'overloaded_error'])));
    }

    public function testRateLimitedAndIdempotencyKeepOwnHandling(): void
    {
        self::assertFalse(CreditsRetryPolicy::isTransientUpstream(new CreditsApiException('rate_limited', 429)));
        self::assertFalse(CreditsRetryPolicy::isTransientUpstream(new CreditsApiException('idempotency_conflict', 409)));
    }
}
