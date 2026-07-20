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
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextPlaceholderService;
use PHPUnit\Framework\TestCase;

final class BrandContextPlaceholderServiceTest extends TestCase
{
    private BrandContextPlaceholderService $service;

    protected function setUp(): void
    {
        $this->service = new BrandContextPlaceholderService();
    }

    public function testReplaceSubstitutesKnownPlaceholders(): void
    {
        $profile = $this->makeProfile([
            'brand_name' => 'NITSAN Technologies',
            'tone_tags' => '["Professional","Bold"]',
            'voice_notes' => 'Clear and direct.',
            'personas' => '[{"name":"CTO","level":"Expert"},{"name":"PM","level":"Intermediate"}]',
            'content_rules' => '[{"direction":"always","text":"Oxford comma"},{"direction":"never","text":"synergy"}]',
            'keywords' => '["TYPO3","Enterprise"]',
            'forbidden_words' => '["synergy"]',
            'language_code' => 'en',
            'competitors' => '["Acme"]',
            'compliance_notes' => 'No medical claims.',
        ]);

        $map = $this->service->buildMap($profile);
        $result = $this->service->replace(
            'Write for {brand_name} targeting {target_persona}. Rules: {content_rules}',
            $map,
        );

        self::assertStringContainsString('NITSAN Technologies', $result);
        self::assertStringContainsString('CTO (Expert)', $result);
        self::assertStringContainsString('Always: Oxford comma', $result);
        self::assertStringContainsString('Never: synergy', $result);
    }

    public function testReplaceIsSinglePassAndDoesNotCascade(): void
    {
        // CTX-05: a token value that itself contains another token string must not
        // be re-scanned. With sequential str_replace, {brand_name} -> "{keywords}"
        // would then be replaced by the keywords value. strtr() replaces in one pass.
        $map = [
            '{brand_name}' => '{keywords}',
            '{keywords}' => 'TYPO3, Enterprise',
        ];

        $result = $this->service->replace('Brand: {brand_name}; Keywords: {keywords}', $map);

        self::assertSame('Brand: {keywords}; Keywords: TYPO3, Enterprise', $result);
    }

    public function testTargetPersonaUsesFirstPersonaOnly(): void
    {
        $map = $this->service->buildMap($this->makeProfile([
            'personas' => '[{"name":"CTO","level":"Expert"},{"name":"PM","level":"Intermediate"}]',
        ]));

        self::assertSame('CTO (Expert)', $map['{target_persona}']);
        self::assertSame('CTO (Expert); PM (Intermediate)', $map['{target_audience}']);
    }

    public function testAssemblerBuildsBrandContextBlock(): void
    {
        $assembler = new BrandContextAssembler($this->service);
        $block = $assembler->assemble($this->makeProfile([
            'brand_name' => 'NITSAN Technologies',
            'tagline' => 'Enterprise TYPO3 agency',
            'tone_tags' => '["Professional"]',
            'personas' => '[{"name":"CTO","level":"Expert"}]',
        ]));

        self::assertStringContainsString('=== BRAND CONTEXT ===', $block);
        self::assertStringContainsString('Brand: NITSAN Technologies', $block);
        self::assertStringContainsString('Tagline: Enterprise TYPO3 agency', $block);
        self::assertStringContainsString('Audience: CTO (Expert)', $block);
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
            'is_default' => 1,
            'completeness' => 0,
            'crdate' => 0,
            'tstamp' => 0,
        ], $overrides));
    }
}
