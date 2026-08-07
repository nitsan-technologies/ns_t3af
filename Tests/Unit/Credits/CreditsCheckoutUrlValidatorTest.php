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

use NITSAN\NsT3AF\Credits\Service\CreditsCheckoutUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditsCheckoutUrlValidatorTest extends TestCase
{
    private CreditsCheckoutUrlValidator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CreditsCheckoutUrlValidator();
    }

    #[DataProvider('allowedUrlsProvider')]
    public function testAllowsTrustedCheckoutHosts(string $url): void
    {
        self::assertTrue($this->subject->isAllowed($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedUrlsProvider(): iterable
    {
        yield 'pabbly payments' => ['https://payments.pabbly.com/checkout/abc'];
        yield 't3planet shop' => ['https://t3planet.shop/pabbly/starter?token=x'];
        yield 'pabbly subdomain' => ['https://pabbly.t3planet.de/checkout/pro'];
        yield 't3planet de subdomain' => ['https://pay.t3planet.de/order/1'];
    }

    #[DataProvider('deniedUrlsProvider')]
    public function testRejectsUntrustedCheckoutHosts(string $url): void
    {
        self::assertFalse($this->subject->isAllowed($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedUrlsProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'http' => ['http://payments.pabbly.com/checkout'];
        yield 'foreign host' => ['https://evil.example/phish'];
        yield 'javascript' => ['javascript:alert(1)'];
    }
}
