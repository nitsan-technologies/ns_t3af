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
use NITSAN\NsT3AF\AiLabel\Dto\LabelDecision;

/**
 * Shared row evaluation for module lists and statistics.
 */
final class AiLabelRecordEvaluator
{
    public function __construct(
        private readonly MediaRuleEngine $mediaRuleEngine,
        private readonly TextRuleEngine $textRuleEngine,
        private readonly ?AiLabelSettingsService $settingsService = null,
    ) {}

    /**
     * @param array<string, mixed> $record
     */
    public function isConfirmed(array $record): bool
    {
        return (int) ($record['tx_nst3af_ailabel_confirmed_at'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function isAwaitingReview(array $record): bool
    {
        if ($this->isConfirmed($record)) {
            return false;
        }

        $involvement = $this->involvement($record);
        $source = trim((string) ($record['tx_nst3af_ailabel_recording_source'] ?? ''));

        return $source !== ''
            || !in_array($involvement, [Involvement::NotReviewed, Involvement::NoAi], true);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function involvement(array $record): Involvement
    {
        return Involvement::tryFrom((string) ($record['tx_nst3af_ailabel_involvement'] ?? ''))
            ?? Involvement::NotReviewed;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function labellingMode(array $record): LabellingMode
    {
        return LabellingMode::tryFrom((string) ($record['tx_nst3af_ailabel_labelling_mode'] ?? ''))
            ?? LabellingMode::Automatic;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function decide(string $table, array $record): LabelDecision
    {
        $involvement = $this->involvement($record);
        $confirmed = $this->isConfirmed($record);

        if ($table === 'sys_file_metadata') {
            return $this->mediaRuleEngine->decide(
                $involvement,
                $this->labellingMode($record),
                $confirmed,
                $this->resolveCreationTimestamp($table, $record),
                ($this->settingsService?->all()['labelUnknownOrigin'] ?? 'no') === 'yes',
            );
        }

        return $this->textRuleEngine->decide(
            $involvement,
            (bool) ($record['tx_nst3af_ailabel_public_interest'] ?? false),
            (bool) ($record['tx_nst3af_ailabel_human_review'] ?? false),
            (string) ($record['tx_nst3af_ailabel_responsible_person'] ?? ''),
            $confirmed,
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    public function labelStateKey(string $table, array $record): string
    {
        $decision = $this->decide($table, $record);
        if ($decision->showLabel) {
            return 'shown';
        }

        if (!$this->isConfirmed($record)) {
            return 'held';
        }

        return 'not_shown';
    }

    /**
     * @param array<string, mixed> $record
     */
    public function hasUnnamedReview(array $record): bool
    {
        return (bool) ($record['tx_nst3af_ailabel_human_review'] ?? false)
            && trim((string) ($record['tx_nst3af_ailabel_responsible_person'] ?? '')) === '';
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveCreationTimestamp(string $table, array $record): int
    {
        return match ($table) {
            'sys_file_metadata' => (int) ($record['file_creation_date'] ?? $record['crdate'] ?? 0),
            default => (int) ($record['crdate'] ?? 0),
        };
    }
}
