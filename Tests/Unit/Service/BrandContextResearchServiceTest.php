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

use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Service\BrandContextResearchService;
use NITSAN\NsT3AF\Service\BrandContextWebsiteFetcher;
use PHPUnit\Framework\TestCase;

final class BrandContextResearchServiceTest extends TestCase
{
    private BrandContextResearchService $service;

    protected function setUp(): void
    {
        $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(\Psr\Http\Message\RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $this->service = new BrandContextResearchService(
            $this->createMock(AiServiceInterface::class),
            new BrandContextWebsiteFetcher(
                $this->createMock(\Psr\Http\Client\ClientInterface::class),
                $requestFactory,
            ),
        );
    }

    public function testParseJsonResponseExtractsObjectFromMarkdownFence(): void
    {
        $raw = <<<TXT
Here is the profile:

```json
{
  "brand_name": "NITSAN",
  "industry": "Technology",
  "confidence": {"brand_name": "HIGH"}
}
```
TXT;

        $parsed = $this->service->parseJsonResponse($raw);

        self::assertSame('NITSAN', $parsed['brand_name']);
        self::assertSame('Technology', $parsed['industry']);
    }

    public function testMapFieldsNormalizesIndustryToneTagsAndLanguage(): void
    {
        $fields = $this->service->mapFields([
            'brand_name' => 'Acme Corp',
            'industry' => 'technology',
            'website_url' => 'https://acme.example',
            'one_line_description' => 'We build tools.',
            'what_brand_sells' => 'SaaS products for teams.',
            'tone_tags' => ['Professional', 'Invalid', 'Friendly'],
            'write_like_this' => 'Write like a helpful expert.',
            'personas' => [
                ['name' => 'CTO', 'level' => 'Expert'],
                ['name' => 'Intern', 'level' => 'Unknown'],
            ],
            'keywords' => ['saas', 'teams'],
            'competitors' => ['Competitor A'],
            'language' => 'German (DE)',
        ], 'https://fallback.example');

        self::assertSame('Acme Corp', $fields['brandName']);
        self::assertSame('Technology', $fields['industry']);
        self::assertSame(['Professional', 'Friendly'], $fields['toneTags']);
        self::assertSame('de', $fields['languageCode']);
        self::assertCount(1, $fields['personas']);
        self::assertSame('CTO', $fields['personas'][0]['name']);
    }

    public function testNormalizeConfidenceFiltersInvalidLevels(): void
    {
        $confidence = $this->service->normalizeConfidence([
            'brand_name' => 'high',
            'industry' => 'banana',
            'tagline' => 'MEDIUM',
        ]);

        self::assertSame([
            'brand_name' => 'HIGH',
            'tagline' => 'MEDIUM',
        ], $confidence);
    }
}
