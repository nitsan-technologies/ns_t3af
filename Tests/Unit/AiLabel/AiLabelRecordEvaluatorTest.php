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
use NITSAN\NsT3AF\AiLabel\Dto\AiLabelFilters;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelRecordEvaluator;
use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\MediaRuleEngine;
use NITSAN\NsT3AF\AiLabel\Service\TextRuleEngine;
use PHPUnit\Framework\TestCase;

final class AiLabelRecordEvaluatorTest extends TestCase
{
    private AiLabelRecordEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $strings = new ComplianceStringsService();
        $this->evaluator = new AiLabelRecordEvaluator(
            new MediaRuleEngine($strings),
            new TextRuleEngine(),
        );
    }

    public function testAwaitingReviewWhenRecordedButUnconfirmed(): void
    {
        $row = [
            'tx_nst3af_ailabel_involvement' => Involvement::AiGenerated->value,
            'tx_nst3af_ailabel_labelling_mode' => LabellingMode::Automatic->value,
            'tx_nst3af_ailabel_confirmed_at' => 0,
            'tx_nst3af_ailabel_recording_source' => 'ns_t3ai',
            'crdate' => strtotime('2026-08-10'),
        ];

        self::assertTrue($this->evaluator->isAwaitingReview($row));
        self::assertSame('held', $this->evaluator->labelStateKey('sys_file_metadata', $row));
    }

    public function testTextUnnamedReviewFlag(): void
    {
        $row = [
            'tx_nst3af_ailabel_involvement' => Involvement::AiGenerated->value,
            'tx_nst3af_ailabel_public_interest' => 1,
            'tx_nst3af_ailabel_human_review' => 1,
            'tx_nst3af_ailabel_responsible_person' => '',
            'tx_nst3af_ailabel_confirmed_at' => time(),
        ];

        self::assertTrue($this->evaluator->hasUnnamedReview($row));
        self::assertSame(
            ReasonCode::EditorialControlIncomplete->value,
            $this->evaluator->decide('tt_content', $row)->reasonCode->value,
        );
    }

    public function testFiltersNormalizePagination(): void
    {
        $filters = AiLabelFilters::fromRequestParams(['max' => 999, 'page' => 0, 'folder' => '/user_upload/']);

        self::assertSame(20, $filters->max);
        self::assertSame(1, $filters->page);
        self::assertSame('/user_upload/', $filters->folder);
    }
}
