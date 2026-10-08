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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Mcp\Service\RecordService;

/**
 * Editor-facing place of a create: first child of a page, or directly after a record.
 *
 * @internal
 */
final class AgentCreatePlacement
{
    public function __construct(
        private readonly AgentTranslator $translator,
        private readonly AgentRecordLabeler $recordLabeler,
        private readonly RecordService $recordService,
    ) {}

    public function describe(string $table, int $pid): string
    {
        if ($table === '' || $pid === 0) {
            return '';
        }

        if ($pid > 0) {
            $label = $table === 'pages' ? 'agent.draft.placeFirstPage' : 'agent.draft.placeFirst';

            return $this->translator->translate($label, [$this->named('pages', $pid)]);
        }

        $anchorUid = abs($pid);
        $anchor = $this->named($table, $anchorUid);
        $parentUid = $this->parentUid($table, $anchorUid);
        if ($parentUid > 0) {
            return $this->translator->translate('agent.draft.placeAfter', [$this->named('pages', $parentUid), $anchor]);
        }

        return $this->translator->translate('agent.draft.placeAfterRecord', [$anchor]);
    }

    private function named(string $table, int $uid): string
    {
        return $this->recordLabeler->recordLabel($table, $uid) . ' [' . $uid . ']';
    }

    private function parentUid(string $table, int $uid): int
    {
        $row = $this->recordService->findByUid($table, $uid, ['pid']);

        return is_array($row) ? (int) ($row['pid'] ?? 0) : 0;
    }
}
