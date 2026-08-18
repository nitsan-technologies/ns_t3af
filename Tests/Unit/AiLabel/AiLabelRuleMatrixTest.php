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
use NITSAN\NsT3AF\AiLabel\Domain\LabellingMode;
use NITSAN\NsT3AF\AiLabel\Domain\ReasonCode;
use NITSAN\NsT3AF\AiLabel\Service\AutoConfirmSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\MediaRuleEngine;
use NITSAN\NsT3AF\AiLabel\Service\TextRuleEngine;
use PHPUnit\Framework\TestCase;

final class AiLabelRuleMatrixTest extends TestCase
{
    private TextRuleEngine $text;
    private MediaRuleEngine $media;

    protected function setUp(): void
    {
        $strings = new ComplianceStringsService();
        $this->text = new TextRuleEngine();
        $this->media = new MediaRuleEngine($strings);
    }

    public function testTextNoAiNeverLabels(): void
    {
        $decision = $this->text->decide(Involvement::NoAi, true, false, '', true);
        self::assertFalse($decision->showLabel);
        self::assertSame(ReasonCode::NoAi, $decision->reasonCode);
    }

    public function testTextWithoutPublicInterestNeverLabels(): void
    {
        $decision = $this->text->decide(Involvement::AiGenerated, false, false, '', true);
        self::assertFalse($decision->showLabel);
        self::assertSame(ReasonCode::NotPublicInterest, $decision->reasonCode);
    }

    public function testMediaPreCutoffDoesNotLabel(): void
    {
        $decision = $this->media->decide(
            Involvement::AiGenerated,
            LabellingMode::Automatic,
            true,
            strtotime('2020-01-01'),
        );
        self::assertFalse($decision->showLabel);
        self::assertSame(ReasonCode::PreCutoff, $decision->reasonCode);
    }

    public function testMediaNeverModeSuppresses(): void
    {
        $decision = $this->media->decide(
            Involvement::AiGenerated,
            LabellingMode::Never,
            true,
            strtotime('2026-09-01'),
        );
        self::assertFalse($decision->showLabel);
        self::assertSame(ReasonCode::ManualExempt, $decision->reasonCode);
    }

    public function testAutoConfirmRespectsHoldList(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelHoldList'] = ['public_interest_text'];
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['ailabelAutoConfirmSources'] = ['ns_t3ai'];

        $service = new AutoConfirmSettingsService();
        self::assertFalse($service->isAutoConfirmAllowed('ns_t3ai', true));
        self::assertTrue($service->isAutoConfirmAllowed('ns_t3ai', false));
    }
}
