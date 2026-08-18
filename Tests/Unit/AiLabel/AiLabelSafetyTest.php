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
use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\EuIconManifestService;
use NITSAN\NsT3AF\AiLabel\Service\MediaRuleEngine;
use NITSAN\NsT3AF\AiLabel\Service\TextRuleEngine;
use PHPUnit\Framework\TestCase;

final class AiLabelSafetyTest extends TestCase
{
    public function testHumanReviewSuppressesTextButNotMedia(): void
    {
        $strings = new ComplianceStringsService();
        $media = new MediaRuleEngine($strings);
        $text = new TextRuleEngine();

        $involvement = Involvement::AiGenerated;
        $mediaDecision = $media->decide($involvement, LabellingMode::Automatic, true, strtotime('2026-09-01'));
        $textDecision = $text->decide($involvement, true, true, 'Jane Editor', true);

        self::assertTrue($mediaDecision->showLabel);
        self::assertSame(ReasonCode::RuleDefault, $mediaDecision->reasonCode);
        self::assertFalse($textDecision->showLabel);
        self::assertSame(ReasonCode::EditorialControl, $textDecision->reasonCode);
    }

    public function testComplianceStringsLoad(): void
    {
        $service = new ComplianceStringsService();
        self::assertSame('2026-08-02', $service->applicationDate());
        self::assertNotSame('', $service->get('caveat'));
        self::assertNotSame('', $service->get('coverageCaveat', 'de'));
    }

    public function testShippedIconsMatchManifest(): void
    {
        self::assertTrue((new EuIconManifestService())->verify());
    }

    public function testUnconfirmedMediaDecisionDoesNotShowLabel(): void
    {
        $strings = new ComplianceStringsService();
        $decision = (new MediaRuleEngine($strings))->decide(
            Involvement::Suggestion,
            LabellingMode::Automatic,
            false,
            time(),
        );
        self::assertFalse($decision->showLabel);
    }

    public function testConfirmedMediaDecisionIncludesLabelText(): void
    {
        $strings = new ComplianceStringsService();
        $decision = (new MediaRuleEngine($strings))->decide(
            Involvement::AiGenerated,
            LabellingMode::Automatic,
            true,
            strtotime('2026-09-01'),
        );
        self::assertTrue($decision->showLabel);
    }
}
