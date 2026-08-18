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

namespace NITSAN\NsT3AF\AiLabel\Dto;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Domain\LabellingMode;
use NITSAN\NsT3AF\AiLabel\Domain\ReasonCode;

/**
 * Resolved visitor-label state for a record or file metadata row.
 */
final class FrontendLabelState
{
    /**
     * @param array<string, mixed> $record
     */
    public function __construct(
        public readonly string $table,
        public readonly int $uid,
        public readonly Involvement $involvement,
        public readonly LabellingMode $labellingMode,
        public readonly bool $confirmed,
        public readonly bool $showLabel,
        public readonly ReasonCode $reasonCode,
        public readonly int $created,
        public readonly array $record,
    ) {}

    public function hasAiInvolvement(): bool
    {
        return $this->involvement === Involvement::AiGenerated
            || $this->involvement === Involvement::AiModified;
    }

    public function isAiGenerated(): bool
    {
        return $this->involvement === Involvement::AiGenerated;
    }

    public function isAiModified(): bool
    {
        return $this->involvement === Involvement::AiModified;
    }

    public function involvementKey(): string
    {
        return $this->involvement->value;
    }

    public function reasonCodeKey(): string
    {
        return $this->reasonCode->value;
    }
}
