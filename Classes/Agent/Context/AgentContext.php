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

namespace NITSAN\NsT3AF\Agent\Context;

/**
 * Resolved backend context for an AI Agent turn (R9).
 *
 * @internal
 */
final readonly class AgentContext
{
    /**
     * @param array{table: string, uid: int}|null $focusedRecord
     */
    public function __construct(
        public string $module,
        public int $pageId,
        public ?array $focusedRecord,
        public int $languageId,
        public string $siteIdentifier,
        public int $workspaceId,
        public ?int $brandContextProfileUid,
        public string $brandName,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'pageId' => $this->pageId,
            'record' => $this->focusedRecord,
            'languageId' => $this->languageId,
            'siteIdentifier' => $this->siteIdentifier,
            'workspaceId' => $this->workspaceId,
            'brandContextProfileUid' => $this->brandContextProfileUid,
            'brandName' => $this->brandName,
        ];
    }
}
