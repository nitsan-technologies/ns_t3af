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

use NITSAN\NsT3AF\Agent\Service\AgentReadFastPathService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentReadFastPathServiceTest extends TestCase
{
    #[Test]
    public function formatContentDetailPreservesHtmlLineBreaks(): void
    {
        $service = (new \ReflectionClass(AgentReadFastPathService::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(AgentReadFastPathService::class, 'formatContentDetail');
        $method->setAccessible(true);

        /** @var string $formatted */
        $formatted = $method->invoke($service, [
            'uid' => 198,
            'header' => 'Invoice Details',
            'bodytext' => '<p>Customer: Acme Corporation</p><p>Invoice Number: INV-2026-001</p><p>Actions:</p><ul><li>Pay</li><li>Download</li></ul>',
        ], false);

        self::assertStringContainsString('tt_content:198 — Invoice Details', $formatted);
        self::assertStringContainsString('Customer: Acme Corporation', $formatted);
        self::assertStringContainsString('Invoice Number: INV-2026-001', $formatted);
        self::assertStringContainsString('Pay', $formatted);
        self::assertStringNotContainsString('CorporationInvoice', $formatted);
    }
}
