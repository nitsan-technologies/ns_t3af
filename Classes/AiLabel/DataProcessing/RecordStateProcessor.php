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

namespace NITSAN\NsT3AF\AiLabel\DataProcessing;

use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelStateFactory;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * Assigns {@see \NITSAN\NsT3AF\AiLabel\Dto\FrontendLabelState} for the current record.
 *
 * TypoScript alias: nst3af-label
 */
final class RecordStateProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly FrontendLabelStateFactory $frontendLabelStateFactory,
    ) {}

    /**
     * @param array<string, mixed> $contentObjectConfiguration
     * @param array<string, mixed> $processorConfiguration
     * @param array<string, mixed> $processedData
     * @return array<string, mixed>
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData,
    ): array {
        if (isset($processorConfiguration['if.']) && !$cObj->checkIf($processorConfiguration['if.'])) {
            return $processedData;
        }

        $as = (string) ($processorConfiguration['as'] ?? 'labelState');
        $table = (string) ($processorConfiguration['table'] ?? 'tt_content');
        $record = $processedData['data'] ?? $cObj->data;
        if (!is_array($record)) {
            $record = [];
        }

        $processedData[$as] = $this->frontendLabelStateFactory->fromRecord($table, $record);

        return $processedData;
    }
}
