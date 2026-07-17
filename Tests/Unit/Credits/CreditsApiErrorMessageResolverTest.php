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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Service\CreditsApiErrorMessageResolver;
use PHPUnit\Framework\TestCase;

final class CreditsApiErrorMessageResolverTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousLang = null;

    protected function setUp(): void
    {
        $this->previousLang = $GLOBALS['LANG'] ?? null;
        $GLOBALS['LANG'] = new class {
            public function sL(string $label): string
            {
                return match (true) {
                    str_contains($label, 'rate_limited') => 'Wait %s seconds.',
                    str_contains($label, 'api_error') => 'Generic API error.',
                    default => '',
                };
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->previousLang === null) {
            unset($GLOBALS['LANG']);
        } else {
            $GLOBALS['LANG'] = $this->previousLang;
        }
    }

    public function testResolveInterpolatesRetryAfter(): void
    {
        $resolver = new CreditsApiErrorMessageResolver();
        $message = $resolver->resolve(new CreditsApiException(
            'rate_limited',
            429,
            'rate_limited',
            ['retry_after' => 45],
        ));

        self::assertSame('Wait 45 seconds.', $message);
    }

    public function testBuildErrorPayloadIncludesUserMessage(): void
    {
        $resolver = new CreditsApiErrorMessageResolver();
        $payload = $resolver->buildErrorPayload(new CreditsApiException(
            'api_error',
            500,
            'upstream failed',
        ));

        self::assertFalse($payload['status']);
        self::assertSame('api_error', $payload['error_code']);
        self::assertSame('Generic API error.', $payload['userMessage']);
        self::assertSame('upstream failed', $payload['message']);
    }
}
