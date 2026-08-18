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
use NITSAN\NsT3AF\AiLabel\Dto\FrontendLabelState;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * Builds {@see FrontendLabelState} from a record row or FAL file.
 */
final class FrontendLabelStateFactory
{
    public function __construct(
        private readonly ConfirmationService $confirmationService,
        private readonly MediaRuleEngine $mediaRuleEngine,
    ) {}

    /**
     * @param array<string, mixed> $record
     */
    public function fromRecord(string $table, array $record): FrontendLabelState
    {
        $uid = (int) ($record['uid'] ?? 0);
        $involvement = Involvement::tryFrom((string) ($record['tx_nst3af_ailabel_involvement'] ?? ''))
            ?? Involvement::NotReviewed;
        $mode = LabellingMode::tryFrom((string) ($record['tx_nst3af_ailabel_labelling_mode'] ?? 'automatic'))
            ?? LabellingMode::Automatic;
        $created = (int) ($record['crdate'] ?? 0);
        $confirmed = $uid > 0 && $this->confirmationService->isConfirmed($table, $uid);
        $decision = $this->mediaRuleEngine->decide($involvement, $mode, $confirmed, $created);

        return new FrontendLabelState(
            $table,
            $uid,
            $involvement,
            $mode,
            $confirmed,
            $decision->showLabel,
            $decision->reasonCode,
            $created,
            $record,
        );
    }

    public function fromFile(mixed $file): FrontendLabelState
    {
        $row = $this->metadataRow($file);
        if ($row === []) {
            return new FrontendLabelState(
                'sys_file_metadata',
                0,
                Involvement::NotReviewed,
                LabellingMode::Automatic,
                false,
                false,
                ReasonCode::Unreviewed,
                0,
                [],
            );
        }

        return $this->fromRecord('sys_file_metadata', $row);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataRow(mixed $file): array
    {
        $original = $this->originalFile($file);
        if ($original === null) {
            return [];
        }

        $row = $original->getMetaData()->get();

        return $row;
    }

    private function originalFile(mixed $file): ?File
    {
        if ($file instanceof FileReference || $file instanceof ProcessedFile) {
            return $file->getOriginalFile();
        }

        return $file instanceof File ? $file : null;
    }
}
