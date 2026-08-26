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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Domain\ReasonCode;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelRecordEvaluator;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelRenderer;
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelTextService;
use NITSAN\NsT3AF\AiLabel\Service\MediaRuleEngine;
use NITSAN\NsT3AF\AiLabel\Service\RivalsRendererGuard;
use NITSAN\NsT3AF\AiLabel\Service\TextRuleEngine;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;

final class FrontendLabelRendererTest extends TestCase
{
    public function testIconOnlyLargeOmitsTextSpan(): void
    {
        $html = $this->buildMarkup('icon_only', 'large');

        self::assertStringContainsString('nst3af-ailabel--icon-only', $html);
        self::assertStringContainsString('nst3af-ailabel--size-large', $html);
        self::assertStringContainsString('nst3af-ailabel__icon', $html);
        self::assertStringNotContainsString('nst3af-ailabel__text', $html);
    }

    public function testShowWordingIsTextOnlyWithoutIcon(): void
    {
        $html = $this->buildMarkup('show_site_language', 'medium');

        self::assertStringContainsString('nst3af-ailabel--text-only', $html);
        self::assertStringContainsString('nst3af-ailabel__text', $html);
        self::assertStringContainsString('AI generated', $html);
        self::assertStringNotContainsString('nst3af-ailabel__icon', $html);
        self::assertStringNotContainsString('nst3af-ailabel--icon-only', $html);
    }

    public function testSecondInfoLayerWrapsDetailsWithoutReasonCode(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'ailabelSecondInfoLayer' => 'on',
        ]);
        $renderer = new FrontendLabelRenderer(
            new AiLabelRecordEvaluator(
                new MediaRuleEngine(new ComplianceStringsService()),
                new TextRuleEngine(),
            ),
            new RivalsRendererGuard(),
            new AiLabelSettingsService($extensionSettings),
            new FrontendLabelTextService(
                $this->createMock(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class),
                new \TYPO3\CMS\Core\Context\Context(),
                $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class),
            ),
        );

        $detailMethod = new \ReflectionMethod(FrontendLabelRenderer::class, 'secondLayerDetail');
        $detailMethod->setAccessible(true);
        $detail = (string) $detailMethod->invoke(
            $renderer,
            Involvement::AiGenerated,
            ReasonCode::RuleDefault,
            ['secondInfoLayer' => 'on'],
        );
        self::assertSame('AI generated', $detail);
        self::assertStringNotContainsString('rule_default', $detail);

        $markupMethod = new \ReflectionMethod(FrontendLabelRenderer::class, 'buildMarkup');
        $markupMethod->setAccessible(true);
        $html = (string) $markupMethod->invoke(
            $renderer,
            '/icon.svg',
            'AI generated',
            'nst3af-ailabel--size-medium',
            false,
            true,
            $detail,
        );

        self::assertStringContainsString('nst3af-ailabel-details', $html);
        self::assertStringContainsString('AI generated', $html);
        self::assertStringNotContainsString('rule_default', $html);
    }

    public function testRenderBadgeMarkupSkipsNonLabelInvolvement(): void
    {
        self::assertSame('', $this->renderer()->renderBadgeMarkup(Involvement::NotReviewed));
    }

    /**
     * Composer `_assets` URLs are already percent-encoded; classic paths may
     * contain literal spaces. Encoding must never produce `%2520`.
     *
     * @dataProvider encodeWebPathCases
     */
    public function testEncodeWebPathDoesNotDoubleEncode(string $input, string $expected): void
    {
        $method = new \ReflectionMethod(FrontendLabelRenderer::class, 'encodeWebPath');
        $method->setAccessible(true);

        self::assertSame($expected, (string) $method->invoke($this->renderer(), $input));
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function encodeWebPathCases(): \Generator
    {
        yield 'composer already encoded space' => [
            '/_assets/abc123/Icons/EuAiLabel/LABEL_AI%20GENERATED_white.svg',
            '/_assets/abc123/Icons/EuAiLabel/LABEL_AI%20GENERATED_white.svg',
        ];
        yield 'classic path with literal space' => [
            '/typo3conf/ext/ns_t3af/Resources/Public/Icons/EuAiLabel/LABEL_AI GENERATED_white.svg',
            '/typo3conf/ext/ns_t3af/Resources/Public/Icons/EuAiLabel/LABEL_AI%20GENERATED_white.svg',
        ];
        yield 'relative path with literal space' => [
            'typo3conf/ext/ns_t3af/Resources/Public/Icons/EuAiLabel/LABEL_AI MODIFIED_black.svg',
            'typo3conf/ext/ns_t3af/Resources/Public/Icons/EuAiLabel/LABEL_AI%20MODIFIED_black.svg',
        ];
    }

    private function renderer(): FrontendLabelRenderer
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([]);

        return new FrontendLabelRenderer(
            new AiLabelRecordEvaluator(
                new MediaRuleEngine(new ComplianceStringsService()),
                new TextRuleEngine(),
            ),
            new RivalsRendererGuard(),
            new AiLabelSettingsService($extensionSettings),
            new FrontendLabelTextService(
                $this->createMock(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class),
                new \TYPO3\CMS\Core\Context\Context(),
                $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class),
            ),
        );
    }

    private function buildMarkup(string $wording, string $size): string
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'ailabelLabelWording' => $wording,
            'ailabelLabelSize' => $size,
        ]);

        $renderer = $this->renderer();
        $method = new \ReflectionMethod(FrontendLabelRenderer::class, 'buildMarkup');
        $method->setAccessible(true);

        return (string) $method->invoke(
            $renderer,
            '/_assets/test/LABEL_AI GENERATED_black.svg',
            'AI generated',
            match ($size) {
                'small' => 'nst3af-ailabel--size-small',
                'large' => 'nst3af-ailabel--size-large',
                default => 'nst3af-ailabel--size-medium',
            },
            $wording === 'icon_only',
            $wording !== 'icon_only',
        );
    }
}
