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

namespace NITSAN\NsT3AF\AiLabel\Service;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Domain\LabellingMode;
use NITSAN\NsT3AF\AiLabel\Domain\ReasonCode;
use NITSAN\NsT3AF\AiLabel\Dto\LabelDecision;

/**
 * R5 media rule engine. Human review does NOT suppress media labels (Art. 50(4)).
 */
final class MediaRuleEngine
{
    public function __construct(
        private readonly ComplianceStringsService $complianceStrings,
    ) {}

    public function decide(
        Involvement $involvement,
        LabellingMode $mode,
        bool $confirmed,
        ?int $creationDate = null,
    ): LabelDecision {
        if ($mode === LabellingMode::Never) {
            return new LabelDecision(false, ReasonCode::ManualExempt);
        }

        if ($creationDate !== null && $creationDate > 0) {
            $cutoff = strtotime($this->complianceStrings->applicationDate() . ' 00:00:00 UTC');
            if ($cutoff !== false && $creationDate < $cutoff) {
                return new LabelDecision(false, ReasonCode::PreCutoff);
            }
        }

        if ($involvement->isUnconfirmed() || !$confirmed) {
            return new LabelDecision(false, ReasonCode::Unreviewed);
        }

        if ($involvement === Involvement::NoAi) {
            return new LabelDecision(false, ReasonCode::NoAi);
        }

        if ($mode === LabellingMode::Always) {
            return new LabelDecision(true, ReasonCode::ManualForced);
        }

        if ($involvement === Involvement::AiGenerated || $involvement === Involvement::AiModified) {
            return new LabelDecision(true, ReasonCode::RuleDefault);
        }

        if ($involvement === Involvement::OriginUnknown) {
            return new LabelDecision(false, ReasonCode::UnknownOrigin);
        }

        return new LabelDecision(false, ReasonCode::Unreviewed);
    }
}
