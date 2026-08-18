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

final class AiLabelFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $involvement = '',
        public readonly string $labelState = '',
        public readonly string $fileType = '',
        public readonly string $publicInterest = '',
        public readonly string $confirmedBy = '',
        public readonly string $dateFrom = '',
        public readonly string $dateTo = '',
        public readonly string $recordingSource = '',
        public readonly string $reasonCode = '',
        public readonly int $page = 1,
        public readonly int $max = 20,
        public readonly string $folder = '',
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public static function fromRequestParams(array $params, string $defaultFolder = ''): self
    {
        $max = (int) ($params['max'] ?? 20);
        if (!in_array($max, [20, 50, 100], true)) {
            $max = 20;
        }

        $page = max(1, (int) ($params['page'] ?? $params['currentPage'] ?? 1));

        return new self(
            search: trim((string) ($params['search'] ?? '')),
            involvement: trim((string) ($params['involvement'] ?? '')),
            labelState: trim((string) ($params['labelState'] ?? '')),
            fileType: trim((string) ($params['fileType'] ?? '')),
            publicInterest: trim((string) ($params['publicInterest'] ?? '')),
            confirmedBy: trim((string) ($params['confirmedBy'] ?? '')),
            dateFrom: trim((string) ($params['dateFrom'] ?? $params['from'] ?? '')),
            dateTo: trim((string) ($params['dateTo'] ?? $params['to'] ?? '')),
            recordingSource: trim((string) ($params['recordingSource'] ?? '')),
            reasonCode: trim((string) ($params['reasonCode'] ?? '')),
            page: $page,
            max: $max,
            folder: trim((string) ($params['folder'] ?? $defaultFolder)),
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toRouteParams(string $subTab = ''): array
    {
        $params = array_filter([
            'search' => $this->search,
            'involvement' => $this->involvement,
            'labelState' => $this->labelState,
            'fileType' => $this->fileType,
            'publicInterest' => $this->publicInterest,
            'confirmedBy' => $this->confirmedBy,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'recordingSource' => $this->recordingSource,
            'reasonCode' => $this->reasonCode,
            'page' => $this->page > 1 ? $this->page : '',
            'max' => $this->max !== 20 ? $this->max : '',
            'folder' => $this->folder,
        ], static fn(string|int $value): bool => $value !== '' && $value !== 0);

        return $params;
    }
}
