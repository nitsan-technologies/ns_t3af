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
use NITSAN\NsT3AF\AiLabel\Domain\ReasonCode;
use NITSAN\NsT3AF\AiLabel\Dto\LabelDecision;

/**
 * Text rule engine. Human review with a named person suppresses text labels.
 */
final class TextRuleEngine
{
    public function decide(
        Involvement $involvement,
        bool $publicInterest,
        bool $humanReview,
        string $responsiblePerson,
        bool $confirmed,
    ): LabelDecision {
        if ($involvement === Involvement::NoAi) {
            return new LabelDecision(false, ReasonCode::NoAi);
        }

        if (!$publicInterest) {
            return new LabelDecision(false, ReasonCode::NotPublicInterest);
        }

        if (!$confirmed || $involvement->isUnconfirmed()) {
            return new LabelDecision(true, ReasonCode::Unreviewed);
        }

        if ($humanReview && $responsiblePerson !== '') {
            return new LabelDecision(false, ReasonCode::EditorialControl);
        }

        if ($humanReview) {
            return new LabelDecision(true, ReasonCode::EditorialControlIncomplete);
        }

        return new LabelDecision(true, ReasonCode::RuleDefault);
    }
}
