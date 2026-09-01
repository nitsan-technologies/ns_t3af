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

use NITSAN\NsT3AF\Agent\Service\AgentFieldExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentFieldExtractorTest extends TestCase
{
    private AgentFieldExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new AgentFieldExtractor();
    }

    #[Test]
    public function extractIncludesMatchingFieldsFromRecord(): void
    {
        $result = $this->extractor->extract(
            'What is the header?',
            'content_get',
            [
                'uid' => 198,
                'header' => 'Welcome',
                'bodytext' => 'Long body',
                'hidden' => 0,
            ],
        );

        self::assertNotSame('', $result['summary']);
        $labels = array_column($result['facts'], 'label');
        self::assertContains('uid', $labels);
        self::assertContains('header', $labels);
        self::assertContains('bodytext', $labels);
    }

    #[Test]
    public function draftUsesOnlyLowRiskFieldsDetectsSeoFields(): void
    {
        self::assertTrue($this->extractor->draftUsesOnlyLowRiskFields([
            ['field' => 'description', 'key' => 'description'],
            ['field' => 'seo_title', 'key' => 'seo_title'],
        ]));
        self::assertFalse($this->extractor->draftUsesOnlyLowRiskFields([
            ['field' => 'title', 'key' => 'title'],
        ]));
    }
}
