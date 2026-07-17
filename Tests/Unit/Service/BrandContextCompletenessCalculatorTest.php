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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Service\BrandContextCompletenessCalculator;
use PHPUnit\Framework\TestCase;

final class BrandContextCompletenessCalculatorTest extends TestCase
{
    private BrandContextCompletenessCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BrandContextCompletenessCalculator();
    }

    public function testEmptyProfileHasZeroPercent(): void
    {
        $result = $this->calculator->calculate($this->makeProfile());

        self::assertSame(0, $result['percent']);
        self::assertSame(0, $result['completed']);
        self::assertSame(6, $result['total']);
    }

    public function testIdentityAndVoiceSectionsIncreaseCompleteness(): void
    {
        $result = $this->calculator->calculate($this->makeProfile([
            'brand_name' => 'NITSAN Technologies',
            'tagline' => 'Enterprise TYPO3 agency',
            'tone_tags' => '["Professional"]',
        ]));

        self::assertSame(33, $result['percent']);
        self::assertTrue($result['sections'][0]['complete']);
        self::assertTrue($result['sections'][1]['complete']);
    }

    public function testFullProfileScoresOneHundredPercent(): void
    {
        $result = $this->calculator->calculate($this->makeProfile([
            'brand_name' => 'NITSAN Technologies',
            'tagline' => 'Enterprise TYPO3 agency',
            'tone_tags' => '["Professional"]',
            'personas' => '[{"name":"CTO","level":"Expert"}]',
            'content_rules' => '[{"direction":"always","text":"Oxford comma"}]',
            'language_code' => 'en-US',
            'keywords' => '["TYPO3"]',
            'sample_content' => 'Example copy.',
        ]));

        self::assertSame(100, $result['percent']);
        self::assertSame(6, $result['completed']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeProfile(array $overrides = []): BrandContextProfile
    {
        return BrandContextProfile::fromRow(array_merge([
            'uid' => 1,
            'pid' => 1,
            'brand_name' => '',
            'industry' => '',
            'website_url' => '',
            'tagline' => '',
            'description' => '',
            'tone_tags' => '',
            'voice_notes' => '',
            'personas' => '',
            'content_rules' => '',
            'forbidden_words' => '',
            'keywords' => '',
            'competitors' => '',
            'language_code' => '',
            'sample_content' => '',
            'compliance_notes' => '',
            'document_extract' => '',
            'is_default' => 0,
            'completeness' => 0,
            'crdate' => 0,
            'tstamp' => 0,
        ], $overrides));
    }
}
